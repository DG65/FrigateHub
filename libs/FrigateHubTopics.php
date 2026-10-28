<?php

declare(strict_types=1);

/**
 * Zerlegt Frigate-MQTT-Nachrichten — reines PHP ohne Symcon, damit der Prüfstand es direkt
 * aufrufen kann.
 *
 * Quelle der Topic-Struktur: https://docs.frigate.video/integrations/mqtt (Frigate 0.18),
 * abgeglichen mit dem, was eine echte 0.18.2-Instanz am 28.10.2026 an Symcons MQTT-Server
 * geschickt hat (30 Topics einer Kamera). Was dort nicht belegt ist, wird nicht gedeutet,
 * sondern als „ignorieren" zurückgegeben und nur im Debug sichtbar.
 *
 * Wichtig: In Topics steht die Kamera-/Zonen-ID aus der Frigate-Konfiguration, nie der
 * friendly_name.
 */
class FrigateHubTopics
{
    /** Zweite Topic-Ebene unter einer Kamera, die KEIN Objektname ist. */
    private const RESERVIERT = [
        'status', 'motion', 'review_status', 'audio', 'ptz', 'ptz_autotracker', 'notifications',
        'classification', 'snapshot',
    ];

    /** Schaltbare Funktionen (…/<key>/state + …/<key>/set) mit deutschem Namen. */
    private const SCHALTER = [
        'enabled'             => 'Kamera aktiv',
        'detect'              => 'Objekterkennung',
        'recordings'          => 'Aufnahme',
        'snapshots'           => 'Schnappschüsse',
        'motion'              => 'Bewegungserkennung',
        'audio'               => 'Geräuscherkennung',
        'audio_transcription' => 'Sprachtranskription',
        'review_alerts'       => 'Alarme melden',
        'review_detections'   => 'Erkennungen melden',
        'improve_contrast'    => 'Kontrastverbesserung',
        'ptz_autotracker'     => 'PTZ-Verfolgung',
        'birdseye'            => 'Birdseye',
        'object_descriptions' => 'KI-Objektbeschreibungen',
        'review_descriptions' => 'KI-Ereignisbeschreibungen',
        'notifications'       => 'Frigate-Benachrichtigungen',
    ];

    /** Unterpfade mit einem Namen dahinter (…/motion_mask/<name>/state). */
    private const SCHALTER_GRUPPEN = [
        'motion_mask' => 'Bewegungsmaske',
        'object_mask' => 'Objektmaske',
        'zone'        => 'Zone',
    ];

    /** Deutsche Mehrzahl für die Zähler; unbekannte Klassen behalten ihren Frigate-Namen. */
    private const OBJEKTE = [
        'all'           => 'Objekte gesamt',
        'person'        => 'Personen',
        'face'          => 'Gesichter',
        'car'           => 'Autos',
        'motorcycle'    => 'Motorräder',
        'bicycle'       => 'Fahrräder',
        'bus'           => 'Busse',
        'truck'         => 'Lastwagen',
        'boat'          => 'Boote',
        'license_plate' => 'Kennzeichen',
        'package'       => 'Pakete',
        'dog'           => 'Hunde',
        'cat'           => 'Katzen',
        'bird'          => 'Vögel',
        'horse'         => 'Pferde',
        'deer'          => 'Rehe',
        'fox'           => 'Füchse',
        'bear'          => 'Bären',
        'squirrel'      => 'Eichhörnchen',
        'rabbit'        => 'Kaninchen',
        'raccoon'       => 'Waschbären',
        'umbrella'      => 'Regenschirme',
        'waste_bin'     => 'Mülltonnen',
        'on_demand'     => 'Manuelle Ereignisse',
    ];

    /** Einzahl für Ereignistexte. */
    private const OBJEKT_EINZAHL = [
        'person' => 'Person', 'face' => 'Gesicht', 'car' => 'Auto', 'motorcycle' => 'Motorrad',
        'bicycle' => 'Fahrrad', 'bus' => 'Bus', 'truck' => 'Lastwagen', 'boat' => 'Boot',
        'license_plate' => 'Kennzeichen', 'package' => 'Paket', 'dog' => 'Hund', 'cat' => 'Katze',
        'bird' => 'Vogel', 'horse' => 'Pferd', 'deer' => 'Reh', 'fox' => 'Fuchs', 'bear' => 'Bär',
        'squirrel' => 'Eichhörnchen', 'rabbit' => 'Kaninchen', 'raccoon' => 'Waschbär',
        'umbrella' => 'Regenschirm', 'waste_bin' => 'Mülltonne',
        // Manuell in der Frigate-Oberfläche (oder per API) ausgelöstes Ereignis, kein Objekt.
        'on_demand' => 'manuell ausgelöst',
    ];

    /** Häufige Geräuschklassen; der Rest behält seinen Frigate-Namen. */
    private const GERAEUSCHE = [
        'all' => 'beliebig', 'speech' => 'Sprache', 'bark' => 'Bellen', 'yell' => 'Schreien',
        'scream' => 'Kreischen', 'crying' => 'Weinen', 'doorbell' => 'Türklingel', 'knock' => 'Klopfen',
        'fire_alarm' => 'Feueralarm', 'smoke_detector' => 'Rauchmelder', 'siren' => 'Sirene',
        'glass' => 'Glas', 'shatter' => 'Splittern', 'car_alarm' => 'Autoalarm', 'music' => 'Musik',
    ];

    /** Topic-Präfix normalisieren: ohne Schrägstriche am Rand, leer → „frigate". */
    public static function praefix(string $praefix): string
    {
        $p = trim($praefix, " \t/");
        return $p === '' ? 'frigate' : $p;
    }

    /**
     * Deutet ein Topic für genau eine Kamera.
     *
     * Rückgabe ['art' => …, …] oder null, wenn das Topic nicht zu dieser Kamera (bzw. zu einer
     * ihrer bekannten Zonen) gehört. Arten:
     *  verfuegbar | status (rolle) | bewegung | pruefstatus | objekt (label) | aktiv (label)
     *  | zone (zone, label) | schalter (schluessel, name) | geraeusch (typ, name)
     *  | events | reviews | ignorieren (grund)
     *
     * @param string[] $zonen bekannte Zonen-IDs dieser Kamera
     */
    public static function deuten(string $topic, string $praefix, string $kamera, array $zonen): ?array
    {
        $praefix = self::praefix($praefix);
        if ($kamera === '' || strpos($topic, $praefix . '/') !== 0) {
            return null;
        }
        $teile = explode('/', substr($topic, strlen($praefix) + 1));
        $n = count($teile);

        if ($n === 1) {
            switch ($teile[0]) {
                case 'available':
                    return ['art' => 'verfuegbar'];
                case 'events':
                    return ['art' => 'events'];
                case 'reviews':
                    return ['art' => 'reviews'];
            }
            return null;
        }

        if ($teile[0] !== $kamera) {
            // Zonen-Zähler stehen OHNE Kamera im Topic: frigate/<zone>/<objekt>
            if ($n === 2 && in_array($teile[0], $zonen, true)) {
                return ['art' => 'zone', 'zone' => $teile[0], 'label' => $teile[1]];
            }
            if ($n === 3 && $teile[2] === 'active' && in_array($teile[0], $zonen, true)) {
                return ['art' => 'ignorieren', 'grund' => 'Zonen-Zähler nur bewegter Objekte'];
            }
            return null;
        }

        // Ab hier: frigate/<kamera>/…
        $zweite = $teile[1];

        if ($n >= 3 && end($teile) === 'state') {
            if ($n === 3 && isset(self::SCHALTER[$zweite])) {
                return ['art' => 'schalter', 'schluessel' => $zweite, 'name' => self::SCHALTER[$zweite]];
            }
            if ($n === 4 && isset(self::SCHALTER_GRUPPEN[$zweite])) {
                $schluessel = $zweite . '/' . $teile[2];
                return ['art' => 'schalter', 'schluessel' => $schluessel, 'name' => self::schalterName($schluessel)];
            }
            return ['art' => 'ignorieren', 'grund' => 'unbekannter oder nicht schaltbarer Zustand'];
        }

        if ($n === 2) {
            if ($zweite === 'motion') {
                return ['art' => 'bewegung'];
            }
            if ($zweite === 'review_status') {
                return ['art' => 'pruefstatus'];
            }
            if (in_array($zweite, self::RESERVIERT, true)) {
                return ['art' => 'ignorieren', 'grund' => 'reserviertes Topic'];
            }
            return ['art' => 'objekt', 'label' => $zweite];
        }

        if ($n === 3) {
            if ($zweite === 'status') {
                return ['art' => 'status', 'rolle' => $teile[2]];
            }
            if ($zweite === 'audio') {
                if (in_array($teile[2], ['dBFS', 'rms', 'transcription'], true)) {
                    return ['art' => 'ignorieren', 'grund' => 'Pegel/Transkript (sehr häufig)'];
                }
                return ['art' => 'geraeusch', 'typ' => $teile[2], 'name' => self::geraeuschName($teile[2])];
            }
            if ($teile[2] === 'active') {
                return ['art' => 'aktiv', 'label' => $zweite];
            }
            if ($teile[2] === 'snapshot') {
                return ['art' => 'ignorieren', 'grund' => 'Schnappschuss als JPEG (Medienobjekt folgt)'];
            }
        }

        return ['art' => 'ignorieren', 'grund' => 'nicht ausgewertet'];
    }

    /** Deutscher Name eines Schalters; Masken/Zonen ohne doppelten Gruppennamen („Bewegungsmaske 1"). */
    public static function schalterName(string $schluessel): string
    {
        if (isset(self::SCHALTER[$schluessel])) {
            return self::SCHALTER[$schluessel];
        }
        [$gruppe, $name] = array_pad(explode('/', $schluessel, 2), 2, '');
        return (self::SCHALTER_GRUPPEN[$gruppe] ?? $gruppe) . ' ' . self::ohneGruppe($gruppe, $name);
    }

    /**
     * Ident eines Schalters. Frigate nennt Masken „motion_mask_1" unter „motion_mask/" —
     * ohne Abzug hieße die Variable Schalter_motion_mask_motion_mask_1 (0.1.0).
     */
    public static function schalterIdent(string $schluessel): string
    {
        if (strpos($schluessel, '/') === false) {
            return self::ident('Schalter', $schluessel);
        }
        [$gruppe, $name] = explode('/', $schluessel, 2);
        return self::ident('Schalter', strpos($name, $gruppe . '_') === 0 ? $name : $gruppe . '_' . $name);
    }

    /** Einfache (nicht gruppierte) Schalter, die sich aus dem Ident zurückrechnen lassen. */
    public static function istEinfacherSchalter(string $schluessel): bool
    {
        return isset(self::SCHALTER[$schluessel]);
    }

    private static function ohneGruppe(string $gruppe, string $name): string
    {
        return strpos($name, $gruppe . '_') === 0 ? substr($name, strlen($gruppe) + 1) : $name;
    }

    /**
     * Schalterzustände einer Kamera aus Frigates /api/config (cameras.<kamera>) — dieselben
     * Schlüssel wie die MQTT-Topics …/<schluessel>/state. Abgeglichen mit Frigate 0.18.2
     * (28.10.2026). Fehlt ein Feld, fehlt auch der Schalter: lieber keiner als ein falscher.
     * „notifications" fehlt bewusst: Frigate meldet ihn per MQTT nur bei eingerichteten
     * Push-Benachrichtigungen, und der Abgleich soll nicht mehr Schalter anlegen als MQTT.
     *
     * @return array<string,bool> Schlüssel => an/aus
     */
    public static function schalterAusConfig(array $kamera): array
    {
        $pfade = [
            'enabled'             => ['enabled'],
            'detect'              => ['detect', 'enabled'],
            'recordings'          => ['record', 'enabled'],
            'snapshots'           => ['snapshots', 'enabled'],
            'motion'              => ['motion', 'enabled'],
            'improve_contrast'    => ['motion', 'improve_contrast'],
            'audio'               => ['audio', 'enabled'],
            'audio_transcription' => ['audio_transcription', 'enabled'],
            'review_alerts'       => ['review', 'alerts', 'enabled'],
            'review_detections'   => ['review', 'detections', 'enabled'],
            'birdseye'            => ['birdseye', 'enabled'],
            'ptz_autotracker'     => ['onvif', 'autotracking', 'enabled'],
            'object_descriptions' => ['objects', 'genai', 'enabled'],
            'review_descriptions' => ['review', 'genai', 'enabled'],
        ];
        $out = [];
        foreach ($pfade as $schluessel => $pfad) {
            $wert = $kamera;
            foreach ($pfad as $teil) {
                $wert = is_array($wert) && array_key_exists($teil, $wert) ? $wert[$teil] : null;
            }
            if (is_bool($wert)) {
                $out[$schluessel] = $wert;
            }
        }
        $gruppen = [
            'motion_mask' => (array)($kamera['motion']['mask'] ?? []),
            'object_mask' => (array)($kamera['objects']['mask'] ?? []),
            'zone'        => (array)($kamera['zones'] ?? []),
        ];
        foreach ($gruppen as $gruppe => $eintraege) {
            foreach ($eintraege as $name => $e) {
                if (is_string($name) && is_array($e) && is_bool($e['enabled'] ?? null)) {
                    $out[$gruppe . '/' . $name] = $e['enabled'];
                }
            }
        }
        return $out;
    }

    /** Zonen-IDs einer Kamera aus /api/config. */
    public static function zonenAusConfig(array $kamera): array
    {
        return array_values(array_filter(array_map('strval', array_keys((array)($kamera['zones'] ?? []))), 'strlen'));
    }

    /**
     * Kurzinhalt fürs Panel „Empfangene Nachrichten". Frigate schickt Schnappschüsse als JPEG
     * über MQTT (…/<objekt>/snapshot) — roh angezeigt wäre das Zeichensalat.
     */
    public static function kurzInhalt(string $art, string $payload): string
    {
        if (in_array($art, ['events', 'reviews'], true)) {
            return '(JSON, ' . strlen($payload) . ' Zeichen)';
        }
        if (strncmp($payload, "\xFF\xD8\xFF", 3) === 0) {
            return '(Bild, ' . strlen($payload) . ' Bytes)';
        }
        // So kommt es live an (28.10.2026): Symcons MQTT-Server reicht jedes Byte als Zeichen
        // U+0000–U+00FF in UTF-8 weiter („ÿØÿà…"). Zurück zu Bytes: mb_convert_encoding(…, 'ISO-8859-1', 'UTF-8').
        if (strncmp($payload, 'ÿØÿ', strlen('ÿØÿ')) === 0) {
            return '(Bild, ' . mb_strlen($payload, 'UTF-8') . ' Bytes)';
        }
        if (!mb_check_encoding($payload, 'UTF-8')) {
            return '(Binärdaten, ' . strlen($payload) . ' Bytes)';
        }
        return mb_substr($payload, 0, 40);
    }

    /** Topic zum Schalten einer Funktion. */
    public static function setTopic(string $praefix, string $kamera, string $schluessel): string
    {
        return self::praefix($praefix) . '/' . $kamera . '/' . $schluessel . '/set';
    }

    /** ON/OFF (Frigate schreibt Großbuchstaben) → bool, alles andere → null. */
    public static function anAus(string $payload): ?bool
    {
        $p = strtoupper(trim($payload));
        if ($p === 'ON') {
            return true;
        }
        if ($p === 'OFF') {
            return false;
        }
        return null;
    }

    /** Ganzzahliger Zähler oder null, wenn die Nutzlast keine ganze Zahl ist. */
    public static function zahl(string $payload): ?int
    {
        $p = trim($payload);
        return preg_match('/^\d+$/', $p) ? (int)$p : null;
    }

    public static function objektName(string $label): string
    {
        return self::OBJEKTE[$label] ?? $label;
    }

    public static function objektEinzahl(string $label): string
    {
        $label = self::ohneVerified($label);
        return self::OBJEKT_EINZAHL[$label] ?? $label;
    }

    public static function geraeuschName(string $typ): string
    {
        return 'Geräusch ' . (self::GERAEUSCHE[$typ] ?? $typ);
    }

    /** Review-Objekte heißen u. a. „person-verified" (Gesicht/Kennzeichen bestätigt). */
    public static function ohneVerified(string $label): string
    {
        return (string)preg_replace('/-verified$/', '', $label);
    }

    /**
     * Symcon-Ident aus beliebigem Frigate-Namen: nur [A-Za-z0-9_]. Wurde etwas ersetzt, hängt
     * ein kurzer Hash an, damit „a-b" und „a_b" nicht auf denselben Ident fallen.
     */
    public static function ident(string $art, string ...$teile): string
    {
        $roh = implode('_', $teile);
        $sauber = (string)preg_replace('/[^A-Za-z0-9_]/', '_', $roh);
        if ($sauber !== $roh) {
            $sauber .= '_' . substr(md5($roh), 0, 4);
        }
        return $art . '_' . $sauber;
    }

    /**
     * Empfangsfilter für SetReceiveDataFilter().
     *
     * Läuft gegen das rohe JSON-Datenpaket. Ob Symcon die Schrägstriche darin maskiert, ist
     * nicht zugesichert (CometWiFi-Fund: ein Filter mit Schrägstrichen traf nie) — deshalb nur
     * schrägstrichfreie Merkmale: Kamera-ID, Zonen-IDs und die drei kameraübergreifenden
     * Topic-Enden. Welche Nachricht wirklich zu dieser Instanz gehört, entscheidet deuten().
     *
     * @param string[] $zonen
     */
    public static function empfangsfilter(string $kamera, array $zonen): string
    {
        $merkmale = array_merge([$kamera], $zonen, ['available', 'events', 'reviews']);
        $merkmale = array_map(fn ($m) => preg_quote((string)$m), array_filter($merkmale, 'strlen'));
        return '.*(' . implode('|', $merkmale) . ').*';
    }

    /** Filter, der nie zutrifft — für Instanzen ohne Kamera. */
    public static function sperrfilter(): string
    {
        return '(?!)';
    }

    /** Zonen-IDs aus einem events-Paket (after.current_zones + entered_zones). */
    public static function zonenAusEvent(array $after): array
    {
        $zonen = array_merge((array)($after['current_zones'] ?? []), (array)($after['entered_zones'] ?? []));
        return array_values(array_unique(array_filter(array_map('strval', $zonen), 'strlen')));
    }

    /** Zonen-IDs aus einem reviews-Paket (after.data.zones). */
    public static function zonenAusReview(array $after): array
    {
        $zonen = (array)($after['data']['zones'] ?? []);
        return array_values(array_unique(array_filter(array_map('strval', $zonen), 'strlen')));
    }

    /** „28.10.2026 18:05:12" aus Frigates Unix-Zeit (Sekunden mit Nachkommastellen). */
    public static function zeit($sekunden): string
    {
        return is_numeric($sekunden) ? date('d.m.Y H:i:s', (int)floor((float)$sekunden)) : '';
    }

    /**
     * Kurztext zu einem verfolgten Objekt (events):
     * „28.10.2026 18:05:12 – Person „Dietmar" (87 %) in Einfahrt"
     */
    public static function eventText(array $after): string
    {
        $text = self::zeit($after['start_time'] ?? null);
        $teil = self::objektEinzahl((string)($after['label'] ?? '?'));
        $sub = $after['sub_label'] ?? null;
        if (is_array($sub)) {
            $sub = $sub[0] ?? null;
        }
        if (is_string($sub) && $sub !== '') {
            $teil .= ' „' . $sub . '“';
        }
        $score = $after['top_score'] ?? ($after['score'] ?? null);
        if (is_numeric($score)) {
            $teil .= ' (' . (int)round((float)$score * 100) . ' %)';
        }
        $zonen = self::zonenAusEvent($after);
        if ($zonen !== []) {
            $teil .= ' in ' . implode(', ', $zonen);
        }
        return ($text !== '' ? $text . ' – ' : '') . $teil;
    }

    /**
     * Kurztext zu einem Review-Eintrag (Alarm oder Erkennung):
     * „28.10.2026 18:05:12 – Person, Auto in Einfahrt"
     */
    public static function reviewText(array $after): string
    {
        $text = self::zeit($after['start_time'] ?? null);
        $objekte = [];
        foreach ((array)($after['data']['objects'] ?? []) as $o) {
            $objekte[] = self::objektEinzahl((string)$o);
        }
        foreach ((array)($after['data']['sub_labels'] ?? []) as $s) {
            if (is_string($s) && $s !== '') {
                $objekte[] = '„' . $s . '“';
            }
        }
        foreach ((array)($after['data']['audio'] ?? []) as $a) {
            $objekte[] = self::geraeuschName((string)$a);
        }
        $objekte = array_values(array_unique($objekte));
        $teil = $objekte === [] ? 'Ereignis' : implode(', ', $objekte);
        $zonen = self::zonenAusReview($after);
        if ($zonen !== []) {
            $teil .= ' in ' . implode(', ', $zonen);
        }
        return ($text !== '' ? $text . ' – ' : '') . $teil;
    }
}
