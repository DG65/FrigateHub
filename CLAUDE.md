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

## Bauweise

- `libs/FrigateHubTopics.php`: reines PHP, deutet Topics und baut Texte. Prüfstand ruft es direkt.
- Variablen entstehen einmalig beim ersten Topic (`variableAnlegen`), danach nur `SetValue`:
  Name und Position gehören dann dem Nutzer, kein Update-Sturm.
- Schalter: `RequestAction` sendet `…/set` (QoS 0, nie retain) und setzt den Wert NICHT; der kommt
  über `…/state`. Ident→Schlüssel im Attribut `Schalter`.

## Offen

- Schnappschuss als Medienobjekt über HTTP (`/api/<kamera>/latest.jpg`, `/api/events/<id>/snapshot.jpg`),
  Port 5000 lokal oder 8971 mit Anmeldung (curl, nicht `Sys_GetURLContentEx`).
- Konfigurator (passive Erkennung der Kameras aus den Topics).
- Push-Benachrichtigung bei Alarm (Vorbild `WarnHub::pushToAllWebfronts`).
- Ob `frigate/onConnect` per MQTT wirklich alle Zustände neu schickt: live prüfen.
