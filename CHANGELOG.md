# Changelog

## 0.11.1 (Build 24) — 29.10.2026

- **Zeitstempel im Livebild:** neue Einstellung „Frigates Zeitstempel ins Livebild“ (Standard an) für Kachel, Meldungsbild und „Aktuelles Kamerabild holen“ (`latest.jpg?timestamp=1`, Aussehen über Frigates `timestamp_style`). Ausschalten, wenn die Kamera selbst schon die Uhrzeit einblendet (Wunsch Dietmar).

## 0.11.0 (Build 23) — 29.10.2026

- **`FRIG_MeldungMitSchnappschuss($id, $Text, $Livebild)`:** Meldung mit Kamerabild an alle Ziele, als Aktion für Alarmanlagen und Regeln (OpenHomeAlarm, Automat, Skripte). Das Livebild (mit Rahmen) kommt in ein eigenes Medienobjekt „Meldungsbild“, der Erkennungs-Schnappschuss bleibt unangetastet.
- **SMTP als Ziel:** Die Zielliste nimmt jetzt auch SMTP-Instanzen; jede Meldung (Alarm, Probe, KI, Meldung mit Schnappschuss) kommt dort als E-Mail mit Bild (`SMTP_SendMailMedia`). Das Bild wird nur geholt, wenn ein SMTP-Ziel eingetragen ist; ohne Bild als Text mit Hinweis.
- **Push öffnet die Kamera:** Kachel-Push-Meldungen tragen die Kamera-Instanz als Ziel, Antippen öffnet die Kachel (live bestätigt 29.10.2026, ebenso die E-Mail mit Bild); lehnt Symcon das ab, Rückfall auf die bisherige Meldung ohne Ziel.
- Panel heißt jetzt „🔔 Push und Meldungen“.

## 0.10.0 (Build 22) — 29.10.2026

- Build 22 (Live-Funde): Archiv-Karten zeigen ihr Vorschaubild wieder in der Karte (eine Regel der Großansicht legte sie übereinander), Objektfilter mit deutschen Namen.

- Build 21 (Live-Fund): Die Server-Kachel überspringt Kamera-Instanzen, die während einer Modulaktualisierung kurz nicht ansprechbar sind (Symcon meldete „InstanceInterface is not available").

- **Gesichter, Kennzeichen, Klassifizierung, KI:** `frigate/tracked_object_update` (face/lpr/classification/description) ergibt die Variablen „Erkannte Person“, „Kennzeichen“, „Klassifizierung“, „KI-Beschreibung“ (entstehen erst mit den ersten Daten; Beschreibungen ohne Kameraangabe werden über die eigenen Objekt-IDs zugeordnet). Frigates Review-`metadata` (GenAI: title, shortSummary, potential_threat_level) ergibt „KI-Beschreibung“ und „KI-Einschätzung“ (INTERVALS unauffällig/verdächtig/kritisch). Push: KI-Zusammenfassung zum gemeldeten Alarm nachreichen, verdächtige Erkennungen melden (Häkchen, Standard an, einmal je Review).
- **Archiv in der Kachel:** Tagesauswahl aus `/api/review/summary` (Anzahl, neu), Filter Alarm/Erkennung/ungesehen/KI-Verdacht/Objekt, Aufnahme eines Zeitraums (`/api/<kamera>/start/<s>/end/<e>/clip.mp4`, höchstens 1 h, stückweise wie Clips).
- **Kachel:** Warnmarken (Kamera/Erkennung/Aufnahme/Schnappschüsse aus), Schalter als „Frigate:“ gekennzeichnet, ungesehene Ereignisse markiert und beim Öffnen in Frigate als gesehen gemeldet (`POST /api/reviews/viewed`), Favorit ☆ (`/api/events/<id>/retain`), Export 💾 (`POST /api/export/…`), KI-Titel auf den Karten. Schreibende Aktionen über `requestAction` (Anmeldung der Visualisierung), auf der IPSView-Seite nur mit Freigabe.
- **PTZ und Bewegung:** Steuerkreuz und Voreinstellungen in der Liveansicht, wenn `/api/<kamera>/ptz/info` Fähigkeiten meldet (MQTT `…/ptz`); „Bewegungsschwelle“ und „Bewegung: Mindestfläche“ als Schieberegler (`…/motion_threshold`, `…/motion_contour_area`, folgen Frigates Bestätigung).
- **Frigate-Server als Übersichtskachel** aller Kameras (Livebild, Alarm, Personen, offline, gestörte Verbindung) mit Birdseye, wenn Frigate es liefert; eigene IPSView-Seite.
- **Aufräumen:** Beim Löschen einer Kamera- oder Server-Instanz entfernt FrigateHub ihren WebHook-Eintrag und zwischengespeicherte Clips (nicht beim Beenden von Symcon).

## 0.9.1 (Build 19) — 29.10.2026

- **Frigate-Server: Leistungswerte ins Formular** (Dietmars Wunsch): Als Variablen bleiben nur Frigate erreichbar, Neueste Version, Speicher Aufnahmen frei, Gestörte Kameras und Kamerazustand. Version, Laufzeit, CPU, Arbeitsspeicher, GPU, Erkennungszeit und belegter Speicher stehen im neuen Panel „📊 Übersicht“ (beim Öffnen frisch über `/api/stats`, sonst letzte MQTT-Statistik), dazu eine Liste je Kamera: Bilder/s von erwarteten, Erkennungen/s, Verbindung, Neuverbindungen und Aussetzer der letzten Stunde.
- Häkchen „Leistungswerte zusätzlich als Variablen“ (Standard aus) für Archiv und Diagramme. Vorhandene Leistungs-Variablen bleiben beim Ausschalten stehen und werden weiter aktualisiert; gelöschte kommen nicht wieder.

## 0.9.0 (Build 18) — 29.10.2026

- **Neue Instanz Frigate-Server** (`FrigateHubServer`, Präfix FRIGS, unter dem MQTT-Server): Frigate erreichbar, Version und „Neueste Version" (aus Frigates `latest_version`), Läuft seit, CPU-Last, Arbeitsspeicher, Erkennungszeit je Detektor, GPU-Last je GPU, Speicher Aufnahmen belegt/frei, „Gestörte Kameras" mit Klartext „Kamerazustand" (keine Bilder, Verbindung schlecht/unbrauchbar, Neuverbindungen der letzten Stunde). Quelle `frigate/stats` und `frigate/available`; mit Frigate-Adresse zusätzlich sofort über `/api/stats`. „Frigate neu starten" (`frigate/restart`, mit Rückfrage, nie retain). Feldnamen und Einheiten an Frigate 0.18.2 geprüft.
- Konfigurator: zusätzliche Zeile „Frigate-Server" zum Anlegen (eine je MQTT-Server). Die Server-Instanz teilt die Anmeldung über Port 8971 mit Kameras und Konfigurator.
- Neuer Prüfstand `.tools/test-server.php` (auch in der CI).

## 0.8.0 (Build 17) — 29.10.2026

- **Eigenständige Seite für IPSView/Browser** (RoboHub-Muster): `/hook/frigatehub<ID>?seite=1&k=<Schlüssel>` liefert die Kachel-Oberfläche mit Kameranamen und eigenem Hintergrund, Daten alle 5 s über `?daten=1`. Anders als bei RoboHub braucht schon die Anzeige den Schlüssel (Kamerabilder sind privat). Schalten über `POST ?schalten=<Ident>&wert=0|1` nur mit Freigabe „Schalter … auf der eigenständigen Seite bedienbar“ (Standard aus), nur die drei Kachel-Schalter, und nur bei aktiver MQTT-Instanz (sonst 502 statt scheinbarem Erfolg). Ohne Freigabe sind die Schalter gesperrt und erklären warum.
- Build 17 (Live-Fund): Die Seite gibt nur geänderte Daten an die Oberfläche weiter. Vorher zeichnete sie alle 5 s neu und lud dabei die Vorschaubilder der Ereignisse jedes Mal neu (schwarze Karten).
- Adresse auf Knopfdruck („Adresse für IPSView anzeigen“, `FRIG_SeiteAdresse`), damit der Schlüssel nicht im Formular steht.

## 0.7.2 (Build 15) — 29.10.2026

- **Kachel erkennt fehlenden Ton:** Kommen 5 s nach Videostart keine Tonpakete, hat der Stream keine abspielbare Tonspur (Kamera ohne Ton oder z. B. AAC). Nach dem Einschalten des Tons misst die Kachel 4 s lang den Pegel; bleibt er unverändert unter −60 dB, schickt die Kamera nur digitale Stille (typisch: Line-Eingang ohne Mikrofon). In beiden Fällen zeigt der Tonknopf „🔇 kein Ton" mit Erklärung. Fund an Dietmars Geutebrück EFD-2251 (nur Line-in, kein Mikrofon): Pegel konstant 0,00024 auch bei Eingangslautstärke 255.

## 0.7.1 (Build 14) — 29.10.2026

- Kachel-Großansicht: Die obere Leiste (Ereignistitel, Bild/Clip, ✕) lag über Symcons Kacheltitel und Vergrößern-Knopf. Sie beginnt jetzt wie die normale Ansicht 46 px tiefer (Fund Dietmar, 29.10.2026).

## 0.7.0 (Build 13) — 29.10.2026

- **Livevideo per WebRTC in der Kachel:** Der Verbindungsaufbau (SDP-Angebot/-Antwort) läuft über den WebHook (`?webrtc=1`, POST) an Frigates `POST /api/go2rtc/webrtc?src=<stream>` (nginx-Pfad der Home-Assistant-Integration, auf 8971 mit Anmeldung), das Video selbst direkt zwischen Browser und go2rtc (Port 8555). Stream-Name aus `cameras.<kamera>.live.streams`, sonst die Kamera-ID; angeboten nur, wenn `/api/go2rtc/streams` ihn kennt (geprüft beim Abgleich).
- Kachel: Video startet stumm (Browser-Vorgabe), 🔇/🔊 schaltet den Ton, Tipp vergrößert. Kein Bild binnen 10 s, Verbindungsfehler oder außerhalb des Heimnetzes → Standbild; höchstens 3 Versuche. Beim Ausblenden wird die Verbindung getrennt.
- Neue Einstellung „Livevideo" (Standard an) und Statuszeile im Panel „🖥️ Kachel".

## 0.6.0 (Build 12) — 29.10.2026

- **Kachel** (HTML-SDK, `FrigateHubKamera/module.html`, Trait `FrigateHubKachel`): Livebild im einstellbaren Takt (Standard 2 s, nur solange sichtbar, wahlweise mit Erkennungsrahmen `bbox`), Statuszeile (online, Alarm, Erkennung, Bewegung, Zähler je Objekt), Alarm-/Erkennungsrahmen, Schalter Erkennung/Aufnahme/Schnappschüsse (über `requestAction`, folgen der Bestätigung von Frigate), Ereignisleiste der letzten 8 Reviews mit Vorschaubild, Großansicht mit Ereignis-Schnappschuss (1080 px) und Clip.
- **WebHook** `/hook/frigatehub<ID>` reicht Bilder und Clips durch: `/api/<kamera>/latest.jpg`, `/clips/review/thumb-<kamera>-<id>.webp`, `/api/events/<id>/thumbnail.jpg|snapshot.jpg|clip.mp4`, mit Frigates Anmeldung und Range-Anfragen (Safari/iOS). Zugriff nur mit dem zufälligen Schlüssel der Instanz (Attribut, „Kachel-Schlüssel erneuern"), nur feste Pfade, IDs streng geprüft. Ohne Frigate-Adresse zeigt die Kachel den Schnappschuss aus dem Medienobjekt.
- Ereignisleiste aus MQTT-`reviews`; ist sie leer, füllt der Abgleich sie einmal über `/api/review`.
- Neuer Prüfstand `.tools/test-kachel.php` (auch in der CI).
- Build 12 (Live-Funde): Frigate 0.18 nimmt die Bildhöhe als `height=` und ignoriert `h=` — FrigateHub schickt beides (`FrigateHubTopics::hoehe()`). Clips: Symcon lieferte einen 1,7-MB-Clip über den WebHook leer aus, und Frigate kennt keine Teilabrufe. Der Hook holt den Clip deshalb einmal, legt ihn 10 min im Temp-Ordner ab und liefert ihn in Stücken von 512 KB (206, Range).

## 0.5.1 (Build 10) — 29.10.2026

- `http://…:8971`: nginx antwortet 400 „The plain HTTP request was sent to HTTPS port". Abgleich, Konfigurator und Anmelden nennen jetzt die richtige https-Adresse statt „nicht erreichbar (HTTP 400)"; die Statuszeile warnt schon vorher (Fund Dietmar, 29.10.2026).
- Der Zugang ist an Host und Port gebunden und wird nach einem Serverwechsel nicht mehr mitgeschickt.

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
