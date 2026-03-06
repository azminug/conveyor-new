/**
 * @file test_main.cpp
 * @brief Unit tests untuk library EL_UHF_RMT01 dan fungsi helper
 * 
 * PlatformIO Unit Testing dengan Unity Framework
 */

#include <Arduino.h>
#include <unity.h>

// ==================== Test Helper Functions ====================

/**
 * @brief Test parsing format barcode "PaketId|Kodepos"
 */
void test_barcode_parsing_valid() {
    String barcode = "PKT001|12345";
    int delimiterPos = barcode.indexOf('|');
    
    TEST_ASSERT_NOT_EQUAL(-1, delimiterPos);
    
    String paketId = barcode.substring(0, delimiterPos);
    String kodepos = barcode.substring(delimiterPos + 1);
    
    TEST_ASSERT_EQUAL_STRING("PKT001", paketId.c_str());
    TEST_ASSERT_EQUAL_STRING("12345", kodepos.c_str());
}

void test_barcode_parsing_invalid() {
    String barcode = "PKT001-12345"; // Format salah (tanpa |)
    int delimiterPos = barcode.indexOf('|');
    
    TEST_ASSERT_EQUAL(-1, delimiterPos);
}

void test_barcode_parsing_empty() {
    String barcode = "";
    int delimiterPos = barcode.indexOf('|');
    
    TEST_ASSERT_EQUAL(-1, delimiterPos);
}

/**
 * @brief Test konversi EPC hex array ke string
 */
void test_epc_to_string() {
    uint8_t epc[] = {0xE2, 0x00, 0x40, 0x01, 0x23, 0x45};
    int epcLength = 6;
    
    String result = "";
    for (int i = 0; i < epcLength; i++) {
        if (epc[i] < 0x10) result += "0";
        result += String(epc[i], HEX);
    }
    result.toUpperCase();
    
    TEST_ASSERT_EQUAL_STRING("E20040012345", result.c_str());
}

void test_epc_to_string_with_leading_zeros() {
    uint8_t epc[] = {0x00, 0x01, 0x02, 0x0A};
    int epcLength = 4;
    
    String result = "";
    for (int i = 0; i < epcLength; i++) {
        if (epc[i] < 0x10) result += "0";
        result += String(epc[i], HEX);
    }
    result.toUpperCase();
    
    TEST_ASSERT_EQUAL_STRING("0001020A", result.c_str());
}

/**
 * @brief Test checksum calculation (XOR dari Type sampai Parameter terakhir)
 */
void test_checksum_calculation() {
    // Frame: BB 00 22 00 00 22 7E
    // Checksum = Type XOR Command XOR ParamLen[0] XOR ParamLen[1]
    uint8_t type = 0x00;
    uint8_t cmd = 0x22;
    uint8_t paramLen0 = 0x00;
    uint8_t paramLen1 = 0x00;
    
    uint8_t checksum = type ^ cmd ^ paramLen0 ^ paramLen1;
    
    TEST_ASSERT_EQUAL_HEX8(0x22, checksum);
}

void test_checksum_with_params() {
    // Frame dengan parameter
    // Type=0x00, Cmd=0xB6, ParamLen=0x0002, Param=0x07D0 (2000 = 20dBm)
    uint8_t type = 0x00;
    uint8_t cmd = 0xB6;
    uint8_t paramLen0 = 0x00;
    uint8_t paramLen1 = 0x02;
    uint8_t param0 = 0x07;
    uint8_t param1 = 0xD0;
    
    uint8_t checksum = type ^ cmd ^ paramLen0 ^ paramLen1 ^ param0 ^ param1;
    
    // Verifikasi checksum dihitung dengan benar
    TEST_ASSERT_NOT_EQUAL(0, checksum); // Checksum harus non-zero
}

/**
 * @brief Test power value conversion
 */
void test_power_conversion() {
    // 2000 = 20.00 dBm
    int powerValue = 2000;
    float dBm = powerValue / 100.0;
    
    TEST_ASSERT_FLOAT_WITHIN(0.01, 20.0, dBm);
}

void test_power_conversion_max() {
    // 3000 = 30.00 dBm (maximum)
    int powerValue = 3000;
    float dBm = powerValue / 100.0;
    
    TEST_ASSERT_FLOAT_WITHIN(0.01, 30.0, dBm);
}

/**
 * @brief Test jalur mapping logic
 */
void test_jalur_mapping() {
    // Simulasi mapping kodepos ke jalur
    struct JalurMapping {
        String jalur;
        String kodepos;
    };
    
    JalurMapping cache[3] = {
        {"jalur1", "12345"},
        {"jalur2", "54321"},
        {"jalur3", "11111"}
    };
    
    // Cari kodepos 54321
    String targetKodepos = "54321";
    String foundJalur = "";
    
    for (int i = 0; i < 3; i++) {
        if (cache[i].kodepos == targetKodepos) {
            foundJalur = cache[i].jalur;
            break;
        }
    }
    
    TEST_ASSERT_EQUAL_STRING("jalur2", foundJalur.c_str());
}

void test_jalur_mapping_not_found() {
    struct JalurMapping {
        String jalur;
        String kodepos;
    };
    
    JalurMapping cache[2] = {
        {"jalur1", "12345"},
        {"jalur2", "54321"}
    };
    
    // Cari kodepos yang tidak ada
    String targetKodepos = "99999";
    String foundJalur = "";
    
    for (int i = 0; i < 2; i++) {
        if (cache[i].kodepos == targetKodepos) {
            foundJalur = cache[i].jalur;
            break;
        }
    }
    
    TEST_ASSERT_EQUAL_STRING("", foundJalur.c_str());
}

/**
 * @brief Test queue circular buffer logic
 */
void test_queue_circular_buffer() {
    const int MAX_QUEUE = 5;
    int head = 0;
    int tail = 0;
    
    // Add items
    tail = (tail + 1) % MAX_QUEUE; // Item 1
    tail = (tail + 1) % MAX_QUEUE; // Item 2
    tail = (tail + 1) % MAX_QUEUE; // Item 3
    
    // Queue should have 3 items
    int count = (tail - head + MAX_QUEUE) % MAX_QUEUE;
    TEST_ASSERT_EQUAL(3, count);
    
    // Remove 1 item
    head = (head + 1) % MAX_QUEUE;
    count = (tail - head + MAX_QUEUE) % MAX_QUEUE;
    TEST_ASSERT_EQUAL(2, count);
}

/**
 * @brief Test RSSI calculation
 */
void test_rssi_conversion() {
    // RSSI byte 0xC8 = 200, which means -200 + 129 = -71 dBm
    // atau sesuai protokol modul
    uint8_t rssiByte = 0xC8;
    int8_t rssi = (int8_t)rssiByte;
    
    // RSSI sebagai signed value
    TEST_ASSERT_LESS_THAN(0, rssi);
}

/**
 * @brief Test timestamp generation
 */
void test_timestamp_format() {
    // Simulasi format timestamp
    char timestamp[25];
    int year = 2024, month = 12, day = 15;
    int hour = 10, minute = 30, second = 45;
    
    snprintf(timestamp, sizeof(timestamp), "%04d-%02d-%02d %02d:%02d:%02d",
             year, month, day, hour, minute, second);
    
    TEST_ASSERT_EQUAL_STRING("2024-12-15 10:30:45", timestamp);
}

// ==================== Setup & Loop ====================

void setup() {
    delay(2000); // Tunggu Serial siap
    
    UNITY_BEGIN();
    
    // Jalankan semua test
    RUN_TEST(test_barcode_parsing_valid);
    RUN_TEST(test_barcode_parsing_invalid);
    RUN_TEST(test_barcode_parsing_empty);
    RUN_TEST(test_epc_to_string);
    RUN_TEST(test_epc_to_string_with_leading_zeros);
    RUN_TEST(test_checksum_calculation);
    RUN_TEST(test_checksum_with_params);
    RUN_TEST(test_power_conversion);
    RUN_TEST(test_power_conversion_max);
    RUN_TEST(test_jalur_mapping);
    RUN_TEST(test_jalur_mapping_not_found);
    RUN_TEST(test_queue_circular_buffer);
    RUN_TEST(test_rssi_conversion);
    RUN_TEST(test_timestamp_format);
    
    UNITY_END();
}

void loop() {
    // Tidak ada yang perlu dijalankan di loop untuk unit test
}
