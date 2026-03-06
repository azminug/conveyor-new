/**
 * @file EL_UHF_RMT01.h
 * @brief Library untuk modul UHF RFID Reader EL-UHF-RMT01
 * @author Conveyor System
 * @version 1.0.0
 * @date 2024
 * 
 * Library ini mengimplementasikan protokol komunikasi untuk
 * modul UHF RFID Reader EL-UHF-RMT01 dari Electron Indonesia.
 * 
 * Fitur:
 * - Single & Multiple Inventory
 * - Read/Write Tag Memory
 * - Konfigurasi Power & Frekuensi
 * - Select Filter untuk tag tertentu
 */

#ifndef EL_UHF_RMT01_H
#define EL_UHF_RMT01_H

#include <Arduino.h>
#include <HardwareSerial.h>

// ==================== Frame Constants ====================
#define UHF_FRAME_HEADER    0xBB
#define UHF_FRAME_END       0x7E

// Frame Types
#define UHF_TYPE_COMMAND    0x00
#define UHF_TYPE_RESPONSE   0x01
#define UHF_TYPE_NOTIFY     0x02

// ==================== Command Codes ====================
// Module Configuration
#define CMD_GET_MODULE_INFO     0x03
#define CMD_SET_BAUDRATE        0x11
#define CMD_MODULE_SLEEP        0x17
#define CMD_MODULE_IDLE_TIME    0x1D
#define CMD_MODULE_IDLE         0x04

// Inventory Commands
#define CMD_SINGLE_INVENTORY    0x22
#define CMD_MULTI_INVENTORY     0x27
#define CMD_STOP_INVENTORY      0x28

// Select Commands
#define CMD_SET_SELECT          0x0C
#define CMD_GET_SELECT          0x0B
#define CMD_SET_SELECT_MODE     0x12

// Tag Commands
#define CMD_READ_TAG            0x39
#define CMD_WRITE_TAG           0x49
#define CMD_LOCK_TAG            0x82
#define CMD_KILL_TAG            0x65
#define CMD_BLOCK_PERMALOCK     0xD3

// Query Commands
#define CMD_GET_QUERY           0x0D
#define CMD_SET_QUERY           0x0E

// RF Commands
#define CMD_SET_REGION          0x07
#define CMD_GET_REGION          0x08
#define CMD_SET_CHANNEL         0xAB
#define CMD_GET_CHANNEL         0xAA
#define CMD_SET_FREQ_HOP        0xAD
#define CMD_INSERT_CHANNEL      0xA9
#define CMD_GET_POWER           0xB7
#define CMD_SET_POWER           0xB6
#define CMD_SET_CW              0xB0
#define CMD_GET_DEMOD           0xF1
#define CMD_SET_DEMOD           0xF0
#define CMD_TEST_JAMMER         0xF2
#define CMD_TEST_RSSI           0xF3

// GPIO
#define CMD_CONTROL_IO          0x1A

// ==================== Error Codes ====================
#define ERR_COMMAND             0x17
#define ERR_FHSS_FAIL           0x20
#define ERR_INVENTORY_FAIL      0x15
#define ERR_WRONG_PASSWORD      0x16
#define ERR_READ_FAIL           0x09
#define ERR_WRITE_FAIL          0x10
#define ERR_LOCK_FAIL           0x13
#define ERR_KILL_FAIL           0x12
#define ERR_PERMALOCK_FAIL      0x14

// ==================== Region Codes ====================
#define REGION_920MHZ           0x01  // Indonesia, China
#define REGION_US               0x02  // United States
#define REGION_EU               0x03  // Europe
#define REGION_840MHZ           0x04  // China 840MHz
#define REGION_KOREA            0x06  // Korea

// ==================== Memory Banks ====================
#define MEMBANK_RESERVED        0x00
#define MEMBANK_EPC             0x01
#define MEMBANK_TID             0x02
#define MEMBANK_USER            0x03

// ==================== Default Configuration ====================
#define UHF_DEFAULT_BAUDRATE    115200
#define UHF_DEFAULT_POWER       2000    // 20 dBm
#define UHF_DEFAULT_REGION      REGION_920MHZ
#define UHF_MAX_EPC_LENGTH      12      // 96 bits = 12 bytes
#define UHF_MAX_TAGS            50

// ==================== Tag Data Structure ====================
struct UHFTag {
    uint8_t epc[UHF_MAX_EPC_LENGTH];
    uint8_t epcLength;
    uint8_t pc[2];          // Protocol Control
    int8_t rssi;            // Signal strength in dBm
    bool valid;
    
    // Helper function to get EPC as hex string
    String getEPCString() const {
        String result = "";
        for (int i = 0; i < epcLength; i++) {
            if (epc[i] < 0x10) result += "0";
            result += String(epc[i], HEX);
        }
        result.toUpperCase();
        return result;
    }
};

// ==================== Response Structure ====================
struct UHFResponse {
    uint8_t type;
    uint8_t cmd;
    uint16_t paramLength;
    uint8_t param[256];
    bool success;
    uint8_t errorCode;
};

// ==================== Main Class ====================
class EL_UHF_RMT01 {
public:
    /**
     * @brief Constructor
     * @param serial Pointer ke HardwareSerial yang akan digunakan
     */
    EL_UHF_RMT01(HardwareSerial* serial);
    
    /**
     * @brief Inisialisasi modul RFID
     * @param rxPin Pin RX ESP32 (terhubung ke TX modul)
     * @param txPin Pin TX ESP32 (terhubung ke RX modul)
     * @param baudRate Baud rate komunikasi (default 115200)
     * @return true jika berhasil
     */
    bool begin(int rxPin, int txPin, long baudRate = UHF_DEFAULT_BAUDRATE);
    
    /**
     * @brief Cek koneksi ke modul dengan membaca info hardware
     * @return true jika modul merespons
     */
    bool isConnected();
    
    // ==================== Module Info ====================
    /**
     * @brief Mendapatkan versi hardware modul
     * @return String versi hardware (contoh: "M100 V1.00")
     */
    String getHardwareVersion();
    
    /**
     * @brief Mendapatkan versi software modul
     * @return String versi software
     */
    String getSoftwareVersion();
    
    // ==================== Inventory Functions ====================
    /**
     * @brief Melakukan single inventory (baca 1 tag)
     * @param tag Pointer ke struct UHFTag untuk menyimpan hasil
     * @return true jika tag ditemukan
     */
    bool singleInventory(UHFTag* tag);
    
    /**
     * @brief Melakukan multiple inventory
     * @param tags Array untuk menyimpan tag yang ditemukan
     * @param maxTags Ukuran maksimal array
     * @param count Pointer untuk menyimpan jumlah tag yang ditemukan
     * @param rounds Jumlah putaran inventory (default 10)
     * @return true jika setidaknya 1 tag ditemukan
     */
    bool multiInventory(UHFTag* tags, int maxTags, int* count, uint16_t rounds = 10);
    
    /**
     * @brief Menghentikan multiple inventory yang sedang berjalan
     * @return true jika berhasil
     */
    bool stopInventory();
    
    // ==================== Tag Memory Operations ====================
    /**
     * @brief Membaca memori tag
     * @param memBank Memory bank (MEMBANK_RESERVED, MEMBANK_EPC, MEMBANK_TID, MEMBANK_USER)
     * @param wordPtr Alamat awal (dalam word = 2 bytes)
     * @param wordCount Jumlah word yang dibaca
     * @param accessPassword Access password (4 bytes), 0x00000000 jika tidak ada password
     * @param data Buffer untuk menyimpan data yang dibaca
     * @param dataLen Pointer untuk menyimpan panjang data
     * @return true jika berhasil
     */
    bool readTagMemory(uint8_t memBank, uint16_t wordPtr, uint8_t wordCount, 
                       uint32_t accessPassword, uint8_t* data, int* dataLen);
    
    /**
     * @brief Menulis ke memori tag
     * @param memBank Memory bank
     * @param wordPtr Alamat awal (dalam word)
     * @param data Data yang akan ditulis
     * @param wordCount Jumlah word yang ditulis
     * @param accessPassword Access password
     * @return true jika berhasil
     */
    bool writeTagMemory(uint8_t memBank, uint16_t wordPtr, uint8_t* data, 
                        uint8_t wordCount, uint32_t accessPassword);
    
    // ==================== RF Configuration ====================
    /**
     * @brief Mengatur region frekuensi
     * @param region Kode region (REGION_920MHZ, REGION_US, dll)
     * @return true jika berhasil
     */
    bool setRegion(uint8_t region);
    
    /**
     * @brief Mendapatkan region frekuensi saat ini
     * @return Kode region
     */
    uint8_t getRegion();
    
    /**
     * @brief Mengatur power transmit
     * @param power Power dalam 0.01 dBm (contoh: 2000 = 20 dBm)
     * @return true jika berhasil
     */
    bool setPower(uint16_t power);
    
    /**
     * @brief Mendapatkan power transmit saat ini
     * @return Power dalam 0.01 dBm
     */
    uint16_t getPower();
    
    /**
     * @brief Mengaktifkan/menonaktifkan frequency hopping
     * @param enable true untuk mengaktifkan
     * @return true jika berhasil
     */
    bool setFrequencyHopping(bool enable);
    
    // ==================== Select Configuration ====================
    /**
     * @brief Mengatur Select filter untuk target tag tertentu
     * @param memBank Memory bank untuk filter (MEMBANK_EPC biasanya)
     * @param pointer Bit pointer dalam memory bank
     * @param maskLen Panjang mask dalam bits
     * @param mask Data mask
     * @return true jika berhasil
     */
    bool setSelect(uint8_t memBank, uint32_t pointer, uint8_t maskLen, uint8_t* mask);
    
    /**
     * @brief Mengatur mode Select
     * @param mode 0x00=Select sebelum semua operasi, 0x01=Tidak ada Select, 0x02=Select sebelum Read/Write
     * @return true jika berhasil
     */
    bool setSelectMode(uint8_t mode);
    
    // ==================== Power Management ====================
    /**
     * @brief Memasukkan modul ke mode sleep
     * @return true jika berhasil
     */
    bool sleep();
    
    /**
     * @brief Membangunkan modul dari sleep
     */
    void wakeup();
    
    /**
     * @brief Mengatur waktu idle sebelum auto-sleep
     * @param minutes Waktu dalam menit (0 = tidak pernah sleep, max 30)
     * @return true jika berhasil
     */
    bool setIdleSleepTime(uint8_t minutes);
    
    // ==================== Debug ====================
    /**
     * @brief Mengaktifkan/menonaktifkan debug output
     * @param enable true untuk mengaktifkan
     */
    void setDebug(bool enable);
    
    /**
     * @brief Mendapatkan error code terakhir
     * @return Error code
     */
    uint8_t getLastError();
    
    /**
     * @brief Mendapatkan deskripsi error
     * @param errorCode Kode error
     * @return String deskripsi error
     */
    String getErrorDescription(uint8_t errorCode);

private:
    HardwareSerial* _serial;
    bool _debug;
    uint8_t _lastError;
    int _rxPin;
    int _txPin;
    
    // Frame handling
    bool sendCommand(uint8_t cmd, uint8_t* param, uint16_t paramLen);
    bool receiveResponse(UHFResponse* response, unsigned long timeout = 1000);
    uint8_t calculateCRC(uint8_t* data, int len);
    
    // Helper functions
    void clearSerialBuffer();
    void printHex(uint8_t* data, int len, const char* prefix = "");
    bool parseInventoryResponse(UHFResponse* response, UHFTag* tag);
};

#endif // EL_UHF_RMT01_H
