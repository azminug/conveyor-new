/**
 * @file main.cpp
 * @brief ESP32 Conveyor Sorting System - Hybrid Barcode + UHF RFID
 *
 * Sistem sorting paket otomatis dengan dual scanner:
 * 1. GM66 Barcode Scanner (Prioritas utama)
 * 2. EL-UHF-RMT01 UHF RFID Reader (Fallback jika barcode gagal)
 *
 * Alur kerja:
 * 1. Coba scan barcode terlebih dahulu
 * 2. Jika barcode gagal (posisi tidak tepat, rusak, dll), gunakan RFID
 * 3. Update status ke Firebase dengan metode identifikasi yang digunakan
 *
 * @author Conveyor System
 * @version 2.0.0
 * @date 2024
 */

#include <Arduino.h>
#include <HardwareSerial.h>
#include <WiFi.h>
#include <FirebaseESP32.h>
#include <ESP32Servo.h>
#include <time.h>
#include <WiFiManager.h>
#include <Preferences.h>
#include "EL_UHF_RMT01.h"

// ==================== Configuration ====================
// Firebase
#define FIREBASE_HOST "https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app"
#define FIREBASE_AUTH "kL4X0XviPTv7XtUnaqpbTEPLS4OOJAb8TY7kx0Of"

// Pin Configuration - GM66 Barcode Scanner
#define GM66_RX_PIN 15
#define GM66_TX_PIN -1 // TX tidak digunakan (hanya receive)

// Pin Configuration - UHF RFID Reader (EL-UHF-RMT01)
#define UHF_RX_PIN 16 // ESP32 RX <- UHF TX
#define UHF_TX_PIN 17 // ESP32 TX -> UHF RX

// Pin Configuration - Servo Motors
#define SERVO1_PIN 33
#define SERVO2_PIN 14

// Pin Configuration - Ultrasonic Sensor (HC-SR04)
#define TRIG_PIN 13
#define ECHO_PIN 12

// Ultrasonic sensor toggle (set to true when HC-SR04 is connected)
#define USE_ULTRASONIC false

// Timing Configuration
#define BARCODE_TIMEOUT_MS 3000     // Timeout menunggu barcode
#define RFID_FALLBACK_DELAY_MS 500  // Delay sebelum fallback ke RFID
#define ULTRASONIC_TIMEOUT_MS 6000  // Timeout deteksi paket
#define SERVO_ACTIVE_MS 5000        // Durasi servo aktif
#define HEARTBEAT_INTERVAL_MS 15000 // Interval heartbeat ke Firebase
#define SCAN_COOLDOWN_MS 2000       // Cooldown setelah scan sukses (pipeline mode)

// ==================== Scan Method Enum ====================
enum ScanMethod
{
  SCAN_NONE = 0,
  SCAN_BARCODE = 1,
  SCAN_RFID = 2
};

// ==================== Structs ====================
// IMPORTANT: Queue-safe structs use char[] instead of String
// because FreeRTOS xQueueSend/Receive does memcpy (shallow copy),
// which corrupts heap when String destructor frees shared buffer.

#define MAX_PAKET_LEN 24
#define MAX_JALUR_LEN 16
#define MAX_EPC_LEN 32
#define MAX_KODEPOS_LEN 12
#define MAX_STATUS_LEN 16
#define MAX_TIME_LEN 24
#define MAX_DAMAGE_LEN 24

struct ControlData
{
  char jalur[MAX_JALUR_LEN];
  char paketId[MAX_PAKET_LEN];
  ScanMethod method;
  char rfidEpc[MAX_EPC_LEN];
};

struct PaketUpdate
{
  char paketId[MAX_PAKET_LEN];
  char status[MAX_STATUS_LEN];
  char jalur[MAX_JALUR_LEN];
  char waktuSortir[MAX_TIME_LEN];
  char damage[MAX_DAMAGE_LEN];
  ScanMethod method;
  char rfidEpc[MAX_EPC_LEN];
};

struct JalurMapping
{
  String jalur;
  String kodepos;
};

// ==================== Global Objects ====================
Preferences preferences;
WiFiManagerParameter custom_dc("dcName", "Nama DC", "", 16);

HardwareSerial SerialGM66(1); // UART1 untuk GM66
HardwareSerial SerialUHF(2);  // UART2 untuk UHF RFID

EL_UHF_RMT01 uhfReader(&SerialUHF);

Servo servo1, servo2;
FirebaseData firebaseData;
FirebaseConfig firebaseConfig;
FirebaseAuth firebaseAuth;

// ==================== Queues ====================
QueueHandle_t scanQueue;        // Queue untuk hasil scan (barcode/RFID)
QueueHandle_t controlQueue;     // Queue untuk kontrol servo
QueueHandle_t statusUpdateQueue; // Queue status update control -> firebase

// ==================== Cache Jalur ====================
#define MAX_KODEPOS 200
JalurMapping jalurCache[MAX_KODEPOS];
int jalurCount = 0;

// ==================== RFID Tag Cache (untuk mapping EPC -> PaketId) ====================
#define MAX_RFID_CACHE 100
struct RFIDTagCache
{
  String epc;
  String paketId;
  String kodepos;
};
RFIDTagCache rfidCache[MAX_RFID_CACHE];
int rfidCacheCount = 0;

// ==================== Offline Queue ====================
#define MAX_UPDATE_QUEUE 50
PaketUpdate updateQueue[MAX_UPDATE_QUEUE];
int updateQueueHead = 0, updateQueueTail = 0;

#define MAX_LOG_QUEUE 50
String logQueue[MAX_LOG_QUEUE];
int logQueueHead = 0, logQueueTail = 0;

// ==================== Global Variables ====================
String ESP32_ID;
String dcName = "-";
bool wifiConnected = false;
unsigned long lastHeartbeat = 0;
unsigned long lastScanTime = 0;

// Guard: paket-paket yang sedang diproses (cegah duplicate scan)
#define MAX_PROCESSING 10
String processingPakets[MAX_PROCESSING];
int processingCount = 0;
SemaphoreHandle_t processingMutex = NULL;

// Servo state tracking for non-blocking control
struct ServoState {
  bool active;
  unsigned long activeSince;
  char paketId[MAX_PAKET_LEN];
  char jalur[MAX_JALUR_LEN];
  ScanMethod method;
  char rfidEpc[MAX_EPC_LEN];
};

// ==================== Scan Result Struct ====================
struct ScanResult
{
  bool success;
  char paketId[MAX_PAKET_LEN];
  char kodepos[MAX_KODEPOS_LEN];
  ScanMethod method;
  char rfidEpc[MAX_EPC_LEN];
};

// ==================== Function Declarations ====================
void initializeTime();
void cacheJalurFromFirebase();
void cacheRFIDTagsFromFirebase();
String getJalurByKodepos(const String &kodepos);
String getTimeString();
float readDistance();

bool updatePaketStatus(const String &paketId, const String &status,
                       const String &jalur = "", const String &waktuSortir = "",
                       const String &damage = "", ScanMethod method = SCAN_NONE,
                       const String &rfidEpc = "");
void addSortirLog(const String &paketId, const String &status,
                  const String &jalur, const String &waktuSortir,
                  const String &damage, ScanMethod method);
void logESP32(const String &aksi, const String &paketId,
              const String &status, const String &result,
              ScanMethod method = SCAN_NONE);
void requestDamageDetection(const String &paketId);
void sendHeartbeat();

// Processing guard helpers
bool isProcessingPaket(const String &paketId);
void addProcessingPaket(const String &paketId);
void removeProcessingPaket(const String &paketId);

void queueUpdatePaket(const PaketUpdate &update);
void flushUpdateQueue();
void queueLog(FirebaseJson &logJson);
void flushLogQueue();

// Task functions
void scannerTask(void *param);
void firebaseTask(void *param);
void controlTask(void *param);

// ==================== Utility Functions ====================
String getTimeString()
{
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo))
    return "-";
  char buf[32];
  strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", &timeinfo);
  return String(buf);
}

float readDistance()
{
#if USE_ULTRASONIC
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);
  long duration = pulseIn(ECHO_PIN, HIGH, 30000); // 30ms timeout
  return duration * 0.034 / 2;
#else
  return 10.0; // Dummy: always "object detected" at 10cm
#endif
}

// ==================== Processing Guard Helpers ====================
bool isProcessingPaket(const String &paketId)
{
  for (int i = 0; i < processingCount; i++)
    if (processingPakets[i] == paketId)
      return true;
  return false;
}

void addProcessingPaket(const String &paketId)
{
  if (processingCount < MAX_PROCESSING)
    processingPakets[processingCount++] = paketId;
  else
    Serial.println("[WARN] Processing slots full!");
}

void removeProcessingPaket(const String &paketId)
{
  for (int i = 0; i < processingCount; i++)
  {
    if (processingPakets[i] == paketId)
    {
      processingPakets[i] = processingPakets[--processingCount];
      return;
    }
  }
}

String scanMethodToString(ScanMethod method)
{
  switch (method)
  {
  case SCAN_BARCODE:
    return "barcode";
  case SCAN_RFID:
    return "rfid";
  default:
    return "unknown";
  }
}

void initializeTime()
{
  configTime(25200, 0, "pool.ntp.org", "time.google.com"); // UTC+7 (WIB)
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo, 10000))  // Wait up to 10 seconds
  {
    Serial.println("[TIME] Failed to obtain time");
  }
  else
  {
    Serial.println("[TIME] Time synchronized!");
  }
}

// ==================== Cache Functions ====================
void cacheJalurFromFirebase()
{
  if (WiFi.status() != WL_CONNECTED)
  {
    Serial.println("[CACHE] WiFi putus! Tidak bisa ambil data jalur.");
    return;
  }

  Serial.println("[CACHE] Mengambil data jalur dari Firebase...");
  String path = "/conveyor/jalur";

  if (Firebase.getJSON(firebaseData, path))
  {
    FirebaseJson json = firebaseData.jsonObject();
    FirebaseJsonData jsonData;
    jalurCount = 0;

    size_t len = json.iteratorBegin();
    for (size_t i = 0; i < len && jalurCount < MAX_KODEPOS; i++)
    {
      int type;
      String jalurKey, value;
      json.iteratorGet(i, type, jalurKey, value);

      FirebaseJson jalurJson;
      jalurJson.setJsonData(value);

      if (jalurJson.get(jsonData, "kodepos"))
      {
        String kodeposList = jsonData.to<String>();
        kodeposList.replace("[", "");
        kodeposList.replace("]", "");
        kodeposList.replace("\"", "");

        int start = 0;
        while (start < (int)kodeposList.length() && jalurCount < MAX_KODEPOS)
        {
          int comma = kodeposList.indexOf(',', start);
          String kodepos = kodeposList.substring(start,
                                                 comma == -1 ? kodeposList.length() : comma);
          kodepos.trim();

          if (kodepos.length() > 0)
          {
            jalurCache[jalurCount++] = {jalurKey, kodepos};
          }

          if (comma == -1)
            break;
          start = comma + 1;
        }
      }
    }
    json.iteratorEnd();
    Serial.printf("[CACHE] Total jalur cached: %d\n", jalurCount);
  }
  else
  {
    Serial.println("[CACHE] Gagal mengambil data jalur!");
  }
}

void cacheRFIDTagsFromFirebase()
{
  if (WiFi.status() != WL_CONNECTED)
  {
    Serial.println("[RFID_CACHE] WiFi putus!");
    return;
  }

  Serial.println("[RFID_CACHE] Mengambil mapping RFID tags...");
  String path = "/conveyor/rfid_tags";

  if (Firebase.getJSON(firebaseData, path))
  {
    FirebaseJson json = firebaseData.jsonObject();
    FirebaseJsonData jsonData;
    rfidCacheCount = 0;

    size_t len = json.iteratorBegin();
    for (size_t i = 0; i < len && rfidCacheCount < MAX_RFID_CACHE; i++)
    {
      int type;
      String epcKey, value;
      json.iteratorGet(i, type, epcKey, value);

      FirebaseJson tagJson;
      tagJson.setJsonData(value);

      String paketId = "";
      String kodepos = "";

      if (tagJson.get(jsonData, "paket_id"))
      {
        paketId = jsonData.to<String>();
      }
      if (tagJson.get(jsonData, "kodepos"))
      {
        kodepos = jsonData.to<String>();
      }

      // Jika kodepos kosong, lookup dari data paket
      if (kodepos.length() == 0 && paketId.length() > 0)
      {
        firebaseData.clear();
        String paketPath = "/conveyor/paket/" + paketId + "/kode_pos";
        if (Firebase.getString(firebaseData, paketPath))
        {
          kodepos = firebaseData.stringData();
          Serial.printf("[RFID_CACHE] Kodepos dari paket: %s\n", kodepos.c_str());
        }
      }

      if (epcKey.length() > 0 && paketId.length() > 0)
      {
        rfidCache[rfidCacheCount++] = {epcKey, paketId, kodepos};
        Serial.printf("[RFID_CACHE] EPC: %s -> Paket: %s (Kodepos: %s)\n",
                      epcKey.c_str(), paketId.c_str(), kodepos.c_str());
      }
    }
    json.iteratorEnd();
    Serial.printf("[RFID_CACHE] Total RFID tags cached: %d\n", rfidCacheCount);
  }
  else
  {
    Serial.printf("[RFID_CACHE] Gagal: %s\n", firebaseData.errorReason().c_str());
  }
}

String getJalurByKodepos(const String &kodepos)
{
  for (int i = 0; i < jalurCount; i++)
  {
    if (jalurCache[i].kodepos == kodepos)
    {
      return jalurCache[i].jalur;
    }
  }
  return "";
}

// Lookup paket info from RFID EPC
bool lookupRFIDTag(const String &epc, String *paketId, String *kodepos)
{
  for (int i = 0; i < rfidCacheCount; i++)
  {
    if (rfidCache[i].epc == epc)
    {
      *paketId = rfidCache[i].paketId;
      *kodepos = rfidCache[i].kodepos;
      return true;
    }
  }
  return false;
}

// ==================== Firebase Functions ====================
void queueLog(FirebaseJson &logJson)
{
  int nextTail = (logQueueTail + 1) % MAX_LOG_QUEUE;
  if (nextTail != logQueueHead)
  {
    logQueue[logQueueTail] = logJson.raw();
    logQueueTail = nextTail;
  }
}

void flushLogQueue()
{
  while (logQueueHead != logQueueTail && wifiConnected)
  {
    String logPath = "/conveyor/esp32_log/" + ESP32_ID + "/" + String(millis());
    FirebaseJson logJson;
    logJson.setJsonData(logQueue[logQueueHead]);
    Firebase.setJSON(firebaseData, logPath, logJson);
    logQueueHead = (logQueueHead + 1) % MAX_LOG_QUEUE;
  }
}

void queueUpdatePaket(const PaketUpdate &update)
{
  int nextTail = (updateQueueTail + 1) % MAX_UPDATE_QUEUE;
  if (nextTail != updateQueueHead)
  {
    updateQueue[updateQueueTail] = update;
    updateQueueTail = nextTail;
  }
}

void flushUpdateQueue()
{
  while (updateQueueHead != updateQueueTail && wifiConnected)
  {
    PaketUpdate upd = updateQueue[updateQueueHead];
    updatePaketStatus(upd.paketId, upd.status, upd.jalur,
                      upd.waktuSortir, upd.damage, upd.method, upd.rfidEpc);
    updateQueueHead = (updateQueueHead + 1) % MAX_UPDATE_QUEUE;
  }
}

void logESP32(const String &aksi, const String &paketId,
              const String &status, const String &result, ScanMethod method)
{
  FirebaseJson logJson;
  logJson.set("waktu", getTimeString());
  logJson.set("aksi", aksi);
  logJson.set("paket_id", paketId);
  logJson.set("status", status);
  logJson.set("result", result);
  logJson.set("scan_method", scanMethodToString(method));

  if (wifiConnected)
  {
    String logPath = "/conveyor/esp32_log/" + ESP32_ID + "/" + String(millis());
    Firebase.setJSON(firebaseData, logPath, logJson);
  }
  else
  {
    queueLog(logJson);
  }
}

void addSortirLog(const String &paketId, const String &status,
                  const String &jalur, const String &waktuSortir,
                  const String &damage, ScanMethod method)
{
  String path = "/conveyor/paket/" + paketId + "/sortir_log";
  FirebaseJson logEntry;
  logEntry.set("waktu", waktuSortir);
  logEntry.set("dcName", dcName);
  logEntry.set("status", status);
  logEntry.set("damage", damage);
  logEntry.set("jalur", jalur);
  logEntry.set("scan_method", scanMethodToString(method));

  Firebase.pushJSON(firebaseData, path, logEntry);
}

bool updatePaketStatus(const String &paketId, const String &status,
                       const String &jalur, const String &waktuSortir,
                       const String &damage, ScanMethod method,
                       const String &rfidEpc)
{
  FirebaseJson updateJson;
  updateJson.set("status", status);
  if (jalur != "")
    updateJson.set("jalur", jalur);
  if (waktuSortir != "")
    updateJson.set("waktu_sortir", waktuSortir);
  if (damage != "")
    updateJson.set("damage", damage);
  updateJson.set("esp32_id", ESP32_ID);
  updateJson.set("dcName", dcName);
  updateJson.set("updated_at", getTimeString());

  // Tambahkan info metode scan
  if (method != SCAN_NONE)
  {
    updateJson.set("scan_method", scanMethodToString(method));
  }
  if (rfidEpc != "")
  {
    updateJson.set("rfid_epc", rfidEpc);
  }

  if (wifiConnected)
  {
    String path = "/conveyor/paket/" + paketId;
    bool ok = Firebase.updateNode(firebaseData, path, updateJson);

    Serial.printf("[UPDATE] Paket: %s, Status: %s, Method: %s, Result: %s\n",
                  paketId.c_str(), status.c_str(), scanMethodToString(method).c_str(),
                  ok ? "success" : "fail");

    logESP32("update_status", paketId, status, ok ? "success" : "fail", method);

    if (status == "Tersortir")
    {
      addSortirLog(paketId, status, jalur, waktuSortir, damage, method);
    }

    return ok;
  }
  else
  {
    PaketUpdate update;
    memset(&update, 0, sizeof(update));
    strncpy(update.paketId, paketId.c_str(), MAX_PAKET_LEN - 1);
    strncpy(update.status, status.c_str(), MAX_STATUS_LEN - 1);
    strncpy(update.jalur, jalur.c_str(), MAX_JALUR_LEN - 1);
    strncpy(update.waktuSortir, waktuSortir.c_str(), MAX_TIME_LEN - 1);
    strncpy(update.damage, damage.c_str(), MAX_DAMAGE_LEN - 1);
    update.method = method;
    strncpy(update.rfidEpc, rfidEpc.c_str(), MAX_EPC_LEN - 1);
    queueUpdatePaket(update);
    return false;
  }
}

void requestDamageDetection(const String &paketId)
{
  if (wifiConnected)
  {
    FirebaseJson detectionRequest;
    detectionRequest.set("needs_damage_detection", true);
    detectionRequest.set("detection_requested_at", getTimeString());
    detectionRequest.set("dcName", dcName);
    detectionRequest.set("esp32_id", ESP32_ID);

    String path = "/conveyor/paket/" + paketId;
    Firebase.updateNode(firebaseData, path, detectionRequest);
  }
}

void sendHeartbeat()
{
  if (wifiConnected)
  {
    String path = "/conveyor/esp32_status/" + ESP32_ID;
    FirebaseJson statusJson;
    statusJson.set("last_seen", getTimeString());
    statusJson.set("ip", WiFi.localIP().toString());
    statusJson.set("ssid", WiFi.SSID());
    statusJson.set("dcName", dcName);
    statusJson.set("rfid_enabled", true); // Indikator RFID aktif
    time_t now = time(nullptr);
    statusJson.set("last_seen_epoch", (long)now);

    Firebase.setJSON(firebaseData, path, statusJson);
  }
}

// ==================== Servo Control ====================
void controlServo(Servo &servo, int position, int initialPosition, bool &flag)
{
  if (flag)
    return;
  flag = true;
  servo.write(position);
  vTaskDelay(pdMS_TO_TICKS(SERVO_ACTIVE_MS));
  servo.write(initialPosition);
  flag = false;
}

// ==================== Scanner Task (Hybrid Barcode + RFID) ====================
void scannerTask(void *param)
{
  unsigned long lastDotPrint = 0;
  unsigned long lastUnknownLog = 0;
  String lastUnknownEpc = "";
  bool rfidScanActive = false;

#define UNKNOWN_LOG_COOLDOWN_MS 10000  // Log unknown EPC max 1x per 10 detik
#define DOT_PRINT_INTERVAL_MS   300    // Print dot tiap 500ms saat scanning

  Serial.println("[SCANNER] Task started - Hybrid Barcode + RFID mode");

  while (true)
  {
    // Cooldown setelah scan sukses
    if (millis() - lastScanTime < SCAN_COOLDOWN_MS)
    {
      vTaskDelay(pdMS_TO_TICKS(100));
      continue;
    }

    ScanResult result;
    memset(&result, 0, sizeof(result));
    result.method = SCAN_NONE;

    // ========== STEP 1: Try Barcode First ==========
    if (SerialGM66.available())
    {
      String rawData = SerialGM66.readString();
      rawData.trim();

      // Clean non-printable characters
      String cleanData = "";
      for (int i = 0; i < (int)rawData.length(); i++)
      {
        char c = rawData.charAt(i);
        if (c >= 32 && c <= 126)
          cleanData += c;
      }

      if (cleanData.length() > 0)
      {
        // Parse barcode format: PaketId|Kodepos
        int delim = cleanData.indexOf('|');
        if (delim != -1)
        {
          result.success = true;
          strncpy(result.paketId, cleanData.substring(0, delim).c_str(), MAX_PAKET_LEN - 1);
          strncpy(result.kodepos, cleanData.substring(delim + 1).c_str(), MAX_KODEPOS_LEN - 1);
          result.method = SCAN_BARCODE;

          Serial.printf("\n[BARCODE] Scan OK: %s | Kodepos: %s\n",
                        result.paketId, result.kodepos);
        }
        else
        {
          Serial.printf("\n[BARCODE] Format tidak valid: %s\n", cleanData.c_str());
        }
      }
    }

    // ========== STEP 2: If Barcode Failed, Try RFID ==========
    if (!result.success)
    {
#if USE_ULTRASONIC
      float distance = readDistance();
      bool objectDetected = (distance > 0 && distance < 30);
#else
      bool objectDetected = true;  // No ultrasonic: always try RFID
#endif

      if (objectDetected)
      {
        // Print dot indikator scanning (tanpa newline, tiap 500ms)
        if (millis() - lastDotPrint >= DOT_PRINT_INTERVAL_MS)
        {
          if (!rfidScanActive)
          {
#if USE_ULTRASONIC
            Serial.printf("\n[RFID] Objek %.1f cm, scanning", readDistance());
#else
            Serial.print("\n[RFID] Scanning");
#endif
            rfidScanActive = true;
          }
          else
          {
            Serial.print(".");
          }
          lastDotPrint = millis();
        }

        vTaskDelay(pdMS_TO_TICKS(RFID_FALLBACK_DELAY_MS));

        UHFTag tag;
        if (uhfReader.singleInventory(&tag))
        {
          String epc = tag.getEPCString();

          // Lookup EPC in cache
          String paketId, kodepos;
          if (lookupRFIDTag(epc, &paketId, &kodepos))
          {
            result.success = true;
            strncpy(result.paketId, paketId.c_str(), MAX_PAKET_LEN - 1);
            strncpy(result.kodepos, kodepos.c_str(), MAX_KODEPOS_LEN - 1);
            result.method = SCAN_RFID;
            strncpy(result.rfidEpc, epc.c_str(), MAX_EPC_LEN - 1);

            Serial.printf("\n[RFID] OK! EPC: %s -> Paket: %s, Kodepos: %s\n",
                          epc.c_str(), paketId.c_str(), kodepos.c_str());
            rfidScanActive = false;
          }
          else
          {
            // EPC tidak ada di cache - log saja, JANGAN panggil Firebase dari scanner task
            // (Firebase SSL operations terlalu berat, menyebabkan WDT crash)
            bool isNewEpc = (epc != lastUnknownEpc);
            bool cooldownExpired = (millis() - lastUnknownLog > UNKNOWN_LOG_COOLDOWN_MS);

            if (isNewEpc || cooldownExpired)
            {
              Serial.printf("\n[RFID] EPC tidak terdaftar: %s\n", epc.c_str());
              lastUnknownEpc = epc;
              lastUnknownLog = millis();
            }
          }
        }
      }
      else
      {
        // Tidak ada objek - reset indikator scanning
        if (rfidScanActive)
        {
          Serial.println();
          rfidScanActive = false;
        }
      }
    }

    // ========== STEP 3: Send to Queue if Successful ==========
    if (result.success)
    {
      // Cek apakah paket ini sedang diproses (cegah duplicate scan)
      bool isDuplicate = false;
      if (xSemaphoreTake(processingMutex, pdMS_TO_TICKS(50)) == pdTRUE)
      {
        if (isProcessingPaket(String(result.paketId)))
        {
          isDuplicate = true;
          Serial.printf("[SCANNER] Paket %s masih diproses, skip.\n", result.paketId);
        }
        xSemaphoreGive(processingMutex);
      }

      if (!isDuplicate)
      {
        rfidScanActive = false;
        lastScanTime = millis();
        xQueueSend(scanQueue, &result, portMAX_DELAY);
      }
    }

    vTaskDelay(pdMS_TO_TICKS(100)); // Yield CPU to prevent WDT
  }
}

// ==================== Firebase Task ====================
void firebaseTask(void *param)
{
  ScanResult scanResult;

  while (true)
  {
    // Check WiFi connection
    if (WiFi.status() != WL_CONNECTED)
    {
      if (wifiConnected)
      {
        wifiConnected = false;
        Serial.println("[WIFI] Disconnected!");
      }
      vTaskDelay(pdMS_TO_TICKS(1000));
      continue;
    }
    else if (!wifiConnected)
    {
      wifiConnected = true;
      Serial.println("[WIFI] Reconnected! Flushing queues...");
      flushUpdateQueue();
      flushLogQueue();
    }

    // Process scan results
    if (xQueueReceive(scanQueue, &scanResult, pdMS_TO_TICKS(100)))
    {
      String jalur = getJalurByKodepos(String(scanResult.kodepos));

      Serial.printf("[FIREBASE] Processing: Paket=%s, Kodepos=%s, Jalur=%s, Method=%s\n",
                    scanResult.paketId, scanResult.kodepos,
                    jalur.c_str(), scanMethodToString(scanResult.method).c_str());

      if (jalur != "")
      {
        // Set guard: paket sedang diproses
        if (xSemaphoreTake(processingMutex, portMAX_DELAY) == pdTRUE)
        {
          addProcessingPaket(String(scanResult.paketId));
          xSemaphoreGive(processingMutex);
        }

        // Update status ke Proses
        updatePaketStatus(String(scanResult.paketId), "Proses", jalur, "", "",
                          scanResult.method, String(scanResult.rfidEpc));

        // Request damage detection
        requestDamageDetection(String(scanResult.paketId));

        // Send to control task
        ControlData ctrlData;
        memset(&ctrlData, 0, sizeof(ctrlData));
        strncpy(ctrlData.jalur, jalur.c_str(), MAX_JALUR_LEN - 1);
        strncpy(ctrlData.paketId, scanResult.paketId, MAX_PAKET_LEN - 1);
        ctrlData.method = scanResult.method;
        strncpy(ctrlData.rfidEpc, scanResult.rfidEpc, MAX_EPC_LEN - 1);
        xQueueSend(controlQueue, &ctrlData, portMAX_DELAY);

        logESP32("scan", String(scanResult.paketId), "Proses", "found", scanResult.method);
      }
      else
      {
        Serial.printf("[FIREBASE] Kodepos %s tidak ditemukan di cache jalur!\n",
                      scanResult.kodepos);
        logESP32("scan", String(scanResult.paketId), "-", "kodepos_not_found", scanResult.method);
      }
    }

    // Process status updates from control task (non-blocking drain)
    PaketUpdate statusUpd;
    while (xQueueReceive(statusUpdateQueue, &statusUpd, pdMS_TO_TICKS(0)))
    {
      if (wifiConnected)
      {
        updatePaketStatus(String(statusUpd.paketId), String(statusUpd.status), String(statusUpd.jalur),
                          String(statusUpd.waktuSortir), String(statusUpd.damage), statusUpd.method,
                          String(statusUpd.rfidEpc));
      }

      // Clear processing guard
      if (xSemaphoreTake(processingMutex, portMAX_DELAY) == pdTRUE)
      {
        removeProcessingPaket(String(statusUpd.paketId));
        xSemaphoreGive(processingMutex);
      }
    }

    // Heartbeat (moved here for thread safety - all Firebase on one task)
    if (millis() - lastHeartbeat > HEARTBEAT_INTERVAL_MS)
    {
      sendHeartbeat();
      lastHeartbeat = millis();
    }

    vTaskDelay(pdMS_TO_TICKS(10));
  }
}

// ==================== Control Task ====================
// ==================== Control Task (Non-blocking Pipeline) ====================
void controlTask(void *param)
{
  ServoState s1;
  memset(&s1, 0, sizeof(s1));
  s1.method = SCAN_NONE;
  ServoState s2;
  memset(&s2, 0, sizeof(s2));
  s2.method = SCAN_NONE;

  // Pending items per servo (when servo is busy, buffer 1 item)
  ControlData pendingJ1, pendingJ2;
  bool hasPendingJ1 = false, hasPendingJ2 = false;

  while (true)
  {
    unsigned long now = millis();

    // === Check Servo 1 timeout → return to neutral ===
    if (s1.active && (now - s1.activeSince >= SERVO_ACTIVE_MS))
    {
      servo1.write(90); // Neutral position
      s1.active = false;

      // Send "Tersortir" to Firebase task via queue
      PaketUpdate statusUpd;
      memset(&statusUpd, 0, sizeof(statusUpd));
      strncpy(statusUpd.paketId, s1.paketId, MAX_PAKET_LEN - 1);
      strncpy(statusUpd.status, "Tersortir", MAX_STATUS_LEN - 1);
      strncpy(statusUpd.jalur, s1.jalur, MAX_JALUR_LEN - 1);
      strncpy(statusUpd.waktuSortir, getTimeString().c_str(), MAX_TIME_LEN - 1);
      statusUpd.method = s1.method;
      strncpy(statusUpd.rfidEpc, s1.rfidEpc, MAX_EPC_LEN - 1);
      xQueueSend(statusUpdateQueue, &statusUpd, pdMS_TO_TICKS(100));

      Serial.printf("[CONTROL] Servo 1 selesai - %s -> %s\n",
                    s1.paketId, s1.jalur);

      // Immediately process pending item for this servo
      if (hasPendingJ1)
      {
        servo1.write(160);
        s1.active = true;
        s1.activeSince = millis();
        strncpy(s1.paketId, pendingJ1.paketId, MAX_PAKET_LEN - 1);
        s1.paketId[MAX_PAKET_LEN - 1] = '\0';
        strncpy(s1.jalur, pendingJ1.jalur, MAX_JALUR_LEN - 1);
        s1.jalur[MAX_JALUR_LEN - 1] = '\0';
        s1.method = pendingJ1.method;
        strncpy(s1.rfidEpc, pendingJ1.rfidEpc, MAX_EPC_LEN - 1);
        s1.rfidEpc[MAX_EPC_LEN - 1] = '\0';
        hasPendingJ1 = false;
        Serial.printf("[CONTROL] Servo 1 -> antrian: %s\n", s1.paketId);
      }
    }

    // === Check Servo 2 timeout → return to neutral ===
    if (s2.active && (now - s2.activeSince >= SERVO_ACTIVE_MS))
    {
      servo2.write(110); // Neutral position
      s2.active = false;

      PaketUpdate statusUpd;
      memset(&statusUpd, 0, sizeof(statusUpd));
      strncpy(statusUpd.paketId, s2.paketId, MAX_PAKET_LEN - 1);
      strncpy(statusUpd.status, "Tersortir", MAX_STATUS_LEN - 1);
      strncpy(statusUpd.jalur, s2.jalur, MAX_JALUR_LEN - 1);
      strncpy(statusUpd.waktuSortir, getTimeString().c_str(), MAX_TIME_LEN - 1);
      statusUpd.method = s2.method;
      strncpy(statusUpd.rfidEpc, s2.rfidEpc, MAX_EPC_LEN - 1);
      xQueueSend(statusUpdateQueue, &statusUpd, pdMS_TO_TICKS(100));

      Serial.printf("[CONTROL] Servo 2 selesai - %s -> %s\n",
                    s2.paketId, s2.jalur);

      if (hasPendingJ2)
      {
        servo2.write(40);
        s2.active = true;
        s2.activeSince = millis();
        strncpy(s2.paketId, pendingJ2.paketId, MAX_PAKET_LEN - 1);
        s2.paketId[MAX_PAKET_LEN - 1] = '\0';
        strncpy(s2.jalur, pendingJ2.jalur, MAX_JALUR_LEN - 1);
        s2.jalur[MAX_JALUR_LEN - 1] = '\0';
        s2.method = pendingJ2.method;
        strncpy(s2.rfidEpc, pendingJ2.rfidEpc, MAX_EPC_LEN - 1);
        s2.rfidEpc[MAX_EPC_LEN - 1] = '\0';
        hasPendingJ2 = false;
        Serial.printf("[CONTROL] Servo 2 -> antrian: %s\n", s2.paketId);
      }
    }

    // === Receive new control commands (non-blocking) ===
    ControlData ctrlData;
    if (xQueueReceive(controlQueue, &ctrlData, pdMS_TO_TICKS(20)))
    {
      Serial.printf("[CONTROL] Paket %s -> %s (via %s)\n",
                    ctrlData.paketId, ctrlData.jalur,
                    scanMethodToString(ctrlData.method).c_str());

      bool proceed = true;

#if USE_ULTRASONIC
      // Brief ultrasonic detection (max 2s)
      bool detected = false;
      unsigned long detectStart = millis();
      while ((millis() - detectStart) < 2000)
      {
        float dist = readDistance();
        if (dist > 0 && dist < 9)
        {
          detected = true;
          Serial.printf("[CONTROL] Paket terdeteksi di %.1f cm\n", dist);
          break;
        }
        vTaskDelay(pdMS_TO_TICKS(50));
      }
      if (!detected)
      {
        PaketUpdate statusUpd;
        memset(&statusUpd, 0, sizeof(statusUpd));
        strncpy(statusUpd.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
        strncpy(statusUpd.status, "Gagal", MAX_STATUS_LEN - 1);
        strncpy(statusUpd.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
        statusUpd.method = ctrlData.method;
        strncpy(statusUpd.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
        xQueueSend(statusUpdateQueue, &statusUpd, pdMS_TO_TICKS(100));
        Serial.printf("[CONTROL] %s timeout - objek tidak terdeteksi\n",
                      ctrlData.paketId);
        proceed = false;
      }
#endif

      if (proceed)
      {
        // Assign to appropriate servo
        if (strcmp(ctrlData.jalur, "Jalur 1") == 0)
        {
          if (!s1.active)
          {
            servo1.write(160);
            s1.active = true;
            s1.activeSince = millis();
            strncpy(s1.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
            s1.paketId[MAX_PAKET_LEN - 1] = '\0';
            strncpy(s1.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
            s1.jalur[MAX_JALUR_LEN - 1] = '\0';
            s1.method = ctrlData.method;
            strncpy(s1.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
            s1.rfidEpc[MAX_EPC_LEN - 1] = '\0';
            Serial.printf("[CONTROL] Servo 1 aktif -> %s\n", ctrlData.paketId);
          }
          else if (!hasPendingJ1)
          {
            pendingJ1 = ctrlData;
            hasPendingJ1 = true;
            Serial.printf("[CONTROL] Servo 1 sibuk, %s antri\n", ctrlData.paketId);
          }
          else
          {
            // Active + pending full → reject
            PaketUpdate statusUpd;
            memset(&statusUpd, 0, sizeof(statusUpd));
            strncpy(statusUpd.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
            strncpy(statusUpd.status, "Gagal", MAX_STATUS_LEN - 1);
            strncpy(statusUpd.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
            statusUpd.method = ctrlData.method;
            strncpy(statusUpd.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
            xQueueSend(statusUpdateQueue, &statusUpd, pdMS_TO_TICKS(100));
            Serial.printf("[CONTROL] Servo 1 penuh, %s ditolak\n", ctrlData.paketId);
          }
        }
        else if (strcmp(ctrlData.jalur, "Jalur 2") == 0)
        {
          if (!s2.active)
          {
            servo2.write(40);
            s2.active = true;
            s2.activeSince = millis();
            strncpy(s2.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
            s2.paketId[MAX_PAKET_LEN - 1] = '\0';
            strncpy(s2.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
            s2.jalur[MAX_JALUR_LEN - 1] = '\0';
            s2.method = ctrlData.method;
            strncpy(s2.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
            s2.rfidEpc[MAX_EPC_LEN - 1] = '\0';
            Serial.printf("[CONTROL] Servo 2 aktif -> %s\n", ctrlData.paketId);
          }
          else if (!hasPendingJ2)
          {
            pendingJ2 = ctrlData;
            hasPendingJ2 = true;
            Serial.printf("[CONTROL] Servo 2 sibuk, %s antri\n", ctrlData.paketId);
          }
          else
          {
            PaketUpdate statusUpd;
            memset(&statusUpd, 0, sizeof(statusUpd));
            strncpy(statusUpd.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
            strncpy(statusUpd.status, "Gagal", MAX_STATUS_LEN - 1);
            strncpy(statusUpd.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
            statusUpd.method = ctrlData.method;
            strncpy(statusUpd.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
            xQueueSend(statusUpdateQueue, &statusUpd, pdMS_TO_TICKS(100));
            Serial.printf("[CONTROL] Servo 2 penuh, %s ditolak\n", ctrlData.paketId);
          }
        }
      }
    }

    vTaskDelay(pdMS_TO_TICKS(20)); // Fast loop for responsive servo timing
  }
}

// ==================== Setup ====================
void setup()
{
  Serial.begin(115200);
  Serial.println("\n========================================");
  Serial.println("  ESP32 Conveyor - Hybrid Scanner v2.0");
  Serial.println("  Barcode (GM66) + UHF RFID (EL-UHF-RMT01)");
  Serial.println("========================================\n");

  // Initialize GPIO
#if USE_ULTRASONIC
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
#endif

  // Initialize Servos
  servo1.attach(SERVO1_PIN);
  servo1.write(90);
  servo2.attach(SERVO2_PIN);
  servo2.write(110);

  // Initialize GM66 Barcode Scanner
  SerialGM66.begin(9600, SERIAL_8N1, GM66_RX_PIN, GM66_TX_PIN);
  Serial.println("[GM66] Barcode scanner initialized");

  // Initialize UHF RFID Reader
  Serial.println("[UHF] Initializing RFID reader...");
  uhfReader.setDebug(true); // Enable debug untuk troubleshooting awal

  if (uhfReader.begin(UHF_RX_PIN, UHF_TX_PIN, 115200))
  {
    Serial.println("[UHF] RFID reader connected!");

    // Configure RFID module
    uhfReader.setRegion(REGION_920MHZ); // Indonesia
    uhfReader.setPower(300);            // 5 dBm (jarak baca ~25-30 cm)
    uhfReader.setFrequencyHopping(true);

    Serial.printf("[UHF] Region: 920MHz, Power: %.1f dBm\n",
                  uhfReader.getPower() / 100.0);
  }
  else
  {
    Serial.println("[UHF] WARNING: RFID reader not responding!");
    Serial.println("[UHF] System will continue with barcode only");
  }

  uhfReader.setDebug(false); // Disable debug setelah init

  // Load saved dcName from preferences
  preferences.begin("conveyor", false);
  dcName = preferences.getString("dcName", "-");
  preferences.end();

  // Initialize WiFi with WiFiManager
  WiFiManager wm;
  wm.addParameter(&custom_dc);

  Serial.println("[WIFI] Starting WiFiManager...");
  bool res = wm.autoConnect("ESP32-Conveyor-Setup");
  wifiConnected = res;

  if (!res)
  {
    Serial.println("[WIFI] Failed to connect, restarting...");
    ESP.restart();
  }

  Serial.println("[WIFI] Connected!");

  // Set explicit DNS servers (Google DNS) to fix DNS resolution issues
  // especially on mobile hotspot / tethered connections
  IPAddress dns1(8, 8, 8, 8);
  IPAddress dns2(8, 8, 4, 4);
  WiFi.config(WiFi.localIP(), WiFi.gatewayIP(), WiFi.subnetMask(), dns1, dns2);
  Serial.printf("[WIFI] DNS set to 8.8.8.8 / 8.8.4.4\n");

  ESP32_ID = WiFi.macAddress();

  // Update dcName if changed via WiFiManager
  String newDC = String(custom_dc.getValue());
  if (newDC.length() > 0 && newDC != dcName)
  {
    dcName = newDC;
    preferences.begin("conveyor", false);
    preferences.putString("dcName", dcName);
    preferences.end();
  }
  if (dcName.length() == 0)
    dcName = "-";

  Serial.printf("[ESP32] Device ID: %s\n", ESP32_ID.c_str());
  Serial.printf("[ESP32] DC Name: %s\n", dcName.c_str());

  // Initialize time
  initializeTime();

  // Initialize Firebase
  firebaseConfig.host = FIREBASE_HOST;
  firebaseConfig.signer.tokens.legacy_token = FIREBASE_AUTH;
  Firebase.begin(&firebaseConfig, &firebaseAuth);
  Firebase.reconnectWiFi(true);

  // Cache data from Firebase
  cacheJalurFromFirebase();
  firebaseData.clear();  // Reset SSL connection sebelum request berikutnya
  delay(100);
  cacheRFIDTagsFromFirebase();

  // Create queues & mutex
  scanQueue = xQueueCreate(10, sizeof(ScanResult));
  controlQueue = xQueueCreate(10, sizeof(ControlData));
  statusUpdateQueue = xQueueCreate(10, sizeof(PaketUpdate));
  processingMutex = xSemaphoreCreateMutex();

  // Create tasks
  xTaskCreatePinnedToCore(scannerTask, "Scanner", 8192, NULL, 2, NULL, 0);
  xTaskCreatePinnedToCore(firebaseTask, "Firebase", 8192, NULL, 1, NULL, 0);
  xTaskCreatePinnedToCore(controlTask, "Control", 16384, NULL, 1, NULL, 1);

  Serial.println("\n[SYSTEM] Setup complete! Ready for scanning...\n");
}

// ==================== Loop ====================
void loop()
{
  // All work done in FreeRTOS tasks (scanner, firebase, control)
  // Heartbeat moved to Firebase task for thread safety
  delay(1000);
}
