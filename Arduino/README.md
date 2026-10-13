# Arduino LoRa Hardware Integration

This folder contains the ESP32 LoRa sender/gateway and receiver sketches used by the Stitch Rescue prototype.

## Current Two-Radio Flow

1. A phone joins the `STITCH-LORA-GW1` Wi-Fi access point hosted by `Sender_GPS` (demo password: `stitchsos`).
2. The sender opens `http://192.168.4.1`, enters a required full name, optionally enters up to 80 description characters, and presses **SEND SOS / EMERGENCY REPORT**.
3. `Sender_GPS` attaches the GY-NEO6MV2/NEO-6M location, gateway ID, persistent message sequence, and sender node ID, then sends the compact packet directly over LoRa.
4. `Reciever` receives the packet, sends a direct radio ACK, and posts the alert to Laravel through Wi-Fi.
5. Laravel saves the alert as an `IncidentReport` with `transmission_type = lora`.
6. The unified admin/monitoring dashboard and Incident Feed display the report as **LoRa**, including full name, gateway GPS pin, source gateway, short description, and radio telemetry.

`Gateway1` and `Gateway2` are retained for the later multi-hop phase; they are not required for the current two-radio sender-to-receiver test. `sketch_may21a` is a GPS-only test sketch.

## Receiver API Setup

Copy this file:

```txt
Arduino/Arduino/Reciever/arduino_secrets.example.h
```

To this local-only file:

```txt
Arduino/Arduino/Reciever/arduino_secrets.h
```

Then set:

```cpp
const char* WIFI_SSID = "YOUR_WIFI_NAME";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char* STITCH_LORA_API_URL = "https://stitch-web-demo.onrender.com/api/v1/lora/alerts";
const char* STITCH_LORA_TOKEN = "same-token-as-LORA_INGEST_TOKEN";
```

`arduino_secrets.h` is ignored by Git so real WiFi and API credentials stay private.

## Laravel Environment

Set the same shared token in Laravel:

```env
LORA_INGEST_TOKEN=replace-with-a-long-random-token
```

For Render, add the same value to the `stitch-web-demo` environment variables.

## Current Direct Packet Format

The receiver expects the sender packet in this format:

```txt
ALERT|S01|42|G1|10.307156|124.980718|7|Juan Dela Cruz|Motorcycle crash, one injured person.
```

Fields:

```txt
ALERT | sender id | sequence | source gateway id | gateway latitude | gateway longitude | satellites | full name | optional description
```

The full name is limited to 48 characters and is required. The optional description is limited to 80 characters so the complete packet remains below the configured 220-byte safety ceiling (and below the LoRa library's 255-byte packet limit).

`Reciever` also accepts the existing relay wrapper for later mesh testing:

```txt
GW|G1|-70|9.5|ALERT|S01|42|G1|10.307156|124.980718|7|Juan Dela Cruz|Short description
```

Laravel deduplicates LoRa alerts by `sender_id + sequence`, and `Sender_GPS` persists its sequence in ESP32 preferences so a retry or reboot does not create an accidental duplicate.
