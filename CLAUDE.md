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
  go2rtc-Restream (RTSP 8554) ist bei Dietmar ohne Streams → kein Live-Video, Livebild = latest.jpg im Takt.
- Schalter: `RequestAction` sendet `…/set` (QoS 0, nie retain) und setzt den Wert NICHT; der kommt
  über `…/state`. Ident→Schlüssel im Attribut `Schalter`.

## Offen

