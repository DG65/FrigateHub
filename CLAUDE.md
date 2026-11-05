# FrigateHub — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Arbeitsname** (28.10.2026). Modulname, Präfix, Branding und Repo entscheidet Dietmar; Vorschlag:
  eigenständig wie ESPHomeHub, `DG65/FrigateHub`, Präfix `FRIG`. Kein Repo, kein Commit ohne Freigabe.
- Übernimmt die handwerklichen Konventionen aus `/Users/dietmar/Nextcloud/Claude/SUITE.md`
  (Formular-Optik, Darstellungen, Store-Review). NRG-Stack passt inhaltlich nicht.
- Module/Präfixe: `FrigateHubKamera` (FRIG) und `FrigateHubKonfigurator` (FRIGC, GUID
  `{AB2DD3FF-1892-4830-8C35-86982AB8D661}`), beide Kind von MQTT-Server/-Client. Nie denselben Präfix
  (sonst „Cannot redeclare function"). Der Konfigurator hängt unter dem MQTT-Server, damit Symcon die
  angelegten Kameras dort anschließt (Muster CometWiFi); er empfängt nichts (Sperrfilter).
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
- `libs/FrigateHubApi.php`: Trait für Frigates HTTP-API (curl), `apiRoh()` ist der Prüfstand-Haken.
- Variablen entstehen einmalig beim ersten Topic (`variableAnlegen`), danach nur `SetValue`:
  Name und Position gehören dann dem Nutzer, kein Update-Sturm.
- Schnappschuss (0.2.0): Medienobjekt per `IPS_CreateMedia(MEDIATYPE_IMAGE)`, gecacht (schont die
  Platte), Quelle MQTT-`…/snapshot` oder `/api/<kamera>/latest.jpg`. Abgleich holt das Livebild nur,
  solange es kein Medium gibt — nie einen Erkennungs-Schnappschuss überschreiben.
  Live 28.10. 23:09: MQTT-Schnappschuss kam an, gültiges JPEG 270×270 (Frigate-Standard `mqtt.crop`/
  `height` 270), API `/api/<cam>/person/snapshot.jpg` dagegen 1280×720. Seit 0.2.1 holt jeder
  MQTT-Schnappschuss mit Frigate-Adresse `/api/<cam>/<label>/snapshot.jpg` in voller Größe.
- Push (0.4.0): Auslöser Review `severity: alert`, einmal je Review-ID (Attribut `LetzterPush`), Ziele
  per GUID unterschieden (Kachel → `VISU_PostNotificationEx(…, 'Alert', $ton, 0)`, WebFront →
  `WFC_PushNotification(…, $this->InstanceID)`), Kürzung nach Bytes (SUITE.md Stolperstein 22).
- Anmeldung (0.5.0) am Frigate-Quellcode geprüft (`frigate/api/auth.py`, `nginx/conf/auth_request.conf`):
  Login setzt nur ein Cookie (kein Token im Inhalt); `/auth` nimmt Bearer ODER Cookie, verlängert aber
  nur Cookie-Tokens → FrigateHub schickt das Token als Cookie. nginx reicht das neue Set-Cookie durch.
  Passwort nie speichern; `ApiPasswort` ist nur Durchreiche (Muster MeterHub/Inexogy).
  Live 29.10.2026: Konfigurator über https://127.0.0.1:8971 angemeldet, Kameraliste kommt; eine Kamera
  auf derselben Adresse übernimmt den Zugang ohne eigene Anmeldung (Abgleich 18 Schalter, Timer 10 min).
  `http://` auf 8971 → nginx 400 „plain HTTP request was sent to HTTPS port" (seit 0.5.1 erkannt).
- Kachel (0.6.0): Trait `FrigateHubKachel`, `module.html`. Bilder nur über den WebHook
  `/hook/frigatehub<ID>?k=<Schlüssel>` (WebHooks sind sonst offen, auch über Connect). Erlaubte Frigate-
  Pfade fest im Code, IDs per `idGueltig()` (Regex mit `\z`, nicht `$` — `$` ließ „\n" durch, Prüfstand-Fund).
  Endpunkte live 29.10.2026 geprüft: `/api/review`, `/clips/review/thumb-…webp`, `/api/events/<id>/thumbnail.jpg|snapshot.jpg|clip.mp4`.
  Bildhöhe: `height=` (0.15+) und `h=` (älter) zusammen schicken. Clips stückweise (512 KB, 206) aus dem
  Temp-Ordner — Symcon gab 1,7 MB über den Hook leer aus, Frigates clip.mp4 ignoriert Range.
  Livevideo (0.7.0): WebRTC, Signalisierung über den Hook an `POST /api/go2rtc/webrtc?src=` (Frigates nginx,
  auth_request mit go2rtc-Stream-Prüfung), Medien direkt Browser↔go2rtc:8555 (braucht `go2rtc.webrtc.candidates`
  mit Host-IP). WebSockets (/live/mse, /live/webrtc/api/ws) lassen sich über PHP-Hooks NICHT durchreichen.
  Dietmars Door-Cam: H.264 1920×1080 13 fps + G.711 (WebRTC-tauglich ohne Umwandlung), go2rtc noch ohne Streams.
- IPSView-Seite (0.8.0): `?seite=1&k=` liefert module.html mit `eigen`-Daten, Abfrage `?daten=1` alle 5 s (nur
  bei Änderung neu zeichnen, sonst laden die Vorschaubilder ständig neu — Live-Fund), Schalten nur POST +
  Freigabe `SeiteBedienung`. Anders als RoboHub braucht schon die Anzeige den Schlüssel (Kamerabilder privat).
- Server-Instanz (0.9.0): `FrigateHubServer` (FRIGS, GUID `{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}`), Auswertung
  rein in `libs/FrigateHubServerWerte.php`. Live 29.10.2026: `/api/stats` = Inhalt von MQTT `frigate/stats`
  (stats_interval 60), Speicher in MB, `gpu_usages.*.gpu` als Text „2.51%", `connection_quality` z. B. „poor".
- Aufnahme-Wächter (0.15.0): Bewertung rein in `libs/FrigateHubWaechter.php`, Server sammelt über die API.
  Belegt 31.10.2026 (SSD-Abriss): Frigate bleibt „healthy“, schreibt ab 5 s danach alle 5 s
  „Error occurred when attempting to maintain recording cache“ + „[Errno 5] Input/output error“ ins Protokoll.
  `/api/logs/frigate` nur Rolle admin (Port 5000 = admin), `start` negativ = letzte N Zeilen (Python-Slice),
  gleiche Folgezeilen → „[LOGGING] Last message repeated N times“ mit Zeitstempel der ersten.
  Ohne Daueraufnahme (`record.continuous.days: 0`, so bei Dietmar) sind fehlende neue Teilstücke normal.
- Kamera als Sensor (0.16.0): Trait `FrigateHubKameraSensor`, Bewertung in `FrigateHubSensor`. Objektliste im Puffer
  `Objekte` aus `frigate/events` (Felder `stationary`, `current_zones`, `average_estimated_speed`,
  `recognized_license_plate`, `sub_label`). Frigate schickt für stillstehende Objekte keine Updates → Verweil-Timer.
  Dietmars Frigate 03.11.: LPR und Gesichter an, Zonen Eingangsbereich/Carport nur `person`, keine Profile,
  `ui.unit_system` metric, `detect.stationary.threshold` 50 bei 5 fps. Symcon-Darstellungen Datum/Uhrzeit (0 = „-")
  und Aufzählung (OPTIONS) live geprüft. Frigate-Konfiguration schreiben ginge über `PUT /api/config/set`
  (admin, `config_data` + `update_topic` `config/cameras/<cam>/<bereich>`, `requires_restart: 0`) — seit 0.17.0 gebaut
  (`FrigateHubEinstellungen`), Listen ersetzt Frigate als Ganzes, `""` löscht einen Schlüssel. Felder ohne Property
  stehen seit 0.17.1 in `elements`: Symcons Konsole (9.0, `console-app.js`) übernimmt beim „Übernehmen“ nur Felder,
  deren Name eine Property ist, und zählt Änderungen an anderen nicht als ungespeichert (SUITE.md 3a „nicht in elements“
  ist damit überholt). `'_aktionen' => true` in `formAufbauTeilen` bleibt als Möglichkeit, wird aber nicht genutzt.
- Zweisprachig (0.18.0, Muster RoboHub 0.35): Quelltexte englisch, Deutsch in `locale.json`. Alle sichtbaren Texte über
  `FrigateHubText::t('English %s', $wert)` (auch in Traits/Modulen; jedes Modul setzt im Konstruktor `$this->Translate`).
  Deutsche Fassungen in `.tools/locale-teile/*.json` pflegen, dann `php .tools/locale-bauen.php` (schreibt libs/locale.json
  und die Kopie in jedem Modulordner, bricht bei Widersprüchen ab). Kacheln: Konstante `KACHEL_TEXTE` + `T()` in module.html.
  Prüfstände laufen auf Deutsch (deutsche Anzeige blieb wortgleich), `.tools/test-englisch.php` prüft fehlende Übersetzungen,
  Platzhalter und deutsche Reste in den englischen Formularen. SendDebug-Texte und Kommentare bleiben deutsch.
  Seit 0.18.3 Merkliste (`FrigateHubText::mitMerkliste`): je Text einmal `Translate()` je Prozess, nie die locale.json selbst lesen
  (Store: Dateizugriff nur als Ausnahme; 0.18.2 las sie per Datei und machte das Kamera-Formular von >2 min auf 30 ms schnell).
- Ereignis in der Kachel (0.19.0): ganzer Zeitraum statt Clip des ersten Objekts (Frigate verliert Personen und führt sie
  als neues Objekt weiter, live 05.11.2026). Ablauf je Objekt aus `/api/timeline?source_id=`, Zeitraum-Clip hängt nur
  aufgezeichnete Stücke aneinander (`retain.mode: motion` verwirft Stillstand) → Videostelle über `/api/<kamera>/recordings`.
- Bildprüfung (0.19.0): „verstellt“ nur bei Farbbild (Tag) gegen `tagansicht` der Stunde ±1, Korrelation < 0,45; kalibriert an
  58 Door-Cam-Bildern 28.10.–05.11. (Tag 0,63–0,99, gespiegelt 0,26, verschoben 0,17; Farbe IR 0–3,5, Tag 8–16).
- Schalter: `RequestAction` sendet `…/set` (QoS 0, nie retain) und setzt den Wert NICHT; der kommt
  über `…/state`. Ident→Schlüssel im Attribut `Schalter`.

## Offen

