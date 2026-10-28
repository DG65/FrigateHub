# FrigateHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.2.1-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

[Frigate](https://frigate.video) in IP-Symcon: Frigate ist eine quelloffene Videoüberwachung
(NVR) mit KI-Objekterkennung. FrigateHub holt die Erkennungen je Kamera und Zone als Variablen
nach Symcon und schaltet Erkennung, Aufnahme, Masken und Zonen von dort aus.

> **Stand 0.2.0:** MQTT, Abgleich über Frigates API und Schnappschuss als Medienobjekt. Ein Konfigurator folgt.

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
| Schnappschuss (Medienobjekt) | Bild der letzten Erkennung (per MQTT) oder, auf Knopfdruck, das Livebild |

Mit eingetragener Frigate-Adresse kommt der Schnappschuss jeder Erkennung in voller
Kameraauflösung. Ohne Adresse nimmt FrigateHub das Bild, das Frigate per MQTT schickt: einen
Ausschnitt um das Objekt (Standard: 270 px hoch). Größe und Zuschnitt stellt man in Frigate je Kamera
unter `mqtt:` ein (`height`, `crop`, `quality`, `bounding_box`, `timestamp`).

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

1. Modulverwaltung → Repository-URL dieses Moduls hinzufügen.
2. Instanz **Frigate-Kamera** anlegen und als übergeordnete Instanz den MQTT-Server wählen, an
   den Frigate sendet.
3. **Kamera-ID** eintragen: der Name aus der Frigate-Konfiguration unter `cameras:`, nicht der
   Anzeigename. Präfix nur ändern, wenn in Frigate `mqtt.topic_prefix` gesetzt ist.
4. Optional, aber empfohlen: **Frigate-Adresse** eintragen, z. B. `http://192.168.1.20:5000`
   (Frigates API ohne Anmeldung; Port 5000 muss im Docker-Aufruf freigegeben sein). Dann holt
   die Instanz Schalter, Zonen und den Kamerazustand sofort nach dem Übernehmen und nach jedem
   Symcon-Start. Port 8971 (mit Anmeldung) wird noch nicht unterstützt.
5. Übernehmen. Ohne Frigate-Adresse erscheinen Schalter erst, wenn Frigate sich neu mit dem
   MQTT-Server verbindet oder etwas umgeschaltet wird — Frigate schickt sie nur dann.

Das Panel **Empfangene Nachrichten** zeigt, was von Frigate ankommt — hilfreich, wenn eine
Variable fehlt.

## Abgrenzung

Kameraeigene Ereignisse liefern im Symcon-Store die Module für Reolink, UniFi Protect
(bgersmann) und ONVIF. FrigateHub ist die herstellerunabhängige Analyse für beliebige
RTSP-Kameras und ergänzt diese Module.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar. Für gewerbliche Nutzung
bitte melden: dietmar@gureth.eu. Spenden freiwillig über
[PayPal](https://paypal.me/DietmarGureth).
