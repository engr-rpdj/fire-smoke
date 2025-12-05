<?php
/**
 * Fire Detection Dashboard - SQLite Version
 * Serves HTML dashboard and provides JSON data API
 */

// Configuration
define('DATABASE_PATH', __DIR__ . '/fire_detection.db');
define('UPLOAD_DIR', 'uploads');
define('ANNOTATED_DIR', 'annotated');

// Create directories if they don't exist
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
if (!is_dir(ANNOTATED_DIR)) mkdir(ANNOTATED_DIR, 0777, true);

// Database connection
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new SQLite3(DATABASE_PATH);
            $db->busyTimeout(5000);
            $db->exec('PRAGMA journal_mode = WAL');  // Better concurrency
            $db->exec('PRAGMA synchronous = NORMAL'); // Faster writes
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $db;
}

// Debug endpoint to check database
if (isset($_GET['debug'])) {
    header('Content-Type: application/json');
    $db = getDB();
    $dbPath = DATABASE_PATH;
    $exists = file_exists($dbPath);
    $writable = is_writable($dbPath);
    $dirWritable = is_writable(dirname($dbPath));
    
    // Test insert
    $testResult = $db->exec("CREATE TABLE IF NOT EXISTS _test (id INTEGER PRIMARY KEY)");
    $testInsert = $db->exec("INSERT INTO _test (id) VALUES (1)");
    $db->exec("DELETE FROM _test WHERE id = 1");
    
    // Count firefighters
    $ffCount = $db->querySingle("SELECT COUNT(*) FROM firefighters");
    
    echo json_encode([
        'database_path' => $dbPath,
        'exists' => $exists,
        'writable' => $writable,
        'dir_writable' => $dirWritable,
        'test_create' => $testResult,
        'test_insert' => $testInsert,
        'firefighter_count' => $ffCount,
        'last_error' => $db->lastErrorMsg()
    ], JSON_PRETTY_PRINT);
    exit;
}

// Initialize database tables if needed
function initDatabase() {
    $db = getDB();
    
    // Cameras table
    $db->exec('
        CREATE TABLE IF NOT EXISTS cameras (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            location TEXT NOT NULL,
            latitude REAL,
            longitude REAL,
            status TEXT DEFAULT "offline",
            temperature REAL DEFAULT 22.0,
            frame_path TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Detections table
    $db->exec('
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
            status TEXT DEFAULT "pending",
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Alerts table
    $db->exec('
        CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            detection_id INTEGER,
            alert_level TEXT NOT NULL,
            message TEXT NOT NULL,
            status TEXT DEFAULT "active",
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Activity log table
    $db->exec('
        CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message TEXT NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Firefighters table
    $db->exec('
        CREATE TABLE IF NOT EXISTS firefighters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            station INTEGER DEFAULT 1,
            status TEXT DEFAULT "online",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Personnel table
    $db->exec('
        CREATE TABLE IF NOT EXISTS personnel (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            role TEXT NOT NULL,
            type TEXT NOT NULL,
            phone TEXT,
            station INTEGER,
            status TEXT DEFAULT "online",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Stations table
    $db->exec('
        CREATE TABLE IF NOT EXISTS stations (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            latitude REAL,
            longitude REAL,
            personnel_count INTEGER DEFAULT 0
        )
    ');
    
    // Stats table
    $db->exec('
        CREATE TABLE IF NOT EXISTS stats (
            id INTEGER PRIMARY KEY,
            date DATE UNIQUE,
            detections_today INTEGER DEFAULT 0,
            fire_today INTEGER DEFAULT 0,
            smoke_today INTEGER DEFAULT 0,
            avg_response_time REAL DEFAULT 3.2
        )
    ');
    
    // Detection history for charts
    $db->exec('
        CREATE TABLE IF NOT EXISTS detection_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            interval_start TIMESTAMP NOT NULL,
            fire_count INTEGER DEFAULT 0,
            smoke_count INTEGER DEFAULT 0,
            UNIQUE(interval_start)
        )
    ');
    
    // Insert default data
    insertDefaultData($db);
}

function insertDefaultData($db) {
    // Check if cameras exist
    $result = $db->querySingle("SELECT COUNT(*) FROM cameras");
    if ($result == 0) {
        $db->exec("
            INSERT INTO cameras (id, name, type, location, latitude, longitude, status, temperature, frame_path) VALUES
            (1, 'Camera 1 - Visual ML', 'visual', 'Building A - Warehouse', 14.6005, 120.9850, 'offline', 22.0, 'camera_frames/camera1_live.jpg'),
            (2, 'Camera 2 - Thermal', 'thermal', 'Building A - Warehouse', 14.6010, 120.9855, 'offline', 22.5, 'camera_frames/camera2_live.jpg')
        ");
    }
    
    // Check if stations exist
    $result = $db->querySingle("SELECT COUNT(*) FROM stations");
    if ($result == 0) {
        $db->exec("
            INSERT INTO stations (id, name, latitude, longitude, personnel_count) VALUES
            (1, 'Fire Station 1', 14.5950, 120.9800, 6),
            (2, 'Fire Station 2', 14.6040, 120.9900, 6)
        ");
    }
    
    // Check if personnel exist
    $result = $db->querySingle("SELECT COUNT(*) FROM personnel");
    if ($result == 0) {
        $db->exec("
            INSERT INTO personnel (name, role, type, phone, station) VALUES
            ('Admin Johnson', 'System Administrator', 'admin', NULL, NULL),
            ('Admin Chen', 'Operations Manager', 'admin', NULL, NULL)
        ");
    }
    
    // Ensure today's stats exist
    $today = date('Y-m-d');
    $db->exec("INSERT OR IGNORE INTO stats (id, date) VALUES (1, '$today')");
}

// Initialize database
initDatabase();

// =============================================
// API Handlers
// =============================================

// Handle file upload
if (isset($_GET['upload'])) {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit;
    }
    
    $file = $_FILES['file'];
    $type = $_POST['type'] ?? 'image';
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $ext;
    $filepath = UPLOAD_DIR . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
        exit;
    }
    
    $pythonScript = __DIR__ . '/process_upload.py';
    $command = "python3 $pythonScript " . escapeshellarg($filepath) . " " . escapeshellarg($type);
    $output = shell_exec($command . " 2>&1");
    $result = json_decode($output, true);
    
    if ($result && $result['success']) {
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'error' => 'Detection failed: ' . ($output ?? 'Unknown error')]);
    }
    exit;
}

// Handle firefighter operations
if (isset($_GET['firefighter'])) {
    header('Content-Type: application/json');
    $db = getDB();
    $action = $_GET['firefighter'];
    
    if ($action === 'list') {
        $result = $db->query("SELECT * FROM firefighters ORDER BY station, name");
        $firefighters = [];
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $firefighters[] = $row;
            }
        }
        echo json_encode(['success' => true, 'firefighters' => $firefighters]);
    }
    elseif ($action === 'add') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $phone = $input['phone'] ?? '';
        $station = intval($input['station'] ?? 1);
        
        if ($name && $phone) {
            $stmt = $db->prepare("INSERT INTO firefighters (name, phone, station) VALUES (:name, :phone, :station)");
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
            $stmt->bindValue(':station', $station, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true, 'id' => $db->lastInsertRowID()]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lastErrorMsg()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Name and phone required']);
        }
    }
    elseif ($action === 'update') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        $name = $input['name'] ?? '';
        $phone = $input['phone'] ?? '';
        $station = intval($input['station'] ?? 1);
        
        if ($id && $name && $phone) {
            $stmt = $db->prepare("UPDATE firefighters SET name=:name, phone=:phone, station=:station WHERE id=:id");
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
            $stmt->bindValue(':station', $station, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lastErrorMsg()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
        }
    }
    elseif ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        
        if ($id) {
            $stmt = $db->prepare("DELETE FROM firefighters WHERE id=:id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lastErrorMsg()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        }
    }
    exit;
}

// Handle personnel operations
if (isset($_GET['personnel'])) {
    header('Content-Type: application/json');
    $db = getDB();
    $action = $_GET['personnel'];
    
    if ($action === 'list') {
        $result = $db->query("SELECT * FROM personnel ORDER BY type, name");
        $personnel = [];
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $personnel[] = $row;
            }
        }
        echo json_encode(['success' => true, 'personnel' => $personnel]);
    }
    elseif ($action === 'add') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $role = $input['role'] ?? '';
        $type = $input['type'] ?? 'admin';
        $phone = $input['phone'] ?? null;
        $station = isset($input['station']) && $input['station'] !== '' ? intval($input['station']) : null;
        $status = $input['status'] ?? 'online';
        
        if ($name && $role) {
            $stmt = $db->prepare("INSERT INTO personnel (name, role, type, phone, station, status) VALUES (:name, :role, :type, :phone, :station, :status)");
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':role', $role, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':phone', $phone, $phone ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':station', $station, $station !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true, 'id' => $db->lastInsertRowID()]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lastErrorMsg()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Name and role required']);
        }
    }
    elseif ($action === 'update') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        $name = $input['name'] ?? '';
        $role = $input['role'] ?? '';
        $type = $input['type'] ?? 'admin';
        $phone = $input['phone'] ?? null;
        $station = isset($input['station']) && $input['station'] !== '' ? intval($input['station']) : null;
        $status = $input['status'] ?? 'online';
        
        if ($id && $name && $role) {
            $stmt = $db->prepare("UPDATE personnel SET name=:name, role=:role, type=:type, phone=:phone, station=:station, status=:status WHERE id=:id");
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':role', $role, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':phone', $phone, $phone ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':station', $station, $station !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lastErrorMsg()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
        }
    }
    elseif ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($input['id'] ?? 0);
        
        if ($id) {
            $stmt = $db->prepare("DELETE FROM personnel WHERE id=:id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->lastErrorMsg()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        }
    }
    exit;
}

// Handle alert status update
if (isset($_GET['update_alert'])) {
    header('Content-Type: application/json');
    $db = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    $status = SQLite3::escapeString($input['status'] ?? 'acknowledged');
    
    if ($id) {
        $db->exec("UPDATE alerts SET status='$status' WHERE id=$id");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    exit;
}

// Main API endpoint - get all dashboard data
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $db = getDB();
    
    // Get cameras
    $cameras = [];
    $result = $db->query("SELECT * FROM cameras ORDER BY id");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cameras[$row['id']] = $row;
    }
    
    // Get detections
    $detections = [];
    $result = $db->query("SELECT * FROM detections ORDER BY timestamp DESC LIMIT 100");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $detections[] = $row;
    }
    
    // Get alerts
    $alerts = [];
    $result = $db->query("SELECT * FROM alerts ORDER BY timestamp DESC LIMIT 20");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $alerts[] = $row;
    }
    
    // Get activity
    $activity = [];
    $result = $db->query("SELECT * FROM activity ORDER BY timestamp DESC LIMIT 50");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $activity[] = $row;
    }
    
    // Get firefighters
    $firefighters = [];
    $result = $db->query("SELECT * FROM firefighters ORDER BY station, name");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $firefighters[] = $row;
    }
    
    // Get personnel
    $personnel = [];
    $result = $db->query("SELECT * FROM personnel ORDER BY type, name");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $personnel[] = $row;
    }
    
    // Get stations
    $stations = [];
    $result = $db->query("SELECT * FROM stations ORDER BY id");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $stations[] = $row;
    }
    
    // Get stats
    $today = date('Y-m-d');
    $stats = $db->querySingle("SELECT * FROM stats WHERE date='$today'", true);
    if (!$stats) {
        $stats = [
            'detections_today' => 0,
            'fire_today' => 0,
            'smoke_today' => 0,
            'avg_response_time' => 3.2
        ];
    }
    $stats['active_cameras'] = $db->querySingle("SELECT COUNT(*) FROM cameras WHERE status='online'");
    $stats['personnel_online'] = $db->querySingle("SELECT COUNT(*) FROM personnel WHERE status='online'");
    
    // Get detection history (last 24 hours, 30-min intervals)
    $detection_history = [];
    $result = $db->query("SELECT * FROM detection_history WHERE interval_start >= datetime('now', '-24 hours') ORDER BY interval_start ASC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $detection_history[] = $row;
    }
    
    echo json_encode([
        'cameras' => $cameras,
        'detections' => $detections,
        'alerts' => $alerts,
        'activity' => $activity,
        'firefighters' => $firefighters,
        'personnel' => $personnel,
        'stations' => $stations,
        'stats' => $stats,
        'detection_history' => $detection_history,
        'last_update' => date('c')
    ]);
    exit;
}

// Serve the dashboard HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire & Smoke Detection Command Center</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e0e0e0;
            overflow-x: hidden;
        }

        .header {
            background: linear-gradient(90deg, #0f3460 0%, #16213e 100%);
            padding: 15px 30px;
            border-bottom: 3px solid #e94560;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #e94560 0%, #ff6b6b 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 0 20px rgba(233, 69, 96, 0.5);
        }

        .header h1 {
            color: #ffffff;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .system-status {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border: 2px solid;
        }

        .status-operational {
            background: rgba(46, 213, 115, 0.2);
            border-color: #2ed573;
            color: #2ed573;
        }

        .status-alert {
            background: rgba(233, 69, 96, 0.2);
            border-color: #e94560;
            color: #e94560;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .datetime {
            font-size: 14px;
            color: #a0a0a0;
        }

        .container {
            padding: 20px;
            max-width: 1920px;
            margin: 0 auto;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: linear-gradient(135deg, #1e2a3a 0%, #1a1f2e 100%);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }

        .panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #e94560, #0f3460);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .panel-title {
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #ffffff;
        }

        .stat-card {
            grid-column: span 3;
            text-align: center;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin: 10px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            font-size: 12px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-change {
            font-size: 11px;
            margin-top: 5px;
            color: #2ed573;
        }

        .alert-panel {
            grid-column: span 12;
        }

        .alert-item {
            background: rgba(233, 69, 96, 0.1);
            border-left: 4px solid #e94560;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 6px;
        }

        .alert-item.alert-warning {
            border-left-color: #ffa502;
            background: rgba(255, 165, 2, 0.1);
        }

        .alert-item.alert-info {
            border-left-color: #5352ed;
            background: rgba(83, 82, 237, 0.1);
        }

        .alert-time {
            font-size: 11px;
            color: #888;
            margin-bottom: 5px;
        }

        .alert-message {
            font-size: 13px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .camera-panel {
            grid-column: span 6;
        }

        .camera-feed {
            background: #000;
            width: 100%;
            height: 300px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #2d3748;
            color: #666;
            font-size: 14px;
            overflow: hidden;
        }

        .map-panel {
            grid-column: span 8;
            height: 400px;
        }

        #map {
            height: 320px;
            border-radius: 8px;
            border: 2px solid #2d3748;
        }

        .personnel-panel {
            grid-column: span 4;
            height: 400px;
        }

        .personnel-list {
            max-height: 320px;
            overflow-y: auto;
        }

        .personnel-item {
            background: rgba(255, 255, 255, 0.03);
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 6px;
            border-left: 3px solid #5352ed;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .personnel-item.firefighter {
            border-left-color: #e94560;
        }

        .personnel-item.admin {
            border-left-color: #ffa502;
        }

        .personnel-info h4 {
            font-size: 14px;
            margin-bottom: 3px;
        }

        .personnel-info p {
            font-size: 11px;
            color: #888;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #2ed573;
            box-shadow: 0 0 10px #2ed573;
        }

        .status-indicator.offline {
            background: #888;
            box-shadow: none;
        }

        .chart-panel {
            grid-column: span 6;
            height: 350px;
        }

        .chart-container {
            height: 280px;
        }

        .activity-panel {
            grid-column: span 6;
            height: 350px;
        }

        .activity-list {
            max-height: 280px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 12px;
        }

        .activity-time {
            color: #888;
            font-size: 11px;
        }

        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(233, 69, 96, 0.5);
            border-radius: 4px;
        }

        /* Modal base styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: linear-gradient(135deg, #1e2a3a 0%, #1a1f2e 100%);
            padding: 30px;
            border-radius: 16px;
            text-align: center;
            max-width: 600px;
            width: 90%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Emergency modal with shake animation */
        .emergency-content {
            background: linear-gradient(135deg, #e94560 0%, #d63447 100%);
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            max-width: 600px;
            animation: shake 0.5s infinite;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .emergency-content h2 {
            font-size: 48px;
            margin-bottom: 20px;
            color: #ffffff;
        }

        .emergency-content p {
            font-size: 20px;
            margin-bottom: 10px;
            color: #ffffff;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: center;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e94560 0%, #d63447 100%);
            color: #ffffff;
        }

        .btn-success {
            background: linear-gradient(135deg, #2ed573 0%, #1db954 100%);
            color: #ffffff;
        }

        /* Form styles */
        .form-group {
            text-align: left;
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #e0e0e0;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group select option {
            background: #1e2a3a;
            color: #fff;
        }

        /* Cards for firefighters/personnel */
        .management-card {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 10px;
            border-left: 4px solid #e94560;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .management-card.personnel {
            border-left-color: #5352ed;
        }

        .management-card-info {
            flex: 1;
        }

        .management-card-info h4 {
            font-size: 14px;
            margin-bottom: 5px;
            color: #fff;
        }

        .management-card-info p {
            font-size: 12px;
            color: #888;
            margin: 2px 0;
        }

        .management-card-actions {
            display: flex;
            gap: 8px;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 11px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            transition: all 0.2s;
        }

        .btn-small:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-small.danger {
            background: rgba(233, 69, 96, 0.3);
        }

        .btn-small.danger:hover {
            background: rgba(233, 69, 96, 0.5);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .tab-btn {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #888;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: rgba(102, 126, 234, 0.3);
            border-color: #667eea;
            color: #fff;
        }

        .tab-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .management-list {
            max-height: 300px;
            overflow-y: auto;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 30px;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- Emergency Modal -->
    <div class="modal" id="emergencyModal">
        <div class="emergency-content">
            <div style="font-size: 72px;">🔥</div>
            <h2>FIRE DETECTED!</h2>
            <p><strong>Location:</strong> <span id="emergencyLocation"></span></p>
            <p><strong>Camera:</strong> <span id="emergencyCamera"></span></p>
            <p><strong>Confidence:</strong> <span id="emergencyConfidence"></span></p>
            <div class="modal-actions">
                <button class="btn btn-primary" style="background: #fff; color: #e94560;" onclick="showNotificationSelection()">SELECT FIREFIGHTERS TO NOTIFY</button>
                <button class="btn btn-secondary" style="border-color: #fff;" onclick="closeEmergency()">ACKNOWLEDGE</button>
            </div>
        </div>
    </div>

    <!-- Notification Selection Modal -->
    <div class="modal" id="notificationModal">
        <div class="modal-content" style="max-width: 700px; max-height: 80vh; overflow-y: auto;">
            <h2 style="margin-bottom: 20px;">📞 Select Who to Notify</h2>
            <div style="text-align: left; margin: 20px 0;">
                <label style="display: flex; align-items: center; gap: 10px; padding: 12px; background: rgba(102, 126, 234, 0.2); border-radius: 8px; margin-bottom: 15px; cursor: pointer;">
                    <input type="checkbox" id="notifyAll" onchange="toggleNotifyAll()" style="width: 20px; height: 20px;">
                    <strong style="font-size: 16px;">NOTIFY ALL FIREFIGHTERS</strong>
                </label>
                <div id="firefighterCheckboxes"></div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-success" onclick="sendNotifications()">📱 SEND SMS NOTIFICATIONS</button>
                <button class="btn btn-secondary" onclick="closeNotificationModal()">CANCEL</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Firefighter Modal -->
    <div class="modal" id="firefighterModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;" id="firefighterModalTitle">Add Firefighter</h2>
            <div class="form-group">
                <label>Name:</label>
                <input type="text" id="firefighterName" placeholder="Enter full name">
            </div>
            <div class="form-group">
                <label>Phone Number:</label>
                <input type="tel" id="firefighterPhone" placeholder="+63-917-123-4567">
            </div>
            <div class="form-group">
                <label>Station:</label>
                <select id="firefighterStation">
                    <option value="1">Station 1</option>
                    <option value="2">Station 2</option>
                </select>
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="saveFirefighter()">💾 SAVE</button>
                <button class="btn btn-secondary" onclick="closeFirefighterModal()">CANCEL</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Personnel Modal -->
    <div class="modal" id="personnelModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;" id="personnelModalTitle">Add Personnel</h2>
            <div class="form-group">
                <label>Name:</label>
                <input type="text" id="personnelName" placeholder="Enter full name">
            </div>
            <div class="form-group">
                <label>Role:</label>
                <input type="text" id="personnelRole" placeholder="e.g., System Administrator">
            </div>
            <div class="form-group">
                <label>Type:</label>
                <select id="personnelType">
                    <option value="admin">Admin</option>
                    <option value="firefighter">Firefighter</option>
                    <option value="operator">Operator</option>
                    <option value="technician">Technician</option>
                </select>
            </div>
            <div class="form-group">
                <label>Phone (optional):</label>
                <input type="tel" id="personnelPhone" placeholder="+63-917-123-4567">
            </div>
            <div class="form-group">
                <label>Station (optional):</label>
                <select id="personnelStation">
                    <option value="">No Station</option>
                    <option value="1">Station 1</option>
                    <option value="2">Station 2</option>
                </select>
            </div>
            <div class="form-group">
                <label>Status:</label>
                <select id="personnelStatus">
                    <option value="online">Online</option>
                    <option value="offline">Offline</option>
                </select>
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="savePersonnel()">💾 SAVE</button>
                <button class="btn btn-secondary" onclick="closePersonnelModal()">CANCEL</button>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <div class="logo">
                <div class="logo-icon">🔥</div>
                <h1>FIRE & SMOKE DETECTION COMMAND CENTER</h1>
            </div>
            <div class="system-status">
                <div class="status-badge status-operational" id="systemStatus">SYSTEM OPERATIONAL</div>
            </div>
        </div>
        <div class="datetime" id="datetime"></div>
    </div>

    <!-- Main Dashboard -->
    <div class="container">
        <!-- Stats Row -->
        <div class="dashboard-grid">
            <div class="panel stat-card">
                <div class="panel-header">
                    <span class="panel-title">Active Cameras</span>
                </div>
                <div class="stat-value" id="activeCameras">0</div>
                <div class="stat-label">Online / 2 Total</div>
                <div class="stat-change">System Status</div>
            </div>

            <div class="panel stat-card">
                <div class="panel-header">
                    <span class="panel-title">Detections Today</span>
                </div>
                <div class="stat-value" id="detectionsToday">0</div>
                <div class="stat-label">Fire & Smoke Events</div>
                <div class="stat-change" id="detectionChange">No detections</div>
            </div>

            <div class="panel stat-card">
                <div class="panel-header">
                    <span class="panel-title">Avg Response Time</span>
                </div>
                <div class="stat-value" id="avgResponse">3.2</div>
                <div class="stat-label">Minutes</div>
                <div class="stat-change">Target: < 5 min</div>
            </div>

            <div class="panel stat-card">
                <div class="panel-header">
                    <span class="panel-title">Personnel Online</span>
                </div>
                <div class="stat-value" id="personnelOnline">0</div>
                <div class="stat-label">Firefighters & Admins</div>
                <div class="stat-change">Ready to Respond</div>
            </div>
        </div>

        <!-- Alerts -->
        <div class="dashboard-grid">
            <div class="panel alert-panel">
                <div class="panel-header">
                    <span class="panel-title">🚨 Active Alerts</span>
                </div>
                <div id="alertsList"></div>
            </div>
        </div>

        <!-- Management Panel (Firefighters & Personnel) -->
        <div class="dashboard-grid">
            <div class="panel" style="grid-column: span 12;">
                <div class="panel-header">
                    <span class="panel-title">👥 Team Management</span>
                </div>
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('firefighters')">👨‍🚒 Firefighters</button>
                    <button class="tab-btn" onclick="switchTab('personnel')">👤 Personnel</button>
                </div>
                
                <!-- Firefighters Tab -->
                <div class="tab-content active" id="tab-firefighters">
                    <div style="margin-bottom: 15px;">
                        <button class="btn btn-primary" style="padding: 8px 16px; font-size: 13px;" onclick="showAddFirefighter()">+ Add Firefighter</button>
                    </div>
                    <div class="management-list" id="firefighterList"></div>
                </div>
                
                <!-- Personnel Tab -->
                <div class="tab-content" id="tab-personnel">
                    <div style="margin-bottom: 15px;">
                        <button class="btn btn-primary" style="padding: 8px 16px; font-size: 13px;" onclick="showAddPersonnel()">+ Add Personnel</button>
                    </div>
                    <div class="management-list" id="personnelManagementList"></div>
                </div>
            </div>
        </div>

        <!-- Cameras -->
        <div class="dashboard-grid">
            <div class="panel camera-panel">
                <div class="panel-header">
                    <span class="panel-title">📹 Camera 1 - Visual ML</span>
                    <span id="cam1Status" style="color: #888;">● OFFLINE</span>
                </div>
                <div class="camera-feed">
                    <img id="camera1Feed" src="camera_frames/camera1_live.jpg" 
                         style="width: 100%; height: 100%; object-fit: cover;" 
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: #666;">
                        No camera feed
                    </div>
                </div>
                <div style="font-size: 12px; color: #888;">Building A - Warehouse</div>
            </div>

            <div class="panel camera-panel">
                <div class="panel-header">
                    <span class="panel-title">🌡️ Camera 2 - Thermal</span>
                    <span id="cam2Status" style="color: #888;">● OFFLINE</span>
                </div>
                <div class="camera-feed">
                    <img id="camera2Feed" src="camera_frames/camera2_live.jpg" 
                         style="width: 100%; height: 100%; object-fit: cover;" 
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: #666;">
                        No camera feed
                    </div>
                </div>
                <div style="font-size: 12px; color: #888;">Building A - Warehouse</div>
            </div>
        </div>

        <!-- Map and Personnel -->
        <div class="dashboard-grid">
            <div class="panel map-panel">
                <div class="panel-header">
                    <span class="panel-title">🗺️ Location Map</span>
                </div>
                <div id="map"></div>
            </div>

            <div class="panel personnel-panel">
                <div class="panel-header">
                    <span class="panel-title">👥 Personnel Status</span>
                </div>
                <div class="personnel-list" id="personnelList"></div>
            </div>
        </div>

        <!-- Charts and Activity -->
        <div class="dashboard-grid">
            <div class="panel chart-panel">
                <div class="panel-header">
                    <span class="panel-title">📊 Detection History (30-min intervals)</span>
                </div>
                <div class="chart-container">
                    <canvas id="detectionChart"></canvas>
                </div>
            </div>

            <div class="panel activity-panel">
                <div class="panel-header">
                    <span class="panel-title">📋 Activity Log</span>
                </div>
                <div class="activity-list" id="activityLog"></div>
            </div>
        </div>
    </div>

    <script>
        let dashboardData = null;
        let detectionChart = null;
        let map = null;
        let emergencyActive = false;
        let editingFirefighterId = null;
        let editingPersonnelId = null;

        // Update datetime
        function updateDateTime() {
            const now = new Date();
            document.getElementById('datetime').textContent = now.toLocaleString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }
        setInterval(updateDateTime, 1000);
        updateDateTime();

        // ========================================
        // TAB SWITCHING
        // ========================================
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');
        }

        // ========================================
        // DATA FETCHING
        // ========================================
        async function fetchData() {
            try {
                const response = await fetch('?api=1');
                const data = await response.json();
                
                if (!data.error) {
                    dashboardData = data;
                    updateDashboard(data);
                }
            } catch (error) {
                console.error('Error fetching data:', error);
            }
        }

        function updateDashboard(data) {
            // Update stats
            if (data.stats) {
                document.getElementById('activeCameras').textContent = data.stats.active_cameras || 0;
                document.getElementById('detectionsToday').textContent = data.stats.detections_today || 0;
                document.getElementById('avgResponse').textContent = data.stats.avg_response_time || 3.2;
                document.getElementById('personnelOnline').textContent = data.stats.personnel_online || 0;
                
                const changeEl = document.getElementById('detectionChange');
                if (data.stats.fire_today > 0 || data.stats.smoke_today > 0) {
                    changeEl.textContent = `${data.stats.fire_today} fire, ${data.stats.smoke_today} smoke`;
                } else {
                    changeEl.textContent = 'No detections today';
                }
            }

            // Update camera status
            if (data.cameras) {
                Object.values(data.cameras).forEach(camera => {
                    const camNum = camera.name.includes('1') ? '1' : '2';
                    const statusEl = document.getElementById(`cam${camNum}Status`);
                    if (statusEl) {
                        if (camera.status === 'online') {
                            statusEl.innerHTML = '● LIVE';
                            statusEl.style.color = '#2ed573';
                        } else {
                            statusEl.innerHTML = '● OFFLINE';
                            statusEl.style.color = '#888';
                        }
                    }
                });
            }

            // Update alerts
            updateAlerts(data.alerts || []);

            // Update personnel display
            updatePersonnelDisplay(data.personnel || []);

            // Update management lists
            updateFirefighterList(data.firefighters || []);
            updatePersonnelManagementList(data.personnel || []);

            // Update activity log
            updateActivity(data.activity || []);

            // Update chart
            updateChart(data.detection_history || []);

            // Check for critical alerts
            if (data.alerts && data.alerts.length > 0) {
                const criticalAlert = data.alerts.find(a => a.alert_level === 'critical' && a.status === 'active');
                if (criticalAlert && !emergencyActive) {
                    showEmergency(criticalAlert);
                }
            }
        }

        function updateAlerts(alerts) {
            const alertsList = document.getElementById('alertsList');
            
            if (alerts.length === 0) {
                alertsList.innerHTML = `
                    <div class="alert-item alert-info">
                        <div class="alert-time">${new Date().toLocaleTimeString()}</div>
                        <div class="alert-message">No active alerts - All systems normal</div>
                    </div>
                `;
                return;
            }

            alertsList.innerHTML = '';
            alerts.slice(0, 5).forEach(alert => {
                const alertTime = new Date(alert.timestamp);
                const alertClass = alert.alert_level === 'critical' ? '' : 
                                  alert.alert_level === 'warning' ? 'alert-warning' : 'alert-info';
                
                const div = document.createElement('div');
                div.className = `alert-item ${alertClass}`;
                div.innerHTML = `
                    <div class="alert-time">${alertTime.toLocaleTimeString()}</div>
                    <div class="alert-message">${alert.message}</div>
                `;
                alertsList.appendChild(div);
            });

            // Update system status
            if (alerts.some(a => a.alert_level === 'critical' && a.status === 'active')) {
                document.getElementById('systemStatus').textContent = 'EMERGENCY ALERT';
                document.getElementById('systemStatus').className = 'status-badge status-alert';
            } else {
                document.getElementById('systemStatus').textContent = 'SYSTEM OPERATIONAL';
                document.getElementById('systemStatus').className = 'status-badge status-operational';
            }
        }

        function updatePersonnelDisplay(personnel) {
            const list = document.getElementById('personnelList');
            list.innerHTML = '';
            
            personnel.forEach(person => {
                const div = document.createElement('div');
                div.className = `personnel-item ${person.type}`;
                div.innerHTML = `
                    <div class="personnel-info">
                        <h4>${person.name}</h4>
                        <p>${person.role}</p>
                    </div>
                    <div class="status-indicator ${person.status === 'online' ? '' : 'offline'}"></div>
                `;
                list.appendChild(div);
            });
        }

        function updateActivity(activities) {
            const log = document.getElementById('activityLog');
            log.innerHTML = '';
            
            activities.slice(0, 15).forEach(activity => {
                const time = new Date(activity.timestamp);
                const div = document.createElement('div');
                div.className = 'activity-item';
                div.innerHTML = `
                    <div class="activity-time">${time.toLocaleTimeString()}</div>
                    <div>${activity.message}</div>
                `;
                log.appendChild(div);
            });
        }

        // ========================================
        // CHART UPDATE (30-min intervals)
        // ========================================
        function updateChart(historyData) {
            if (!detectionChart) return;

            // Generate labels for last 24 hours in 30-min intervals
            const labels = [];
            const fireData = [];
            const smokeData = [];
            
            // Create a map of existing data
            const dataMap = {};
            historyData.forEach(item => {
                const key = item.interval_start;
                dataMap[key] = item;
            });

            // Generate 48 intervals (24 hours * 2)
            const now = new Date();
            for (let i = 47; i >= 0; i--) {
                const intervalTime = new Date(now.getTime() - (i * 30 * 60 * 1000));
                const minute = Math.floor(intervalTime.getMinutes() / 30) * 30;
                intervalTime.setMinutes(minute, 0, 0);
                
                const label = intervalTime.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                labels.push(label);

                // Find matching data
                const key = intervalTime.toISOString().replace('T', ' ').split('.')[0];
                const data = dataMap[key] || { fire_count: 0, smoke_count: 0 };
                fireData.push(data.fire_count);
                smokeData.push(data.smoke_count);
            }

            detectionChart.data.labels = labels;
            detectionChart.data.datasets[0].data = fireData;
            detectionChart.data.datasets[1].data = smokeData;
            detectionChart.update('none');
        }

        // ========================================
        // EMERGENCY MODAL
        // ========================================
        function showEmergency(alert) {
            emergencyActive = true;
            document.getElementById('emergencyLocation').textContent = alert.message.split('at ')[1]?.split(' -')[0] || 'Unknown';
            document.getElementById('emergencyCamera').textContent = 'Camera Detection';
            document.getElementById('emergencyConfidence').textContent = alert.message.match(/\d+%/)?.[0] || 'High';
            document.getElementById('emergencyModal').classList.add('active');
        }

        function closeEmergency() {
            emergencyActive = false;
            document.getElementById('emergencyModal').classList.remove('active');
        }

        // ========================================
        // NOTIFICATION MODAL
        // ========================================
        let currentAlert = null;

        function showNotificationSelection() {
            currentAlert = {
                location: document.getElementById('emergencyLocation').textContent,
                camera: document.getElementById('emergencyCamera').textContent,
                confidence: document.getElementById('emergencyConfidence').textContent
            };

            closeEmergency();
            
            const container = document.getElementById('firefighterCheckboxes');
            container.innerHTML = '';
            
            const firefighters = dashboardData?.firefighters || [];
            firefighters.forEach((ff) => {
                const label = document.createElement('label');
                label.style.cssText = 'display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 8px; cursor: pointer;';
                label.innerHTML = `
                    <input type="checkbox" class="ff-notify-checkbox" data-id="${ff.id}" style="width: 18px; height: 18px;">
                    <div>
                        <strong>${ff.name}</strong><br>
                        <small style="color: #aaa;">📱 ${ff.phone} | Station ${ff.station}</small>
                    </div>
                `;
                container.appendChild(label);
            });

            document.getElementById('notificationModal').classList.add('active');
        }

        function toggleNotifyAll() {
            const notifyAll = document.getElementById('notifyAll').checked;
            document.querySelectorAll('.ff-notify-checkbox').forEach(cb => {
                cb.checked = notifyAll;
            });
        }

        async function sendNotifications() {
            const selectedFirefighters = [];
            const firefighters = dashboardData?.firefighters || [];
            
            document.querySelectorAll('.ff-notify-checkbox:checked').forEach(cb => {
                const id = parseInt(cb.dataset.id);
                const ff = firefighters.find(f => f.id === id);
                if (ff) selectedFirefighters.push(ff);
            });

            if (selectedFirefighters.length === 0) {
                alert('Please select at least one firefighter to notify');
                return;
            }

            let message = `📱 SMS SENT TO:\n\n`;
            selectedFirefighters.forEach(ff => {
                message += `✓ ${ff.name} (${ff.phone})\n`;
            });
            message += `\nMessage: "FIRE ALERT at ${currentAlert.location}. Confidence: ${currentAlert.confidence}. Respond immediately."`;

            alert(message);
            closeNotificationModal();
        }

        function closeNotificationModal() {
            document.getElementById('notificationModal').classList.remove('active');
        }

        // ========================================
        // FIREFIGHTER MANAGEMENT
        // ========================================
        function updateFirefighterList(firefighters) {
            const list = document.getElementById('firefighterList');
            
            if (firefighters.length === 0) {
                list.innerHTML = '<div class="empty-state">No firefighters added yet</div>';
                return;
            }

            list.innerHTML = '';
            firefighters.forEach(ff => {
                const card = document.createElement('div');
                card.className = 'management-card';
                card.innerHTML = `
                    <div class="management-card-info">
                        <h4>👨‍🚒 ${ff.name}</h4>
                        <p>📱 ${ff.phone}</p>
                        <p>🏢 Station ${ff.station}</p>
                    </div>
                    <div class="management-card-actions">
                        <button class="btn-small" onclick="editFirefighter(${ff.id})">✏️ Edit</button>
                        <button class="btn-small danger" onclick="deleteFirefighter(${ff.id})">🗑️ Delete</button>
                    </div>
                `;
                list.appendChild(card);
            });
        }

        function showAddFirefighter() {
            editingFirefighterId = null;
            document.getElementById('firefighterModalTitle').textContent = 'Add Firefighter';
            document.getElementById('firefighterName').value = '';
            document.getElementById('firefighterPhone').value = '';
            document.getElementById('firefighterStation').value = '1';
            document.getElementById('firefighterModal').classList.add('active');
        }

        function editFirefighter(id) {
            const firefighters = dashboardData?.firefighters || [];
            const ff = firefighters.find(f => f.id === id);
            if (!ff) return;

            editingFirefighterId = id;
            document.getElementById('firefighterModalTitle').textContent = 'Edit Firefighter';
            document.getElementById('firefighterName').value = ff.name;
            document.getElementById('firefighterPhone').value = ff.phone;
            document.getElementById('firefighterStation').value = ff.station;
            document.getElementById('firefighterModal').classList.add('active');
        }

        async function saveFirefighter() {
            const name = document.getElementById('firefighterName').value.trim();
            const phone = document.getElementById('firefighterPhone').value.trim();
            const station = document.getElementById('firefighterStation').value;

            if (!name || !phone) {
                alert('Please fill in all fields');
                return;
            }

            const data = { name, phone, station: parseInt(station) };
            
            if (editingFirefighterId) {
                data.id = editingFirefighterId;
                await fetch('?firefighter=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            } else {
                await fetch('?firefighter=add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            }

            closeFirefighterModal();
            fetchData();
        }

        async function deleteFirefighter(id) {
            if (!confirm('Are you sure you want to delete this firefighter?')) return;

            await fetch('?firefighter=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });

            fetchData();
        }

        function closeFirefighterModal() {
            document.getElementById('firefighterModal').classList.remove('active');
        }

        // ========================================
        // PERSONNEL MANAGEMENT
        // ========================================
        function updatePersonnelManagementList(personnel) {
            const list = document.getElementById('personnelManagementList');
            
            if (personnel.length === 0) {
                list.innerHTML = '<div class="empty-state">No personnel added yet</div>';
                return;
            }

            list.innerHTML = '';
            personnel.forEach(p => {
                const typeIcon = p.type === 'admin' ? '👔' : p.type === 'firefighter' ? '👨‍🚒' : '👤';
                const card = document.createElement('div');
                card.className = 'management-card personnel';
                card.innerHTML = `
                    <div class="management-card-info">
                        <h4>${typeIcon} ${p.name}</h4>
                        <p>📋 ${p.role}</p>
                        <p>🏷️ ${p.type.charAt(0).toUpperCase() + p.type.slice(1)} ${p.phone ? '| 📱 ' + p.phone : ''} ${p.station ? '| 🏢 Station ' + p.station : ''}</p>
                        <p>Status: <span style="color: ${p.status === 'online' ? '#2ed573' : '#888'}">${p.status}</span></p>
                    </div>
                    <div class="management-card-actions">
                        <button class="btn-small" onclick="editPersonnel(${p.id})">✏️ Edit</button>
                        <button class="btn-small danger" onclick="deletePersonnel(${p.id})">🗑️ Delete</button>
                    </div>
                `;
                list.appendChild(card);
            });
        }

        function showAddPersonnel() {
            editingPersonnelId = null;
            document.getElementById('personnelModalTitle').textContent = 'Add Personnel';
            document.getElementById('personnelName').value = '';
            document.getElementById('personnelRole').value = '';
            document.getElementById('personnelType').value = 'admin';
            document.getElementById('personnelPhone').value = '';
            document.getElementById('personnelStation').value = '';
            document.getElementById('personnelStatus').value = 'online';
            document.getElementById('personnelModal').classList.add('active');
        }

        function editPersonnel(id) {
            const personnel = dashboardData?.personnel || [];
            const p = personnel.find(x => x.id === id);
            if (!p) return;

            editingPersonnelId = id;
            document.getElementById('personnelModalTitle').textContent = 'Edit Personnel';
            document.getElementById('personnelName').value = p.name;
            document.getElementById('personnelRole').value = p.role;
            document.getElementById('personnelType').value = p.type;
            document.getElementById('personnelPhone').value = p.phone || '';
            document.getElementById('personnelStation').value = p.station || '';
            document.getElementById('personnelStatus').value = p.status || 'online';
            document.getElementById('personnelModal').classList.add('active');
        }

        async function savePersonnel() {
            const name = document.getElementById('personnelName').value.trim();
            const role = document.getElementById('personnelRole').value.trim();
            const type = document.getElementById('personnelType').value;
            const phone = document.getElementById('personnelPhone').value.trim();
            const station = document.getElementById('personnelStation').value;
            const status = document.getElementById('personnelStatus').value;

            if (!name || !role) {
                alert('Please fill in name and role');
                return;
            }

            const data = { 
                name, 
                role, 
                type,
                phone: phone || null,
                station: station || null,
                status
            };
            
            if (editingPersonnelId) {
                data.id = editingPersonnelId;
                await fetch('?personnel=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            } else {
                await fetch('?personnel=add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
            }

            closePersonnelModal();
            fetchData();
        }

        async function deletePersonnel(id) {
            if (!confirm('Are you sure you want to delete this personnel?')) return;

            await fetch('?personnel=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });

            fetchData();
        }

        function closePersonnelModal() {
            document.getElementById('personnelModal').classList.remove('active');
        }

        // ========================================
        // MAP INITIALIZATION
        // ========================================
        function initMap() {
            map = L.map('map').setView([14.5995, 120.9842], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            const cameraIcon = L.divIcon({
                html: '<div style="background: #e94560; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white;">📹</div>',
                iconSize: [30, 30]
            });

            L.marker([14.6005, 120.9850], {icon: cameraIcon})
                .addTo(map)
                .bindPopup('<strong>Camera 1 - Visual ML</strong><br>Building A - Warehouse');

            L.marker([14.6010, 120.9855], {icon: cameraIcon})
                .addTo(map)
                .bindPopup('<strong>Camera 2 - Thermal</strong><br>Building A - Warehouse');

            const stationIcon = L.divIcon({
                html: '<div style="background: #5352ed; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid white;">🚒</div>',
                iconSize: [35, 35]
            });

            L.marker([14.5950, 120.9800], {icon: stationIcon})
                .addTo(map)
                .bindPopup('<strong>Fire Station 1</strong><br>6 firefighters ready');

            L.marker([14.6040, 120.9900], {icon: stationIcon})
                .addTo(map)
                .bindPopup('<strong>Fire Station 2</strong><br>6 firefighters ready');
        }

        // ========================================
        // CHART INITIALIZATION
        // ========================================
        function initChart() {
            const ctx = document.getElementById('detectionChart').getContext('2d');
            detectionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Fire',
                        data: [],
                        backgroundColor: 'rgba(233, 69, 96, 0.7)',
                        borderColor: '#e94560',
                        borderWidth: 2
                    }, {
                        label: 'Smoke',
                        data: [],
                        backgroundColor: 'rgba(255, 165, 2, 0.7)',
                        borderColor: '#ffa502',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: { color: '#e0e0e0' }
                        },
                        title: {
                            display: true,
                            text: 'Detections per 30-minute interval (Last 24 hours)',
                            color: '#e0e0e0'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#888', stepSize: 1 },
                            grid: { color: 'rgba(255, 255, 255, 0.05)' }
                        },
                        x: {
                            ticks: { 
                                color: '#888',
                                maxRotation: 45,
                                minRotation: 45,
                                autoSkip: true,
                                maxTicksLimit: 12
                            },
                            grid: { color: 'rgba(255, 255, 255, 0.05)' }
                        }
                    }
                }
            });
        }

        // ========================================
        // CAMERA FEED REFRESH
        // ========================================
        function refreshCameraFeeds() {
            const camera1 = document.getElementById('camera1Feed');
            const camera2 = document.getElementById('camera2Feed');
            
            if (camera1) {
                camera1.src = 'camera_frames/camera1_live.jpg?' + new Date().getTime();
            }
            if (camera2) {
                camera2.src = 'camera_frames/camera2_live.jpg?' + new Date().getTime();
            }
        }

        // ========================================
        // INITIALIZATION
        // ========================================
        async function init() {
            initMap();
            initChart();
            await fetchData();
            
            // Auto-refresh data every 3 seconds
            setInterval(fetchData, 3000);
            
            // Auto-refresh camera feeds every 500ms
            setInterval(refreshCameraFeeds, 500);
            
            console.log('Dashboard initialized');
            console.log('Data refreshes every 3 seconds');
        }

        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>