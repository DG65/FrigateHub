# Changelog

## 0.2.0 (Build 4) — 28.10.2026

- **Medienobjekt „Schnappschuss"** (Ident `Schnappschuss`, Datei `media/FrigateHub_<InstanzID>.jpg`, gecacht): Frigates MQTT-Schnappschüsse (`frigate/<kamera>/<objekt>/snapshot`) landen darin. Symcons MQTT-Server reicht die JPEG-Bytes als UTF-8-Zeichen weiter; FrigateHub wandelt sie verlustfrei zurück.
- `FRIG_BildHolen($id)` / Knopf „Aktuelles Kamerabild holen": Livebild über `/api/<kamera>/latest.jpg?h=720` (braucht die Frigate-Adresse).
- Der Abgleich legt das Medienobjekt mit dem Livebild an, solange es noch keins gibt; einen vorhandenen Schnappschuss überschreibt er nicht.
- Neues Formular-Panel „🖼️ Schnappschuss" mit Zeit, Quelle und Größe des letzten Bilds.

## 0.1.1 (Build 3) — 28.10.2026

- **HTTP-Abgleich:** neue Eigenschaft „Frigate-Adresse" (`ApiUrl`, z. B. `http://192.168.1.20:5000`). Kurz nach dem Übernehmen und nach jedem Symcon-Start liest die Instanz `/api/config` (Schalter, Masken, Zonen) und `/api/stats` (Kamera liefert Bilder) und setzt „Frigate erreichbar". Grund: Frigate schickt Schalterzustände per MQTT nur beim Verbinden, `frigate/onConnect` beantwortet es damit nicht, und Symcons MQTT-Server gibt gespeicherte Nachrichten nicht an neue Instanzen weiter (live geprüft).
- Schonfrist 60 s: Frigates `/api/config` hinkt der MQTT-Bestätigung einige Sekunden hinterher (live: 2,5 s nach „an" noch „aus"). Ein gerade per MQTT bestätigter oder von Symcon geschalteter Schalter wird vom Abgleich nicht überschrieben.
- Knopf „Zustand jetzt abfragen" (`FRIG_ZustandAnfordern`) nutzt den Abgleich; ohne Adresse bleibt es bei `frigate/onConnect`.
- Masken- und Zonen-Schalter: Ident und Name ohne Doppelung (`Schalter_motion_mask_1`, „Bewegungsmaske 1"). Variablen aus 0.1.0 (`Schalter_motion_mask_motion_mask_1_…`) werden nicht übernommen und können gelöscht werden.
- Manuelle Ereignisse (`on_demand`) heißen „manuell ausgelöst".
- „Empfangene Nachrichten" zeigt Schnappschüsse als „(Bild, n Bytes)".

## 0.1.0 (Build 1) — 28.10.2026

- Erstes Modul **Frigate-Kamera** (`FrigateHubKamera`, Präfix `FRIG`): hängt unter Symcons MQTT-Server oder einem MQTT-Client, an den Frigate sendet.
- Feste Variablen: Frigate erreichbar, Kamera online, Bewegung, Alarm, Erkennung, Letzter Alarm, Letzte Erkennung, Letztes Objekt.
- Zähler je Objektklasse und je Zone entstehen beim ersten Topic; Zonen lernt die Instanz aus `frigate/events` und `frigate/reviews`.
- Schalter für alle `…/state`-Topics mit ON/OFF (Erkennung, Aufnahme, Schnappschüsse, Bewegungserkennung, Masken, Zonen …). Die Variable folgt der Bestätigung von Frigate, nicht dem Klick.
- Knöpfe „Zustand bei Frigate anfordern" (`frigate/onConnect`) und „Gelernte Zonen vergessen", Panel „Empfangene Nachrichten".
- Prüfstand `.tools/test-frigatehub.php` mit den echten Topics einer Frigate-0.18.2-Kamera.
