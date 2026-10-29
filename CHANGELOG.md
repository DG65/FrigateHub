# Changelog

## 0.5.0 (Build 9) — 29.10.2026

- **Anmeldung über Port 8971:** Neuer Trait-Teil in `FrigateHubApi` für Kamera und Konfigurator. `POST /api/login`, das JWT aus dem Set-Cookie (Name aus `auth.cookie_name`) liegt im Attribut `ApiZugang`, das Passwort wird nicht gespeichert (Property `ApiPasswort` nur als Durchreiche, nach der Anmeldung geleert). Anfragen schicken das Token als Cookie; Frigate verlängert es per Set-Cookie, der Timer „Anmeldung" fragt dafür alle 10 min `/api/version` ab. 401 mit Token → Zugang verworfen, Hinweis im Formular und Warnung im Meldungsfenster.
- Instanzen mit derselben https-Adresse teilen den Zugang (`FRIG_AnmeldungTeilen`/`FRIGC_AnmeldungTeilen`). Der Konfigurator gibt Benutzer und Zertifikatsprüfung an angelegte Kameras weiter.
- Frigates selbst signiertes Zertifikat wird standardmäßig angenommen (Schalter „Zertifikat prüfen").
- Neuer Prüfstand `.tools/test-anmeldung.php` (auch in der CI).
- Build 9: Bei HTTP 401 heißt es „antwortet, verlangt aber eine Anmeldung" statt „nicht erreichbar" (live-Fund am echten Port 8971).

## 0.4.0 (Build 7) — 29.10.2026

- **Push bei Alarm:** Neues Panel „🔔 Push bei Alarm" mit Zielliste (WebFront per `WFC_PushNotification`, Kachel-Visualisierung per `VISU_PostNotificationEx`), Ton und Mindestabstand (Standard 60 s). Auslöser ist ein Review mit `severity: alert` (`new` oder `update`), einmal je Review-ID; steigt eine Erkennung zum Alarm auf, kommt die Meldung dann. Titel = Instanzname, Text = Zeit, Objekte, Zonen; nach Bytes auf 32/256 gekürzt.
- `FRIG_PushTesten($id)` / Knopf „Probemeldung senden" mit Zustellstatus je Ziel; nicht zugestellte Meldungen landen als Warnung im Meldungsfenster.

## 0.3.0 (Build 6) — 29.10.2026

- **Neues Modul Frigate-Konfigurator** (`FrigateHubKonfigurator`, Präfix `FRIGC`): hängt unter dem MQTT-Server, liest die Kameras über `/api/config` und `/api/stats` und legt je Kamera eine Frigate-Kamera an, mit Kamera-ID, MQTT-Präfix (`mqtt.topic_prefix`) und Frigate-Adresse. Spalten: Name (friendly_name), Kamera-ID, Erkennung, Auflösung, Zonen, Zustand.
- Vorhandene Kameras werden über Kamera-ID und denselben MQTT-Elternteil erkannt; Instanzen ohne Kamera in Frigate erscheinen als „nicht mehr in Frigate".
- HTTP-Zugriff in den gemeinsamen Trait `FrigateHubApi` ausgelagert. Eigener Prüfstand `.tools/test-konfigurator.php` (auch in der CI).

## 0.2.1 (Build 5) — 28.10.2026

- **Schnappschuss in voller Größe:** Mit Frigate-Adresse holt die Instanz bei jedem MQTT-Schnappschuss `/api/<kamera>/<objekt>/snapshot.jpg` (live 1280×720 statt 270×270). Ist die API nicht erreichbar, bleibt es beim MQTT-Ausschnitt, im Bildstatus als „(Ausschnitt …)" gekennzeichnet.

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
