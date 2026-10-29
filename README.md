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
- **PTZ:** Kann Frigate die Kamera schwenken oder zoomen (`/api/<kamera>/ptz/info`), erscheint in
  der vergrößerten Liveansicht ein Steuerkreuz mit den Voreinstellungen.

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

## Abgrenzung

Kameraeigene Ereignisse liefern im Symcon-Store die Module für Reolink, UniFi Protect
(bgersmann) und ONVIF. FrigateHub ist die herstellerunabhängige Analyse für beliebige
RTSP-Kameras und ergänzt diese Module.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar. Für gewerbliche Nutzung
bitte melden: dietmar@gureth.eu. Spenden freiwillig über
[PayPal](https://paypal.me/DietmarGureth).
