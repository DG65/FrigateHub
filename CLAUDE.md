# FrigateHub — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Arbeitsname** (28.10.2026). Modulname, Präfix, Branding und Repo entscheidet Dietmar; Vorschlag:
  eigenständig wie ESPHomeHub, `DG65/FrigateHub`, Präfix `FRIG`. Kein Repo, kein Commit ohne Freigabe.
- Übernimmt die handwerklichen Konventionen aus `/Users/dietmar/Nextcloud/Claude/SUITE.md`
  (Formular-Optik, Darstellungen, Store-Review). NRG-Stack passt inhaltlich nicht.
- Module/Präfixe: `FrigateHubKamera` (FRIG, Kind von MQTT-Server/-Client). Reserviert:
  Konfigurator-GUID `{AB2DD3FF-1892-4830-8C35-86982AB8D661}`, Präfix FRIGC (nie denselben Präfix
  wie die Kamera, sonst „Cannot redeclare function").
- Abgrenzung: bgersmann/UnifiProtect, Reolink, ONVIF liefern kameraeigene Ereignisse; FrigateHub ist
  die herstellerneutrale Analyse. Nicht doppeln.

## Belegte Fakten (Dietmars Frigate 0.18.2, 28.10.2026)

- Frigate läuft auf smarthome (192.168.2.7) im Container, Symcon 9.0 auf demselben Host. Port 8971
  mit Anmeldung, Port 5000 nur an 127.0.0.1 → Symcon erreicht die HTTP-API ohne Anmeldung.
- MQTT an Symcons MQTT-Server #17640: 30 Topics für eine Kamera `Door-Cam` (Liste im Prüfstand).
  `events`/`reviews` erscheinen erst bei einer echten Erkennung.
- Zonen-Zähler stehen OHNE Kamera im Topic (`frigate/<zone>/<objekt>`) → Zonen werden aus
  `events`/`reviews` gelernt und in den Empfangsfilter aufgenommen.
- Empfangsfilter nur mit schrägstrichfreien Merkmalen (CometWiFi-Fund: Filter mit `/` trifft nie).
- Live 28.10.: `frigate/onConnect` bringt KEINE Schalterzustände; `…/state` und `available` kommen nur
  beim Verbinden (retained), Symcons MQTT-Server gibt retained nicht an neue Kinder → HTTP-Abgleich
  (0.1.1) über `/api/config` (`cameras.<cam>`: `motion.mask.<name>.enabled`, `objects.mask`, `zones`,
  `review.alerts/detections`, `objects.genai`, `review.genai`, `onvif.autotracking`) und `/api/stats`
  (`cameras.<cam>.camera_fps`). `notifications` kommt per MQTT nicht → auch nicht im Abgleich.
  `/api/config` hinkt der MQTT-Bestätigung >2,5 s hinterher → Schonfrist 60 s je Schalter (Puffer `Frisch`).
- Schnappschüsse kommen per MQTT (`…/<objekt>/snapshot`); Symcon reicht Binärdaten als Zeichen
  U+00XX in UTF-8 weiter → Bytes zurück mit `mb_convert_encoding($p, 'ISO-8859-1', 'UTF-8')`.

## Bauweise

- `libs/FrigateHubTopics.php`: reines PHP, deutet Topics und baut Texte. Prüfstand ruft es direkt.
- Variablen entstehen einmalig beim ersten Topic (`variableAnlegen`), danach nur `SetValue`:
  Name und Position gehören dann dem Nutzer, kein Update-Sturm.
- Schnappschuss (0.2.0): Medienobjekt per `IPS_CreateMedia(MEDIATYPE_IMAGE)`, gecacht (schont die
  Platte), Quelle MQTT-`…/snapshot` oder `/api/<kamera>/latest.jpg`. Abgleich holt das Livebild nur,
  solange es kein Medium gibt — nie einen Erkennungs-Schnappschuss überschreiben.
  Live 28.10. 23:09: MQTT-Schnappschuss kam an, gültiges JPEG 270×270 (Frigate-Standard `mqtt.crop`/
  `height` 270), API `/api/<cam>/person/snapshot.jpg` dagegen 1280×720. Seit 0.2.1 holt jeder
  MQTT-Schnappschuss mit Frigate-Adresse `/api/<cam>/<label>/snapshot.jpg` in voller Größe.
- Schalter: `RequestAction` sendet `…/set` (QoS 0, nie retain) und setzt den Wert NICHT; der kommt
  über `…/state`. Ident→Schlüssel im Attribut `Schalter`.

## Offen

- Konfigurator (passive Erkennung der Kameras aus den Topics).
- Push-Benachrichtigung bei Alarm (Vorbild `WarnHub::pushToAllWebfronts`).
- Port 8971 mit Anmeldung (JWT) für den Abgleich, falls Port 5000 nicht freigegeben ist.
