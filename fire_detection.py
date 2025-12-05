import cv2
from ultralytics import YOLO
import os
from datetime import datetime
import time

# FORCE CPU USAGE (fixes old GPU compatibility issues)
import torch
torch.cuda.is_available = lambda: False

# Import database module
from database import (
    init_database, get_cameras, update_camera_status, log_detection,
    update_detection_clip, create_alert, add_activity, get_stats
)

# -------- SETTINGS --------
MODEL_PATH = "10best.pt"
SAVE_DIR_IMG = "detected_images"
CAMERA_FRAMES_DIR = "camera_frames"

# Clip settings: 1 second before, 4 seconds after
CLIP_BEFORE_SEC = 1.0
CLIP_AFTER_SEC = 4.0
CLIP_DURATION_SEC = CLIP_BEFORE_SEC + CLIP_AFTER_SEC
CLIP_BUFFER_SEC = CLIP_DURATION_SEC + 2.0

SAVE_DIR_CLIP = "detected_clips"

# Frame buffers and pending clips
FRAME_BUFFERS = {}
PENDING_CLIPS = {}

# Detection thresholds
FIRE_CONFIDENCE_THRESHOLD = 0.70
SMOKE_CONFIDENCE_THRESHOLD = 0.65

# Create directories
os.makedirs(SAVE_DIR_IMG, exist_ok=True)
os.makedirs(CAMERA_FRAMES_DIR, exist_ok=True)
os.makedirs(SAVE_DIR_CLIP, exist_ok=True)

# Initialize database
print("Initializing database...")
init_database()

# Load model
print("Loading YOLO model...")
model = YOLO(MODEL_PATH)
print("Model loaded successfully!")

# Cache camera data
CAMERAS_CACHE = None

def get_camera_info(camera_id):
    """Get camera info from database with caching"""
    global CAMERAS_CACHE
    if CAMERAS_CACHE is None:
        cameras = get_cameras()
        CAMERAS_CACHE = {cam['id']: cam for cam in cameras}
    return CAMERAS_CACHE.get(camera_id)

def refresh_camera_cache():
    """Refresh the camera cache"""
    global CAMERAS_CACHE
    cameras = get_cameras()
    CAMERAS_CACHE = {cam['id']: cam for cam in cameras}

# Frame buffer utilities
def update_frame_buffer(camera_id, frame):
    """Keep a rolling buffer of frames for each camera."""
    now = time.time()
    if camera_id not in FRAME_BUFFERS:
        FRAME_BUFFERS[camera_id] = []
    buf = FRAME_BUFFERS[camera_id]
    buf.append((now, frame.copy()))
    
    cutoff = now - CLIP_BUFFER_SEC
    while buf and buf[0][0] < cutoff:
        buf.pop(0)

def save_detection_clip(camera_id, detection_id, trigger_time):
    """Save a clip from 1 second before to 4 seconds after trigger_time with bounding boxes."""
    buf = FRAME_BUFFERS.get(camera_id, [])
    if not buf:
        return None

    start_time = trigger_time - CLIP_BEFORE_SEC
    end_time = trigger_time + CLIP_AFTER_SEC

    selected_frames = [frame for (t, frame) in buf if start_time <= t <= end_time]
    if not selected_frames:
        selected_frames = [frame for (_, frame) in buf]
        if not selected_frames:
            return None

    height, width, _ = selected_frames[0].shape
    duration = end_time - start_time
    if duration <= 0:
        duration = CLIP_DURATION_SEC
    fps = len(selected_frames) / duration if len(selected_frames) > 1 else 10

    filename = f"camera{camera_id}_det_{detection_id}.mp4"
    save_path = os.path.join(SAVE_DIR_CLIP, filename)

    fourcc = cv2.VideoWriter_fourcc(*"mp4v")
    out = cv2.VideoWriter(save_path, fourcc, fps, (width, height))

    for fr in selected_frames:
        results = model.predict(source=fr, verbose=False)
        annotated = results[0].plot()
        out.write(annotated)

    out.release()

    # Update detection with clip path in database
    update_detection_clip(detection_id, save_path)
    
    print(f"Saved detection clip with boxes: {save_path}")
    return save_path

def handle_pending_clips(camera_id):
    """Check if we can now save a pending clip for this camera."""
    pending = PENDING_CLIPS.get(camera_id)
    if not pending:
        return

    now = time.time()
    trigger_time = pending["trigger_time"]

    if now >= trigger_time + CLIP_AFTER_SEC:
        save_detection_clip(camera_id, pending["detection_id"], trigger_time)
        del PENDING_CLIPS[camera_id]

def process_detection_results(results, camera_id, frame, save_image=True):
    """Process YOLO detection results"""
    detection_info = {
        'has_fire': False,
        'has_smoke': False,
        'max_fire_confidence': 0,
        'max_smoke_confidence': 0
    }
    
    for result in results:
        boxes = result.boxes
        for box in boxes:
            cls_id = int(box.cls[0])
            confidence = float(box.conf[0])
            class_name = result.names[cls_id].lower()
            
            if 'fire' in class_name:
                detection_info['has_fire'] = True
                detection_info['max_fire_confidence'] = max(
                    detection_info['max_fire_confidence'], 
                    confidence
                )
            elif 'smoke' in class_name:
                detection_info['has_smoke'] = True
                detection_info['max_smoke_confidence'] = max(
                    detection_info['max_smoke_confidence'], 
                    confidence
                )
    
    if save_image and (detection_info['has_fire'] or detection_info['has_smoke']):
        if detection_info['max_fire_confidence'] >= detection_info['max_smoke_confidence']:
            log_type = 'fire'
            confidence = detection_info['max_fire_confidence']
        else:
            log_type = 'smoke'
            confidence = detection_info['max_smoke_confidence']
        
        threshold = FIRE_CONFIDENCE_THRESHOLD if log_type == 'fire' else SMOKE_CONFIDENCE_THRESHOLD
        
        if confidence >= threshold:
            # Save annotated image
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            save_name = f"camera{camera_id}_{log_type}_{timestamp}.jpg"
            save_path = os.path.join(SAVE_DIR_IMG, save_name)
            
            annotated_frame = results[0].plot()
            cv2.imwrite(save_path, annotated_frame)
            
            # Get camera info
            camera = get_camera_info(camera_id)
            
            # Log detection to database
            detection_id = log_detection(
                camera_id=camera_id,
                detection_type=log_type,
                confidence=confidence,
                image_path=save_path,
                location=camera['location'],
                latitude=camera['latitude'],
                longitude=camera['longitude'],
                camera_name=camera['name']
            )
            
            detection_info['detection_id'] = detection_id
            
            # Create alert if high confidence
            if confidence >= 0.85:
                alert_level = 'critical' if log_type == 'fire' else 'warning'
                message = f"{log_type.upper()} detected at {camera['location']} - Confidence: {confidence:.1%}"
                create_alert(detection_id, alert_level, message)
                add_activity(f"ALERT: {message}")

            # Mark pending clip
            trigger_time = time.time()
            PENDING_CLIPS[camera_id] = {
                "detection_id": detection_id,
                "trigger_time": trigger_time
            }
            
            print(f"\nDETECTED {log_type.upper()} with confidence {confidence:.1%}")
    
    return detection_info

def detect_from_webcam(camera_id=1):
    """Run detection on webcam"""
    cap = cv2.VideoCapture(0)
    
    if not cap.isOpened():
        print("Error: Could not open webcam.")
        return
    
    camera = get_camera_info(camera_id)
    
    print(f"\n{'='*60}")
    print(f"Camera: {camera['name']}")
    print(f"{'='*60}")
    print("Press 'q' to quit, 's' to save current frame")
    print("Camera feed is also streaming to dashboard at http://localhost:8000")
    print(f"{'='*60}\n")
    
    update_camera_status(camera_id, 'online')
    add_activity(f"{camera['name']} started")
    
    frame_count = 0
    detection_cooldown = 0
    last_frame_save = 0
    
    try:
        while True:
            ret, frame = cap.read()
            if not ret:
                break

            update_frame_buffer(camera_id, frame)
            handle_pending_clips(camera_id)
            
            frame_count += 1
            
            if frame_count - last_frame_save >= 10:
                frame_path = os.path.join(CAMERA_FRAMES_DIR, f"camera{camera_id}_live.jpg")
                cv2.imwrite(frame_path, frame)
                last_frame_save = frame_count
            
            if frame_count % 5 == 0:
                results = model.predict(source=frame, verbose=False)
                annotated_frame = results[0].plot()
                
                should_save = (detection_cooldown <= 0)
                detection_info = process_detection_results(results, camera_id, frame, save_image=should_save)
                
                if detection_info.get('detection_id'):
                    detection_cooldown = 300
                
                if detection_cooldown > 0:
                    detection_cooldown -= 1
                
                if camera_id == 2:
                    temp = 22 + (detection_info['max_fire_confidence'] * 100)
                    update_camera_status(camera_id, 'online', temperature=temp)
            else:
                annotated_frame = frame
            
            cv2.imshow("Fire & Smoke Detection", annotated_frame)
            
            key = cv2.waitKey(1) & 0xFF
            if key == ord('q'):
                break
            elif key == ord('s'):
                timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
                save_name = f"camera{camera_id}_manual_{timestamp}.jpg"
                save_path = os.path.join(SAVE_DIR_IMG, save_name)
                cv2.imwrite(save_path, annotated_frame)
                print(f"Saved: {save_name}")
    
    finally:
        update_camera_status(camera_id, 'offline')
        add_activity(f"{camera['name']} stopped")
        cap.release()
        cv2.destroyAllWindows()

def detect_dual_cameras():
    """Run detection on two cameras simultaneously"""
    print(f"\n{'='*60}")
    print("DUAL CAMERA MODE")
    print(f"{'='*60}")
    
    cap1 = cv2.VideoCapture(0)
    cap2 = cv2.VideoCapture(1)
    
    if not cap1.isOpened():
        print("Error: Could not open camera 1")
        return
    
    refresh_camera_cache()
    
    update_camera_status(1, 'online')
    add_activity('Dual camera monitoring started')
    
    has_camera2 = cap2.isOpened()
    if has_camera2:
        update_camera_status(2, 'online')
    else:
        print("Camera 2 not available, using single camera mode")
    
    print("Press 'q' to quit")
    print("Camera feeds are streaming to dashboard at http://localhost:8000")
    print(f"{'='*60}\n")
    
    frame_count = 0
    detection_cooldown_1 = 0
    detection_cooldown_2 = 0
    last_frame_save = 0
    
    try:
        while True:
            ret1, frame1 = cap1.read()
            if not ret1:
                break
            
            frame_count += 1

            update_frame_buffer(1, frame1)
            handle_pending_clips(1)
            
            if frame_count - last_frame_save >= 10:
                frame1_path = os.path.join(CAMERA_FRAMES_DIR, "camera1_live.jpg")
                cv2.imwrite(frame1_path, frame1)
                last_frame_save = frame_count
            
            if frame_count % 5 == 0:
                results1 = model.predict(source=frame1, verbose=False)
                annotated_frame1 = results1[0].plot()
                
                should_save_1 = (detection_cooldown_1 <= 0)
                detection_info_1 = process_detection_results(results1, 1, frame1, save_image=should_save_1)
                
                if detection_info_1.get('detection_id'):
                    detection_cooldown_1 = 300
                if detection_cooldown_1 > 0:
                    detection_cooldown_1 -= 1
            else:
                annotated_frame1 = frame1
            
            if has_camera2:
                ret2, frame2 = cap2.read()
                if ret2:
                    update_frame_buffer(2, frame2)
                    handle_pending_clips(2)

                    if frame_count - last_frame_save >= 10:
                        frame2_path = os.path.join(CAMERA_FRAMES_DIR, "camera2_live.jpg")
                        cv2.imwrite(frame2_path, frame2)
                    
                    if frame_count % 5 == 0:
                        results2 = model.predict(source=frame2, verbose=False)
                        annotated_frame2 = results2[0].plot()
                        
                        should_save_2 = (detection_cooldown_2 <= 0)
                        detection_info_2 = process_detection_results(results2, 2, frame2, save_image=should_save_2)
                        
                        if detection_info_2.get('detection_id'):
                            detection_cooldown_2 = 300
                        if detection_cooldown_2 > 0:
                            detection_cooldown_2 -= 1
                        
                        temp = 22 + (detection_info_2.get('max_fire_confidence', 0) * 100)
                        update_camera_status(2, 'online', temperature=temp)
                    else:
                        annotated_frame2 = frame2
                    
                    combined = cv2.hconcat([annotated_frame1, annotated_frame2])
                    cv2.imshow("Dual Camera Detection", combined)
                else:
                    cv2.imshow("Camera 1 - Visual ML", annotated_frame1)
            else:
                cv2.imshow("Camera 1 - Visual ML", annotated_frame1)
            
            if cv2.waitKey(1) & 0xFF == ord('q'):
                break
    
    finally:
        update_camera_status(1, 'offline')
        if has_camera2:
            update_camera_status(2, 'offline')
            cap2.release()
        add_activity('Dual camera monitoring stopped')
        cap1.release()
        cv2.destroyAllWindows()

def main():
    add_activity('Fire detection system started')
    
    while True:
        print("\n" + "="*60)
        print("FIRE AND SMOKE DETECTION SYSTEM")
        print("="*60)
        print("1. Dual camera detection (Camera 1 and 2)")
        print("2. Single webcam (Camera 1)")
        print("3. View statistics")
        print("4. Exit")
        print("="*60)
        choice = input("Select option (1-4): ").strip()
        
        if choice == "1":
            detect_dual_cameras()
        elif choice == "2":
            detect_from_webcam(camera_id=1)
        elif choice == "3":
            stats = get_stats()
            print(f"\nToday's Statistics:")
            print(f"   Fire detections: {stats.get('fire_today', 0)}")
            print(f"   Smoke detections: {stats.get('smoke_today', 0)}")
            print(f"   Total detections: {stats.get('detections_today', 0)}")
            print(f"   Active cameras: {stats.get('active_cameras', 0)}")
            print(f"   Personnel online: {stats.get('personnel_online', 0)}")
        elif choice == "4":
            add_activity('Fire detection system stopped')
            print("Exiting...")
            break
        else:
            print("Invalid choice.")

if __name__ == "__main__":
    main()