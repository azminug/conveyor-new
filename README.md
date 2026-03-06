# ESP32 Conveyor Sorting System - Hybrid Scanner

Sistem sorting paket otomatis dengan **dual scanner** untuk meningkatkan akurasi identifikasi paket.

## 🎯 Fitur Utama

- **Hybrid Scanning**: Kombinasi Barcode (GM66) + UHF RFID (EL-UHF-RMT01)
- **Fallback Otomatis**: Jika barcode gagal, sistem otomatis mencoba RFID
- **Offline Support**: Queue untuk menyimpan data saat WiFi terputus
- **Real-time Firebase**: Sinkronisasi status paket secara real-time
- **Multi-jalur Sorting**: Support multiple jalur dengan servo motor

## 📁 Struktur Proyek

```
convey_esp32/
├── platformio.ini              # Konfigurasi PlatformIO
├── src/
│   ├── main.cpp                # Program utama hybrid scanner
│   └── rfid_register.cpp.example  # Utility registrasi RFID tag
├── lib/
│   ├── EL_UHF_RMT01.h          # Library UHF RFID Reader (header)
│   └── EL_UHF_RMT01.cpp        # Library UHF RFID Reader (implementation)
└── README.md                   # Dokumentasi ini
```

## 🔌 Wiring Diagram

### ESP32 Pin Assignment

| Komponen | Pin ESP32 | Keterangan |
|----------|-----------|------------|
| **GM66 Barcode** | | |
| RX | GPIO 15 | ESP32 RX ← GM66 TX |
| **UHF RFID** | | |
| RX | GPIO 16 | ESP32 RX ← UHF TX |
| TX | GPIO 17 | ESP32 TX → UHF RX |
| **Servo 1** | GPIO 33 | Jalur 1 |
| **Servo 2** | GPIO 14 | Jalur 2 |
| **HC-SR04** | | |
| TRIG | GPIO 13 | Trigger |
| ECHO | GPIO 12 | Echo |

### Wiring UHF RFID (EL-UHF-RMT01)

```
ESP32           UHF Module
─────           ──────────
3.3V    ───────→ VCC
GND     ───────→ GND
GPIO16  ←─────── TX
GPIO17  ───────→ RX
```

⚠️ **Penting**: Modul UHF menggunakan logic level 3.3V, kompatibel langsung dengan ESP32.

## 🚀 Cara Penggunaan

### 1. Install PlatformIO

Pastikan VS Code dengan extension PlatformIO sudah terinstall.

### 2. Build & Upload

```bash
# Build
pio run

# Upload
pio run --target upload

# Monitor Serial
pio device monitor
```

### 3. Konfigurasi WiFi

Saat pertama kali boot:
1. ESP32 akan membuat AP "ESP32-Conveyor-Setup"
2. Hubungkan ke AP tersebut
3. Buka browser ke 192.168.4.1
4. Masukkan SSID, Password, dan Nama DC
5. ESP32 akan restart dan terhubung ke WiFi

### 4. Registrasi RFID Tag

Untuk mendaftarkan RFID tag baru:

1. Rename `rfid_register.cpp.example` menjadi `main.cpp`
2. Upload ke ESP32
3. Buka Serial Monitor
4. Dekatkan tag RFID
5. Masukkan Paket ID dan Kodepos
6. Tag akan tersimpan di Firebase

## 📊 Alur Kerja Sistem

```
┌─────────────────────────────────────────────────────────┐
│                    PAKET MASUK                          │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │   1. Scan Barcode      │
              │      (GM66)            │
              └────────────────────────┘
                           │
              ┌────────────┴────────────┐
              │                         │
         [Berhasil]               [Gagal/Timeout]
              │                         │
              │                         ▼
              │           ┌────────────────────────┐
              │           │   2. Fallback RFID     │
              │           │   (EL-UHF-RMT01)       │
              │           └────────────────────────┘
              │                         │
              │              ┌──────────┴──────────┐
              │              │                     │
              │         [Berhasil]           [Gagal]
              │              │                     │
              │              │                     ▼
              │              │          ┌──────────────────┐
              │              │          │  Manual Check    │
              │              │          │  atau Alert      │
              │              │          └──────────────────┘
              │              │
              └──────┬───────┘
                     │
                     ▼
        ┌────────────────────────┐
        │  Lookup Jalur          │
        │  (Kodepos → Jalur)     │
        └────────────────────────┘
                     │
                     ▼
        ┌────────────────────────┐
        │  Update Firebase       │
        │  Status: "Proses"      │
        │  + scan_method         │
        └────────────────────────┘
                     │
                     ▼
        ┌────────────────────────┐
        │  Request Damage        │
        │  Detection (Flask)     │
        └────────────────────────┘
                     │
                     ▼
        ┌────────────────────────┐
        │  Ultrasonic Detect     │
        │  (Tunggu paket)        │
        └────────────────────────┘
                     │
                     ▼
        ┌────────────────────────┐
        │  Aktivasi Servo        │
        │  (Jalur 1/2)           │
        └────────────────────────┘
                     │
                     ▼
        ┌────────────────────────┐
        │  Update Firebase       │
        │  Status: "Tersortir"   │
        └────────────────────────┘
```

## 📦 Firebase Data Structure

### Paket Data
```json
{
  "conveyor": {
    "paket": {
      "<paket_id>": {
        "status": "Ready|Proses|Tersortir",
        "jalur": "Jalur 1|Jalur 2",
        "damage": "none|minor|major",
        "dcName": "DC-A",
        "esp32_id": "AA:BB:CC:DD:EE:FF",
        "scan_method": "barcode|rfid",
        "rfid_epc": "E200301666...",
        "waktu_sortir": "2024-01-01 12:00:00",
        "updated_at": "2024-01-01 12:00:00"
      }
    }
  }
}
```

### RFID Tag Mapping
```json
{
  "conveyor": {
    "rfid_tags": {
      "<EPC_HEX>": {
        "paket_id": "PKT-001",
        "kodepos": "12345",
        "registered_at": "1234567890"
      }
    }
  }
}
```

## ⚙️ Konfigurasi

### Timing Configuration (main.cpp)

```cpp
#define BARCODE_TIMEOUT_MS      3000    // Timeout menunggu barcode
#define RFID_FALLBACK_DELAY_MS  500     // Delay sebelum fallback ke RFID
#define ULTRASONIC_TIMEOUT_MS   6000    // Timeout deteksi paket
#define SERVO_ACTIVE_MS         5000    // Durasi servo aktif
#define SCAN_COOLDOWN_MS        8000    // Cooldown setelah scan sukses
```

### RFID Configuration

```cpp
uhfReader.setRegion(REGION_920MHZ);  // Indonesia: 920-925 MHz
uhfReader.setPower(2000);            // 20 dBm (max untuk jarak jauh)
uhfReader.setFrequencyHopping(true); // Auto frequency hopping
```

## 🔧 Troubleshooting

### RFID Reader Tidak Terdeteksi

1. Periksa wiring (VCC, GND, TX, RX)
2. Pastikan baud rate 115200
3. Cek Serial Monitor untuk error message

### Tag Tidak Terbaca

1. Pastikan tag dalam jangkauan (1-5 meter)
2. Cek power setting (tingkatkan jika perlu)
3. Pastikan tidak ada interferensi metal

### WiFi Sering Disconnect

1. Cek jarak ke router
2. Pertimbangkan menggunakan antenna eksternal
3. Data akan di-queue dan dikirim saat reconnect

## 📝 Format Barcode

Sistem mengharapkan barcode dengan format:
```
<PaketID>|<Kodepos>
```

Contoh:
```
PKT-001|12345
PKT-002|67890
```

## 📄 License

MIT License - Feel free to use and modify.
