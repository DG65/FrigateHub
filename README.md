# FrigateHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.1.0-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

[Frigate](https://frigate.video) in IP-Symcon: Frigate ist eine quelloffene Videoüberwachung
(NVR) mit KI-Objekterkennung. FrigateHub holt die Erkennungen je Kamera und Zone als Variablen
nach Symcon und schaltet Erkennung, Aufnahme, Masken und Zonen von dort aus.

> **Stand 0.1.0:** MQTT-Teil. Schnappschüsse als Medienobjekt und ein Konfigurator folgen.

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
4. Übernehmen. Eine neue Instanz ist zunächst leer; Variablen erscheinen, sobald Frigate etwas
   meldet. **Zustand bei Frigate anfordern** beschleunigt das.

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
