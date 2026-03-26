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
#include <lwip/dns.h>
#include <lwip/ip_addr.h>
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

// Servo Angle / Motion Tuning
// Mekanik saat ini:
// - HOLD = posisi menahan paket
// - THROW = kembali ke posisi semula (NEUTRAL/OPEN)
// Pola baru: sebelum THROW lakukan PREALIGN (membuka perlahan mendekati posisi semula), lalu OPEN cepat (kick)
#define SERVO1_HOLD_ANGLE 20
#define SERVO1_PREALIGN_ANGLE 45
#define SERVO1_NEUTRAL_ANGLE 90
#define SERVO1_OPEN_OVERSHOOT_ANGLE 100

#define SERVO2_HOLD_ANGLE 40
#define SERVO2_PREALIGN_ANGLE 65
#define SERVO2_NEUTRAL_ANGLE 110
#define SERVO2_OPEN_OVERSHOOT_ANGLE 120

// Pre-align happens near the end of SERVO_ACTIVE_MS; total active duration remains SERVO_ACTIVE_MS
#define SERVO1_PREALIGN_LEAD_MS 600
#define SERVO2_PREALIGN_LEAD_MS 700
#define SERVO_PREALIGN_STEP_MS 20
#define SERVO_SETTLE_MS 80
#define SERVO1_THROW_DWELL_MS 250
#define SERVO2_THROW_DWELL_MS 300

// Pin Configuration - Ultrasonic Sensor (HC-SR04)
#define TRIG_PIN 13
#define ECHO_PIN 12

// Pin Configuration - Onboard LED indicator
// Most ESP32 DevKit boards use GPIO2 for the onboard LED.
#define READY_LED_PIN 2

// Ultrasonic sensor toggle (set to true when HC-SR04 is connected)
#define USE_ULTRASONIC false

// Timing Configuration
#define BARCODE_TIMEOUT_MS 3000     // Timeout menunggu barcode
#define RFID_FALLBACK_DELAY_MS 500  // Delay sebelum fallback ke RFID
#define ULTRASONIC_TIMEOUT_MS 6000  // Timeout deteksi paket
#define SERVO_ACTIVE_MS 3800        // Durasi servo aktif
#define SERVO1_MOVE_DELAY_MS 2000   // Delay sebelum Servo 1 bergerak setelah jalur ditentukan
#define SERVO2_MOVE_DELAY_MS 5500   // Delay sebelum Servo 2 bergerak setelah jalur ditentukan
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

// ==================== App Log Queue ====================
#define MAX_LOG_TAG_LEN 16
#define MAX_LOG_LEVEL_LEN 8
#define MAX_LOG_LINE_LEN 180

struct AppLogLine
{
  char level[MAX_LOG_LEVEL_LEN];
  char tag[MAX_LOG_TAG_LEN];
  char line[MAX_LOG_LINE_LEN];
  uint32_t uptimeMs;
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
QueueHandle_t appLogQueue;      // Queue untuk log lines (all tasks -> firebaseTask)

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
bool timeSynced = false;
bool initialCacheLoaded = false;
bool readyAnnounced = false;
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
  unsigned long endAt;
  unsigned long kickAt;
  unsigned long preAlignStartAt;

  bool preAlignStarted;
  bool preAlignDone;
  bool kicked;
  unsigned long settleUntil;
  unsigned long nextStepAt;
  int currentAngle;

  char paketId[MAX_PAKET_LEN];
  char jalur[MAX_JALUR_LEN];
  ScanMethod method;
  char rfidEpc[MAX_EPC_LEN];

  // Pre-move delay (non-blocking): servo menunggu dulu sebelum bergerak
  bool moveInitiated;
  unsigned long movePendingSince;
  unsigned long moveDelayMs;
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
bool initializeTime(uint32_t timeoutMs = 10000);
void cacheJalurFromFirebase();
void cacheRFIDTagsFromFirebase();
String getJalurByKodepos(const String &kodepos);
String getTimeString();
float readDistance();

static void configureDnsServers()
{
  ip_addr_t dns1;
  ip_addr_t dns2;
  ipaddr_aton("8.8.8.8", &dns1);
  ipaddr_aton("8.8.4.4", &dns2);
  dns_setserver(0, &dns1);
  dns_setserver(1, &dns2);
  Serial.println("[WIFI] DNS set to 8.8.8.8 / 8.8.4.4 (lwIP)");
}

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

static String sanitizeBarcodeString(String s)
{
  s.trim();

  // Keep only printable ASCII to avoid hidden chars
  String out;
  out.reserve(s.length());
  for (int i = 0; i < (int)s.length(); i++)
  {
    char c = s.charAt(i);
    if (c >= 32 && c <= 126)
      out += c;
  }

  out.trim();

  // If the scanner appends extra tokens/spaces, take the first token
  int sp = out.indexOf(' ');
  if (sp > 0)
    out = out.substring(0, sp);

  // Fix common duplication: "PKT-123PKT-123" -> "PKT-123"
  int n = out.length();
  if (n >= 8 && (n % 2) == 0)
  {
    int half = n / 2;
    if (out.substring(0, half) == out.substring(half))
      out = out.substring(0, half);
  }

  out.trim();
  return out;
}

static bool fetchKodeposByPaketId(const String &paketId, String *kodeposOut)
{
  if (!kodeposOut)
    return false;
  *kodeposOut = "";

  if (WiFi.status() != WL_CONNECTED)
    return false;

  // Prefer direct child reads (lighter than getJSON)
  firebaseData.clear();
  String path1 = "/conveyor/paket/" + paketId + "/kode_pos";
  if (Firebase.getString(firebaseData, path1))
  {
    *kodeposOut = firebaseData.stringData();
    kodeposOut->trim();
    if (kodeposOut->length() > 0)
      return true;
  }

  firebaseData.clear();
  String path2 = "/conveyor/paket/" + paketId + "/kodepos";
  if (Firebase.getString(firebaseData, path2))
  {
    *kodeposOut = firebaseData.stringData();
    kodeposOut->trim();
    if (kodeposOut->length() > 0)
      return true;
  }

  // Fallback: read entire paket object and try multiple keys
  firebaseData.clear();
  String fbPath = "/conveyor/paket/" + paketId;
  if (Firebase.getJSON(firebaseData, fbPath))
  {
    String payload = firebaseData.payload();
    if (payload == "null")
      return false;

    FirebaseJson &json = firebaseData.jsonObject();
    FirebaseJsonData d;
    if (json.get(d, "kode_pos") && d.success)
    {
      *kodeposOut = d.to<String>();
      kodeposOut->trim();
      if (kodeposOut->length() > 0)
        return true;
    }
    if (json.get(d, "kodepos") && d.success)
    {
      *kodeposOut = d.to<String>();
      kodeposOut->trim();
      if (kodeposOut->length() > 0)
        return true;
    }
  }

  return false;
}

static void enqueueAppLogLine(const char *level, const char *tag, const String &line)
{
  if (!appLogQueue)
    return;

  AppLogLine item;
  memset(&item, 0, sizeof(item));
  item.uptimeMs = (uint32_t)millis();
  strncpy(item.level, level ? level : "I", MAX_LOG_LEVEL_LEN - 1);
  strncpy(item.tag, tag ? tag : "APP", MAX_LOG_TAG_LEN - 1);
  strncpy(item.line, line.c_str(), MAX_LOG_LINE_LEN - 1);

  // Non-blocking: drop if queue is full
  xQueueSend(appLogQueue, &item, 0);
}

static void signalDeviceReady()
{
  // Visible indicator without needing Serial Monitor.
  pinMode(READY_LED_PIN, OUTPUT);
  for (int i = 0; i < 3; i++)
  {
    digitalWrite(READY_LED_PIN, HIGH);
    delay(120);
    digitalWrite(READY_LED_PIN, LOW);
    delay(120);
  }
}

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

bool initializeTime(uint32_t timeoutMs)
{
  configTime(25200, 0, "pool.ntp.org", "time.google.com"); // UTC+7 (WIB)
  struct tm timeinfo;
  if (!getLocalTime(&timeinfo, timeoutMs)) // Wait up to timeout
  {
    Serial.println("[TIME] Failed to obtain time");
    return false;
  }
  else
  {
    Serial.println("[TIME] Time synchronized!");
    return true;
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
    bool ready = (wifiConnected && timeSynced && initialCacheLoaded);
    statusJson.set("ready", ready);
    statusJson.set("uptime_ms", (long)millis());
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
      String cleanData = sanitizeBarcodeString(rawData);

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
          enqueueAppLogLine("I", "BARCODE", String("[BARCODE] Scan OK: ") + result.paketId + " | Kodepos: " + result.kodepos);
        }
        else
        {
          // PaketId-only mode: let firebaseTask resolve kodepos to keep ALL Firebase calls single-threaded
          Serial.printf("\n[BARCODE] Scan OK (Hanya ID): '%s'. Kodepos akan diambil oleh Firebase task...\n", cleanData.c_str());
          enqueueAppLogLine("I", "BARCODE", String("[BARCODE] Scan OK (Hanya ID): '") + cleanData + "'. Kodepos akan diambil oleh Firebase task...");

          result.success = true;
          strncpy(result.paketId, cleanData.c_str(), MAX_PAKET_LEN - 1);
          result.kodepos[0] = '\0';
          result.method = SCAN_BARCODE;
        }
      }
    }

    // ========== STEP 2: Auto-Bind - If Barcode OK, also try RFID for binding ==========
    if (result.success && result.method == SCAN_BARCODE)
    {
      // Quick RFID scan attempt for auto-bind (non-blocking, 1 try)
      UHFTag bindTag;
      if (uhfReader.singleInventory(&bindTag))
      {
        String bindEpc = bindTag.getEPCString();
        if (bindEpc.length() > 0)
        {
          strncpy(result.rfidEpc, bindEpc.c_str(), MAX_EPC_LEN - 1);
          Serial.printf("[AUTO-BIND] Barcode OK + RFID tag terdeteksi: %s\n", bindEpc.c_str());
        }
      }
    }

    // ========== STEP 3: If Barcode Failed, Try RFID as identifier ==========
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
            enqueueAppLogLine("I", "RFID", String("[RFID] OK! EPC: ") + epc + " -> Paket: " + paketId + ", Kodepos: " + kodepos);
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
              enqueueAppLogLine("W", "RFID", String("[RFID] EPC tidak terdaftar: ") + epc);
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

    // ========== STEP 4: Send to Queue if Successful ==========
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
  unsigned long lastCacheAttempt = 0;

  while (true)
  {
    // Drain application log queue (all tasks -> here)
    AppLogLine logItem;
    while (appLogQueue && xQueueReceive(appLogQueue, &logItem, 0))
    {
      FirebaseJson logJson;
      logJson.set("waktu", getTimeString());
      logJson.set("aksi", "log");
      logJson.set("level", String(logItem.level));
      logJson.set("tag", String(logItem.tag));
      logJson.set("line", String(logItem.line));
      logJson.set("uptime_ms", (long)logItem.uptimeMs);
      logJson.set("dcName", dcName);
      logJson.set("esp32_id", ESP32_ID);

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

    // Check WiFi connection
    if (WiFi.status() != WL_CONNECTED)
    {
      if (wifiConnected)
      {
        wifiConnected = false;
        Serial.println("[WIFI] Disconnected!");
        enqueueAppLogLine("W", "WIFI", "[WIFI] Disconnected!");
      }
      vTaskDelay(pdMS_TO_TICKS(1000));
      continue;
    }
    else if (!wifiConnected)
    {
      wifiConnected = true;
      Serial.println("[WIFI] Reconnected! Flushing queues...");
      enqueueAppLogLine("I", "WIFI", "[WIFI] Reconnected! Flushing queues...");
      flushUpdateQueue();
      flushLogQueue();

      // Force recache after reconnect
      initialCacheLoaded = false;
      lastCacheAttempt = 0;
      readyAnnounced = false;
    }

    // Ensure time is synchronized before any TLS Firebase operations
    if (!timeSynced)
    {
      timeSynced = initializeTime();
      if (!timeSynced)
      {
        vTaskDelay(pdMS_TO_TICKS(1000));
        continue;
      }
    }

    // (Re)load cache if not loaded yet (throttled)
    if (!initialCacheLoaded)
    {
      unsigned long now = millis();
      if (now - lastCacheAttempt > 15000)
      {
        lastCacheAttempt = now;
        cacheJalurFromFirebase();
        firebaseData.clear();
        vTaskDelay(pdMS_TO_TICKS(100));
        cacheRFIDTagsFromFirebase();

        // Mark as loaded if we have at least jalur data
        if (jalurCount > 0)
        {
          initialCacheLoaded = true;
          Serial.printf("[CACHE] Loaded: jalur=%d, rfid=%d\n", jalurCount, rfidCacheCount);
          enqueueAppLogLine("I", "CACHE", "[CACHE] Loaded: jalur=" + String(jalurCount) + ", rfid=" + String(rfidCacheCount));
        }
      }
    }

    // Ready indicator (once): WiFi + time synced + cache loaded
    if (!readyAnnounced && wifiConnected && timeSynced && initialCacheLoaded)
    {
      readyAnnounced = true;
      Serial.println("[SYSTEM] READY - device siap dipakai");
      enqueueAppLogLine("I", "SYSTEM", "[SYSTEM] READY - device siap dipakai");
      signalDeviceReady();

      // Push an immediate heartbeat so UI updates fast
      sendHeartbeat();
      lastHeartbeat = millis();
    }

    // Process scan results
    if (xQueueReceive(scanQueue, &scanResult, pdMS_TO_TICKS(100)))
    {
      String kodeposStr = String(scanResult.kodepos);

      // Barcode PaketId-only: resolve kodepos here (single-threaded Firebase)
      if (scanResult.method == SCAN_BARCODE && kodeposStr.length() == 0)
      {
        Serial.printf("[BARCODE] Paket %s tanpa kodepos, fetching dari Firebase...\n", scanResult.paketId);
        String fetched;
        if (fetchKodeposByPaketId(String(scanResult.paketId), &fetched))
        {
          kodeposStr = fetched;
          Serial.printf("[BARCODE] Fetch kodepos OK -> %s\n", kodeposStr.c_str());
        }
        else
        {
          Serial.printf("[BARCODE] Gagal fetch kodepos untuk paket %s (pastikan field kode_pos/kodepos ada)\n", scanResult.paketId);
          logESP32("scan", String(scanResult.paketId), "-", "kodepos_missing", scanResult.method);
          vTaskDelay(pdMS_TO_TICKS(10));
          continue;
        }
      }

      String jalur = getJalurByKodepos(kodeposStr);

      Serial.printf("[FIREBASE] Processing: Paket=%s, Kodepos=%s, Jalur=%s, Method=%s\n",
                    scanResult.paketId, kodeposStr.c_str(),
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

        // ========== Auto-Bind: Barcode scan + RFID tag detected → bind EPC to paket ==========
        String epcStr = String(scanResult.rfidEpc);
        if (scanResult.method == SCAN_BARCODE && epcStr.length() > 0)
        {
          // Check if this EPC is already bound
          String existingPaket, existingKodepos;
          if (!lookupRFIDTag(epcStr, &existingPaket, &existingKodepos))
          {
            // New EPC → bind to this paket
            String paketIdStr = String(scanResult.paketId);
            // Use resolved kodepos (for PaketId-only barcode)
            String kodeposToBind = kodeposStr;

            // 1. Write to /conveyor/rfid_tags/{EPC}
            FirebaseJson rfidJson;
            rfidJson.set("paket_id", paketIdStr);
            rfidJson.set("kodepos", kodeposToBind);
            rfidJson.set("registered_at", getTimeString());
            rfidJson.set("registered_by", "auto-bind");

            String rfidPath = "/conveyor/rfid_tags/" + epcStr;
            bool ok = Firebase.setJSON(firebaseData, rfidPath, rfidJson);

            // 2. Update paket with rfid_epc field
            if (ok)
            {
              Firebase.setString(firebaseData, "/conveyor/paket/" + paketIdStr + "/rfid_epc", epcStr);
            }

            // 3. Update local cache
            if (ok && rfidCacheCount < MAX_RFID_CACHE)
            {
              rfidCache[rfidCacheCount++] = {epcStr, paketIdStr, kodeposToBind};
            }

            Serial.printf("[AUTO-BIND] %s: EPC %s -> Paket %s (kodepos: %s) %s\n",
                          ok ? "OK" : "GAGAL", epcStr.c_str(), paketIdStr.c_str(),
                          kodeposToBind.c_str(), ok ? "" : firebaseData.errorReason().c_str());

            logESP32("auto_bind", paketIdStr, "-", ok ? "success" : "fail", SCAN_RFID);
          }
          else
          {
            Serial.printf("[AUTO-BIND] EPC %s sudah terdaftar -> %s\n",
                          epcStr.c_str(), existingPaket.c_str());
          }
        }

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
  s1.moveDelayMs = SERVO1_MOVE_DELAY_MS;
  ServoState s2;
  memset(&s2, 0, sizeof(s2));
  s2.method = SCAN_NONE;
  s2.moveDelayMs = SERVO2_MOVE_DELAY_MS;

  // Pending items per servo (when servo is busy, buffer 1 item)
  ControlData pendingJ1, pendingJ2;
  bool hasPendingJ1 = false, hasPendingJ2 = false;

  while (true)
  {
    unsigned long now = millis();

    // === Start move after per-servo delay (non-blocking) ===
    if (s1.active && !s1.moveInitiated && (now - s1.movePendingSince >= s1.moveDelayMs))
    {
      servo1.write(SERVO1_HOLD_ANGLE);
      s1.moveInitiated = true;
      s1.activeSince = millis();
      s1.endAt = s1.activeSince + SERVO_ACTIVE_MS;
      s1.kickAt = (s1.endAt > SERVO1_THROW_DWELL_MS) ? (s1.endAt - SERVO1_THROW_DWELL_MS) : s1.endAt;
      s1.preAlignStartAt = (s1.kickAt > SERVO1_PREALIGN_LEAD_MS) ? (s1.kickAt - SERVO1_PREALIGN_LEAD_MS) : s1.activeSince;
      s1.preAlignStarted = false;
      s1.preAlignDone = false;
      s1.kicked = false;
      s1.settleUntil = 0;
      s1.nextStepAt = 0;
      s1.currentAngle = SERVO1_HOLD_ANGLE;
      Serial.printf("[CONTROL] Servo 1 bergerak setelah delay %lu ms -> %s\n", s1.moveDelayMs, s1.paketId);
      enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 bergerak setelah delay ") + String(s1.moveDelayMs) + " ms -> " + String(s1.paketId));
    }

    if (s2.active && !s2.moveInitiated && (now - s2.movePendingSince >= s2.moveDelayMs))
    {
      servo2.write(SERVO2_HOLD_ANGLE);
      s2.moveInitiated = true;
      s2.activeSince = millis();
      s2.endAt = s2.activeSince + SERVO_ACTIVE_MS;
      s2.kickAt = (s2.endAt > SERVO2_THROW_DWELL_MS) ? (s2.endAt - SERVO2_THROW_DWELL_MS) : s2.endAt;
      s2.preAlignStartAt = (s2.kickAt > SERVO2_PREALIGN_LEAD_MS) ? (s2.kickAt - SERVO2_PREALIGN_LEAD_MS) : s2.activeSince;
      s2.preAlignStarted = false;
      s2.preAlignDone = false;
      s2.kicked = false;
      s2.settleUntil = 0;
      s2.nextStepAt = 0;
      s2.currentAngle = SERVO2_HOLD_ANGLE;
      Serial.printf("[CONTROL] Servo 2 bergerak setelah delay %lu ms -> %s\n", s2.moveDelayMs, s2.paketId);
      enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 bergerak setelah delay ") + String(s2.moveDelayMs) + " ms -> " + String(s2.paketId));
    }

    // === Servo 1: pre-align slow close, then open kick near the end (non-blocking) ===
    if (s1.active && s1.moveInitiated)
    {
      if (!s1.preAlignStarted && now >= s1.preAlignStartAt)
      {
        s1.preAlignStarted = true;
        s1.nextStepAt = now;
        Serial.printf("[CONTROL] Servo 1 pre-align mulai -> %s\n", s1.paketId);
        enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 pre-align mulai -> ") + String(s1.paketId));
      }

      if (s1.preAlignStarted && !s1.preAlignDone && now >= s1.nextStepAt && now < s1.kickAt)
      {
        if (s1.currentAngle != SERVO1_PREALIGN_ANGLE)
        {
          s1.currentAngle += (s1.currentAngle < SERVO1_PREALIGN_ANGLE) ? 1 : -1;
          servo1.write(s1.currentAngle);
          s1.nextStepAt = now + SERVO_PREALIGN_STEP_MS;
        }
        else
        {
          s1.preAlignDone = true;
          s1.settleUntil = now + SERVO_SETTLE_MS;
        }
      }

      if (s1.preAlignDone && s1.settleUntil > 0 && now >= s1.settleUntil)
      {
        // Settled; wait for kickAt
        s1.settleUntil = 0;
      }

      if (!s1.kicked && now >= s1.kickAt)
      {
        int target = SERVO1_OPEN_OVERSHOOT_ANGLE;
        if (target < 0) target = 0;
        if (target > 180) target = 180;
        servo1.write(target);
        s1.kicked = true;
        Serial.printf("[CONTROL] Servo 1 buang (open kick) -> %s\n", s1.paketId);
        enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 buang (open kick) -> ") + String(s1.paketId));
      }
    }

    // === Servo 2: pre-align slow close, then open kick near the end (non-blocking) ===
    if (s2.active && s2.moveInitiated)
    {
      if (!s2.preAlignStarted && now >= s2.preAlignStartAt)
      {
        s2.preAlignStarted = true;
        s2.nextStepAt = now;
        Serial.printf("[CONTROL] Servo 2 pre-align mulai -> %s\n", s2.paketId);
        enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 pre-align mulai -> ") + String(s2.paketId));
      }

      if (s2.preAlignStarted && !s2.preAlignDone && now >= s2.nextStepAt && now < s2.kickAt)
      {
        if (s2.currentAngle != SERVO2_PREALIGN_ANGLE)
        {
          s2.currentAngle += (s2.currentAngle < SERVO2_PREALIGN_ANGLE) ? 1 : -1;
          servo2.write(s2.currentAngle);
          s2.nextStepAt = now + SERVO_PREALIGN_STEP_MS;
        }
        else
        {
          s2.preAlignDone = true;
          s2.settleUntil = now + SERVO_SETTLE_MS;
        }
      }

      if (s2.preAlignDone && s2.settleUntil > 0 && now >= s2.settleUntil)
      {
        s2.settleUntil = 0;
      }

      if (!s2.kicked && now >= s2.kickAt)
      {
        int target = SERVO2_OPEN_OVERSHOOT_ANGLE;
        if (target < 0) target = 0;
        if (target > 180) target = 180;
        servo2.write(target);
        s2.kicked = true;
        Serial.printf("[CONTROL] Servo 2 buang (open kick) -> %s\n", s2.paketId);
        enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 buang (open kick) -> ") + String(s2.paketId));
      }
    }

    // === Check Servo 1 end-of-cycle → return to neutral ===
    if (s1.active && s1.moveInitiated && (now >= s1.endAt))
    {
      servo1.write(SERVO1_NEUTRAL_ANGLE); // Neutral position
      s1.active = false;
      s1.moveInitiated = false;

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
      enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 selesai - ") + String(s1.paketId) + " -> " + String(s1.jalur));

      // Immediately process pending item for this servo
      if (hasPendingJ1)
      {
        s1.active = true;
        s1.moveInitiated = false;
        s1.movePendingSince = millis();
        s1.moveDelayMs = SERVO1_MOVE_DELAY_MS;
        s1.activeSince = 0;
        strncpy(s1.paketId, pendingJ1.paketId, MAX_PAKET_LEN - 1);
        s1.paketId[MAX_PAKET_LEN - 1] = '\0';
        strncpy(s1.jalur, pendingJ1.jalur, MAX_JALUR_LEN - 1);
        s1.jalur[MAX_JALUR_LEN - 1] = '\0';
        s1.method = pendingJ1.method;
        strncpy(s1.rfidEpc, pendingJ1.rfidEpc, MAX_EPC_LEN - 1);
        s1.rfidEpc[MAX_EPC_LEN - 1] = '\0';
        hasPendingJ1 = false;
        Serial.printf("[CONTROL] Servo 1 -> antrian: %s (delay %lu ms)\n", s1.paketId, s1.moveDelayMs);
        enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 -> antrian: ") + String(s1.paketId) + " (delay " + String(s1.moveDelayMs) + " ms)");
      }
    }

    // === Check Servo 2 end-of-cycle → return to neutral ===
    if (s2.active && s2.moveInitiated && (now >= s2.endAt))
    {
      servo2.write(SERVO2_NEUTRAL_ANGLE); // Neutral position
      s2.active = false;
      s2.moveInitiated = false;

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
      enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 selesai - ") + String(s2.paketId) + " -> " + String(s2.jalur));

      if (hasPendingJ2)
      {
        s2.active = true;
        s2.moveInitiated = false;
        s2.movePendingSince = millis();
        s2.moveDelayMs = SERVO2_MOVE_DELAY_MS;
        s2.activeSince = 0;
        strncpy(s2.paketId, pendingJ2.paketId, MAX_PAKET_LEN - 1);
        s2.paketId[MAX_PAKET_LEN - 1] = '\0';
        strncpy(s2.jalur, pendingJ2.jalur, MAX_JALUR_LEN - 1);
        s2.jalur[MAX_JALUR_LEN - 1] = '\0';
        s2.method = pendingJ2.method;
        strncpy(s2.rfidEpc, pendingJ2.rfidEpc, MAX_EPC_LEN - 1);
        s2.rfidEpc[MAX_EPC_LEN - 1] = '\0';
        hasPendingJ2 = false;
        Serial.printf("[CONTROL] Servo 2 -> antrian: %s (delay %lu ms)\n", s2.paketId, s2.moveDelayMs);
        enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 -> antrian: ") + String(s2.paketId) + " (delay " + String(s2.moveDelayMs) + " ms)");
      }
    }

    // === Receive new control commands (non-blocking) ===
    ControlData ctrlData;
    if (xQueueReceive(controlQueue, &ctrlData, pdMS_TO_TICKS(20)))
    {
      Serial.printf("[CONTROL] Paket %s -> %s (via %s)\n",
                    ctrlData.paketId, ctrlData.jalur,
                    scanMethodToString(ctrlData.method).c_str());
      enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Paket ") + ctrlData.paketId + " -> " + ctrlData.jalur + " (via " + scanMethodToString(ctrlData.method) + ")");

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
          enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Paket terdeteksi di ") + String(dist, 1) + " cm");
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
        enqueueAppLogLine("W", "CONTROL", String("[CONTROL] ") + ctrlData.paketId + " timeout - objek tidak terdeteksi");
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
            s1.active = true;
            s1.moveInitiated = false;
            s1.movePendingSince = millis();
            s1.moveDelayMs = SERVO1_MOVE_DELAY_MS;
            s1.activeSince = 0;
            strncpy(s1.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
            s1.paketId[MAX_PAKET_LEN - 1] = '\0';
            strncpy(s1.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
            s1.jalur[MAX_JALUR_LEN - 1] = '\0';
            s1.method = ctrlData.method;
            strncpy(s1.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
            s1.rfidEpc[MAX_EPC_LEN - 1] = '\0';
            Serial.printf("[CONTROL] Servo 1 pending (%lu ms) -> %s\n", s1.moveDelayMs, ctrlData.paketId);
            enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 pending (") + String(s1.moveDelayMs) + " ms) -> " + String(ctrlData.paketId));
          }
          else if (!hasPendingJ1)
          {
            pendingJ1 = ctrlData;
            hasPendingJ1 = true;
            Serial.printf("[CONTROL] Servo 1 sibuk, %s antri\n", ctrlData.paketId);
            enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 1 sibuk, ") + String(ctrlData.paketId) + " antri");
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
            enqueueAppLogLine("E", "CONTROL", String("[CONTROL] Servo 1 penuh, ") + String(ctrlData.paketId) + " ditolak");
          }
        }
        else if (strcmp(ctrlData.jalur, "Jalur 2") == 0)
        {
          if (!s2.active)
          {
            s2.active = true;
            s2.moveInitiated = false;
            s2.movePendingSince = millis();
            s2.moveDelayMs = SERVO2_MOVE_DELAY_MS;
            s2.activeSince = 0;
            strncpy(s2.paketId, ctrlData.paketId, MAX_PAKET_LEN - 1);
            s2.paketId[MAX_PAKET_LEN - 1] = '\0';
            strncpy(s2.jalur, ctrlData.jalur, MAX_JALUR_LEN - 1);
            s2.jalur[MAX_JALUR_LEN - 1] = '\0';
            s2.method = ctrlData.method;
            strncpy(s2.rfidEpc, ctrlData.rfidEpc, MAX_EPC_LEN - 1);
            s2.rfidEpc[MAX_EPC_LEN - 1] = '\0';
            Serial.printf("[CONTROL] Servo 2 pending (%lu ms) -> %s\n", s2.moveDelayMs, ctrlData.paketId);
            enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 pending (") + String(s2.moveDelayMs) + " ms) -> " + String(ctrlData.paketId));
          }
          else if (!hasPendingJ2)
          {
            pendingJ2 = ctrlData;
            hasPendingJ2 = true;
            Serial.printf("[CONTROL] Servo 2 sibuk, %s antri\n", ctrlData.paketId);
            enqueueAppLogLine("I", "CONTROL", String("[CONTROL] Servo 2 sibuk, ") + String(ctrlData.paketId) + " antri");
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
            enqueueAppLogLine("E", "CONTROL", String("[CONTROL] Servo 2 penuh, ") + String(ctrlData.paketId) + " ditolak");
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
  servo1.write(SERVO1_NEUTRAL_ANGLE);
  servo2.attach(SERVO2_PIN);
  servo2.write(SERVO2_NEUTRAL_ANGLE);

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
    uhfReader.setPower(1000);           // Diturunkan ke 10.0 dBm (Medium-Low) agar jarak baca PAS di bawahnya
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

  // Force DNS servers at lwIP level (helps when DHCP DNS is broken)
  configureDnsServers();

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
  timeSynced = initializeTime();

  // Initialize Firebase
  firebaseConfig.host = FIREBASE_HOST;
  firebaseConfig.signer.tokens.legacy_token = FIREBASE_AUTH;
  Firebase.begin(&firebaseConfig, &firebaseAuth);
  Firebase.reconnectWiFi(true);

  // Cache data from Firebase (requires correct time for TLS). If time sync failed,
  // defer caching; firebaseTask will retry time sync & load cache when ready.
  if (timeSynced)
  {
    cacheJalurFromFirebase();
    firebaseData.clear(); // Reset SSL connection sebelum request berikutnya
    delay(100);
    cacheRFIDTagsFromFirebase();
    initialCacheLoaded = true;
  }
  else
  {
    Serial.println("[TIME] Skipping initial Firebase cache (will retry in firebaseTask)");
    initialCacheLoaded = false;
  }

  // Create queues & mutex
  scanQueue = xQueueCreate(10, sizeof(ScanResult));
  controlQueue = xQueueCreate(10, sizeof(ControlData));
  statusUpdateQueue = xQueueCreate(10, sizeof(PaketUpdate));
  appLogQueue = xQueueCreate(40, sizeof(AppLogLine));
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
