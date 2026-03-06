import cv2

cap = cv2.VideoCapture(1)  # atau 0, 1, CAP_DSHOW, dsb.
if not cap.isOpened():
    print("Gagal membuka kamera.")
    exit()

while True:
    ret, frame = cap.read()
    if not ret:
        print("Gagal membaca frame.")
        break

    cv2.imshow("Camera Test", frame)
    if cv2.waitKey(1) & 0xFF == 27:  # ESC untuk keluar
        break

cap.release()
cv2.destroyAllWindows()
