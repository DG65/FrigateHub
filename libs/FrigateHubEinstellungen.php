<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';

/**
 * Frigate-Einstellungen einer Kamera aus Symcon heraus — reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Schreibweg wie Frigates eigene Oberfläche (web/src/components/config-form/sections/BaseSection.tsx,
 * Frigate 0.18.2): PUT /api/config/set mit {requires_restart: 0, update_topic:
 * "config/cameras/<kamera>/<bereich>", config_data: {cameras: {<kamera>: {<bereich>: …}}}}.
 * Frigate schreibt die Werte in die config.yml (ruamel, Kommentare bleiben), prüft die ganze
 * Konfiguration und stellt bei einem Fehler die alte Datei wieder her; mit update_topic gilt die
 * Änderung sofort. Für die Kamerabereiche hier verlangt Frigates Oberfläche keinen Neustart
 * (section-configs/*.ts: restartRequired leer). Listen werden als Ganzes ersetzt.
 * Nur Rolle admin (Port 5000 = admin).
 */
class FrigateHubEinstellungen
{
    /** Objektarten zur Auswahl: Frigates Standardmodell (COCO) plus Frigate+-Klassen; weitere frei eintragbar. */
    public const OBJEKTE = ['person', 'car', 'motorcycle', 'bicycle', 'bus', 'truck', 'boat', 'dog', 'cat', 'bird', 'horse',
        'sheep', 'cow', 'bear', 'package', 'face', 'license_plate'];

    /** Geräusche zur Auswahl (Frigates Audio-Modell, häufigste); weitere frei eintragbar. */
    public const GERAEUSCHE = ['speech', 'yell', 'scream', 'bark', 'fire_alarm', 'smoke_detector', 'glass', 'siren', 'car_alarm', 'doorbell', 'knock', 'crying', 'baby_cry'];

    /**
     * Was Frigate von Alarmen bzw. Erkennungen aufbewahrt (record.alerts|detections.retain.mode): alle Teilstücke,
     * nur solche mit Bewegung (Frigate-Standard) oder solange ein erkanntes Objekt aktiv ist. „Nur mit Bewegung"
     * verliert Sekunden, in denen jemand still steht (Dietmar 05.11.2026: 11 s Lücke mitten im Alarm).
     */
    public const AUFBEWAHREN = ['all' => 'Everything', 'motion' => 'Only with motion', 'active_objects' => 'While an object is active'];

    /** Formularwerte, die Listen sind (Eingabe als Liste oder mit Komma). */
    private const LISTEN = ['verfolgen', 'alarmObjekte', 'alarmZonen', 'hoeren'];

    /** Bereiche, die diese Seite schreibt (= update_topic-Namen in Frigate). */
    public const BEREICHE = ['record', 'objects', 'review', 'snapshots', 'detect', 'lpr', 'face_recognition', 'audio', 'zones'];

    /**
     * Werte für das Formular aus /api/config.cameras.<kamera> (+ globale Schalter für LPR/Gesichter).
     *
     * @return array<string,mixed>
     */
    public static function lesen(array $kamera, array $config): array
    {
        $r = (array)($kamera['record'] ?? []);
        $fps = max(1, (int)($kamera['detect']['fps'] ?? 5));
        $zonen = [];
        foreach ((array)($kamera['zones'] ?? []) as $name => $z) {
            $zonen[(string)$name] = [
                'objekte'  => array_values(array_map('strval', (array)($z['objects'] ?? []))),
                'verweilen' => (int)($z['loitering_time'] ?? 0),
            ];
        }
        return [
            'aufnahme'      => (bool)($r['enabled'] ?? false),
            'dauer'         => (float)($r['continuous']['days'] ?? 0),
            'bewegung'      => (float)($r['motion']['days'] ?? 0),
            'alarme'        => (float)($r['alerts']['retain']['days'] ?? 0),
            'erkennungen'   => (float)($r['detections']['retain']['days'] ?? 0),
            'alarmModus'    => (string)($r['alerts']['retain']['mode'] ?? 'motion'),
            'erkennungModus' => (string)($r['detections']['retain']['mode'] ?? 'motion'),
            'verfolgen'     => array_values(array_map('strval', (array)($kamera['objects']['track'] ?? []))),
            'alarmObjekte'  => array_values(array_map('strval', (array)($kamera['review']['alerts']['labels'] ?? []))),
            'alarmZonen'    => array_values(array_map('strval', (array)($kamera['review']['alerts']['required_zones'] ?? []))),
            'schnappschuss' => (bool)($kamera['snapshots']['enabled'] ?? false),
            'schnappTage'   => (float)($kamera['snapshots']['retain']['default'] ?? 0),
            'stillstand'    => (int)round((int)($kamera['detect']['stationary']['threshold'] ?? 50) / $fps),
            'fps'           => $fps,
            // null = in Frigate global aus: dann lässt es sich je Kamera nicht einschalten
            'kennzeichen'   => !empty($config['lpr']['enabled']) ? (bool)($kamera['lpr']['enabled'] ?? false) : null,
            'gesichter'     => !empty($config['face_recognition']['enabled']) ? (bool)($kamera['face_recognition']['enabled'] ?? false) : null,
            'audio'         => (bool)($kamera['audio']['enabled'] ?? false),
            'audioRolle'    => self::hatAudioRolle($kamera),
            'hoeren'        => array_values(array_map('strval', (array)($kamera['audio']['listen'] ?? []))),
            'zonen'         => $zonen,
        ];
    }

    /** Hat die Kamera einen Stream mit Rolle „audio"? Ohne den lehnt Frigate Audio-Erkennung ab. */
    public static function hatAudioRolle(array $kamera): bool
    {
        foreach ((array)($kamera['ffmpeg']['inputs'] ?? []) as $i) {
            if (in_array('audio', (array)($i['roles'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Was sich geändert hat, je Frigate-Bereich als config_data-Ausschnitt. Nur geänderte Felder,
     * damit Frigates YAML nichts Unberührtes umschreibt.
     *
     * @return array<string,array> Bereich → Daten
     */
    public static function aenderungen(array $alt, array $neu): array
    {
        $out = [];
        $anders = fn (string $k): bool => array_key_exists($k, $neu)
            && self::norm($neu[$k], in_array($k, self::LISTEN, true)) !== self::norm($alt[$k] ?? null, in_array($k, self::LISTEN, true));

        $rec = [];
        if ($anders('aufnahme')) {
            $rec['enabled'] = (bool)$neu['aufnahme'];
        }
        if ($anders('dauer')) {
            $rec['continuous'] = ['days' => self::tage($neu['dauer'])];
        }
        if ($anders('bewegung')) {
            $rec['motion'] = ['days' => self::tage($neu['bewegung'])];
        }
        if ($anders('alarme')) {
            $rec['alerts']['retain']['days'] = self::tage($neu['alarme']);
        }
        if ($anders('alarmModus') && isset(self::AUFBEWAHREN[(string)$neu['alarmModus']])) {
            $rec['alerts']['retain']['mode'] = (string)$neu['alarmModus'];
        }
        if ($anders('erkennungen')) {
            $rec['detections']['retain']['days'] = self::tage($neu['erkennungen']);
        }
        if ($anders('erkennungModus') && isset(self::AUFBEWAHREN[(string)$neu['erkennungModus']])) {
            $rec['detections']['retain']['mode'] = (string)$neu['erkennungModus'];
        }
        if ($rec !== []) {
            $out['record'] = $rec;
        }
        if ($anders('verfolgen')) {
            $out['objects'] = ['track' => self::liste($neu['verfolgen'])];
        }
        $rev = [];
        if ($anders('alarmObjekte')) {
            $rev['labels'] = self::liste($neu['alarmObjekte']);
        }
        if ($anders('alarmZonen')) {
            $rev['required_zones'] = self::liste($neu['alarmZonen']);
        }
        if ($rev !== []) {
            $out['review'] = ['alerts' => $rev];
        }
        $snap = [];
        if ($anders('schnappschuss')) {
            $snap['enabled'] = (bool)$neu['schnappschuss'];
        }
        if ($anders('schnappTage')) {
            $snap['retain'] = ['default' => self::tage($neu['schnappTage'])];
        }
        if ($snap !== []) {
            $out['snapshots'] = $snap;
        }
        if ($anders('stillstand')) {
            $fps = max(1, (int)($alt['fps'] ?? 5));
            $out['detect'] = ['stationary' => ['threshold' => max(1, (int)$neu['stillstand']) * $fps]];
        }
        if ($anders('kennzeichen') && ($alt['kennzeichen'] ?? null) !== null) {
            $out['lpr'] = ['enabled' => (bool)$neu['kennzeichen']];
        }
        if ($anders('gesichter') && ($alt['gesichter'] ?? null) !== null) {
            $out['face_recognition'] = ['enabled' => (bool)$neu['gesichter']];
        }
        $audio = [];
        if ($anders('audio')) {
            $audio['enabled'] = (bool)$neu['audio'];
        }
        if ($anders('hoeren')) {
            $audio['listen'] = self::liste($neu['hoeren']);
        }
        if ($audio !== []) {
            $out['audio'] = $audio;
        }
        $zonen = [];
        foreach ((array)($neu['zonen'] ?? []) as $name => $z) {
            $vorher = $alt['zonen'][$name] ?? null;
            if ($vorher === null) {
                continue;   // Zonen anlegen geht nur in Frigate (Polygon zeichnen)
            }
            $d = [];
            if (self::norm($z['objekte'] ?? [], true) !== self::norm($vorher['objekte'], true)) {
                $d['objects'] = self::liste($z['objekte'] ?? []);
            }
            if ((int)($z['verweilen'] ?? 0) !== (int)$vorher['verweilen']) {
                $d['loitering_time'] = max(0, (int)$z['verweilen']);
            }
            if ($d !== []) {
                $zonen[(string)$name] = $d;
            }
        }
        if ($zonen !== []) {
            $out['zones'] = $zonen;
        }
        return $out;
    }

    /** Anfrage für PUT /api/config/set: ein Bereich, sofort wirksam. */
    public static function anfrage(string $kamera, string $bereich, array $daten): array
    {
        return [
            'requires_restart' => 0,
            'update_topic'     => 'config/cameras/' . $kamera . '/' . $bereich,
            'config_data'      => ['cameras' => [$kamera => [$bereich => $daten]]],
        ];
    }

    /** Lesbare Zusammenfassung einer Änderung fürs Formular und das Meldungsprotokoll. */
    public static function beschreiben(array $aenderungen): string
    {
        $namen = ['record' => 'Recording', 'objects' => 'Objects', 'review' => 'Alerts', 'snapshots' => 'Snapshots', 'detect' => 'Stationary objects',
            'lpr' => 'License plates', 'face_recognition' => 'Faces', 'audio' => 'Sounds', 'zones' => 'Zones'];
        return implode(', ', array_map(fn ($b) => isset($namen[$b]) ? FrigateHubText::t($namen[$b]) : $b, array_keys($aenderungen)));
    }

    /** Kommagetrennte Eingabe oder Liste → Liste ohne Leerstellen/Doppelte, Kleinschreibung bleibt (Frigate-Namen). */
    public static function liste($wert): array
    {
        $teile = is_array($wert) ? $wert : explode(',', (string)$wert);
        $out = [];
        foreach ($teile as $t) {
            $t = trim((string)$t);
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Tage: ganze Zahl, wenn ganz (YAML bleibt lesbar), sonst eine Nachkommastelle. */
    private static function tage($wert)
    {
        $t = max(0.0, round((float)$wert, 1));
        return floor($t) === $t ? (int)$t : $t;
    }

    /** Vergleichsform: Listen sortiert, Zahlen als Zahl, Schalter als bool. */
    private static function norm($wert, bool $liste)
    {
        if ($liste) {
            $l = self::liste($wert ?? []);
            sort($l);
            return $l;
        }
        if (is_bool($wert) || $wert === null) {
            return $wert;
        }
        return is_numeric($wert) ? round((float)$wert, 1) : $wert;
    }
}
