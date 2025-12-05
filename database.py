"""
Fire Detection System - SQLite Database Module
Handles all database operations for the fire detection dashboard
"""

import sqlite3
import os
from datetime import datetime
from contextlib import contextmanager

DATABASE_PATH = "fire_detection.db"

@contextmanager
def get_db():
    """Context manager for database connections"""
    conn = sqlite3.connect(DATABASE_PATH)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
    finally:
        conn.close()

def init_database():
    """Initialize the SQLite database with all required tables"""
    with get_db() as conn:
        cursor = conn.cursor()
        
        # Cameras table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS cameras (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                location TEXT NOT NULL,
                latitude REAL,
                longitude REAL,
                status TEXT DEFAULT 'offline',
                temperature REAL DEFAULT 22.0,
                frame_path TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Detections table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS detections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                camera_id INTEGER NOT NULL,
                camera_name TEXT NOT NULL,
                detection_type TEXT NOT NULL,
                confidence REAL NOT NULL,
                image_path TEXT,
                clip_path TEXT,
                location TEXT,
                latitude REAL,
                longitude REAL,
                status TEXT DEFAULT 'pending',
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (camera_id) REFERENCES cameras(id)
            )
        ''')
        
        # Alerts table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                detection_id INTEGER,
                alert_level TEXT NOT NULL,
                message TEXT NOT NULL,
                status TEXT DEFAULT 'active',
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (detection_id) REFERENCES detections(id)
            )
        ''')
        
        # Activity log table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS activity (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Firefighters table (user-managed)
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS firefighters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                phone TEXT NOT NULL,
                station INTEGER DEFAULT 1,
                status TEXT DEFAULT 'online',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Personnel table (system personnel including admins)
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS personnel (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                role TEXT NOT NULL,
                type TEXT NOT NULL,
                phone TEXT,
                station INTEGER,
                status TEXT DEFAULT 'online',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Stations table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS stations (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                latitude REAL,
                longitude REAL,
                personnel_count INTEGER DEFAULT 0
            )
        ''')
        
        # Stats table (for daily tracking)
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS stats (
                id INTEGER PRIMARY KEY,
                date DATE UNIQUE,
                detections_today INTEGER DEFAULT 0,
                fire_today INTEGER DEFAULT 0,
                smoke_today INTEGER DEFAULT 0,
                avg_response_time REAL DEFAULT 3.2
            )
        ''')
        
        # Detection history for charts (30-min intervals)
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS detection_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                interval_start TIMESTAMP NOT NULL,
                fire_count INTEGER DEFAULT 0,
                smoke_count INTEGER DEFAULT 0,
                UNIQUE(interval_start)
            )
        ''')
        
        # Notification log table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_id INTEGER,
                firefighter_id INTEGER,
                message TEXT,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT 'sent',
                FOREIGN KEY (alert_id) REFERENCES alerts(id),
                FOREIGN KEY (firefighter_id) REFERENCES firefighters(id)
            )
        ''')
        
        # ============================================
        # NEW: Firefighter Alert Responses Table
        # Tracks firefighter responses to alerts
        # ============================================
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS firefighter_alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_id INTEGER,
                detection_id INTEGER,
                firefighter_id INTEGER,
                station_id INTEGER,
                alert_type TEXT NOT NULL,
                location TEXT,
                area TEXT,
                confidence REAL,
                status TEXT DEFAULT 'pending',
                response_type TEXT,
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                responded_at TIMESTAMP,
                FOREIGN KEY (alert_id) REFERENCES alerts(id),
                FOREIGN KEY (detection_id) REFERENCES detections(id),
                FOREIGN KEY (firefighter_id) REFERENCES firefighters(id),
                FOREIGN KEY (station_id) REFERENCES stations(id)
            )
        ''')
        
        # ============================================
        # NEW: Firefighter Stats Table
        # Tracks individual firefighter performance
        # ============================================
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS firefighter_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                firefighter_id INTEGER UNIQUE,
                total_responded INTEGER DEFAULT 0,
                total_acknowledged INTEGER DEFAULT 0,
                total_alerts_today INTEGER DEFAULT 0,
                avg_response_time_seconds REAL DEFAULT 0,
                last_response_at TIMESTAMP,
                stats_date DATE,
                FOREIGN KEY (firefighter_id) REFERENCES firefighters(id)
            )
        ''')
        
        conn.commit()
        
        # Insert default data if tables are empty
        _insert_default_data(cursor, conn)

def _insert_default_data(cursor, conn):
    """Insert default cameras, stations, and personnel if not exists"""
    
    # Check if cameras exist
    cursor.execute("SELECT COUNT(*) FROM cameras")
    if cursor.fetchone()[0] == 0:
        cameras = [
            (1, 'Camera 1 - Visual ML', 'visual', 'Building A - Warehouse', 14.6005, 120.9850, 'offline', 22.0, 'camera_frames/camera1_live.jpg'),
            (2, 'Camera 2 - Thermal', 'thermal', 'Building A - Warehouse', 14.6010, 120.9855, 'offline', 22.5, 'camera_frames/camera2_live.jpg')
        ]
        cursor.executemany('''
            INSERT INTO cameras (id, name, type, location, latitude, longitude, status, temperature, frame_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ''', cameras)
    
    # Check if stations exist
    cursor.execute("SELECT COUNT(*) FROM stations")
    if cursor.fetchone()[0] == 0:
        stations = [
            (1, 'Fire Station 1', 14.5950, 120.9800, 6),
            (2, 'Fire Station 2', 14.6040, 120.9900, 6)
        ]
        cursor.executemany('''
            INSERT INTO stations (id, name, latitude, longitude, personnel_count)
            VALUES (?, ?, ?, ?, ?)
        ''', stations)
    
    # Check if personnel exist
    cursor.execute("SELECT COUNT(*) FROM personnel")
    if cursor.fetchone()[0] == 0:
        personnel = [
            ('Admin Johnson', 'System Administrator', 'admin', None, None),
            ('Admin Chen', 'Operations Manager', 'admin', None, None),
            ('FF Rodriguez', 'Fire Chief - Station 1', 'firefighter', '+63-917-111-0001', 1),
            ('FF Martinez', 'Firefighter - Station 1', 'firefighter', '+63-917-111-0002', 1),
            ('FF Santos', 'Firefighter - Station 1', 'firefighter', '+63-917-111-0003', 1),
            ('FF Reyes', 'Firefighter - Station 1', 'firefighter', '+63-917-111-0004', 1),
            ('FF Cruz', 'Firefighter - Station 1', 'firefighter', '+63-917-111-0005', 1),
            ('FF Bautista', 'Firefighter - Station 1', 'firefighter', '+63-917-111-0006', 1),
            ('FF Garcia', 'Fire Chief - Station 2', 'firefighter', '+63-917-222-0001', 2),
            ('FF Lopez', 'Firefighter - Station 2', 'firefighter', '+63-917-222-0002', 2),
            ('FF Hernandez', 'Firefighter - Station 2', 'firefighter', '+63-917-222-0003', 2),
            ('FF Dela Cruz', 'Firefighter - Station 2', 'firefighter', '+63-917-222-0004', 2),
        ]
        cursor.executemany('''
            INSERT INTO personnel (name, role, type, phone, station)
            VALUES (?, ?, ?, ?, ?)
        ''', personnel)
    
    # Initialize today's stats
    today = datetime.now().date().isoformat()
    cursor.execute("INSERT OR IGNORE INTO stats (id, date) VALUES (1, ?)", (today,))
    
    conn.commit()

# ============================================
# Camera Operations
# ============================================

def get_cameras():
    """Get all cameras"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM cameras ORDER BY id")
        return [dict(row) for row in cursor.fetchall()]

def update_camera_status(camera_id, status, temperature=None):
    """Update camera status and optionally temperature"""
    with get_db() as conn:
        cursor = conn.cursor()
        if temperature is not None:
            cursor.execute('''
                UPDATE cameras SET status = ?, temperature = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ''', (status, temperature, camera_id))
        else:
            cursor.execute('''
                UPDATE cameras SET status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ''', (status, camera_id))
        conn.commit()

def get_active_camera_count():
    """Get count of online cameras"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM cameras WHERE status = 'online'")
        return cursor.fetchone()[0]

# ============================================
# Detection Operations
# ============================================

def log_detection(camera_id, detection_type, confidence, image_path, location, latitude, longitude, camera_name):
    """Log a new detection and return its ID"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO detections (camera_id, camera_name, detection_type, confidence, image_path, location, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ''', (camera_id, camera_name, detection_type, confidence, image_path, location, latitude, longitude))
        detection_id = cursor.lastrowid
        
        # Update daily stats
        _update_daily_stats(cursor, detection_type)
        
        # Update 30-min interval history
        _update_detection_history(cursor, detection_type)
        
        conn.commit()
        return detection_id

def update_detection_clip(detection_id, clip_path):
    """Update detection with clip path"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("UPDATE detections SET clip_path = ? WHERE id = ?", (clip_path, detection_id))
        conn.commit()

def get_detections(limit=100):
    """Get recent detections"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM detections ORDER BY timestamp DESC LIMIT ?", (limit,))
        return [dict(row) for row in cursor.fetchall()]

def _update_daily_stats(cursor, detection_type):
    """Update daily statistics"""
    today = datetime.now().date().isoformat()
    
    # Ensure today's record exists
    cursor.execute("INSERT OR IGNORE INTO stats (id, date) VALUES (1, ?)", (today,))
    
    # Update counts
    cursor.execute('''
        UPDATE stats SET 
            detections_today = detections_today + 1,
            fire_today = fire_today + CASE WHEN ? = 'fire' THEN 1 ELSE 0 END,
            smoke_today = smoke_today + CASE WHEN ? = 'smoke' THEN 1 ELSE 0 END
        WHERE date = ?
    ''', (detection_type, detection_type, today))

def _update_detection_history(cursor, detection_type):
    """Update 30-minute interval detection history"""
    now = datetime.now()
    # Round down to nearest 30 minutes
    minute = (now.minute // 30) * 30
    interval_start = now.replace(minute=minute, second=0, microsecond=0)
    
    # Insert or update interval
    cursor.execute('''
        INSERT INTO detection_history (interval_start, fire_count, smoke_count)
        VALUES (?, 
                CASE WHEN ? = 'fire' THEN 1 ELSE 0 END,
                CASE WHEN ? = 'smoke' THEN 1 ELSE 0 END)
        ON CONFLICT(interval_start) DO UPDATE SET
            fire_count = fire_count + CASE WHEN ? = 'fire' THEN 1 ELSE 0 END,
            smoke_count = smoke_count + CASE WHEN ? = 'smoke' THEN 1 ELSE 0 END
    ''', (interval_start.isoformat(), detection_type, detection_type, detection_type, detection_type))

def get_detection_history(hours=24):
    """Get detection history for chart (30-min intervals)"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            SELECT interval_start, fire_count, smoke_count 
            FROM detection_history 
            WHERE interval_start >= datetime('now', ? || ' hours')
            ORDER BY interval_start ASC
        ''', (f'-{hours}',))
        return [dict(row) for row in cursor.fetchall()]

# ============================================
# Alert Operations
# ============================================

def create_alert(detection_id, alert_level, message):
    """Create a new alert"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO alerts (detection_id, alert_level, message)
            VALUES (?, ?, ?)
        ''', (detection_id, alert_level, message))
        conn.commit()
        return cursor.lastrowid

def get_alerts(limit=20):
    """Get recent alerts"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM alerts ORDER BY timestamp DESC LIMIT ?", (limit,))
        return [dict(row) for row in cursor.fetchall()]

def update_alert_status(alert_id, status):
    """Update alert status"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("UPDATE alerts SET status = ? WHERE id = ?", (status, alert_id))
        conn.commit()

# ============================================
# Activity Log Operations
# ============================================

def add_activity(message):
    """Add activity to log"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("INSERT INTO activity (message) VALUES (?)", (message,))
        conn.commit()
        print(f"[ACTIVITY] {message}")

def get_activity(limit=50):
    """Get recent activity"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM activity ORDER BY timestamp DESC LIMIT ?", (limit,))
        return [dict(row) for row in cursor.fetchall()]

# ============================================
# Firefighter Operations (User-Managed)
# ============================================

def add_firefighter(name, phone, station=1):
    """Add a new firefighter"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO firefighters (name, phone, station)
            VALUES (?, ?, ?)
        ''', (name, phone, station))
        conn.commit()
        return cursor.lastrowid

def update_firefighter(firefighter_id, name, phone, station):
    """Update a firefighter"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            UPDATE firefighters SET name = ?, phone = ?, station = ?
            WHERE id = ?
        ''', (name, phone, station, firefighter_id))
        conn.commit()

def delete_firefighter(firefighter_id):
    """Delete a firefighter"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("DELETE FROM firefighters WHERE id = ?", (firefighter_id,))
        conn.commit()

def get_firefighters():
    """Get all firefighters"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM firefighters ORDER BY station, name")
        return [dict(row) for row in cursor.fetchall()]

# ============================================
# Personnel Operations
# ============================================

def add_personnel(name, role, person_type, phone=None, station=None):
    """Add a new personnel"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO personnel (name, role, type, phone, station)
            VALUES (?, ?, ?, ?, ?)
        ''', (name, role, person_type, phone, station))
        conn.commit()
        return cursor.lastrowid

def update_personnel(personnel_id, name, role, person_type, phone=None, station=None, status='online'):
    """Update a personnel"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            UPDATE personnel SET name = ?, role = ?, type = ?, phone = ?, station = ?, status = ?
            WHERE id = ?
        ''', (name, role, person_type, phone, station, status, personnel_id))
        conn.commit()

def delete_personnel(personnel_id):
    """Delete a personnel"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("DELETE FROM personnel WHERE id = ?", (personnel_id,))
        conn.commit()

def get_personnel():
    """Get all personnel"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM personnel ORDER BY type, name")
        return [dict(row) for row in cursor.fetchall()]

def get_online_personnel_count():
    """Get count of online personnel"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM personnel WHERE status = 'online'")
        return cursor.fetchone()[0]

# ============================================
# Station Operations
# ============================================

def get_stations():
    """Get all stations"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM stations ORDER BY id")
        return [dict(row) for row in cursor.fetchall()]

# ============================================
# Stats Operations
# ============================================

def get_stats():
    """Get current statistics"""
    today = datetime.now().date().isoformat()
    with get_db() as conn:
        cursor = conn.cursor()
        
        # Ensure today's record exists
        cursor.execute("INSERT OR IGNORE INTO stats (id, date) VALUES (1, ?)", (today,))
        conn.commit()
        
        cursor.execute("SELECT * FROM stats WHERE date = ?", (today,))
        row = cursor.fetchone()
        
        if row:
            stats = dict(row)
        else:
            stats = {
                'detections_today': 0,
                'fire_today': 0,
                'smoke_today': 0,
                'avg_response_time': 3.2
            }
        
        # Add computed stats
        stats['active_cameras'] = get_active_camera_count()
        stats['personnel_online'] = get_online_personnel_count()
        
        return stats

def reset_daily_stats():
    """Reset daily stats (call at midnight)"""
    with get_db() as conn:
        cursor = conn.cursor()
        today = datetime.now().date().isoformat()
        cursor.execute('''
            INSERT OR REPLACE INTO stats (id, date, detections_today, fire_today, smoke_today, avg_response_time)
            VALUES (1, ?, 0, 0, 0, 3.2)
        ''', (today,))
        conn.commit()

# ============================================
# Notification Operations
# ============================================

def log_notification(alert_id, firefighter_id, message):
    """Log a sent notification"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO notifications (alert_id, firefighter_id, message)
            VALUES (?, ?, ?)
        ''', (alert_id, firefighter_id, message))
        conn.commit()
        return cursor.lastrowid

# ============================================
# NEW: Firefighter Alert Operations
# ============================================

def create_firefighter_alert(alert_id, detection_id, firefighter_id, station_id, alert_type, location, area, confidence):
    """Create a firefighter alert entry"""
    with get_db() as conn:
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO firefighter_alerts 
            (alert_id, detection_id, firefighter_id, station_id, alert_type, location, area, confidence, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ''', (alert_id, detection_id, firefighter_id, station_id, alert_type, location, area, confidence))
        conn.commit()
        return cursor.lastrowid

def get_pending_firefighter_alerts(firefighter_id=None, station_id=None):
    """Get pending alerts for a firefighter or station"""
    with get_db() as conn:
        cursor = conn.cursor()
        if firefighter_id:
            cursor.execute('''
                SELECT * FROM firefighter_alerts 
                WHERE firefighter_id = ? AND status = 'pending'
                ORDER BY received_at DESC
            ''', (firefighter_id,))
        elif station_id:
            cursor.execute('''
                SELECT * FROM firefighter_alerts 
                WHERE station_id = ? AND status = 'pending'
                ORDER BY received_at DESC
            ''', (station_id,))
        else:
            cursor.execute('''
                SELECT * FROM firefighter_alerts 
                WHERE status = 'pending'
                ORDER BY received_at DESC
            ''')
        return [dict(row) for row in cursor.fetchall()]

def get_firefighter_alert_history(firefighter_id=None, station_id=None, limit=20):
    """Get alert history for a firefighter or station"""
    with get_db() as conn:
        cursor = conn.cursor()
        if firefighter_id:
            cursor.execute('''
                SELECT * FROM firefighter_alerts 
                WHERE firefighter_id = ? AND status != 'pending'
                ORDER BY received_at DESC LIMIT ?
            ''', (firefighter_id, limit))
        elif station_id:
            cursor.execute('''
                SELECT * FROM firefighter_alerts 
                WHERE station_id = ? AND status != 'pending'
                ORDER BY received_at DESC LIMIT ?
            ''', (station_id, limit))
        else:
            cursor.execute('''
                SELECT * FROM firefighter_alerts 
                WHERE status != 'pending'
                ORDER BY received_at DESC LIMIT ?
            ''', (limit,))
        return [dict(row) for row in cursor.fetchall()]

def respond_to_firefighter_alert(alert_id, response_type):
    """Respond to or acknowledge a firefighter alert"""
    with get_db() as conn:
        cursor = conn.cursor()
        now = datetime.now().isoformat()
        cursor.execute('''
            UPDATE firefighter_alerts 
            SET status = ?, response_type = ?, responded_at = ?
            WHERE id = ?
        ''', (response_type, response_type, now, alert_id))
        conn.commit()
        
        # Update firefighter stats
        cursor.execute('SELECT firefighter_id, received_at FROM firefighter_alerts WHERE id = ?', (alert_id,))
        row = cursor.fetchone()
        if row:
            _update_firefighter_stats(cursor, row['firefighter_id'], response_type, row['received_at'], now)
            conn.commit()

def _update_firefighter_stats(cursor, firefighter_id, response_type, received_at, responded_at):
    """Update firefighter statistics after response"""
    today = datetime.now().date().isoformat()
    
    # Calculate response time in seconds
    try:
        received = datetime.fromisoformat(received_at)
        responded = datetime.fromisoformat(responded_at)
        response_time = (responded - received).total_seconds()
    except:
        response_time = 0
    
    # Ensure stats record exists
    cursor.execute('''
        INSERT OR IGNORE INTO firefighter_stats (firefighter_id, stats_date)
        VALUES (?, ?)
    ''', (firefighter_id, today))
    
    # Update stats based on response type
    if response_type == 'responded':
        cursor.execute('''
            UPDATE firefighter_stats SET 
                total_responded = total_responded + 1,
                total_alerts_today = total_alerts_today + 1,
                avg_response_time_seconds = (avg_response_time_seconds * total_responded + ?) / (total_responded + 1),
                last_response_at = ?
            WHERE firefighter_id = ?
        ''', (response_time, responded_at, firefighter_id))
    else:  # acknowledged
        cursor.execute('''
            UPDATE firefighter_stats SET 
                total_acknowledged = total_acknowledged + 1,
                total_alerts_today = total_alerts_today + 1,
                last_response_at = ?
            WHERE firefighter_id = ?
        ''', (responded_at, firefighter_id))

def get_firefighter_stats(firefighter_id=None, station_id=None):
    """Get firefighter statistics"""
    with get_db() as conn:
        cursor = conn.cursor()
        today = datetime.now().date().isoformat()
        
        if firefighter_id:
            cursor.execute('''
                SELECT * FROM firefighter_stats WHERE firefighter_id = ?
            ''', (firefighter_id,))
            row = cursor.fetchone()
            if row:
                return dict(row)
        elif station_id:
            cursor.execute('''
                SELECT 
                    SUM(fs.total_responded) as total_responded,
                    SUM(fs.total_acknowledged) as total_acknowledged,
                    SUM(fs.total_alerts_today) as total_alerts_today,
                    AVG(fs.avg_response_time_seconds) as avg_response_time_seconds
                FROM firefighter_stats fs
                JOIN firefighters f ON fs.firefighter_id = f.id
                WHERE f.station = ?
            ''', (station_id,))
            row = cursor.fetchone()
            if row:
                return dict(row)
        
        # Return default stats
        return {
            'total_responded': 0,
            'total_acknowledged': 0,
            'total_alerts_today': 0,
            'avg_response_time_seconds': 0
        }

def broadcast_alert_to_station(station_id, alert_id, detection_id, alert_type, location, area, confidence):
    """Broadcast an alert to all firefighters in a station"""
    with get_db() as conn:
        cursor = conn.cursor()
        
        # Get all firefighters in the station
        cursor.execute('''
            SELECT id FROM firefighters WHERE station = ? AND status = 'online'
        ''', (station_id,))
        firefighters = cursor.fetchall()
        
        alert_ids = []
        for ff in firefighters:
            cursor.execute('''
                INSERT INTO firefighter_alerts 
                (alert_id, detection_id, firefighter_id, station_id, alert_type, location, area, confidence, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ''', (alert_id, detection_id, ff['id'], station_id, alert_type, location, area, confidence))
            alert_ids.append(cursor.lastrowid)
        
        conn.commit()
        return alert_ids

# ============================================
# Export for Dashboard
# ============================================

def get_dashboard_data():
    """Get all data needed for dashboard as a dictionary"""
    cameras = get_cameras()
    cameras_dict = {cam['id']: cam for cam in cameras}
    
    return {
        'cameras': cameras_dict,
        'detections': get_detections(),
        'alerts': get_alerts(),
        'activity': get_activity(),
        'firefighters': get_firefighters(),
        'personnel': get_personnel(),
        'stations': get_stations(),
        'stats': get_stats(),
        'detection_history': get_detection_history(),
        'last_update': datetime.now().isoformat()
    }

# Initialize database on module import
if __name__ == "__main__":
    init_database()
    print("Database initialized successfully!")