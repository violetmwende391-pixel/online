#include <WiFi.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <esp_task_wdt.h>
#include <ArduinoJson.h>

// --- Configuration ---
#define FLOW_SENSOR_PIN 27
#define VALVE_ENABLE_PIN 26
#define VALVE_IN1_PIN 25
#define VALVE_IN2_PIN 33
#define FLOW_CALIBRATION 7.5
#define PRICE_PER_LITER 1.0
#define MIN_BALANCE_THRESHOLD 0.10  // Minimum balance to keep valve open (KES)

#define VALVE_OPERATION_TIME 5000   // Time for valve to fully open/close (ms)
#define MANUAL_OVERRIDE_TIMEOUT 30000  // 30 seconds manual override
#define SAVE_INTERVAL 30000         // Save to flash every 30 seconds
#define FLOW_CALC_INTERVAL 1000     // Calculate flow every second
#define SERVER_SEND_INTERVAL 5000   // Send data to server every 5 seconds
#define WIFI_RETRY_INTERVAL 30000
#define DEBOUNCE_TIME 10
#define WATCHDOG_TIMEOUT_SEC 15

const char* ssid = "GalaxyA23B729";
const char* password = "Chris1234";
char meter_serial[32] = "9999"; // Default serial if server fails
const char* serverIP = "192.168.109.121";
const char* commandEndpoint = "/smart/get_command.php";
const char* logEndpoint = "/smart/log_data.php";
const char* serialEndpoint = "/smart/get_serial.php";

// --- System Variables ---
volatile unsigned long pulseCount = 0;
float flowRate = 0.0;
float totalVolume = 0.0;
float sessionVolume = 0.0;
String operationMode = "prepaid";

enum ValveState { CLOSED, OPENING, OPEN, CLOSING };
ValveState valveState = CLOSED;
unsigned long valveOperationStart = 0;
bool valveOperating = false;

bool manualValveControl = false;
unsigned long manualCommandTime = 0;

unsigned long lastSendTime = 0;
unsigned long lastCommandTime = 0;
unsigned long lastFlowCalcTime = 0;
unsigned long lastSaveTime = 0;
unsigned long lastPulseTime = 0;
unsigned long lastValveCheckTime = 0;

Preferences preferences;

// --- Flow Sensor Interrupt ---
void IRAM_ATTR pulseCounter() {
  unsigned long now = millis();
  if (now - lastPulseTime > DEBOUNCE_TIME) {
    pulseCount++;
    lastPulseTime = now;
  }
}

void fetchMeterSerial() {
  HTTPClient http;
  String url = "http://" + String(serverIP) + serialEndpoint;
  http.begin(url);
  http.setTimeout(3000);
  int code = http.GET();

  if (code == HTTP_CODE_OK) {
    String payload = http.getString();
    payload.trim();
    
    if (payload.length() > 0 && payload.indexOf(" ") == -1) {
      int len = payload.length();
      if (len > sizeof(meter_serial) - 1) {
        len = sizeof(meter_serial) - 1;
      }
      payload.toCharArray(meter_serial, len + 1);
      Serial.println("✅ Meter serial received: " + String(meter_serial));
    } else {
      Serial.println("⚠ Invalid serial response: " + payload + ", using default");
    }
  } else {
    Serial.println("❌ Failed to get meter serial: " + String(code) + ", using default");
  }
  http.end();
}

// --- Setup ---
void setup() {
  Serial.begin(115200);
  delay(1000);

  // Initialize Watchdog
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = WATCHDOG_TIMEOUT_SEC * 1000,
    .idle_core_mask = 1 << (portNUM_PROCESSORS - 1),
    .trigger_panic = true
  };
  esp_task_wdt_init(&wdt_config);
  esp_task_wdt_add(NULL);

  // Initialize pins
  pinMode(FLOW_SENSOR_PIN, INPUT_PULLUP);
  pinMode(VALVE_ENABLE_PIN, OUTPUT);
  pinMode(VALVE_IN1_PIN, OUTPUT);
  pinMode(VALVE_IN2_PIN, OUTPUT);
  digitalWrite(VALVE_ENABLE_PIN, LOW);

  // Initialize flow sensor interrupt
  attachInterrupt(digitalPinToInterrupt(FLOW_SENSOR_PIN), pulseCounter, RISING);

  // Load saved data
  preferences.begin("water-meter", false);
  totalVolume = preferences.getFloat("totalVolume", 0);
  operationMode = preferences.getString("mode", "prepaid");
  valveState = (ValveState)preferences.getUInt("valveState", CLOSED);
  manualValveControl = preferences.getBool("manualControl", false);
  preferences.end();

  // Initialize valve based on saved state
  if (valveState == OPEN) {
    digitalWrite(VALVE_IN1_PIN, HIGH);
    digitalWrite(VALVE_IN2_PIN, LOW);
    Serial.println("Initial valve state: OPEN");
  } else {
    digitalWrite(VALVE_IN1_PIN, LOW);
    digitalWrite(VALVE_IN2_PIN, HIGH);
    Serial.println("Initial valve state: CLOSED");
  }

  // Connect to WiFi and fetch serial
  connectToWiFi();
  fetchMeterSerial();

  Serial.printf("Init Complete | Volume: %.2f L | Mode: %s | Serial: %s | Valve: %s | Manual: %s\n",
                totalVolume, operationMode.c_str(), meter_serial, 
                valveState == OPEN ? "OPEN" : "CLOSED",
                manualValveControl ? "YES" : "NO");
}

void connectToWiFi() {
  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");
  int retries = 0;
  while (WiFi.status() != WL_CONNECTED && retries++ < 20) {
    delay(500);
    Serial.print(".");
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n✅ WiFi Connected");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("\n❌ WiFi Failed");
  }
}

void ensureWiFiConnection() {
  static unsigned long lastAttempt = 0;
  if (WiFi.status() != WL_CONNECTED && millis() - lastAttempt > WIFI_RETRY_INTERVAL) {
    lastAttempt = millis();
    connectToWiFi();
  }
}

void loop() {
  esp_task_wdt_reset();
  unsigned long now = millis();

  // Calculate flow rate at regular intervals
  if (now - lastFlowCalcTime > FLOW_CALC_INTERVAL) {
    calculateFlowRate();
    lastFlowCalcTime = now;
  }

  // Send data to server at regular intervals
  if (now - lastSendTime > SERVER_SEND_INTERVAL) {
    ensureWiFiConnection();
    if (WiFi.status() == WL_CONNECTED) {
      sendToServer();
      checkForAdminCommand();
    }
    lastSendTime = now;
  }

  // Check and update valve state
  updateValveState();
  
  // Check for manual override timeout
  if (manualValveControl && millis() - manualCommandTime > MANUAL_OVERRIDE_TIMEOUT) {
    manualValveControl = false;
    Serial.println("🔄 Manual control timeout - Returning to AUTO mode");
  }
}

void calculateFlowRate() {
  noInterrupts();
  unsigned long pulses = pulseCount;
  pulseCount = 0;
  interrupts();

  flowRate = pulses / FLOW_CALIBRATION;
  float liters = flowRate / 60.0;
  
  // Track water usage when valve is open
  if (valveState == OPEN && liters > 0) {
    sessionVolume += liters;
    totalVolume += liters;
  }

  // Save data to flash at regular intervals
  if (millis() - lastSaveTime > SAVE_INTERVAL) {
    preferences.begin("water-meter", false);
    preferences.putFloat("totalVolume", totalVolume);
    preferences.putString("mode", operationMode);
    preferences.putUInt("valveState", valveState);
    preferences.putBool("manualControl", manualValveControl);
    preferences.end();
    lastSaveTime = millis();
    Serial.println("✅ Saved to flash");
  }
}

void updateValveState() {
  if (valveOperating && millis() - valveOperationStart >= VALVE_OPERATION_TIME) {
    digitalWrite(VALVE_ENABLE_PIN, LOW);
    valveOperating = false;
    
    // Update valve state after operation completes
    if (valveState == OPENING) {
      valveState = OPEN;
      Serial.println("✅ Valve fully OPEN");
    } else if (valveState == CLOSING) {
      valveState = CLOSED;
      Serial.println("✅ Valve fully CLOSED");
    }
    
    // Save the new valve state
    preferences.begin("water-meter", false);
    preferences.putUInt("valveState", valveState);
    preferences.end();
  }
}

void openValve() {
  if (valveState == OPEN || valveState == OPENING) return;
  
  valveState = OPENING;
  digitalWrite(VALVE_IN1_PIN, HIGH);
  digitalWrite(VALVE_IN2_PIN, LOW);
  digitalWrite(VALVE_ENABLE_PIN, HIGH);
  valveOperating = true;
  valveOperationStart = millis();
  Serial.println("🔓 Opening valve...");
}

void closeValve() {
  if (valveState == CLOSED || valveState == CLOSING) return;
  
  valveState = CLOSING;
  digitalWrite(VALVE_IN1_PIN, LOW);
  digitalWrite(VALVE_IN2_PIN, HIGH);
  digitalWrite(VALVE_ENABLE_PIN, HIGH);
  valveOperating = true;
  valveOperationStart = millis();
  Serial.println("🔒 Closing valve...");
}

void sendToServer() {
  HTTPClient http;
  String url = "http://" + String(serverIP) + logEndpoint;
  http.begin(url);
  http.setTimeout(5000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Connection", "close");

  String json = "{";
  json += "\"flow\":" + String(flowRate, 2) + ",";
  json += "\"volume\":" + String(sessionVolume, 2) + ",";
  json += "\"total_volume\":" + String(totalVolume, 2) + ",";
  json += "\"mode\":\"" + operationMode + "\",";
String valveStatusStr = "unknown";
if (valveState == OPEN || valveState == OPENING) {
  valveStatusStr = "open";
} else if (valveState == CLOSED || valveState == CLOSING) {
  valveStatusStr = "closed";
}
json += "\"valve_status\":\"" + valveStatusStr + "\",";

  json += "\"balance\":0.0,"; 
  json += "\"meter_serial\":\"" + String(meter_serial) + "\",";
  json += "\"manual_control\":" + String(manualValveControl ? "true" : "false");
  json += "}";

  esp_task_wdt_reset();
  int code = http.POST(json);
  esp_task_wdt_reset();

  if (code == HTTP_CODE_OK) {
    Serial.println("✅ Data sent to server");
    sessionVolume = 0;
  } else {
    Serial.println("❌ Server send failed: " + String(code) + " - " + http.errorToString(code));
  }
  http.end();
}

void checkForAdminCommand() {
  HTTPClient http;
  String url = "http://" + String(serverIP) + commandEndpoint + "?meter_serial=" + meter_serial;
  http.begin(url);
  http.setTimeout(5000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Connection", "close");

  Serial.println("🔍 Requesting command from: " + url);
  
  esp_task_wdt_reset();
  int httpCode = http.GET();
  esp_task_wdt_reset();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    payload.trim();
    Serial.println("📥 Raw server response: " + payload);

    // Check if response is valid JSON
    if (payload.startsWith("{") && payload.endsWith("}")) {
      DynamicJsonDocument doc(512);
      DeserializationError error = deserializeJson(doc, payload);
      
      if (error) {
        Serial.println("❌ JSON parsing failed: " + String(error.c_str()));
        http.end();
        return;
      }

      // Get required fields with default values
      const char* status = doc["status"] | "error";
      float currentBalance = doc["balance"] | 0.0f;
      const char* valveCommand = doc["valve_command"] | "";
      const char* mode = doc["mode"] | "";

      // Only process if status is success
      if (strcmp(status, "success") == 0) {
        Serial.println("✅ Valid command received");

        // Process valve commandsSSSS
        if (strcmp(valveCommand, "open") == 0) {
          manualValveControl = true;
          manualCommandTime = millis();
          openValve();
          Serial.println("🔄 Manual OPEN command received");
        } 
        else if (strcmp(valveCommand, "close") == 0) {
          manualValveControl = true;
          manualCommandTime = millis();
          closeValve();
          Serial.println("🔄 Manual CLOSE command received");
        } 
        else if (strcmp(valveCommand, "auto") == 0) {
          manualValveControl = false;
          Serial.println("🔄 AUTO mode activated");
        }

        // Process mode changes
        if (strcmp(mode, "prepaid") == 0 && operationMode != "prepaid") {
          operationMode = "prepaid";
          preferences.begin("water-meter", false);
          preferences.putString("mode", operationMode);
          preferences.end();
          Serial.println("🔄 Mode changed to PREPAID");
        } 
        else if (strcmp(mode, "postpaid") == 0 && operationMode != "postpaid") {
          operationMode = "postpaid";
          preferences.begin("water-meter", false);
          preferences.putString("mode", operationMode);
          preferences.end();
          Serial.println("🔄 Mode changed to POSTPAID");
          
          // In postpaid mode, open valve if not in manual mode
          if (!manualValveControl && (valveState == CLOSED || valveState == CLOSING)) {
            openValve();
          }
        }

        // Automatic valve control based on balance (if not in manual mode)
        if (!manualValveControl) {
          if (strcmp(operationMode.c_str(), "prepaid") == 0) {
            if (currentBalance > MIN_BALANCE_THRESHOLD) {
              if (valveState == CLOSED || valveState == CLOSING) {
                Serial.printf("💰 Balance available (%.2f KES), opening valve\n", currentBalance);
                openValve();
              }
            } else {
              if (valveState == OPEN || valveState == OPENING) {
                Serial.printf("⚠ Low balance (%.2f KES), closing valve\n", currentBalance);
                closeValve();
              }
            }
          } else { // Postpaid mode
            if (valveState == CLOSED || valveState == CLOSING) {
              Serial.println("📝 Postpaid mode, opening valve");
              openValve();
            }
          }
        }
      } else {
        Serial.println("⚠ Server returned error status: " + String(status));
      }
    } else {
      Serial.println("⚠ Invalid JSON response from server");
    }
  } else {
    Serial.println("❌ HTTP error: " + String(httpCode) + " - " + http.errorToString(httpCode));
  }

  http.end();
}