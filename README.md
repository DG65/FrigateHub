# FrigateHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.3.0-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

[Frigate](https://frigate.video) in IP-Symcon: Frigate ist eine quelloffene Videoüberwachung
(NVR) mit KI-Objekterkennung. FrigateHub holt die Erkennungen je Kamera und Zone als Variablen
nach Symcon und schaltet Erkennung, Aufnahme, Masken und Zonen von dort aus.

> **Stand 0.7.0:** Livevideo per WebRTC in der Kachel. Davor: MQTT, Abgleich über Frigates API (Port 5000 oder 8971 mit Anmeldung), Schnappschuss als Medienobjekt, Konfigurator, Push bei Alarm und eine Kachel mit Livebild, Ereignissen und Clips.

## Was tut dieses Modul?

Frigate erkennt auf den Kamerabildern Personen, Autos, Tiere, Pakete und mehr und meldet das per
MQTT. FrigateHub hängt unter Symcons eigenem MQTT-Server (oder einem MQTT-Client) und macht
daraus je Kamera:

| Variable | Bedeutung |
|---|---|
| Frigate erreichbar | Frigate ist mit dem MQTT-Server verbunden (`frigate/available`) |
| Kamera online | Erkennungsstrom der Kamera läuft (`…/status/detect`) |
| Bewegung | Frigate sieht gerade Bewegung |
| Alarm / Erkennung | Prüfstatus von Frigate: Alarm bzw. Alarm oder Erkennung (`…/review_status`) |
| Letzter Alarm, Letzte Erkennung, Letztes Objekt | Zeit, Objekte, Name (Gesicht/Kennzeichen) und Zone als Text |
| Personen, Autos, … | Anzahl je Objektklasse, sobald Frigate sie meldet |
| _Zone_: Personen, … | Anzahl je Zone; Zonen lernt die Instanz aus den Ereignissen |
| Geräusch … | Geräuscherkennung, wenn in Frigate eingeschaltet |
| Schalter | Objekterkennung, Aufnahme, Schnappschüsse, Bewegungserkennung, Masken, Zonen … |
| Bewegungsschwelle, Bewegung: Mindestfläche | Frigates `motion.threshold`/`contour_area`, per Schieberegler einstellbar |
| Erkannte Person, Kennzeichen, Klassifizierung | Gesichtserkennung, Kennzeichenerkennung und eigene Klassifizierungsmodelle von Frigate, mit Trefferquote |
| KI-Beschreibung, KI-Einschätzung | Frigates generative KI (GenAI): Titel und Zusammenfassung eines Ereignisses, Einschätzung unauffällig/verdächtig/kritisch |
| Schnappschuss (Medienobjekt) | Bild der letzten Erkennung (per MQTT) oder, auf Knopfdruck, das Livebild |

Dazu kommt eine Instanz **Frigate-Server** für den Server selbst. Als Variablen (für
Benachrichtigungen und Archiv):

| Variable | Bedeutung |
|---|---|
| Frigate erreichbar | Frigate ist mit dem MQTT-Server verbunden (`frigate/available`) |
| Neueste Version | „aktuell" oder „0.19.0 verfügbar", sobald Frigate eine neuere kennt |
| Speicher Aufnahmen frei | freier Platz unter `/media/frigate/recordings` in GB |
| Gestörte Kameras, Kamerazustand | Anzahl und Klartext: Kameras ohne Bilder oder mit schlechter/unbrauchbarer Verbindung |
| Aufnahme in Ordnung, Aufnahmezustand | Aufnahme-Wächter: zeichnet Frigate wirklich auf? (siehe unten) |

Die Leistungswerte stehen im Formular in der **Übersicht**, beim Öffnen frisch von Frigate
geholt: Version, Laufzeit, CPU-Last, Arbeitsspeicher, Erkennungszeit je Detektor, GPU-Last,
belegter Speicher und je Kamera Bilder/s, Erkennungen/s, Verbindungsqualität, Neuverbindungen
und Aussetzer der letzten Stunde. Wer sie archivieren oder als Diagramm sehen will, setzt das
Häkchen „Leistungswerte zusätzlich als Variablen".

Die Werte kommen aus `frigate/stats` (Frigate schickt das jede Minute, `mqtt.stats_interval`).
„Frigate erreichbar" und „Gestörte Kameras" eignen sich als Auslöser für eine Benachrichtigung.
Im Formular lässt sich Frigate neu starten (`frigate/restart`; Docker muss es mit
`restart: unless-stopped` wieder starten).

Mit eingetragener Frigate-Adresse kommt der Schnappschuss jeder Erkennung in voller
Kameraauflösung. Ohne Adresse nimmt FrigateHub das Bild, das Frigate per MQTT schickt: einen
Ausschnitt um das Objekt (Standard: 270 px hoch). Größe und Zuschnitt stellt man in Frigate je Kamera
unter `mqtt:` ein (`height`, `crop`, `quality`, `bounding_box`, `timestamp`).

**Kachel:** Die Instanz lässt sich direkt in die Kachel-Visualisierung legen. Sie zeigt das
Livebild (wahlweise mit Rahmen um erkannte Objekte), einen roten Rahmen bei Alarm, die Zähler und
die letzten Ereignisse mit Vorschaubild. Ein Tipp auf ein Ereignis zeigt Schnappschuss und Clip,
ein Tipp aufs Livebild vergrößert es; Erkennung, Aufnahme und Schnappschüsse lassen sich direkt
schalten. Die Bilder holt die Instanz selbst bei Frigate und reicht sie über einen eigenen
WebHook weiter — der Browser muss Frigate also nicht erreichen. Jeder Abruf braucht einen
geheimen Schlüssel der Instanz; der lässt sich im Panel „Kachel" erneuern.

Weiter in der Kachel:
- **Warnmarken** oben, wenn in Frigate Kamera, Erkennung, Aufnahme oder Schnappschüsse aus sind.
  Die Schalter darunter sind als „Frigate:" gekennzeichnet — sie ändern die Einstellung in Frigate,
  sie filtern nichts.
- **Ereignisse:** ein Punkt markiert noch nicht gesehene; Öffnen markiert sie in Frigate als gesehen.
  Mit KI-Einschätzung zeigt die Karte deren Titel und bei Verdacht ein Zeichen; die Großansicht die
  Zusammenfassung. ☆ behält ein Ereignis dauerhaft (Frigate löscht es dann nicht), 💾 legt einen
  Export in Frigate an.
- **📅 Archiv:** alle Ereignisse eines Tages (Auswahl mit Anzahl je Tag), filterbar nach Alarm,
  Erkennung, ungesehen, KI-Verdacht und Objekt. „🎞 Aufnahme" spielt die Aufnahme ab einer Uhrzeit
  (1–30 min) — vorausgesetzt, Frigate hat in dieser Zeit aufgezeichnet.
- **Zeitstempel:** Livebild, Meldungsbild und „Aktuelles Kamerabild holen" tragen Frigates
  Zeitstempel (abschaltbar, Aussehen über `timestamp_style` in Frigate). Für die Schnappschüsse zu
  Ereignissen gilt Frigates `snapshots: timestamp: true`. In Aufnahmen und Clips schreibt Frigate
  keinen Zeitstempel — dort hilft nur die Uhrzeit-Einblendung der Kamera selbst.
- **PTZ:** Kann Frigate die Kamera schwenken oder zoomen (`/api/<kamera>/ptz/info`), erscheint in
  der vergrößerten Liveansicht ein Steuerkreuz mit den Voreinstellungen.

**Aufnahme-Wächter:** Frigate meldet weiter „läuft", auch wenn es nichts mehr speichert — etwa
wenn die USB-Platte mit den Aufnahmen abreißt. Der Wächter in der Instanz **Frigate-Server**
prüft deshalb jede Minute über Frigates API (braucht die Frigate-Adresse):

| Prüfung | Woran er es erkennt |
|---|---|
| Frigate antwortet nicht | 3 Minuten keine Antwort der API |
| Schreibfehler | neue Fehler beim Ablegen der Aufnahmen in Frigates Protokoll (ab 3 je Prüfung); auf Port 8971 braucht der Frigate-Benutzer dafür die Rolle „admin" |
| Aufnahmespeicher gewechselt | Größe oder Art des Speichers unter `/media/frigate/recordings` weicht vom gemerkten Stand ab (Platte fehlt, Frigate schreibt auf die Systemplatte) |
| Alarm ohne Aufnahme | ein Alarm ist seit 2 Minuten vorbei und hat keine Aufnahme |
| Daueraufnahme steht | neueste Aufnahme älter als 5 Minuten (nur Kameras mit Daueraufnahme) |

Meldungen gehen an Kachel-Visualisierung, WebFront oder E-Mail: bei jeder neuen Störung, als
tägliche Erinnerung und als Entwarnung. Ohne Daueraufnahme sind Stunden ohne neue Aufnahme
normal; das wertet der Wächter nicht als Fehler. Nach einem gewollten Plattentausch
„Aktuellen Speicher übernehmen". Läuft Symcon auf demselben Rechner wie Frigate, kann bei einem
Ausfall des ganzen Rechners nichts warnen.

**Übersicht aller Kameras:** Die Instanz **Frigate-Server** ist zugleich eine Kachel mit den
Livebildern aller Kameras dieser Frigate-Instanz (Alarm, Personen, offline, gestörte Verbindung)
und Birdseye, wenn Frigate es liefert; auch sie gibt es als eigenständige Seite für IPSView.

**IPSView und Browser:** Dieselbe Oberfläche gibt es als eigenständige Seite
`http://<Symcon-IP>:3777/hook/frigatehub<ID>?seite=1&k=<Schlüssel>` — in IPSView als
Webseiten-Element (WebView) einbinden. Die vollständige Adresse zeigt der Knopf „Adresse für
IPSView anzeigen" im Panel „🖥️ Kachel und IPSView". Die Seite zeigt den Kameranamen, holt alle
5 s neue Daten und hat Livebild, Livevideo, Ereignisse und Clips wie die Kachel. Weil sie ohne
Symcon-Anmeldung erreichbar ist, braucht schon die Anzeige den Schlüssel; die Schalter lassen sich
nur bedienen, wenn das im Formular freigegeben ist (sonst nur Anzeige). Ein neuer Schlüssel macht
alte Adressen ungültig.

**Livevideo in der Kachel:** Hat Frigates eingebauter Restream (go2rtc) einen Stream für die
Kamera, spielt die Kachel echtes Video (mit Ton, wenn die Kamera welchen liefert) statt des Standbilds — im Heimnetz, per WebRTC.
Dafür in der Frigate-Konfiguration (Beispiel, Namen und Zugang wie in der eigenen Kamera):

```yaml
go2rtc:
  streams:
    haustuer:                                   # Name = Kamera-ID in Frigate
      - rtsp://benutzer:{FRIGATE_RTSP_PASSWORD}@192.168.1.30:554/stream1
  webrtc:
    candidates:
      - 192.168.1.20:8555                       # IP des Frigate-Rechners
      - stun:8555

cameras:
  haustuer:
    ffmpeg:
      inputs:
        - path: rtsp://127.0.0.1:8554/haustuer  # optional: Frigate nutzt den Restream,
          input_args: preset-rtsp-restream      # die Kamera hat dann nur eine Verbindung
          roles: [record, detect, audio]
```

Im Docker-Aufruf Port 8555 (TCP und UDP) freigeben. Die Kamera muss H.264 liefern (H.265 spielt
kaum ein Browser). Nach dem Neustart von Frigate in der Instanz „Zustand jetzt abfragen" — das
Panel „🖥️ Kachel" zeigt dann „Livevideo über go2rtc-Stream …". Unterwegs (außerhalb des
Heimnetzes) zeigt die Kachel weiter das Standbild.

**Ton:** Das Video startet stumm (Vorgabe der Browser), 🔇 schaltet den Ton ein. Der Browser
spielt G.711 (PCMU/PCMA) und Opus; Ton im AAC-Format bringt go2rtc nur mit einer zweiten Quelle
`- ffmpeg:haustuer#audio=opus` beim Stream mit. Die Kachel erkennt zwei Fälle selbst und zeigt
dann „🔇 kein Ton" mit Erklärung: Der Stream hat keine abspielbare Tonspur, oder die Kamera schickt
nur Stille. Das Zweite ist typisch für Kameras mit Line-Eingang ohne angeschlossenes Mikrofon und
lässt sich erst nach dem Einschalten des Tons messen.

**Push bei Alarm:** Im Panel „🔔 Push und Meldungen" eine oder mehrere Ziele eintragen
(Kachel-Visualisierung, WebFront oder SMTP für E-Mail mit Bild). Jeder neue Alarm kommt dann einmal als Meldung aufs Handy,
Titel ist der Name der Instanz, Text Zeit, Objekt und Zone. Was ein Alarm ist, legt man in Frigate
fest (`review: alerts: labels` bzw. `required_zones`). Ton und Mindestabstand sind einstellbar.
Push-Nachrichten können kein Bild mitschicken — Antippen öffnet die Kamera-Kachel. Wer das Bild
in der Meldung will, trägt zusätzlich eine **SMTP-Instanz** als Ziel ein: Dann kommt jede Meldung
als E-Mail mit dem aktuellen Kamerabild (Rahmen um erkannte Objekte).

**Meldung mit Schnappschuss für Alarmanlagen und Regeln:**
`FRIG_MeldungMitSchnappschuss($id, "Text", true)` schickt eine Meldung mit dem aktuellen
Kamerabild an dieselben Ziele. In Symcon-Aktionen („Instanzfunktion ausführen") lässt sie sich
z. B. in OpenHomeAlarm als Alarm-Aktion oder im DG65-Toolkit Automat eintragen. Leerer Text nimmt
„Letztes Objekt", `false` den letzten Schnappschuss statt des Livebilds. Das Livebild landet im
eigenen Medienobjekt „Meldungsbild" und überschreibt den Erkennungs-Schnappschuss nicht.

Mit Frigates GenAI kommt die KI-Zusammenfassung als zweite Meldung hinterher; findet die KI eine
bloße Erkennung verdächtig, meldet FrigateHub auch diese (abschaltbar). Bedingte Meldungen
(nur bei Abwesenheit, nachts …) gehören in eine Regel, z. B. mit dem DG65-Toolkit Automat.

Schalter übernehmen einen Wert erst, wenn Frigate ihn bestätigt. Bleibt die Bestätigung aus,
zeigt Symcon weiter den echten Zustand.

## Kamera als Sensor

Jede Kamera liefert über Alarm und Erkennung hinaus Werte mit Bedeutung — für Abläufe (z. B. mit
dem DG65-Toolkit Automat), Alarmanlagen und das Energiemanagement. Die Variablen entstehen erst,
wenn Frigate die Daten liefert; was Frigate erkennen soll, muss dort eingerichtet sein.

| Variable | Bedeutung | Frigate braucht |
|---|---|---|
| Zustand \<Modell\> | Klasse aus der Zustandsklassifizierung, z. B. Tor `open`/`closed`, Tonne draußen | Zustandsklassifizierung (0.17+) |
| \<Objekt\> steht seit, \<Zone\>: \<Objekt\> steht seit | Zeitpunkt, seit dem z. B. ein Auto im Carport steht („-" = keins) | Objekt verfolgen (stationary ist Standard) |
| Paket entfernt | ein liegendes Paket ist verschwunden | Objekt `package` |
| \<Zone\>: verweilt | jemand bleibt länger als N Sekunden in der Zone (Standard 30 s, Personen) | Zonen |
| Erkannte Person (Name), Unbekanntes Gesicht | wer erkannt wurde; Zeitpunkt, wenn ein Gesicht gesehen, aber niemand erkannt wurde | Gesichtserkennung |
| Erkanntes Fahrzeug, Unbekanntes Kennzeichen | Name aus Frigates bekannten Kennzeichen, sonst das Kennzeichen; Zeitpunkt bei fremdem Kennzeichen | Kennzeichenerkennung |
| Letzte Geschwindigkeit | Durchschnitt des letzten gemessenen Objekts (km/h bzw. mph) | Zone mit Geschwindigkeitsmessung |
| Auslöser \<Name\> | Zeitpunkt, wann ein semantischer Auslöser anschlug („roter Transporter") | Semantische Suche mit Auslösern |
| Gesprochener Text | Transkript, was an der Kamera gesagt wurde | Audio-Transkription |
| Heute: Personen, Autos …, Alarme | Tageszähler, um Mitternacht auf 0 | — |
| Bild auffällig, Bildzustand | Kamera abgedeckt/verdreckt, verstellt oder Dauerbewegung (Spinnennetz, Regen) | Frigate-Adresse |

Die **Bildprüfung** holt alle 5 Minuten ein kleines Livebild und vergleicht es mit der gelernten
Ansicht derselben Tagesstunde. Helligkeit zählt dabei nicht (Sonne, Licht), nur das Muster; erst
drei auffällige Prüfungen in Folge melden. Nachts ohne Licht ist ein dunkles Bild normal.
Nach einem gewollten Umbau „Ansicht neu lernen".

## In Frigate einstellen

Viele Einstellungen lassen sich ändern, ohne Frigates Oberfläche zu öffnen: Panel
**⚙️ In Frigate einstellen** in der Kamera (unten bei den Aktionen). Es zeigt beim Öffnen den
Stand aus Frigate; gespeichert wird nur, was geändert wurde.

- Aufnahme an/aus, wie lange Alarme, Erkennungen, Daueraufnahme und Bewegungsaufnahmen bleiben
- welche Objektarten Frigate erkennt, welche als Alarm zählen und in welchen Zonen
- je Zone: welche Objekte zählen (z. B. `car` im Carport) und die Verweildauer
- Schnappschüsse und wie lange sie bleiben, ab wann ein Objekt als stehend gilt
- Kennzeichen- und Gesichtserkennung je Kamera (wenn in Frigate global an), Geräusche und welche

Frigate schreibt die Werte in seine `config.yml` (Kommentare bleiben), prüft sie und übernimmt sie
sofort; ungültige Werte nimmt Frigate selbst zurück, der Grund steht dann im Formular. Das braucht
die Frigate-Rolle „admin" (Port 5000 hat sie immer). Zonen und Masken zeichnen sowie Kameras
anlegen bleiben in Frigates Oberfläche.

## Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Frigate (getestet mit 0.18.2), das per MQTT an Symcon sendet:

```yaml
mqtt:
  enabled: true
  host: <IP des Symcon-Servers>
  port: 1883
  user: "{FRIGATE_MQTT_USER}"          # falls der MQTT-Server Zugangsdaten verlangt
  password: "{FRIGATE_MQTT_PASSWORD}"
```

## Einrichtung

**Am schnellsten mit dem Konfigurator:**

1. Modulverwaltung → Repository-URL dieses Moduls hinzufügen.
2. Instanz **Frigate-Konfigurator** unter dem MQTT-Server anlegen, an den Frigate sendet.
3. Frigate-Adresse eintragen und übernehmen: `http://192.168.1.20:5000` (ohne Anmeldung) oder
   `https://192.168.1.20:8971` — dann Frigate-Benutzer und Passwort eintragen und **Anmelden**.
4. In der Liste die gewünschten Kameras **Erstellen** — fertig eingerichtet. Die letzte Zeile
   **Frigate-Server** legt die Server-Instanz an.

**Oder von Hand:**

1. Modulverwaltung → Repository-URL dieses Moduls hinzufügen.
2. Instanz **Frigate-Kamera** anlegen und als übergeordnete Instanz den MQTT-Server wählen, an
   den Frigate sendet.
3. **Kamera-ID** eintragen: der Name aus der Frigate-Konfiguration unter `cameras:`, nicht der
   Anzeigename. Präfix nur ändern, wenn in Frigate `mqtt.topic_prefix` gesetzt ist.
4. Optional, aber empfohlen: **Frigate-Adresse** eintragen, z. B. `http://192.168.1.20:5000`
   (Frigates API ohne Anmeldung; Port 5000 muss im Docker-Aufruf freigegeben sein). Dann holt
   die Instanz Schalter, Zonen und den Kamerazustand sofort nach dem Übernehmen und nach jedem
   Symcon-Start. Alternativ Port 8971 (`https://…:8971`) mit Frigate-Benutzer und Passwort.
5. Übernehmen. Ohne Frigate-Adresse erscheinen Schalter erst, wenn Frigate sich neu mit dem
   MQTT-Server verbindet oder etwas umgeschaltet wird — Frigate schickt sie nur dann.

Das Panel **Empfangene Nachrichten** zeigt, was von Frigate ankommt — hilfreich, wenn eine
Variable fehlt.

Im Panel **🪟 Formular: Aufbau** (Kamera und Frigate-Server) legst du fest, welche Bereiche beim
Öffnen aufgeklappt, zugeklappt oder ausgeblendet sind und in welcher Reihenfolge sie stehen.
Ausgeblendete Bereiche behalten ihre Einstellungen und arbeiten weiter.

### Anmeldung über Port 8971

Ist Port 5000 nicht freigegeben, geht der Weg über Frigates Weboberfläche auf Port 8971:

- Benutzer und Passwort eines Frigate-Benutzers eintragen, **Anmelden**. Das Passwort wird nur
  für diese Anmeldung benutzt und nicht gespeichert. FrigateHub behält Frigates Zugangsschlüssel
  (in der Instanz, nicht im Formular sichtbar; Symcon verschlüsselt seine Datenbank nicht).
- Frigate verlängert den Schlüssel, solange Symcon läuft (Abfrage alle 10 min). War Symcon
  länger aus als Frigates `auth.session_length` (Standard 24 h), einmal neu anmelden.
- Alle FrigateHub-Instanzen mit derselben Adresse nutzen die Anmeldung mit — im Konfigurator
  einmal anmelden genügt.
- Frigate erzeugt für Port 8971 ein eigenes Zertifikat. „Zertifikat prüfen" nur einschalten, wenn
  Frigate ein gültiges Zertifikat hat.

## Zusammen mit OpenHomeAlarm (optional)

[OpenHomeAlarm](https://community.symcon.de/t/modul-openhomealarm-flexibles-alarmsystem-fuer-symcon/144342)
von Burki24 ist eine Alarmanlage für Symcon (Bereiche, Scharfmodi, Verzögerungen, Eskalation).
Die beiden Module arbeiten über gewöhnliche Symcon-Variablen und Aktionen zusammen. Keines setzt
das andere voraus, und keines muss vom anderen wissen. Die Aufteilung ist mit Burki24 abgestimmt:
FrigateHub kümmert sich um Kameras, Zonen, Objekterkennung und Bilder, OpenHomeAlarm bekommt nur
ein bereits bewertetes Ereignis und entscheidet nach Bereich und Scharfmodus, ob daraus ein Alarm wird.

**FrigateHub → OpenHomeAlarm (Alarmereignis-Schnittstelle, ab OpenHomeAlarm 1.423):**
OpenHomeAlarm hat eine herstellerneutrale Schnittstelle für bestätigte Alarmereignisse aus
Videoüberwachungssoftware. FrigateHub meldet darüber jeden neuen Frigate-Alarm einer Kamera:
- In der Kamera, Panel „🛡️ OpenHomeAlarm (optional)", eine **Quellen-ID** eintragen (z. B.
  `frigate-haustuer`) und wählen, ob nur Frigate-Alarme oder auch Erkennungen gemeldet werden.
- In OpenHomeAlarm unter „Externe Alarmereignis-Schnittstelle" die Schnittstelle einschalten und
  dieselbe Quellen-ID mit Bereich und erlaubten Scharfmodi eintragen.
- OpenHomeAlarm entscheidet selbst: Nur wenn der Bereich in einem freigegebenen Modus scharf ist,
  wird daraus ein Alarm. Unscharf werden Ereignisse verworfen und lösen auch später nicht aus; jede
  Frigate-Meldung trägt ihre Review-ID, Wiederholungen verwirft OpenHomeAlarm.
- Die Statuszeile zeigt, ob die Quelle in OpenHomeAlarm eingetragen ist, und das Ergebnis der
  letzten Meldung. „Verbindung prüfen" schickt ein Probeereignis — nur solange der Bereich unscharf
  ist, so dass dabei nie ein Alarm entstehen kann.

Alternativ, ohne die Schnittstelle, über Variablen:

**FrigateHub → OpenHomeAlarm (Melder):**
- „Alarm" einer Kamera (Bool) als Sensor mit Auslösewert „wahr" — Frigates eigene Einstufung, also
  nur Objekte und Zonen, die in Frigate als Alarm gelten.
- Oder ein Zähler wie „Eingangsbereich: Personen" mit der Regel „Abweichung vom Normalwert",
  Normalwert 0. Die Zähler zeigen die gerade erkannte Anzahl und fallen danach wieder auf 0 —
  keine fortlaufende Summe.
- „Kamera online" als Störungseingang, Normalwert „wahr". Ob eine ausgefallene Kamera nur als
  Störung erscheint oder das Scharfschalten verhindert, legt man in OpenHomeAlarm fest.

**OpenHomeAlarm → FrigateHub (Aktionen):**
- Eine Alarmaktion „Schalte auf Wert" auf „Aufnahme" oder „Objekterkennung" der Kamera, mit
  automatischem Umkehren beim Zurücksetzen.
- Meldung mit Kamerabild: Alarmaktion „Instanzfunktion ausführen" mit
  `FRIG_MeldungMitSchnappschuss(<Kamera-Instanz>, "Alarm", true)`. Das Bild verschickt FrigateHub an
  die Ziele der Kamera (E-Mail mit Bild über SMTP, Push öffnet die Kamera).
- Mehrere Kameras: Die Alarmaktionen von OpenHomeAlarm hängen nicht an einem bestimmten Sensor.
  Eine einzige Alarmaktion `FRIGS_MeldungLetzteKamera(<Frigate-Server>, "Alarm", true)` genügt:
  Sie nimmt die Kamera mit dem jüngsten Alarm (sonst der jüngsten Erkennung) und schickt deren Bild.

**Die Module finden sich von selbst** (wie im NRG-Stack: Modul-GUID, Abruffunktion mit
Vertragsversion, kein Muss):
- FrigateHub findet OpenHomeAlarm über dessen Modul-GUID und liest nur `OHA_GetControlState()`.
  Im Panel „🛡️ OpenHomeAlarm (optional)" der Kamera steht, ob es gefunden wurde und welche Bereiche
  es meldet. Dort lässt sich einstellen, was beim **Scharfschalten eines Bereichs** geschieht
  (Aufnahme und/oder Objekterkennung an) und ob das Unscharfschalten wieder ausschaltet. Geschaltet
  wird nur beim tatsächlichen Wechsel (scharf erst nach der Ausgangsverzögerung), nie beim
  Übernehmen. FrigateHub ist gegen OpenHomeAlarms Control-API 3 geprüft (`ApiVersion` in
  `OHA_GetControlState()`); meldet es eine andere, schaltet FrigateHub die Kopplung sichtbar ab,
  statt falsch zu lesen.
- Für andere Module bietet die Instanz Frigate-Server `FRIGS_GetCameras($id)`: je Kamera die
  Variablen für Melder (Alarm, Erkennung, Bewegung, Personen, Zonen-Zähler, online), die Schalter
  und die Meldefunktion, mit `contractVersion` (derzeit 1.1, Hauptversion nur bei Bruch).
  Seit 1.1 zusätzlich: Verweilen je Zone (ein besserer Scharf-Melder als „Person erkannt"),
  Geräusche, unbekanntes Gesicht/Kennzeichen, Sabotage (Bild abgedeckt/verstellt), Störungen
  (Kamera offline, Aufnahme gestört) und Zustände aus Frigates Klassifizierung.
- `FRIGS_ProfilSetzen($id, "away")` schaltet ein Frigate-Profil (Frigate 0.18+), etwa beim
  Scharfschalten; `""` oder `"none"` schaltet es ab.

Fragen zu OpenHomeAlarm selbst gehören in dessen Forum-Thread, Fragen zu Kameras und Frigate in
den von FrigateHub.

## Hinweise zur Store-Prüfung

- **Dateien:** Clips für die Kachel legt FrigateHub kurz im Temp-Ordner des Systems ab
  (`frigatehub_<Instanz>_<Hash>.mp4`) und liefert sie stückweise aus — Symcon gibt große Antworten
  über einen WebHook sonst leer aus, und Frigates `clip.mp4` kennt keine Teilabrufe. Die Dateien
  werden nach 10 Minuten und beim Löschen der Instanz entfernt. Bilder liegen als Medienobjekte
  der eigenen Instanz im Symcon-Medienordner.
- **WebHook:** Kamera und Frigate-Server tragen ihren eigenen Hook (`/hook/frigatehub<ID>`,
  `/hook/frigatehubserver<ID>`) in Symcons WebHook-Steuerung ein und beim Löschen wieder aus;
  andere Einträge bleiben unberührt. Der Hook verlangt einen Schlüssel je Instanz.
- **Frigate-Passwort:** keine Property; nur Frigates Zugangsschlüssel wird gespeichert (Attribut).

## Abgrenzung

Kameraeigene Ereignisse liefern im Symcon-Store die Module für Reolink, UniFi Protect
(bgersmann) und ONVIF. FrigateHub ist die herstellerunabhängige Analyse für beliebige
RTSP-Kameras und ergänzt diese Module.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar. Für gewerbliche Nutzung
bitte melden: dietmar@gureth.eu. Spenden freiwillig über
[PayPal](https://paypal.me/DietmarGureth).
