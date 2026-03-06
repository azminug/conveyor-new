/**
 * @file EL_UHF_RMT01.cpp
 * @brief Implementation of EL-UHF-RMT01 UHF RFID Reader Library
 */

#include "EL_UHF_RMT01.h"

// ==================== Constructor ====================
EL_UHF_RMT01::EL_UHF_RMT01(HardwareSerial* serial) {
    _serial = serial;
    _debug = false;
    _lastError = 0;
    _rxPin = -1;
    _txPin = -1;
}

// ==================== Initialization ====================
bool EL_UHF_RMT01::begin(int rxPin, int txPin, long baudRate) {
    _rxPin = rxPin;
    _txPin = txPin;
    
    _serial->begin(baudRate, SERIAL_8N1, rxPin, txPin);
    delay(100);
    
    // Clear any garbage in buffer
    clearSerialBuffer();
    
    // Test connection by getting hardware version
    if (_debug) Serial.println("[UHF] Initializing module...");
    
    String version = getHardwareVersion();
    if (version.length() > 0) {
        if (_debug) Serial.printf("[UHF] Module detected: %s\n", version.c_str());
        return true;
    }
    
    if (_debug) Serial.println("[UHF] Module not responding!");
    return false;
}

bool EL_UHF_RMT01::isConnected() {
    String version = getHardwareVersion();
    return version.length() > 0;
}

// ==================== Module Info ====================
String EL_UHF_RMT01::getHardwareVersion() {
    uint8_t param = 0x00;  // Hardware version
    if (!sendCommand(CMD_GET_MODULE_INFO, &param, 1)) {
        return "";
    }
    
    UHFResponse response;
    if (!receiveResponse(&response)) {
        return "";
    }
    
    if (response.success && response.paramLength > 1) {
        // First byte is the param type, rest is ASCII string
        String version = "";
        for (int i = 1; i < response.paramLength; i++) {
            version += (char)response.param[i];
        }
        return version;
    }
    
    return "";
}

String EL_UHF_RMT01::getSoftwareVersion() {
    uint8_t param = 0x01;  // Software version
    if (!sendCommand(CMD_GET_MODULE_INFO, &param, 1)) {
        return "";
    }
    
    UHFResponse response;
    if (!receiveResponse(&response)) {
        return "";
    }
    
    if (response.success && response.paramLength > 1) {
        String version = "";
        for (int i = 1; i < response.paramLength; i++) {
            version += (char)response.param[i];
        }
        return version;
    }
    
    return "";
}

// ==================== Inventory Functions ====================
bool EL_UHF_RMT01::singleInventory(UHFTag* tag) {
    if (tag == nullptr) return false;
    
    tag->valid = false;
    
    if (!sendCommand(CMD_SINGLE_INVENTORY, nullptr, 0)) {
        return false;
    }
    
    UHFResponse response;
    if (!receiveResponse(&response, 2000)) {
        _lastError = ERR_INVENTORY_FAIL;
        return false;
    }
    
    // Check for notification (type 0x02) with tag data
    if (response.type == UHF_TYPE_NOTIFY && response.cmd == CMD_SINGLE_INVENTORY) {
        return parseInventoryResponse(&response, tag);
    }
    
    // Check for error response
    if (response.type == UHF_TYPE_RESPONSE && response.cmd == 0xFF) {
        _lastError = response.param[0];
        if (_debug) Serial.printf("[UHF] Inventory error: 0x%02X\n", _lastError);
        return false;
    }
    
    return false;
}

bool EL_UHF_RMT01::multiInventory(UHFTag* tags, int maxTags, int* count, uint16_t rounds) {
    if (tags == nullptr || count == nullptr) return false;
    
    *count = 0;
    
    // Command: Reserved (0x22) + Count (2 bytes)
    uint8_t param[3];
    param[0] = 0x22;  // Reserved
    param[1] = (rounds >> 8) & 0xFF;
    param[2] = rounds & 0xFF;
    
    if (!sendCommand(CMD_MULTI_INVENTORY, param, 3)) {
        return false;
    }
    
    // Receive multiple notifications
    unsigned long startTime = millis();
    unsigned long timeout = 5000 + (rounds * 50);  // Dynamic timeout based on rounds
    
    while ((millis() - startTime) < timeout && *count < maxTags) {
        UHFResponse response;
        if (receiveResponse(&response, 500)) {
            if (response.type == UHF_TYPE_NOTIFY && response.cmd == CMD_SINGLE_INVENTORY) {
                // Check if this EPC already exists (avoid duplicates)
                UHFTag tempTag;
                if (parseInventoryResponse(&response, &tempTag)) {
                    bool isDuplicate = false;
                    for (int i = 0; i < *count; i++) {
                        if (tags[i].epcLength == tempTag.epcLength) {
                            bool same = true;
                            for (int j = 0; j < tempTag.epcLength; j++) {
                                if (tags[i].epc[j] != tempTag.epc[j]) {
                                    same = false;
                                    break;
                                }
                            }
                            if (same) {
                                isDuplicate = true;
                                // Update RSSI if stronger signal
                                if (tempTag.rssi > tags[i].rssi) {
                                    tags[i].rssi = tempTag.rssi;
                                }
                                break;
                            }
                        }
                    }
                    
                    if (!isDuplicate) {
                        tags[*count] = tempTag;
                        (*count)++;
                        if (_debug) Serial.printf("[UHF] Tag %d: %s (RSSI: %d dBm)\n", 
                            *count, tempTag.getEPCString().c_str(), tempTag.rssi);
                    }
                }
            } else if (response.type == UHF_TYPE_RESPONSE && response.cmd == 0xFF) {
                // End of inventory or error
                break;
            }
        }
    }
    
    return *count > 0;
}

bool EL_UHF_RMT01::stopInventory() {
    if (!sendCommand(CMD_STOP_INVENTORY, nullptr, 0)) {
        return false;
    }
    
    UHFResponse response;
    if (!receiveResponse(&response)) {
        return false;
    }
    
    return response.success;
}

// ==================== Tag Memory Operations ====================
bool EL_UHF_RMT01::readTagMemory(uint8_t memBank, uint16_t wordPtr, uint8_t wordCount,
                                  uint32_t accessPassword, uint8_t* data, int* dataLen) {
    if (data == nullptr || dataLen == nullptr) return false;
    
    // Build command: Access Password (4) + MemBank (1) + WordPtr (2) + WordCount (1)
    uint8_t param[9];
    param[0] = (accessPassword >> 24) & 0xFF;
    param[1] = (accessPassword >> 16) & 0xFF;
    param[2] = (accessPassword >> 8) & 0xFF;
    param[3] = accessPassword & 0xFF;
    param[4] = memBank;
    param[5] = (wordPtr >> 8) & 0xFF;
    param[6] = wordPtr & 0xFF;
    param[7] = 0x00;  // Reserved
    param[8] = wordCount;
    
    if (!sendCommand(CMD_READ_TAG, param, 9)) {
        return false;
    }
    
    UHFResponse response;
    if (!receiveResponse(&response, 2000)) {
        return false;
    }
    
    if (response.success && response.paramLength > 14) {
        // Response format: PC+EPC Length (1) + PC (2) + EPC (12) + Data
        int pcEpcLen = response.param[0];
        int dataStart = 1 + pcEpcLen;
        *dataLen = response.paramLength - dataStart;
        
        for (int i = 0; i < *dataLen; i++) {
            data[i] = response.param[dataStart + i];
        }
        return true;
    }
    
    if (response.cmd == 0xFF) {
        _lastError = response.param[0];
    }
    
    return false;
}

bool EL_UHF_RMT01::writeTagMemory(uint8_t memBank, uint16_t wordPtr, uint8_t* data,
                                   uint8_t wordCount, uint32_t accessPassword) {
    if (data == nullptr || wordCount == 0 || wordCount > 32) return false;
    
    // Build command
    int paramLen = 8 + (wordCount * 2);
    uint8_t* param = new uint8_t[paramLen];
    
    param[0] = (accessPassword >> 24) & 0xFF;
    param[1] = (accessPassword >> 16) & 0xFF;
    param[2] = (accessPassword >> 8) & 0xFF;
    param[3] = accessPassword & 0xFF;
    param[4] = memBank;
    param[5] = (wordPtr >> 8) & 0xFF;
    param[6] = wordPtr & 0xFF;
    param[7] = wordCount;
    
    for (int i = 0; i < wordCount * 2; i++) {
        param[8 + i] = data[i];
    }
    
    bool success = sendCommand(CMD_WRITE_TAG, param, paramLen);
    delete[] param;
    
    if (!success) return false;
    
    UHFResponse response;
    if (!receiveResponse(&response, 2000)) {
        return false;
    }
    
    if (response.cmd == 0xFF) {
        _lastError = response.param[0];
        return false;
    }
    
    return response.success;
}

// ==================== RF Configuration ====================
bool EL_UHF_RMT01::setRegion(uint8_t region) {
    if (!sendCommand(CMD_SET_REGION, &region, 1)) {
        return false;
    }
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

uint8_t EL_UHF_RMT01::getRegion() {
    if (!sendCommand(CMD_GET_REGION, nullptr, 0)) {
        return 0;
    }
    
    UHFResponse response;
    if (receiveResponse(&response) && response.success && response.paramLength >= 1) {
        return response.param[0];
    }
    
    return 0;
}

bool EL_UHF_RMT01::setPower(uint16_t power) {
    uint8_t param[2];
    param[0] = (power >> 8) & 0xFF;
    param[1] = power & 0xFF;
    
    if (!sendCommand(CMD_SET_POWER, param, 2)) {
        return false;
    }
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

uint16_t EL_UHF_RMT01::getPower() {
    if (!sendCommand(CMD_GET_POWER, nullptr, 0)) {
        return 0;
    }
    
    UHFResponse response;
    if (receiveResponse(&response) && response.success && response.paramLength >= 2) {
        return (response.param[0] << 8) | response.param[1];
    }
    
    return 0;
}

bool EL_UHF_RMT01::setFrequencyHopping(bool enable) {
    uint8_t param = enable ? 0xFF : 0x00;
    
    if (!sendCommand(CMD_SET_FREQ_HOP, &param, 1)) {
        return false;
    }
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

// ==================== Select Configuration ====================
bool EL_UHF_RMT01::setSelect(uint8_t memBank, uint32_t pointer, uint8_t maskLen, uint8_t* mask) {
    if (mask == nullptr) return false;
    
    int maskBytes = (maskLen + 7) / 8;
    int paramLen = 7 + maskBytes;
    uint8_t* param = new uint8_t[paramLen];
    
    // SelParam: Target(3bits) + Action(3bits) + MemBank(2bits)
    param[0] = (0x00 << 5) | (0x00 << 2) | (memBank & 0x03);
    
    // Pointer (4 bytes, in bits)
    param[1] = (pointer >> 24) & 0xFF;
    param[2] = (pointer >> 16) & 0xFF;
    param[3] = (pointer >> 8) & 0xFF;
    param[4] = pointer & 0xFF;
    
    // MaskLen
    param[5] = maskLen;
    
    // Truncate
    param[6] = 0x00;  // Disable truncation
    
    // Mask
    for (int i = 0; i < maskBytes; i++) {
        param[7 + i] = mask[i];
    }
    
    bool success = sendCommand(CMD_SET_SELECT, param, paramLen);
    delete[] param;
    
    if (!success) return false;
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

bool EL_UHF_RMT01::setSelectMode(uint8_t mode) {
    if (!sendCommand(CMD_SET_SELECT_MODE, &mode, 1)) {
        return false;
    }
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

// ==================== Power Management ====================
bool EL_UHF_RMT01::sleep() {
    if (!sendCommand(CMD_MODULE_SLEEP, nullptr, 0)) {
        return false;
    }
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

void EL_UHF_RMT01::wakeup() {
    // Send any byte to wake up
    _serial->write(0x00);
    delay(100);
    clearSerialBuffer();
}

bool EL_UHF_RMT01::setIdleSleepTime(uint8_t minutes) {
    if (minutes > 30) minutes = 30;
    
    if (!sendCommand(CMD_MODULE_IDLE_TIME, &minutes, 1)) {
        return false;
    }
    
    UHFResponse response;
    return receiveResponse(&response) && response.success;
}

// ==================== Debug ====================
void EL_UHF_RMT01::setDebug(bool enable) {
    _debug = enable;
}

uint8_t EL_UHF_RMT01::getLastError() {
    return _lastError;
}

String EL_UHF_RMT01::getErrorDescription(uint8_t errorCode) {
    switch (errorCode) {
        case ERR_COMMAND:       return "Command Error";
        case ERR_FHSS_FAIL:     return "Frequency Hopping Failed";
        case ERR_INVENTORY_FAIL: return "Inventory Failed - No Tag";
        case ERR_WRONG_PASSWORD: return "Wrong Access Password";
        case ERR_READ_FAIL:     return "Read Failed";
        case ERR_WRITE_FAIL:    return "Write Failed";
        case ERR_LOCK_FAIL:     return "Lock Failed";
        case ERR_KILL_FAIL:     return "Kill Failed";
        case ERR_PERMALOCK_FAIL: return "Permalock Failed";
        default:
            // Check for EPC Gen2 errors (0xAX - 0xEX)
            if ((errorCode & 0xF0) >= 0xA0 && (errorCode & 0xF0) <= 0xE0) {
                uint8_t gen2Error = errorCode & 0x0F;
                switch (gen2Error) {
                    case 0x00: return "Gen2: Other Error";
                    case 0x01: return "Gen2: Not Supported";
                    case 0x02: return "Gen2: Insufficient Privileges";
                    case 0x03: return "Gen2: Memory Overrun";
                    case 0x04: return "Gen2: Memory Locked";
                    case 0x0B: return "Gen2: Insufficient Power";
                    case 0x0F: return "Gen2: Non-specific Error";
                    default:   return "Gen2: Unknown Error";
                }
            }
            return "Unknown Error";
    }
}

// ==================== Private: Frame Handling ====================
bool EL_UHF_RMT01::sendCommand(uint8_t cmd, uint8_t* param, uint16_t paramLen) {
    // Calculate frame size: Header(1) + Type(1) + CMD(1) + PL(2) + Param(N) + CRC(1) + End(1)
    int frameLen = 7 + paramLen;
    uint8_t* frame = new uint8_t[frameLen];
    
    frame[0] = UHF_FRAME_HEADER;
    frame[1] = UHF_TYPE_COMMAND;
    frame[2] = cmd;
    frame[3] = (paramLen >> 8) & 0xFF;
    frame[4] = paramLen & 0xFF;
    
    for (int i = 0; i < paramLen; i++) {
        frame[5 + i] = param[i];
    }
    
    // Calculate CRC: sum from Type to last param byte, take LSB
    frame[5 + paramLen] = calculateCRC(&frame[1], 4 + paramLen);
    frame[6 + paramLen] = UHF_FRAME_END;
    
    if (_debug) {
        printHex(frame, frameLen, "[UHF TX] ");
    }
    
    _serial->write(frame, frameLen);
    _serial->flush();
    
    delete[] frame;
    return true;
}

bool EL_UHF_RMT01::receiveResponse(UHFResponse* response, unsigned long timeout) {
    if (response == nullptr) return false;
    
    response->success = false;
    response->errorCode = 0;
    
    unsigned long startTime = millis();
    
    // Wait for header
    while (_serial->available() < 1) {
        if ((millis() - startTime) > timeout) {
            if (_debug) Serial.println("[UHF] Timeout waiting for header");
            return false;
        }
        delay(1);
    }
    
    // Find header
    while (_serial->available() > 0) {
        uint8_t b = _serial->read();
        if (b == UHF_FRAME_HEADER) {
            break;
        }
        if ((millis() - startTime) > timeout) {
            if (_debug) Serial.println("[UHF] Timeout finding header");
            return false;
        }
    }
    
    // Wait for minimum frame: Type(1) + CMD(1) + PL(2)
    while (_serial->available() < 4) {
        if ((millis() - startTime) > timeout) {
            if (_debug) Serial.println("[UHF] Timeout waiting for frame header");
            return false;
        }
        delay(1);
    }
    
    response->type = _serial->read();
    response->cmd = _serial->read();
    response->paramLength = (_serial->read() << 8) | _serial->read();
    
    // Sanity check
    if (response->paramLength > 256) {
        if (_debug) Serial.printf("[UHF] Invalid param length: %d\n", response->paramLength);
        clearSerialBuffer();
        return false;
    }
    
    // Wait for parameters + CRC + End
    while (_serial->available() < (int)(response->paramLength + 2)) {
        if ((millis() - startTime) > timeout) {
            if (_debug) Serial.println("[UHF] Timeout waiting for parameters");
            return false;
        }
        delay(1);
    }
    
    // Read parameters
    for (int i = 0; i < response->paramLength; i++) {
        response->param[i] = _serial->read();
    }
    
    uint8_t crc = _serial->read();
    uint8_t end = _serial->read();
    
    // Verify end byte
    if (end != UHF_FRAME_END) {
        if (_debug) Serial.printf("[UHF] Invalid end byte: 0x%02X\n", end);
        return false;
    }
    
    // Verify CRC
    uint8_t calcCRC = response->type + response->cmd + 
                      ((response->paramLength >> 8) & 0xFF) + 
                      (response->paramLength & 0xFF);
    for (int i = 0; i < response->paramLength; i++) {
        calcCRC += response->param[i];
    }
    
    if (crc != (calcCRC & 0xFF)) {
        if (_debug) Serial.printf("[UHF] CRC mismatch: got 0x%02X, expected 0x%02X\n", crc, calcCRC & 0xFF);
        return false;
    }
    
    // Check for error response
    if (response->cmd == 0xFF) {
        response->success = false;
        if (response->paramLength >= 1) {
            response->errorCode = response->param[0];
            _lastError = response->errorCode;
        }
    } else {
        response->success = true;
    }
    
    if (_debug) {
        Serial.printf("[UHF RX] Type: 0x%02X, CMD: 0x%02X, Len: %d, Success: %d\n",
            response->type, response->cmd, response->paramLength, response->success);
        if (response->paramLength > 0) {
            printHex(response->param, response->paramLength, "[UHF RX] Param: ");
        }
    }
    
    return true;
}

uint8_t EL_UHF_RMT01::calculateCRC(uint8_t* data, int len) {
    uint8_t crc = 0;
    for (int i = 0; i < len; i++) {
        crc += data[i];
    }
    return crc;
}

void EL_UHF_RMT01::clearSerialBuffer() {
    while (_serial->available() > 0) {
        _serial->read();
    }
}

void EL_UHF_RMT01::printHex(uint8_t* data, int len, const char* prefix) {
    Serial.print(prefix);
    for (int i = 0; i < len; i++) {
        if (data[i] < 0x10) Serial.print("0");
        Serial.print(data[i], HEX);
        Serial.print(" ");
    }
    Serial.println();
}

bool EL_UHF_RMT01::parseInventoryResponse(UHFResponse* response, UHFTag* tag) {
    if (response == nullptr || tag == nullptr) return false;
    if (response->paramLength < 15) return false;  // Minimum: RSSI(1) + PC(2) + EPC(12)
    
    // Parse RSSI (signed byte)
    tag->rssi = (int8_t)response->param[0];
    
    // Parse PC (Protocol Control)
    tag->pc[0] = response->param[1];
    tag->pc[1] = response->param[2];
    
    // Calculate EPC length from PC
    // PC bits 15-11 contain the EPC length in words
    uint8_t epcWords = (tag->pc[0] >> 3) & 0x1F;
    tag->epcLength = epcWords * 2;  // Convert words to bytes
    
    if (tag->epcLength > UHF_MAX_EPC_LENGTH) {
        tag->epcLength = UHF_MAX_EPC_LENGTH;
    }
    
    // Parse EPC
    for (int i = 0; i < tag->epcLength && (3 + i) < response->paramLength; i++) {
        tag->epc[i] = response->param[3 + i];
    }
    
    tag->valid = true;
    
    return true;
}
