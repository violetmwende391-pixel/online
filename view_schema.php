<!-- /*
  LoRa Gateway (ESP32) - Multi-slave, persistent mapping, status web UI
  - Forwards LoRa JSON to https://smart-meter-server.onrender.com/log_data.php (POST JSON)
  - If log returns 404 and packet has device_id -> POST device_id to /get_serial.php -> store mapping
  - After successful log -> GET /get_command?meter_serial=<serial>, enqueue commands and send via LoRa
  - Stores device_id->meter_serial mappings and last_seen/last_reading in Preferences (flash)
  - Provides local status UI: http://<gateway-ip>/  and JSON at /status.json
*/

#include <SPI.h>
#include <LoRa.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include <Preferences.h>
#include <WebServer.h>

// ========== CONFIG ==========
#define LORA_BAND 923E6
#define LORA_SS   18
#define LORA_RST  14
#define LORA_DIO0 26

const char* ssid = "GalaxyA23B729";
const char* password = "Chris1234";
const char* apiBaseUrl = "https://smart-meter-server.onrender.com"; // no trailing slash

// ========== QUEUE ITEM ==========
struct PendingCommand {
  char meterSerial[32];
  char commandType[16];   // "valve_command" or "mode"
  char commandValue[64];
  unsigned long receivedTime;
};

QueueHandle_t commandQueue;
Preferences prefs;
WebServer webServer(80);

unsigned long lastCommandCheck = 0;
const long commandCheckInterval = 10000; // 10s
String lastReceivedMeterSerial = "";

// In-memory cache stored as ArduinoJson object; persists to prefs as a JSON string
// Structure:
// {
//   "mappings": { "device_id": "meter_serial", ... },
//   "meta": {
//      "meter_serial": { "last_seen": 123456789, "last_reading": { ... } },
//      ...
//   }
// }
StaticJsonDocument<8192> storeDoc; // adjust size if many slaves; this holds mappings+meta

// ========== Helpers ==========
static void safeStrCopy(char* dest, const String& src, size_t maxlen) {
  size_t toCopy = min((size_t)src.length(), maxlen - 1);
  src.toCharArray(dest, toCopy + 1);
  dest[toCopy] = '\0';
}

String urlEncode(const String &str) {
  String ret;
  char buf[4];
  for (size_t i = 0; i < str.length(); i++) {
    char c = str.charAt(i);
    if ( ('a' <= c && c <= 'z') ||
         ('A' <= c && c <= 'Z') ||
         ('0' <= c && c <= '9') ||
         c == '-' || c == '_' || c == '.' || c == '~' ) {
      ret += c;
    } else {
      sprintf(buf, "%%%02X", (uint8_t)c);
      ret += buf;
    }
  }
  return ret;
}

String isoTimestamp(unsigned long epochMillis) {
  // return simple human-friendly elapsed/time. We'll produce millis->local time string approximate.
  // For simplicity we show epochMillis as millis() difference from gateway boot (can't get RTC). We'll show relative secs.
  unsigned long now = millis();
  long diff = (long)((now > epochMillis) ? (now - epochMillis) : 0);
  if (diff < 1000) return "just now";
  if (diff < 60000) return String(diff / 1000) + "s ago";
  if (diff < 3600000) return String(diff / 60000) + "m ago";
  if (diff < 86400000) return String(diff / 3600000) + "h ago";
  return String(diff / 86400000) + "d ago";
}

// Persist storeDoc to Preferences under key "store_json"
void saveStoreToPrefs() {
  String out;
  serializeJson(storeDoc, out);
  prefs.begin("gateway_store", false);
  prefs.putString("store_json", out);
  prefs.end();
  Serial.println("Store persisted to flash (size " + String(out.length()) + ")");
}

// Load storeDoc from Preferences (if present), else initialize empty shape
void loadStoreFromPrefs() {
  prefs.begin("gateway_store", true);
  String s = prefs.getString("store_json", "");
  prefs.end();
  if (s.length() > 0) {
    DeserializationError err = deserializeJson(storeDoc, s);
    if (err) {
      Serial.println("Failed to parse stored JSON, resetting store.");
      storeDoc.clear();
    } else {
      Serial.println("Loaded store from prefs.");
    }
  } else {
    // Initialize structure
    storeDoc.to<JsonObject>(); // ensure doc exists
    storeDoc["mappings"] = JsonObject();
    storeDoc["meta"] = JsonObject();
    Serial.println("Initialized empty store.");
  }
}

// Helper: set mapping device_id -> meter_serial and persist
void setMappingAndPersist(const String& deviceId, const String& meterSerial) {
  if (deviceId.length() == 0 || meterSerial.length() == 0) return;
  JsonObject mappings = storeDoc["mappings"].as<JsonObject>();
  mappings[deviceId] = meterSerial;
  saveStoreToPrefs();
  Serial.println("Mapping saved: " + deviceId + " -> " + meterSerial);
}

// Helper: update meta for meterSerial (last_seen, last_reading)
void updateMetaForSerial(const String& meterSerial, const DynamicJsonDocument& reading) {
  if (meterSerial.length() == 0) return;
  JsonObject meta = storeDoc["meta"].as<JsonObject>();
  JsonObject entry = meta[meterSerial].as<JsonObject>();
  if (entry.isNull()) {
    entry = meta.createNestedObject(meterSerial);
  }
  entry["last_seen_ms"] = millis();
  // copy reading object into entry["last_reading"]
  entry.remove("last_reading");
  entry.createNestedObject("last_reading");
  serializeJson(reading, Serial); // debug
  // We'll copy using a temporary string to avoid deep copying issues
  String tmp;
  serializeJson(reading, tmp);
  DynamicJsonDocument tmpDoc(1024);
  deserializeJson(tmpDoc, tmp);
  entry["last_reading"] = tmpDoc.as<JsonVariant>();
  saveStoreToPrefs();
}

// ========== WiFi & LoRa ==========
void connectToWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nConnected! IP: " + WiFi.localIP().toString());
  } else {
    Serial.println("\nFailed to connect to WiFi (check credentials / signal)");
  }
}

void setupLoRa() {
  SPI.begin(5, 19, 27, LORA_SS);
  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(LORA_BAND)) {
    Serial.println("LoRa init failed!");
    while (1) delay(1000);
  }
  LoRa.setTxPower(20);
  LoRa.setSpreadingFactor(9);
  LoRa.setSignalBandwidth(125E3);
  LoRa.setCodingRate4(5);
  LoRa.enableCrc();
  LoRa.setSyncWord(0xF3); // Must match slave!
  Serial.println("LoRa Ready");
}

// ========== Server interactions ==========
String callGetSerialForDevice(const String& deviceId) {
  if (deviceId.length() == 0) return "";

  // check in-memory mapping first
  if (storeDoc.containsKey("mappings")) {
    JsonObject mappings = storeDoc["mappings"].as<JsonObject>();
    if (mappings.containsKey(deviceId)) {
      String cached = String(mappings[deviceId].as<const char*>());
      Serial.println("Cached mapping found: " + deviceId + " -> " + cached);
      return cached;
    }
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("No WiFi - cannot call get_serial.php");
    return "";
  }

  HTTPClient http;
  String url = String(apiBaseUrl) + "/get_serial.php";
  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String body = "device_id=" + urlEncode(deviceId);
  int code = http.POST(body);
  String resp = http.getString();
  Serial.printf("POST %s -> %d\n", url.c_str(), code);
  Serial.println("Body: " + resp);

  if (code == HTTP_CODE_OK) {
    String serial = resp;
    serial.trim();
    if (serial.length() > 0) {
      // update mapping
      setMappingAndPersist(deviceId, serial);
      http.end();
      return serial;
    }
  } else {
    Serial.println("get_serial.php returned code: " + String(code));
    Serial.println("Body: " + resp);
  }
  http.end();
  return "";
}

bool forwardToAPIAndMaybeRegister(const String& originalPacket) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("No WiFi - cannot forward data");
    return false;
  }

  DynamicJsonDocument inDoc(2048);
  DeserializationError derr = deserializeJson(inDoc, originalPacket);
  if (derr) {
    Serial.println("Received non-JSON LoRa payload. Skipping forward.");
    return false;
  }

  // Build payload expected by log_data.php
  DynamicJsonDocument outDoc(1024);
  if (inDoc.containsKey("flow")) outDoc["flow"] = (double)inDoc["flow"];
  if (inDoc.containsKey("volume")) outDoc["volume"] = (double)inDoc["volume"];
  if (inDoc.containsKey("total_volume")) outDoc["total_volume"] = (double)inDoc["total_volume"];
  if (inDoc.containsKey("valve_status")) outDoc["valve_status"] = String(inDoc["valve_status"].as<const char*>());
  if (inDoc.containsKey("balance")) outDoc["balance"] = (double)inDoc["balance"];

  String meterSerial = "";
  if (inDoc.containsKey("meter_serial")) meterSerial = String(inDoc["meter_serial"].as<const char*>());

  // If no meter_serial, try get mapping via device_id
  if (meterSerial.length() == 0 && inDoc.containsKey("device_id")) {
    String dev = inDoc["device_id"].as<const char*>();
    meterSerial = callGetSerialForDevice(dev);
  }

  if (meterSerial.length() > 0) outDoc["meter_serial"] = meterSerial;

  // store last_reading / last_seen for the meter (if we have meterSerial)
  if (meterSerial.length() > 0) {
    // create temp doc for last reading copy
    DynamicJsonDocument tmpReading(1024);
    tmpReading = inDoc; // copy
    updateMetaForSerial(meterSerial, tmpReading);
  }

  // Validate required fields
  if (!outDoc.containsKey("flow") || !outDoc.containsKey("volume") ||
      !outDoc.containsKey("total_volume") || !outDoc.containsKey("valve_status") ||
      !outDoc.containsKey("meter_serial")) {
    Serial.println("Missing required fields for log_data.php; not sending.");
    return false;
  }

  // POST JSON
  HTTPClient http;
  String url = String(apiBaseUrl) + "/log_data.php";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  String outPayload;
  serializeJson(outDoc, outPayload);
  int code = http.POST(outPayload);
  String resp = http.getString();
  Serial.printf("POST %s -> %d\n", url.c_str(), code);
  Serial.println("Response: " + resp);

  if (code == HTTP_CODE_OK) {
    // Successful logging -> immediately check commands
    String serialToQuery = outDoc["meter_serial"].as<const char*>();
    if (serialToQuery.length() > 0) checkForCommandForSerial(serialToQuery);
    http.end();
    return true;
  } else if (code == HTTP_CODE_NOT_FOUND) {
    // meter not found; try auto-registration if device_id present
    Serial.println("log_data.php: meter not found (404)");
    if (inDoc.containsKey("device_id")) {
      String dev = inDoc["device_id"].as<const char*>();
      String newSerial = callGetSerialForDevice(dev);
      if (newSerial.length() > 0) {
        outDoc["meter_serial"] = newSerial;
        String retryPayload;
        serializeJson(outDoc, retryPayload);
        HTTPClient http2;
        http2.begin(String(apiBaseUrl) + "/log_data.php");
        http2.addHeader("Content-Type", "application/json");
        int retryCode = http2.POST(retryPayload);
        String retryResp = http2.getString();
        Serial.printf("Retry POST -> %d\n", retryCode);
        Serial.println("Response: " + retryResp);
        if (retryCode == HTTP_CODE_OK) {
          checkForCommandForSerial(newSerial);
          http2.end();
          http.end();
          return true;
        }
        http2.end();
      } else {
        Serial.println("Auto-registration failed or no meters available.");
      }
    } else {
      Serial.println("No device_id to attempt auto-registration.");
    }
  } else {
    Serial.println("log_data.php returned code: " + String(code));
  }
  http.end();
  return false;
}


void forwardConfirmationToServer(int commandId) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("No WiFi - cannot forward confirmation");
    return;
  }

  HTTPClient http;
  String url = String(apiBaseUrl) + "/get_command.php";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");

  String json = "{\"command_id\":" + String(commandId) + "}";
  int code = http.POST(json);
  String body = http.getString();
  
  Serial.printf("POST confirmation for command %d -> %d\n", commandId, code);
  Serial.println("Response: " + body);
  
  http.end();
}


void checkForCommandForSerial(const String& meterSerial) {
  if (meterSerial.length() == 0) return;
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("No WiFi - cannot check commands");
    return;
  }

  HTTPClient http;
  String url = String(apiBaseUrl) + "/get_command.php?meter_serial=" + urlEncode(meterSerial);
  http.begin(url);
  int code = http.GET();
  String body = http.getString();
  Serial.printf("GET %s -> %d\n", url.c_str(), code);
  Serial.println("Body: " + body);

  if (code == HTTP_CODE_OK) {
    DynamicJsonDocument doc(512);
    DeserializationError derr = deserializeJson(doc, body);
    if (derr) {
      Serial.println("Failed to parse get_command JSON");
      http.end();
      return;
    }
    String status = doc["status"] | "";
    if (status != "success") {
      Serial.println("get_command status != success");
      http.end();
      return;
    }

    String valveCmd = doc["valve_command"] | "";
    String modeCmd  = doc["mode"] | "";

    if (valveCmd.length() > 0) {
      PendingCommand q; memset(&q,0,sizeof(q));
      safeStrCopy(q.meterSerial, meterSerial, sizeof(q.meterSerial));
      safeStrCopy(q.commandType, "valve_command", sizeof(q.commandType));
      safeStrCopy(q.commandValue, valveCmd, sizeof(q.commandValue));
      q.receivedTime = millis();
      if (xQueueSend(commandQueue, &q, 0) != pdTRUE) Serial.println("Command queue full — valve_command dropped");
      else Serial.println("Queued valve_command: " + valveCmd);
    }
    if (modeCmd.length() > 0) {
      PendingCommand q; memset(&q,0,sizeof(q));
      safeStrCopy(q.meterSerial, meterSerial, sizeof(q.meterSerial));
      safeStrCopy(q.commandType, "mode", sizeof(q.commandType));
      safeStrCopy(q.commandValue, modeCmd, sizeof(q.commandValue));
      q.receivedTime = millis();
      if (xQueueSend(commandQueue, &q, 0) != pdTRUE) Serial.println("Command queue full — mode dropped");
      else Serial.println("Queued mode command: " + modeCmd);
    }
  } else {
    Serial.println("get_command returned HTTP code: " + String(code));
    Serial.println("Body: " + body);
  }
  http.end();
}

void sendLoRaCommandFromStruct(const PendingCommand& cmd) {
  DynamicJsonDocument doc(512); // Increased size
  
  // Get fresh command from server (don't just use queued command)
  HTTPClient http;
  String url = String(apiBaseUrl) + "/get_command.php?meter_serial=" + urlEncode(String(cmd.meterSerial));
  http.begin(url);
  int code = http.GET();
  
  if (code == HTTP_CODE_OK) {
    String body = http.getString();
    deserializeJson(doc, body); // Use server's full response
    
    // Add target field for LoRa addressing
    doc["target"] = String(cmd.meterSerial);
    
    String output;
    serializeJson(doc, output);
    LoRa.beginPacket();
    LoRa.print(output);
    LoRa.endPacket();
    Serial.println("Sent full LoRa command: " + output);
  } else {
    Serial.println("Failed to refresh command from server");
  }
  http.end();
}

// ========== Web UI ==========
String buildStatusHtml() {
  String html = "<!doctype html><html><head><meta charset='utf-8'><title>Gateway Status</title>"
                "<style>body{font-family:Arial;padding:12px}table{border-collapse:collapse;width:100%}"
                "th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#eee}</style></head><body>";
  html += "<h2>LoRa Gateway Status</h2>";
  html += "<p>IP: " + WiFi.localIP().toString() + " &nbsp; Queue size: " + String(uxQueueMessagesWaiting(commandQueue)) + "</p>";

  html += "<h3>Mappings (device_id -> meter_serial)</h3>";
  html += "<table><tr><th>Device ID</th><th>Meter Serial</th><th>Last Seen</th><th>Last Reading</th></tr>";
  if (storeDoc.containsKey("mappings")) {
    JsonObject mappings = storeDoc["mappings"].as<JsonObject>();
    JsonObject meta = storeDoc["meta"].as<JsonObject>();
    for (JsonPair kv : mappings) {
      String dev = String(kv.key().c_str());
      String serial = String(kv.value().as<const char*>());
      html += "<tr><td>" + dev + "</td><td>" + serial + "</td>";
      if (meta.containsKey(serial)) {
        JsonObject ent = meta[serial].as<JsonObject>();
        unsigned long lastSeen = ent["last_seen_ms"] | 0;
        String lastSeenStr = isoTimestamp(lastSeen);
        html += "<td>" + lastSeenStr + "</td>";
        // last_reading
        String readingStr;
        if (ent.containsKey("last_reading")) {
          String tmp; serializeJson(ent["last_reading"], tmp);
          readingStr = tmp;
        } else readingStr = "";
        html += "<td><pre style='white-space:pre-wrap;max-width:600px;'>" + readingStr + "</pre></td>";
      } else {
        html += "<td>-</td><td>-</td>";
      }
      html += "</tr>";
    }
  }
  html += "</table>";

  html += "<p>Refesh to update. /status.json gives machine-readable output.</p>";
  html += "</body></html>";
  return html;
}

void handleRoot() {
  webServer.send(200, "text/html", buildStatusHtml());
}

void handleStatusJson() {
  String out;
  serializeJson(storeDoc, out);
  // also add some runtime info
  DynamicJsonDocument wrapper(4096);
  wrapper["ip"] = WiFi.localIP().toString();
  wrapper["queue_size"] = uxQueueMessagesWaiting(commandQueue);
  wrapper["store"] = storeDoc;
  String result;
  serializeJson(wrapper, result);
  webServer.send(200, "application/json", result);
}

// ========== Setup/Loop ==========
void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n=== LoRa Gateway (multi-slave, status UI) ===");

  // create queue for commands
  commandQueue = xQueueCreate(20, sizeof(PendingCommand));
  if (commandQueue == NULL) {
    Serial.println("Failed to create command queue - aborting");
    while (1) delay(1000);
  }

  prefs.begin("gateway_store", false);
  loadStoreFromPrefs();

  connectToWiFi();
  setupLoRa();

  // web server
  webServer.on("/", handleRoot);
  webServer.on("/status.json", handleStatusJson);
  webServer.begin();
  Serial.println("Web server started at / and /status.json");
}

void loop() {
  // web server handling
  webServer.handleClient();

  // Receive LoRa packets
  int packetSize = LoRa.parsePacket();
  if (packetSize) {
    String packet = "";
    while (LoRa.available()) packet += (char)LoRa.read();
    Serial.println("Received LoRa payload: " + packet);

    // parse packet to JSON
    DynamicJsonDocument tmpDoc(2048);
    if (deserializeJson(tmpDoc, packet) == DeserializationError::Ok) {
      // First check if this is a command confirmation
      if (tmpDoc.containsKey("confirmation") && tmpDoc["confirmation"] == true) {
        int commandId = tmpDoc["command_id"] | -1;
        if (commandId > 0) {
          forwardConfirmationToServer(commandId);
        }
      }
      
      // Then process the rest of the packet as normal
      // update mapping if packet contains device_id and meter_serial (or use callGetSerialForDevice)
      String deviceId = tmpDoc.containsKey("device_id") ? String(tmpDoc["device_id"].as<const char*>()) : "";
      String meterSerial = tmpDoc.containsKey("meter_serial") ? String(tmpDoc["meter_serial"].as<const char*>()) : "";

      if (deviceId.length() > 0 && meterSerial.length() > 0) {
        setMappingAndPersist(deviceId, meterSerial);
      } else if (deviceId.length() > 0 && meterSerial.length() == 0) {
        // try to get mapping from server (if not present in mappings)
        String mapped = callGetSerialForDevice(deviceId);
        if (mapped.length() > 0) meterSerial = mapped;
      }

      if (meterSerial.length() > 0) lastReceivedMeterSerial = meterSerial;

      // update meta (last_seen + last_reading)
      updateMetaForSerial(meterSerial, tmpDoc);
    }

    // forward to server (and auto-register if necessary)
    forwardToAPIAndMaybeRegister(packet);
  }
  // Periodic check for commands for lastReceivedMeterSerial
  if (millis() - lastCommandCheck > commandCheckInterval) {
    if (lastReceivedMeterSerial.length() > 0) {
      checkForCommandForSerial(lastReceivedMeterSerial);
    }
    lastCommandCheck = millis();
  }

  // Process queued commands
  if (uxQueueMessagesWaiting(commandQueue) > 0) {
    PendingCommand cmd;
    if (xQueueReceive(commandQueue, &cmd, 0) == pdTRUE) {
      sendLoRaCommandFromStruct(cmd);
    }
  }

  // Keep WiFi alive
  if (WiFi.status() != WL_CONNECTED) connectToWiFi();

  delay(50);
} -->