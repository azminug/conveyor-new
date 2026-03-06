# flask_app/app.py

from flask import Flask, Response, request
from flask_cors import CORS
import cv2
from ultralytics import YOLO
import threading
from firebase_admin import credentials, initialize_app, db
from queue import Queue
import time
from datetime import datetime
import os
import logging
import argparse
import glob
import random

app = Flask(__name__)
CORS(app)

# Konfigurasi Logging
logging.basicConfig(
    level=logging.DEBUG,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler("flask_app.log"), logging.StreamHandler()],
)

# ---------------------------
# 1. Parse Command Line Arguments
# ---------------------------
parser = argparse.ArgumentParser(description='Flask YOLO Detection')
parser.add_argument('--dummy', action='store_true', help='Use dummy image folder')
parser.add_argument('--dummy_folder', type=str, default='dummy_images', help='Path to dummy images folder')
parser.add_argument('--camera', type=int, default=2, help='Camera index or URL')
args = parser.parse_args()

# ---------------------------
# 2. Inisialisasi Model YOLO
# ---------------------------
model = YOLO("best.pt")
logging.info("Model YOLO berhasil diinisialisasi.")

# ---------------------------
# 3. Variabel & Queue
# ---------------------------
frame_queue = {} 
latest_annotated_frame = {} 
stop_thread = {} 
auto_start = True  # Start detection automatically

# ---------------------------
# 4. Inisialisasi Firebase
# ---------------------------
def initialize_firebase():
    try:
        cred = credentials.Certificate("serviceAccountKey.json")
        firebase_app = initialize_app(
            cred,
            {
                "databaseURL": "https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app",
            },
        )
        firebase_ref = db.reference("conveyor/paket")
        logging.info("Firebase berhasil diinisialisasi.")
        return firebase_ref
    except Exception as e:
        logging.error(f"Error inisialisasi Firebase: {e}")
        return None

firebase_ref = initialize_firebase()

# ========== THREAD OBJECTS =========
camera_thread_objs = {} 
detection_thread_objs = {} 

# ---------------------------
# 5. Fungsi Utility
# ---------------------------
def is_within(pkg_box, dmg_box):
    pkg_x1, pkg_y1, pkg_x2, pkg_y2 = pkg_box
    dmg_x1, dmg_y1, dmg_x2, dmg_y2 = dmg_box
    dmg_center_x = (dmg_x1 + dmg_x2) / 2
    dmg_center_y = (dmg_y1 + dmg_y2) / 2
    return pkg_x1 <= dmg_center_x <= pkg_x2 and pkg_y1 <= dmg_center_y <= pkg_y2

def classify_damage(detections):
    packages = []
    damage_boxes = []
    
    for detection in detections:
        cls_name = detection["class"]
        bbox = detection["bbox"]
        if cls_name.lower() == "package":
            packages.append({"bbox": bbox, "damages": [], "damage_count": 0})
        elif cls_name.lower() == "damage":
            damage_boxes.append({"bbox": bbox, "confidence": detection["confidence"]})

    # Handle no package case
    if len(packages) == 0 and len(damage_boxes) > 0:
        packages.append({
            "bbox": [0, 0, 0, 0],
            "damages": damage_boxes,
            "damage_count": len(damage_boxes),
            "damage_level": "Rusak Berat" if len(damage_boxes) > 2 else "Rusak"
        })
    elif len(packages) == 0:
        packages.append({
            "bbox": [0, 0, 0, 0],
            "damages": [],
            "damage_count": 0,
            "damage_level": "Tidak Terdeteksi"
        })
    else:
        for package in packages:
            for damage in damage_boxes:
                if is_within(package["bbox"], damage["bbox"]):
                    package["damages"].append(damage)
                    package["damage_count"] += 1

            damage_count = package["damage_count"]
            if damage_count == 0:
                package["damage_level"] = "Tidak Rusak"
            elif damage_count == 1:
                package["damage_level"] = "Rusak Ringan"
            elif damage_count == 2:
                package["damage_level"] = "Rusak"
            else:
                package["damage_level"] = "Sangat Rusak"
    
    return packages

# ---------------------------
# 6. Thread: Pembacaan Kamera/Dummy
# ---------------------------
def camera_thread(dc_name):
    global stop_thread, frame_queue
    
    try:
        if args.dummy:
            # Dummy mode: load images from folder
            images = glob.glob(os.path.join(args.dummy_folder, "*.jpg")) + \
                     glob.glob(os.path.join(args.dummy_folder, "*.png"))
            random.shuffle(images)
            if not images:
                logging.error(f"No images found in {args.dummy_folder}")
                return
        else:
            cap = cv2.VideoCapture(args.camera)
            if not cap.isOpened():
                logging.error(f"Camera access failed for DC: {dc_name}")
                return

        while True:
            if stop_thread.get(dc_name, False):
                time.sleep(0.1)
                continue

            if args.dummy:
                # Cycle through images
                img_path = random.choice(images)
                frame = cv2.imread(img_path)
                if frame is None:
                    logging.error(f"Failed to read image: {img_path}")
                    time.sleep(0.5)
                    continue
            else:
                success, frame = cap.read()
                if not success:
                    logging.error(f"Failed to read frame for DC: {dc_name}")
                    time.sleep(0.1)
                    continue

            # Resize for processing
            frame = cv2.resize(frame, (640, 360), interpolation=cv2.INTER_AREA)
            
            # Manage frame queue
            if frame_queue[dc_name].full():
                frame_queue[dc_name].get()
                
            frame_queue[dc_name].put(frame)
            time.sleep(0.033)  # ~30fps
            
        if not args.dummy:
            cap.release()
    except Exception as e:
        logging.error(f"Camera thread error for {dc_name}: {e}")

# ---------------------------
# 7. Thread: Deteksi & Anotasi
# ---------------------------
def detection_thread(dc_name):
    global latest_annotated_frame, stop_thread, frame_queue
    
    try:
        frame_count = 0
        last_packages = None
        skip_frame = 2  # Process every 2nd frame

        while True:
            if stop_thread.get(dc_name, False):
                time.sleep(0.1)
                continue

            if frame_queue[dc_name].empty():
                time.sleep(0.01)
                continue

            frame = frame_queue[dc_name].get()
            frame_count += 1
            
            # Skip some frames to reduce processing load
            if frame_count % skip_frame != 0:
                if last_packages is not None:
                    annotated_frame = draw_bounding_boxes(frame, last_packages)
                    latest_annotated_frame[dc_name] = annotated_frame
                else:
                    latest_annotated_frame[dc_name] = frame
                continue

            # Run detection
            results = model(frame, imgsz=320, conf=0.3)
            detections = []
            
            for result in results:
                for box in result.boxes:
                    cls_name = model.names[int(box.cls)]
                    conf = float(box.conf)
                    bbox = box.xyxy.tolist()[0]
                    detections.append({
                        "class": cls_name, 
                        "confidence": conf, 
                        "bbox": bbox
                    })

            packages = classify_damage(detections)
            last_packages = packages
            
            annotated_frame = draw_bounding_boxes(frame, packages)
            latest_annotated_frame[dc_name] = annotated_frame
            
            # Update Firebase
            if firebase_ref:
                update_firebase(packages, dc_name)
            
            logging.debug(f"Processed frame {frame_count} for {dc_name}")
            time.sleep(0.01)
            
    except Exception as e:
        logging.error(f"Detection thread error for {dc_name}: {e}")

# ---------------------------
# 8. Bounding Box Visualization
# ---------------------------
def draw_bounding_boxes(frame, packages):
    annotated_frame = frame.copy()
    
    for package in packages:
        if package["damage_level"] == "Tidak Terdeteksi":
            # Show "No Package" message
            cv2.putText(
                annotated_frame, 
                "Tidak Ada Paket Terdeteksi", 
                (10, 30), 
                cv2.FONT_HERSHEY_SIMPLEX,
                0.7, 
                (0, 0, 255), 
                2
            )
            continue
        
        # Draw package bounding box
        x1, y1, x2, y2 = map(int, package["bbox"])
        cv2.rectangle(annotated_frame, (x1, y1), (x2, y2), (0, 255, 0), 2)
        
        # Package label
        label = f"Paket: {package['damage_level']}"
        cv2.putText(
            annotated_frame, 
            label, 
            (x1, y1 - 10), 
            cv2.FONT_HERSHEY_SIMPLEX,
            0.7, 
            (0, 255, 0), 
            2
        )
        
        # Draw damage boxes
        for damage in package.get("damages", []):
            dx1, dy1, dx2, dy2 = map(int, damage["bbox"])
            cv2.rectangle(annotated_frame, (dx1, dy1), (dx2, dy2), (0, 0, 255), 2)
            
            # Damage label
            dmg_label = f"Rusak: {damage['confidence']:.2f}"
            cv2.putText(
                annotated_frame, 
                dmg_label, 
                (dx1, dy1 - 10), 
                cv2.FONT_HERSHEY_SIMPLEX,
                0.5, 
                (0, 0, 255), 
                1
            )
    
    return annotated_frame

# ---------------------------
# 9. Firebase Update Logic
# ---------------------------
def update_firebase(packages, dc_name):
    try:
        current_time = datetime.now()
        
        # Get latest package data from conveyor/paket
        latest_data = firebase_ref.order_by_child("updated_at").limit_to_last(1).get()
        
        if not latest_data:
            logging.info("No recent Firebase data")
            return
            
        latest_key = list(latest_data.keys())[0]
        latest_node = latest_data[latest_key]
        
        # Skip if damage already detected for this paket
        existing_damage = latest_node.get("damage", "")
        if existing_damage and existing_damage not in ["", "Belum Dideteksi"]:
            return
        
        # Validate updated_at
        if "updated_at" not in latest_node:
            return
            
        # Calculate time difference
        updated_time = datetime.strptime(latest_node["updated_at"], "%Y-%m-%d %H:%M:%S")
        time_diff = (current_time - updated_time).total_seconds()
        
        # Update if within 10-second window
        if time_diff <= 10:
            damage_level = packages[0]["damage_level"] if packages else "Tidak Terdeteksi"
            
            firebase_ref.child(latest_key).update({
                "damage": damage_level,
                "damage_detected_at": current_time.strftime("%Y-%m-%d %H:%M:%S")
            })
            
            logging.info(f"Updated Firebase [{latest_key}]: {damage_level}")
    except Exception as e:
        logging.error(f"Firebase update error: {e}")

# ---------------------------
# 10. Video Feed Endpoint
# ---------------------------
@app.route("/video_feed")
def video_feed_stream():
    dc_name = request.args.get("dcName", "DC-A")
    
    def generate():
        while True:
            if dc_name in latest_annotated_frame and latest_annotated_frame[dc_name] is not None:
                frame = latest_annotated_frame[dc_name]
                success, jpeg = cv2.imencode(".jpg", frame)
                
                if success:
                    yield (b"--frame\r\n"
                           b"Content-Type: image/jpeg\r\n\r\n" + 
                           jpeg.tobytes() + b"\r\n")
            time.sleep(0.01)
            
    return Response(generate(), mimetype="multipart/x-mixed-replace; boundary=frame")

# ---------------------------
# 11. Auto-start Threads
# ---------------------------
def start_detection(dc_name):
    global frame_queue, stop_thread
    
    # Initialize structures
    frame_queue[dc_name] = Queue(maxsize=5)
    stop_thread[dc_name] = False
    
    # Start camera thread
    camera_thread_objs[dc_name] = threading.Thread(
        target=camera_thread,
        args=(dc_name,),
        daemon=True
    )
    camera_thread_objs[dc_name].start()
    
    # Start detection thread
    detection_thread_objs[dc_name] = threading.Thread(
        target=detection_thread,
        args=(dc_name,),
        daemon=True
    )
    detection_thread_objs[dc_name].start()
    
    logging.info(f"Started detection for {dc_name}")

# ---------------------------
# 12. Initialize Detection
# ---------------------------
@app.before_first_request
def initialize_detection():
    if auto_start:
        start_detection("DC-A")

# ---------------------------
# 13. Start/Stop Endpoints
# ---------------------------
@app.route("/start_video")
def start_video():
    dc_name = request.args.get("dcName", "DC-A")
    stop_thread[dc_name] = False
    return f"Detection started for {dc_name}"

@app.route("/stop_video")
def stop_video():
    dc_name = request.args.get("dcName", "DC-A")
    stop_thread[dc_name] = True
    return f"Detection stopped for {dc_name}"

# ---------------------------
# 14. Run Application
# ---------------------------
if __name__ == "__main__":
    # Auto-start for main DC
    if auto_start:
        start_detection("DC-A")
        
    app.run(host="0.0.0.0", port=5000, debug=True)