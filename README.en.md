# FrigateHub

![Symcon](https://img.shields.io/badge/Symcon-PHP%20module-blue)
![Module version](https://img.shields.io/badge/Module-0.19.0-informational)
![Symcon version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

*Deutsch: [README.md](README.md)*

[Frigate](https://frigate.video) in Symcon: Frigate is an open-source video surveillance system
(NVR) with AI object detection. FrigateHub brings its detections per camera and zone into Symcon as
variables and lets you switch detection, recording, masks and zones from there.

FrigateHub is also the way to cameras that are otherwise hard to integrate into Symcon: every camera
with an RTSP stream – Mobotix, Axis, Hikvision, Dahua, Reolink, Instar, Foscam, Doorbird, Ubiquiti,
TP-Link Tapo or no-name – becomes a Symcon camera with live video, recordings, archive and events,
without a Symcon module from the manufacturer. Pure cloud cameras without RTSP (Ring, Blink, Arlo,
Nest) usually do not work.

The module follows the language of your Symcon installation (English or German).

## What does this module do?

Frigate detects people, cars, animals, packages and more in the camera images and reports this via
MQTT. FrigateHub sits below Symcon's own MQTT server (or an MQTT client) and creates per camera:

| Variable | Meaning |
|---|---|
| Frigate reachable | Frigate is connected to the MQTT server (`frigate/available`) |
| Camera online | the camera's detect stream is running (`…/status/detect`) |
| Motion | Frigate currently sees motion |
| Alert / Detection | Frigate's review status: alert, or alert or detection (`…/review_status`) |
| Last alert, last detection, last object | time, objects, name (face/plate) and zone as text |
| People, cars, … | count per object class as soon as Frigate reports it |
| _Zone_: people, … | count per zone; the instance learns zones from the events |
| Sound … | audio detection, if enabled in Frigate |
| Switches | object detection, recording, snapshots, motion detection, masks, zones … |
| Motion threshold, motion: minimum area | Frigate's `motion.threshold`/`contour_area`, adjustable by slider |
| Recognized person, license plate, classification | Frigate's face recognition, license plate recognition and custom classification models, with score |
| AI description, AI assessment | Frigate's generative AI (GenAI): title and summary of an event, assessment normal/suspicious/dangerous |
| Snapshot (media object) | image of the last detection (via MQTT) or, on demand, the live image |

In addition there is a **Frigate server** instance for the server itself:

| Variable | Meaning |
|---|---|
| Frigate reachable | Frigate is connected to the MQTT server (`frigate/available`) |
| Latest version | “up to date” or “0.19.0 available” as soon as Frigate knows a newer one |
| Recordings storage free | free space below `/media/frigate/recordings` in GB |
| Faulty cameras, camera state | count and plain text: cameras without frames or with a poor/unusable connection |
| Recording OK, recording state | recording watchdog: is Frigate really recording? (see below) |

The performance values (version, uptime, CPU load, memory, inference time per detector, GPU load,
storage, and per camera frames/s, detections/s, connection quality, reconnects and skipped frames
in the last hour) are shown in the form under **Overview**, fetched fresh from Frigate. Tick
“Performance values additionally as variables” to archive them.

With a Frigate address the snapshot of every detection arrives in full camera resolution. Without
it FrigateHub uses the image Frigate sends via MQTT: a crop around the object (default 270 px high,
see `mqtt:` per camera in Frigate).

**Tile:** The instance can be placed directly in the tile visualization. It shows the live image
(optionally with boxes around detected objects), a red frame on alert, the counters and the latest
events with thumbnails. Tapping an event shows snapshot and clip — the whole period with all objects —
and below it the course per object as in Frigate (detected, entered zone, stationary, active, left);
tapping an entry jumps to that moment in the video. Tapping the live image enlarges
it; detection, recording and snapshots can be switched directly. The instance fetches the images
from Frigate itself and passes them on via its own WebHook, so the browser does not need to reach
Frigate. Every request needs a secret key of the instance, which can be renewed in the “Tile” panel.

Also in the tile:
- **Warnings** at the top if camera, detection, recording or snapshots are off in Frigate.
- **Events:** a dot marks unseen ones; opening marks them as reviewed in Frigate. ☆ keeps an event
  permanently, 💾 creates an export in Frigate.
- **📅 Archive:** all events of a day, filterable by alert, detection, unseen, AI suspicion and
  object. “🎞 Recording” plays the recording from a time of day (1–30 min).
- **Date and time** at the top right, running along with live video.
- **PTZ:** if Frigate can pan or zoom the camera, the enlarged live view shows a control pad with presets.

**Recording watchdog:** Frigate keeps reporting “running” even when it no longer stores anything –
for example when the USB disk with the recordings disconnects. The watchdog in the **Frigate server**
instance therefore checks every minute via Frigate's API (needs the Frigate address):

| Check | How it is detected |
|---|---|
| Frigate does not respond | no API response for 3 minutes |
| Write errors | new errors while storing recordings in Frigate's log (from 3 per check); on port 8971 the Frigate user needs the “admin” role |
| Recording storage changed | size or type of the storage below `/media/frigate/recordings` differs from the remembered state |
| Alert without recording | an alert ended 2 minutes ago and has no recording |
| Continuous recording stalled | newest recording older than 5 minutes (only cameras with continuous recording) |

Notifications go to the tile visualization, WebFront or e-mail: on every new fault, as a daily
reminder and as all-clear. After an intended disk change click “Accept current storage”.

**Overview of all cameras:** The **Frigate server** instance is also a tile with the live images of
all cameras of this Frigate instance and Birdseye, if Frigate provides it.

**IPSView and browser:** The same interface is available as a standalone page
`http://<Symcon-IP>:3777/hook/frigatehub<ID>?seite=1&k=<key>` – embed it in IPSView as a web page
element. The button “Show address for IPSView” in the “🖥️ Tile and IPSView” panel shows the full
address. Switches can only be operated if this is enabled in the form.

**Live video in the tile:** If Frigate's built-in restream (go2rtc) has a stream for the camera, the
tile plays real video (with sound, if the camera provides it) via WebRTC in the home network:

```yaml
go2rtc:
  streams:
    frontdoor:                                  # name = camera ID in Frigate
      - rtsp://user:{FRIGATE_RTSP_PASSWORD}@192.168.1.30:554/stream1
  webrtc:
    candidates:
      - 192.168.1.20:8555                       # IP of the Frigate host
      - stun:8555
```

Expose port 8555 (TCP and UDP) in Docker. The camera must deliver H.264. Away from home the tile
shows the still image. The video starts muted (browser rule); 🔇 turns the sound on.

**Push on alert:** In the “🔔 Push and notifications” panel enter one or more targets (tile
visualization, WebFront, or SMTP for e-mail with image). Every new alert then arrives once as a
notification. What counts as an alert is set in Frigate (`review: alerts: labels` / `required_zones`).
Push notifications cannot carry an image – tapping opens the camera tile; an SMTP target sends the
current camera image by e-mail.

`FRIG_MeldungMitSchnappschuss($id, "Text", true)` sends a notification with the current camera image
to the same targets – usable as an action in alarm systems and rules.

Switches only take a value once Frigate confirms it.

## Camera as a sensor

| Variable | Meaning | Frigate needs |
|---|---|---|
| State \<model\> | class from the state classification, e.g. gate `open`/`closed` | state classification (0.17+) |
| \<object\> stationary since, \<zone\>: \<object\> stationary since | time since which e.g. a car is parked in the carport (“-” = none) | object tracking |
| Package removed | a package that was lying there is gone | object `package` |
| \<zone\>: loitering | someone stays longer than N seconds in the zone (default 30 s, people) | zones |
| Recognized person (name), unknown face | who was recognized; time when a face was seen but nobody recognized | face recognition |
| Recognized vehicle, unknown license plate | name from Frigate's known plates, otherwise the plate; time for an unknown plate | license plate recognition |
| Last speed | average of the last measured object | zone with speed estimation |
| Trigger \<name\> | time a semantic trigger fired | semantic search with triggers |
| Spoken text | transcript of what was said at the camera | audio transcription |
| Today: people, cars …, alerts | daily counters, reset at midnight | — |
| Image suspicious, image state | camera covered/dirty, moved, or permanent motion | Frigate address |

The **image check** fetches a small live image every 5 minutes. “Covered/uniform” is checked around the
clock against the learned view of the same hour; a dark image at night is normal. “Moved” is checked only
in daylight (colour image) against the learned daytime views of the same and neighbouring hours, comparing
only the pattern — brightness and contrast do not count (sun, dusk, backlight). Infrared images are not
checked for this. Only three suspicious checks in a row raise a report. After an intended change click
“Learn view anew”.

## Robots in the image

Frigate often takes a mowing robot outdoors or a vacuum robot indoors for an animal or a person. In the
panel **“Robots in the image”** of every camera instance, choose which robots can appear in the image
(default: none). While an assigned robot is out of its station (and shortly after), FrigateHub does not
report such false detections as an alert — push, OpenHomeAlarm and the “Alert” variable stay quiet,
recording continues. What was not reported is shown in “Not reported (robot)”.

- **Sources:** every robot from the RoboHub module (found automatically, RoboHub with
  `ROBO_GetActivity()`) and Frigate+ with the object `robot_lawnmower`.
- **Never suppressed:** an upright person (taller than wide), a recognized face, vehicles, and anything
  while the robot state is unknown or outdated. When in doubt, FrigateHub alerts.

## Configure Frigate from Symcon

Many settings can be changed without opening Frigate's interface: panel **⚙️ Configure in Frigate**
in the form of every camera instance. It shows Frigate's current state; only what you changed is saved.

- recording on/off, how long alerts, detections, continuous and motion recordings are kept
- what is kept of alerts and detections: everything, only with motion, or while an object is active
  (“only with motion” drops seconds in which someone stands still)
- which object types Frigate detects, which count as alerts and in which zones
- per zone: which objects count (e.g. `car` in the carport) and the loitering time
- snapshots and retention, after how long an object counts as stationary
- license plate and face recognition per camera (if enabled globally in Frigate), sounds

Frigate writes the values to its `config.yml`, validates them and applies them immediately without a
restart; invalid values are rolled back by Frigate and the reason is shown in the form. This needs the
Frigate role “admin” (port 5000 always has it). Drawing zones and masks and adding cameras stay in
Frigate's interface.

## Requirements

- Symcon 9.0 or newer
- Frigate (tested with 0.18.2) sending via MQTT to Symcon:

```yaml
mqtt:
  enabled: true
  host: <IP of the Symcon server>
  port: 1883
  user: "{FRIGATE_MQTT_USER}"          # if the MQTT server requires credentials
  password: "{FRIGATE_MQTT_PASSWORD}"
```

## Setup

1. Install FrigateHub from the Module Store.
2. Create a **Frigate configurator** instance below the MQTT server Frigate sends to.
3. Enter the Frigate address and apply: `http://192.168.1.20:5000` (no login) or
   `https://192.168.1.20:8971` – then enter Frigate user and password and click **Sign in**.
4. **Create** the cameras you want in the list. The last row **Frigate server** creates the server instance.

Without the configurator: create a **Frigate camera** instance below the MQTT server, enter the
**camera ID** (the name under `cameras:` in Frigate) and, recommended, the Frigate address.

The **Received messages** panel shows what arrives from Frigate. In the **🪟 Form: layout** panel you
choose which sections are expanded, collapsed or hidden and in which order.

### Login via port 8971

If port 5000 is not exposed, use Frigate's web interface on port 8971: enter user and password and
click **Sign in**. The password is only used for this login and is not stored; FrigateHub keeps
Frigate's access token and renews it while Symcon is running. All FrigateHub instances with the same
address share the login. Enable “Verify certificate” only if Frigate has a valid certificate.

## Together with OpenHomeAlarm (optional)

[OpenHomeAlarm](https://community.symcon.de/t/modul-openhomealarm-flexibles-alarmsystem-fuer-symcon/144342)
by Burki24 is an alarm system for Symcon. Neither module requires the other.

- **FrigateHub → OpenHomeAlarm:** each new Frigate alert of a camera is reported to OpenHomeAlarm's
  external alarm event interface (from OpenHomeAlarm 1.423) under a source ID; OpenHomeAlarm decides
  by area and arming mode whether it becomes an alarm.
- **On arming** an area FrigateHub can switch recording and/or detection on (reads `OHA_GetControlState()` only).
- **For other modules** the Frigate server instance offers `FRIGS_GetCameras($id)` (contract 1.1):
  per camera the variables for sensors (alert, detection, motion, people, zone counters, online,
  loitering, sounds, unknown face/plate, sabotage, faults, states), the switches and the notification function.
- `FRIGS_ProfilSetzen($id, "away")` switches a Frigate profile (Frigate 0.18+).

## Store review notes

- **Files:** clips for the tile are stored briefly in the system temp folder and delivered in parts;
  they are removed after 10 minutes and when the instance is deleted.
- **WebHook:** camera and Frigate server register their own hook and remove it on deletion.
- **Frigate password:** not a property; only Frigate's access token is stored (attribute).

## License

PolyForm Noncommercial 1.0.0 — free for private and non-commercial use. For commercial use please
contact dietmar@gureth.eu. Donations are voluntary via [PayPal](https://paypal.me/DietmarGureth).
