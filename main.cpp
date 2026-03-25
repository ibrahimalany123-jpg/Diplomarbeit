#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
 
// =====================
// HIER EINTRAGEN!
// =====================
#define WIFI_SSID   "HTL-WLAN-IoT"          // <-- HIER EINTRAGEN
#define WIFI_PASS   "HTL2IoT!"      // <-- HIER EINTRAGEN
 
#define API_URL     "http://10.115.61.62/api/scan.php" 
#define API_KEY     "Marcel123!"          // <-- MUSS GENAU WIE IN scan.php SEIN
 
#define LOCATION_CODE "W119"  // <-- hier fix, wie du willst
 
// =================== Steuer-Pins ===================
#define MOD_L_PIN 26
#define MOD_R_PIN 32
#define SHD_L_PIN 14
#define SHD_R_PIN 25
 
// =================== Read-Pins =====================
#define DEMOD_OUT_L_PIN 27   // DEMOD_OUT_L -> IO27
#define RDY_CLK_L_PIN   34   // RDY/CLK_L   -> IO34 (input-only)
 
// =================== Decoder-Parameter =============
static constexpr uint16_t DELAYVAL_US   = 384;
static constexpr uint32_t TIMEOUT_LOOPS = 200000;
 
// Holds 5 bytes Tag-ID
uint8_t tagData[5];
 
// ------------------ Decode (EM4100 Manchester/Parity) -----------------
bool decodeTag(uint8_t *buf)
{
  uint8_t i = 0;
  uint32_t timeCount;
  bool timeOutFlag = false;
  uint8_t row, col;
  uint8_t row_parity;
  uint16_t col_parity[5];
  uint8_t dat;
  uint8_t j;
 
  while (true)
  {
    timeCount = 0;
    while (digitalRead(DEMOD_OUT_L_PIN) == LOW)
    {
      if (++timeCount >= TIMEOUT_LOOPS) return false;
    }
 
    delayMicroseconds(DELAYVAL_US);
 
    if (digitalRead(DEMOD_OUT_L_PIN) == HIGH)
    {
      for (i = 0; i < 8; i++)
      {
        timeCount = 0;
        while (digitalRead(DEMOD_OUT_L_PIN) == HIGH)
        {
          if (++timeCount >= TIMEOUT_LOOPS) { timeOutFlag = true; break; }
        }
        if (timeOutFlag) return false;
 
        delayMicroseconds(DELAYVAL_US);
        if (digitalRead(DEMOD_OUT_L_PIN) == LOW)
        {
          return false;
        }
      }
 
      timeCount = 0;
      while (digitalRead(DEMOD_OUT_L_PIN) == HIGH)
      {
        if (++timeCount >= TIMEOUT_LOOPS) return false;
      }
 
      for (int k = 0; k < 5; k++) buf[k] = 0;
 
      col_parity[0] = col_parity[1] = col_parity[2] = col_parity[3] = col_parity[4] = 0;
 
      for (row = 0; row < 11; row++)
      {
        row_parity = 0;
        j = row >> 1;
 
        for (col = 0; col < 5; col++)
        {
          delayMicroseconds(DELAYVAL_US);
          dat = digitalRead(DEMOD_OUT_L_PIN) ? 1 : 0;
 
          if (col < 4 && row < 10)
          {
            buf[j] <<= 1;
            buf[j] |= dat;
          }
 
          row_parity += dat;
          col_parity[col] += dat;
 
          timeCount = 0;
          while (digitalRead(DEMOD_OUT_L_PIN) == dat)
          {
            if (++timeCount >= TIMEOUT_LOOPS) { timeOutFlag = true; break; }
          }
          if (timeOutFlag) return false;
        }
 
        if (row < 10)
        {
          if (row_parity & 0x01) return false;
        }
      }
 
      if ((col_parity[0] & 0x01) || (col_parity[1] & 0x01) ||
          (col_parity[2] & 0x01) || (col_parity[3] & 0x01))
      {
        return false;
      }
 
      return true;
    }
  }
}
 
bool compareTagData(const uint8_t *a, const uint8_t *b)
{
  for (int j = 0; j < 5; j++)
    if (a[j] != b[j]) return false;
  return true;
}
 
void transferToBuffer(const uint8_t *src, uint8_t *dst)
{
  for (int j = 0; j < 5; j++) dst[j] = src[j];
}
 
bool scanForTag(uint8_t *outTag)
{
  static uint8_t lastRead[5];
  static int readCount = 0;
 
  uint8_t current[5];
  bool ok = decodeTag(current);
 
  if (!ok)
  {
    readCount = 0;
    return false;
  }
 
  readCount++;
 
  if (readCount == 1)
  {
    transferToBuffer(current, lastRead);
    return false;
  }
 
  if (readCount == 2)
  {
    bool verified = compareTagData(current, lastRead);
    readCount = 0;
    if (verified)
    {
      transferToBuffer(current, outTag);
      return true;
    }
    return false;
  }
 
  readCount = 0;
  return false;
}
 
// ============ Tag -> "74,11,23,99,0" ============
String tagToDecCsv(const uint8_t *b5) {
  char s[32];
  snprintf(s, sizeof(s), "%u,%u,%u,%u,%u", b5[0], b5[1], b5[2], b5[3], b5[4]);
  return String(s);
}
 
// ============ HTTP POST to server ============
bool postScanToServer(const String& tagUid) {
  if (WiFi.status() != WL_CONNECTED) return false;
 
  HTTPClient http;
  http.begin(API_URL);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
 
  String body = "api_key=" + String(API_KEY) +
                "&tag_uid=" + tagUid +
                "&location=" + String(LOCATION_CODE);
 
  int code = http.POST(body);
  String resp = http.getString();
  http.end();
 
  Serial.printf("API %d: %s\n", code, resp.c_str());
  return (code == 200);
}
 
void wifiConnect() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
 
  Serial.print("WiFi connecting");
  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(300);
    Serial.print(".");
  }
  Serial.println();
 
  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("WiFi OK, IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("WiFi FAILED (Decoder läuft, aber DB logging geht nicht).");
  }
}
 
void setup()
{
  setCpuFrequencyMhz(240);
 
  pinMode(MOD_L_PIN, OUTPUT);
  pinMode(MOD_R_PIN, OUTPUT);
  pinMode(SHD_L_PIN, OUTPUT);
  pinMode(SHD_R_PIN, OUTPUT);
 
  pinMode(DEMOD_OUT_L_PIN, INPUT);
  pinMode(RDY_CLK_L_PIN, INPUT);
 
  // Invertiert: ESP HIGH => EM4095 LOW
  // SHD LOW am EM4095 = aktiv
  digitalWrite(SHD_L_PIN, HIGH);
  digitalWrite(SHD_R_PIN, HIGH);
 
  // MOD LOW am EM4095 = Feld an
  digitalWrite(MOD_L_PIN, HIGH);
  digitalWrite(MOD_R_PIN, HIGH);
 
  Serial.begin(115200);
  delay(200);
 
  wifiConnect();
 
  Serial.println("EM4095 aktiv, Feld EIN. Warte auf EM4100...");
}
 
void loop()
{
  static uint32_t lastSentMs = 0;
 
  if (scanForTag(tagData))
  {
    String uid = tagToDecCsv(tagData);
 
    // Spam-Schutz (2s)
    uint32_t now = millis();
    if (now - lastSentMs < 2000) return;
    lastSentMs = now;
 
    Serial.print("TAG (DEC CSV): ");
    Serial.println(uid);
 
    bool ok = postScanToServer(uid);
    Serial.println(ok ? "Logged to DB" : "DB log failed");
  }
}