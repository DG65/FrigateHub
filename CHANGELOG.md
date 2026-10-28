# Changelog

## 0.1.0 (Build 1) — 28.10.2026, lokal, noch nicht veröffentlicht

- Erstes Modul **Frigate-Kamera** (`FrigateHubKamera`, Präfix `FRIG`): hängt unter Symcons MQTT-Server oder einem MQTT-Client, an den Frigate sendet.
- Feste Variablen: Frigate erreichbar, Kamera online, Bewegung, Alarm, Erkennung, Letzter Alarm, Letzte Erkennung, Letztes Objekt.
- Zähler je Objektklasse und je Zone entstehen beim ersten Topic; Zonen lernt die Instanz aus `frigate/events` und `frigate/reviews`.
- Schalter für alle `…/state`-Topics mit ON/OFF (Erkennung, Aufnahme, Schnappschüsse, Bewegungserkennung, Masken, Zonen …). Die Variable folgt der Bestätigung von Frigate, nicht dem Klick.
- Knöpfe „Zustand bei Frigate anfordern" (`frigate/onConnect`) und „Gelernte Zonen vergessen", Panel „Empfangene Nachrichten".
- Prüfstand `.tools/test-frigatehub.php` mit den echten Topics einer Frigate-0.18.2-Kamera.
