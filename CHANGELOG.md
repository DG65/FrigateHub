# Changelog

## 0.18.1 (Build 37) — 04.11.2026

- **Datum im Englischen eindeutig:** Datum und Uhrzeit erscheinen auf Englisch als `2026-11-04 20:22:41` statt `04.11.2026` (las sich dort als 11. April). Die Formate laufen über die Übersetzung (`FrigateHubText::t('Y-m-d H:i:s')` → Deutsch `d.m.Y H:i:s`, ebenso `Y-m-d H:i` und `m-d H:i`); die Kachel zeigt auf Englisch `JJJJ-MM-TT`, im Archiv `MM-TT`. Auf Deutsch unverändert. Anlass: Live-Prüfung auf Englisch bei Dietmar.

## 0.18.0 (Build 36) — 04.11.2026

- **Englisch:** FrigateHub spricht die Sprache der Symcon-Installation. Quelltexte stehen englisch im Code, die deutsche Fassung in `locale.json` (Muster RoboHub 0.35): Formulare, Statuszeilen, Variablennamen, Darstellungsoptionen, Meldungen und Push-Texte, Wächter, Konfigurator, beide Kacheln (Konstante `KACHEL_TEXTE`, Funktion `T()` in `module.html`).
  - `libs/FrigateHubText.php`: Übersetzer für die reinen Klassen (Topics, Wächter, Sensor, Einstellungen, Serverwerte); jedes Modul setzt ihn im Konstruktor auf `$this->Translate`.
  - `locale.json` entsteht aus `.tools/locale-teile/*.json` per `php .tools/locale-bauen.php` und liegt in allen drei Modulordnern (Symcon liest sie nur dort).
  - Die deutsche Anzeige ist unverändert; die Prüfstände laufen weiter auf Deutsch (`FRIGATEHUB_SPRACHE=en` für Englisch).
  - Bereits angelegte Variablen behalten ihren Namen (gehören dem Nutzer); neue Instanzen bekommen Namen in der Sprache der Installation. Öffentliche Funktionen und `FRIGS_GetCameras()` (Vertrag 1.1) bleiben unverändert.
- `README.en.md`; README nennt Kameras, die sich sonst schwer einbinden lassen.

## 0.17.1 (Build 35) — 03.11.2026

- **Store-Richtlinie (Instanzeigenschaften gehören dem Nutzer):** Das Frigate-Passwort ist keine Property mehr. Bisher schrieb das Modul ein per „Übernehmen" gespeichertes Passwort mit `IPS_SetProperty`/`IPS_ApplyChanges` wieder leer. Jetzt steht das Passwortfeld ohne Property im Panel „🔑 Anmeldung an Frigate" (Kamera, Server und Konfigurator) und geht nur an `Anmelden()`; gespeichert wird weiterhin nur Frigates Zugangsschlüssel (Attribut). Der Benutzer bleibt oben im Formular. Die Property `ApiPasswort` entfällt; sie war nach jeder Anmeldung leer.
- Live geprüft 03.11.2026: „In Frigate einstellen" hat bei Dietmar `car` in der Zone Carport ergänzt — sofort in `/api/config`, in der config.yaml nur diese Stelle geändert, Frigate lief ohne Neustart weiter.
- **„In Frigate einstellen" und „Anmeldung an Frigate" stehen wieder in `elements`** bei den übrigen Fachpanels (Standard: vor „Formular: Aufbau"), nicht mehr ganz unten in `actions` (Dietmar 03.11.: Panel nicht gefunden). Belegt am Konsolen-Code (Symcon 9.0, `console-app.js`): „Übernehmen" übernimmt nur Formularfelder, deren Name eine Property ist (`for (e in configuration) if (werte.has(e))`), und Änderungen an anderen Feldern zählen nicht als ungespeichert. Knöpfe in `elements` lesen die Felder ihres Bereichs (`$FE_…`, `$ApiPasswort`). `IPS_SetConfiguration` mit unbekanntem Schlüssel meldet dagegen „Eigenschaft … nicht gefunden" (live geprüft) — das betrifft die Konsole nicht. Der Benutzer muss vor dem Anmelden nicht mehr übernommen werden.
- Neu-Texte nennen die Kamera-Instanz als Ort von „In Frigate einstellen" und „Kamera als Sensor".
- README: Abschnitt „Hinweise zur Store-Prüfung" (Temp-Dateien für Clips, WebHook-Eintrag).

## 0.17.0 (Build 34) — 03.11.2026

- **In Frigate einstellen** (Panel in den Aktionen der Kamera, Trait `libs/FrigateHubKameraEinstellungen.php`, Logik rein in `libs/FrigateHubEinstellungen.php`): liest beim Öffnen `/api/config`, zeigt Aufbewahrung (record.alerts/detections.retain.days, continuous/motion.days), `objects.track`, `review.alerts.labels`/`required_zones`, Zonen (`objects`, `loitering_time`), Schnappschüsse (`enabled`, `retain.default`), Stillstand (`detect.stationary.threshold`, in Sekunden über detect.fps), `lpr`/`face_recognition`/`audio.enabled`, `audio.listen`.
  - Schreiben wie Frigates eigene Oberfläche (`web/src/components/config-form/sections/BaseSection.tsx`, 0.18.2): `PUT /api/config/set` je Bereich mit `requires_restart: 0`, `update_topic: config/cameras/<kamera>/<bereich>`, nur geänderte Felder; vor dem Schreiben frisch gegen Frigate verglichen. Frigate prüft und stellt bei Fehler die alte Datei wieder her; dessen Meldung erscheint im Formular. 403 → Hinweis auf die Rolle admin.
  - Zonen anlegen/zeichnen und Kameras anlegen bewusst nicht (Polygone, Stream-Adressen mit Passwort).
  - Kennzeichen/Gesichter nur, wenn in Frigate global an; Geräusche-Schalter ohne Stream mit Rolle „audio" gesperrt.
- **Formular-Aufbau** steuert jetzt auch Panels im `actions`-Bereich (SUITE.md 3a, Eingabefelder ohne Property), Kennzeichnung „(unten bei den Aktionen)".
- Prüfstand `.tools/test-einstellungen.php` (29 Prüfungen, führt den Speichern-Knopf wie Symcon aus, Mutationsgegenprobe).
- Nicht gebaut: Frigate-Profile anlegen — deren Inhalt sind Abweichungen je Kamera, die in Frigates Oberfläche gepflegt werden; schalten geht seit 0.16.0.

## 0.16.0 (Build 33) — 03.11.2026

- **Kamera als Sensor** (Trait `libs/FrigateHubKameraSensor.php`, Bewertung rein in `libs/FrigateHubSensor.php`, Felder laut Frigate 0.18.2 `docs/integrations/mqtt.md`):
  - „Zustand <Modell>" aus `frigate/<kamera>/classification/<modell>` (Zustandsklassifizierung).
  - „<Objekt> steht seit" / „<Zone>: <Objekt> steht seit" (Datum/Uhrzeit, 0 = „-") aus `stationary` der Events; „Paket entfernt", wenn ein stehendes `package` endet.
  - „<Zone>: verweilt": ein Objekt der gewählten Klassen (Standard `person`) ist ≥ N s in der Zone (Standard 30, 0 = aus). Timer genau auf die nächste Schwelle, weil Frigate stillstehende Objekte nicht mehr meldet.
  - „Erkannte Person (Name)", „Unbekanntes Gesicht" (erst am Objektende: Versuche, aber kein Treffer), „Erkanntes Fahrzeug" (Name aus Frigates bekannten Kennzeichen, sonst Kennzeichen), „Unbekanntes Kennzeichen".
  - „Letzte Geschwindigkeit" (`average_estimated_speed`, Einheit aus `ui.unit_system`), „Auslöser <Name>" (`frigate/triggers`), „Gesprochener Text" (`audio/transcription`, bisher verworfen).
  - Tageszähler „Heute: <Objekte>" und „Heute: Alarme", Mitternacht auf 0.
  - Bildprüfung (Standard alle 5 min, braucht Frigate-Adresse und GD): 8×8-Graustufenraster gegen die gelernte Ansicht je Tagesstunde, ohne mittlere Helligkeit verglichen (Schwelle 30; an Dietmars Door-Cam 03.11.: gleiches Bild 0,2, gespiegelt 51, Streuung tags 40); „einfarbig" nur, wenn die Stunde sonst Struktur hat (Nacht); 3 Treffer in Folge; Dauerbewegung ab 15 min. „Bild auffällig"/„Bildzustand", Meldung an die Push-Ziele (abschaltbar), „Ansicht neu lernen".
- **Frigate-Server:** Profile (Frigate 0.18+): Variable „Frigate-Profil" (Aufzählung, schaltbar) aus `frigate/profile/state` und `/api/profiles`, `FRIGS_ProfilSetzen($id, $Profil)` (`frigate/profile/set`, nie retain, unbekannte Namen abgelehnt).
- **`FRIGS_GetCameras()` Vertrag 1.1** (rein ergänzend): `melder.verweilen`, `melder.geraeusche`, `melder.unbekanntesGesicht`, `melder.unbekanntesKennzeichen`, `sabotage`, `stoerung.offline`/`stoerung.aufnahme`, `zustaende` — für OpenHomeAlarm und Regelwerke.
- Darstellungen „Datum/Uhrzeit" und „Aufzählung" live geprüft (Symcon 9, 03.11.2026). Prüfstand `.tools/test-sensor.php` (76 Prüfungen, Mutationsgegenprobe), `test-server.php` um Vertrag 1.1 und Profile erweitert.

## 0.15.2 (Build 32) — 03.11.2026

- **Kachel der Kamera:** Oben rechts stehen jetzt Datum und Uhrzeit (`03.11.2026 10:31:14`), auch bei Livevideo — dann mit „Live“ davor und sekündlich mitlaufend; beim Standbild der Zeitpunkt des Bildes. In 0.15.1 fehlte die Uhrzeit bei Livevideo ganz (Dietmars Rückmeldung).
- Die Marke „● LIVE“ im Bild und der Chip „● online“ entfallen: Zwei Punkte für fast dasselbe verwirrten. Die Kopfzeile zeigt nur noch Störungen (Frigate nicht erreichbar, Kamera offline), Zähler und Warnungen.

## 0.15.1 (Build 31) — 03.11.2026

- **Kachel der Kamera:** Uhrzeit des Livebilds (18 px) und „📅 Archiv“ (farbiger Knopf) stehen oben rechts in der Kopfzeile, statt 11 px klein unten rechts im Bild bzw. unauffällig in der Schalterzeile (Wunsch Dietmar). Bei Livevideo bleibt die Uhrzeit wie bisher ausgeblendet. 8 px Abstand zum Vergrößern-Knopf von Symcon, damit der frei bleibt.
- **Aufnahme-Wächter:** Erste Prüfung 3 s nach dem Übernehmen statt nach einer Minute. Vorher stand „Aufnahme in Ordnung“ nach dem Update bis zu einer Minute fälschlich auf „gestört“ (live bemerkt).
- CI: PHP 8.2 statt 8.1 (Konstanten in Traits, seit 0.12 genutzt; die CI war seitdem rot), Prüfstand Kachel liest die Dateizeit nach `touch()` neu.

## 0.15.0 (Build 30) — 02.11.2026

- **Aufnahme-Wächter** in der Instanz Frigate-Server (neues Panel „🎥 Aufnahme-Wächter“, Bewertung rein in `libs/FrigateHubWaechter.php`): prüft jede Minute über die API, ob Frigate aufzeichnet. Anlass: Abriss der Aufnahme-SSD bei Dietmar am 31.10.2026, Frigate meldete weiter „healthy“, 75 min ohne Aufnahme.
  - Frigate antwortet 3 min nicht.
  - Schreibfehler aus `/api/logs/frigate?start=-300` (ab 3 neuen Zeilen „Error occurred when attempting to maintain recording cache“ / „Unable to store recording segment“ / „Unable to convert“ je Prüfung, behoben nach 2 Prüfungen ohne; Frigates „Last message repeated N times“ zählt den Zuwachs). Endpunkt laut Quellcode 0.18.2 nur Rolle admin; ohne Zugriff Hinweis im Formular.
  - Aufnahmespeicher gewechselt: Größe (> 2 %) oder `mount_type` von `/media/frigate/recordings` weicht vom gemerkten Stand ab; „Aktuellen Speicher übernehmen“ (`FRIGS_SpeicherUebernehmen`).
  - Alarm ohne Aufnahme: Alarm seit 2 min zu Ende, keine Teilstücke mit Inhalt (`/api/<kamera>/recordings`); nur Kameras mit `record.alerts.retain.days` > 0. Steht bis zum nächsten Alarm mit Aufnahme, höchstens 24 h.
  - Daueraufnahme steht: neueste Aufnahme älter als 5 min, nur bei `record.continuous.days` > 0 und Kamera liefert Bilder.
  - Meldung je neuer Störung, tägliche Erinnerung, Entwarnung; Variablen „Aufnahme in Ordnung“ und „Aufnahmezustand“; `FRIGS_AufnahmePruefen`, `FRIGS_PushTesten`. Abschaltbar („Aufnahme überwachen“).
- Push-Versand als gemeinsamer Baustein `libs/FrigateHubPush.php` für Kamera und Server; Verhalten der Kamera unverändert, außer: Der Wächter schickt E-Mails ohne Bild und ohne den Hinweis „Kein Bild verfügbar“.
- Prüfstand `.tools/test-waechter.php` (76 Prüfungen, mit den echten Protokollzeilen vom 31.10.2026), in der CI.

## 0.14.1 (Build 29) — 30.10.2026

- Panel „💬 Rückmeldungen“ verweist jetzt auf den Forum-Thread (community.symcon.de/t/144813) statt auf GitHub.
- OpenHomeAlarm-Kopplung hört nur noch auf dessen Zustandsvariablen (`State`, `Partition_<hex>_State`, laut Quellcode) statt auf alle ~22 Variablen; findet sie keine, wie bisher auf alle. Weniger Nachfragen, gleiches Verhalten.

## 0.14.0 (Build 28) — 29.10.2026

- **🪟 Formular: Aufbau** (SUITE.md 3a, Referenz Batteriewächter) in Frigate-Kamera und Frigate-Server: je Fachpanel aufgeklappt, zugeklappt oder ausgeblendet (`visible:false`, Werte bleiben und wirken weiter) und Position 1–99; Property `FormLayout`, stabile Schlüssel, unbekannte Schlüssel fallen auf Standard zurück; „↩️ Auf Standard zurücksetzen“ (`FRIG_/FRIGS_ResetFormLayout`). Der Konfigurator hat nur ein Fachpanel und bekommt es nicht.
- Neu-Panel um die Versionen 0.8.0 bis 0.14.0 ergänzt.

## 0.13.0 (Build 27) — 29.10.2026

- **Alarme an OpenHomeAlarm melden** über dessen neue Externe Alarmereignis-Schnittstelle (OpenHomeAlarm 1.423, `OHA_TriggerExternalAlarmEvent`, Ereignis-API 1, am Quellcode von Burki24 geprüft): je Kamera Quellen-ID und „Melden bei“ (Frigate-Alarm / auch Erkennung), einmal je Review, EventID = `frigate-<Review-ID>`. Statuszeile aus `OHA_GetExternalAlarmEventApi()` (Quelle eingetragen? Bereich, Modi), Ergebnis der letzten Meldung im Klartext. „Verbindung prüfen“ sendet ein Probeereignis nur bei unscharfem Bereich. Andere Ereignis-API-Version → Melden aus.

## 0.12.0 (Build 26) — 29.10.2026

- Build 26: Kopplung prüft OpenHomeAlarms eigene `ApiVersion` (Control-API 3, am Quellcode von `GetControlState()` bestätigt) statt eines erbetenen `contractVersion`; andere Version → Kopplung sichtbar aus.

- **OpenHomeAlarm finden (optional, mit Burki24 abgestimmt):** Die Kamera findet OpenHomeAlarm über dessen Modul-GUID und liest nur `OHA_GetControlState()` (`Partitions.<Bereich>.State.Name`). Neues Panel „🛡️ OpenHomeAlarm (optional)“ mit Statuszeile und Bereichsauswahl: beim tatsächlichen Wechsel auf scharf (`armed`, nicht schon in der Ausgangsverzögerung) Aufnahme und/oder Objekterkennung an, auf Wunsch beim Unscharfschalten wieder aus. Übernehmen schaltet nichts. Andere Vertrags-Hauptversion → Kopplung sichtbar aus.
- **`FRIGS_GetCameras()`** (Vertrag 1.0): neutrale Kameraliste mit Variablen für Melder, Schalter und Meldefunktion — für Alarmanlagen und Regelwerke, damit sie FrigateHub von selbst finden.
- **`FRIGS_MeldungLetzteKamera($id, $Text, $Livebild)`:** Bild der Kamera mit dem jüngsten Alarm (sonst Erkennung) — eine Alarmaktion für alle Kameras.

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
