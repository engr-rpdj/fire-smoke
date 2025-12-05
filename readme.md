# 🔥 Fire Detection System - SIMPLE VERSION
## Just 2 Files!

---

## 📁 Files

1. **fire_detection.py** - Python YOLO detection (saves to JSON)
2. **dashboard.php** - Dashboard (reads JSON and displays)

---

## 🚀 Super Quick Setup

### Step 1: Install Python Packages

```bash
pip install opencv-python ultralytics --break-system-packages
```

### Step 2: Run Python (First!)

```bash
python3 fire_detection.py
```

Select option:
- **1** = Dual camera mode (if you have 2 cameras)
- **2** = Single webcam (most common)

### Step 3: Run PHP (Second!)

Open a new terminal:

```bash
php -S localhost:8000 dashboard.php
```

### Step 4: Open Dashboard

Open your browser:
```
http://localhost:8000
```

**That's it!** 🎉

---

## 📊 How It Works

```
Python Script          JSON File         PHP Dashboard
    ↓                     ↓                   ↓
Run camera        fire_data.json        Reads JSON
Detect fire    ←  Writes data      →   Shows data
Save to JSON          Updates           Auto-refresh
```

### Simple Flow:

1. **Python** runs your YOLO model on camera
2. **Python** saves all detections to `fire_data.json`
3. **PHP** reads `fire_data.json` when you open dashboard
4. **Dashboard** auto-refreshes every 3 seconds

**No database needed!** Everything is in the JSON file.

---

## 🎯 What You'll See on Dashboard

### Real-Time Stats:
- ✅ Active Cameras (0-2)
- ✅ Detections Today
- ✅ Response Time
- ✅ Personnel Online (12)

### Live Panels:
- ✅ Active Alerts (when fire/smoke detected)
- ✅ Camera Status (online/offline)
- ✅ Interactive Map (cameras + fire stations)
- ✅ Personnel List (12 firefighters + admins)
- ✅ Detection Chart
- ✅ Activity Log

### Emergency Alert:
When fire is detected with >85% confidence:
- 🚨 Full-screen emergency modal
- Shows location, camera, confidence
- "Dispatch Firefighters" button

---

## 🧪 Testing

### Test the System:

1. **Start Python** (it will create `fire_data.json`)
2. **Start PHP** dashboard
3. **Open browser** to localhost:8000
4. **Point camera** at a fire image or video
5. **Watch dashboard** update automatically!

---

## 📋 Files Created

- `fire_data.json` - All detection data (auto-created by Python)
- `detected_images/` - Saved detection images with bounding boxes

---

## 🔧 Configuration

### Change Camera Location:

Edit `fire_detection.py` lines 18-37:

```python
CAMERAS = {
    1: {
        'location': 'YOUR LOCATION HERE',
        'latitude': YOUR_LAT,
        'longitude': YOUR_LNG,
    }
}
```

### Change Detection Threshold:

Edit `fire_detection.py` lines 13-14:

```python
FIRE_CONFIDENCE_THRESHOLD = 0.70  # 70%
SMOKE_CONFIDENCE_THRESHOLD = 0.65  # 65%
```

### Change Refresh Rate:

Edit `dashboard.php` line ~730:

```javascript
setInterval(fetchData, 3000); // 3 seconds
```

---

## ❓ Troubleshooting

### "Module not found" error?
```bash
pip install opencv-python ultralytics --break-system-packages
```

### Dashboard shows "No data available"?
- Make sure Python script is running FIRST
- Check that `fire_data.json` exists in same folder

### Camera not opening?
- Check camera is connected
- Try different camera index (change `VideoCapture(0)` to `VideoCapture(1)`)
- Check camera permissions

### Dashboard not loading?
- Make sure PHP is running: `php -S localhost:8000 dashboard.php`
- Try different port: `php -S localhost:8080 dashboard.php`

---

## 💡 Why This Architecture?

### Question: Why not just PHP/JavaScript?

**Answer:** You NEED Python because:

1. ✅ YOLO model only runs in Python
2. ✅ Your `10best.pt` file is a Python model
3. ✅ OpenCV (camera access) is Python-based
4. ✅ No browser-based AI powerful enough for fire detection

### The Setup:

```
Python (Required)          PHP (Simple)
     ↓                         ↓
YOLO Detection    →    Display Results
Camera Access     →    Web Dashboard
Save to JSON      ←    Read from JSON
```

**Python = Brain** (does the AI work)
**PHP = Face** (shows you what Python found)

---

## 🎓 What Data is Tracked?

### In `fire_data.json`:

```json
{
  "cameras": {...},          // Camera status
  "detections": [...],       // All fire/smoke detections
  "alerts": [...],           // Critical alerts
  "activity": [...],         // System activity log
  "personnel": [...],        // 12 firefighters/admins
  "stats": {...}            // Today's statistics
}
```

All updates automatically when Python detects fire/smoke!

---

## 🔥 Production Use

### For Real Deployment:

1. **Run Python as service** (systemd/supervisor)
2. **Use Nginx/Apache** instead of PHP dev server
3. **Add authentication** to dashboard
4. **Set up alerts** (email/SMS)
5. **Backup** `fire_data.json` regularly

### Example Systemd Service:

```ini
[Unit]
Description=Fire Detection System

[Service]
ExecStart=/usr/bin/python3 /path/to/fire_detection.py
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## ✅ Quick Reference

### To Start Everything:

```bash
# Terminal 1: Start detection
python3 fire_detection.py

# Terminal 2: Start dashboard
php -S localhost:8000 dashboard.php

# Browser: Open dashboard
http://localhost:8000
```

### To Stop:

Press `Ctrl+C` in both terminals

---

## 📞 Need Help?

1. Make sure Python script runs first
2. Check `fire_data.json` is being created
3. Make sure both files are in same directory
4. Check console for errors

---

**That's all you need! Just 2 files. Simple! 🎉**