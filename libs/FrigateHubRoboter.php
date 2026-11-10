<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';

/**
 * „Roboter im Bild" (0.20.0): kein Fehlalarm durch fahrende Mäh- und Saugroboter — reines PHP,
 * vom Prüfstand direkt aufgerufen. Konzept: FrigateHub-Roboter-Konzept.md (mit RoboHub abgestimmt 10.11.2026).
 *
 * Quellen: Frigate+-Objekt `robot_lawnmower` (Rahmen im Bild) und RoboHubs Vertrag
 * `ROBO_GetActivity()` / `robot_activity` 1.x (herstellerneutral, ohne Ort im Bild).
 *
 * Grundsatz: Im Zweifel melden. Unterdrückt wird ein Alarm nur, wenn JEDES Objekt darin als
 * Roboter-Fehldeutung gilt. Ein aufrechter Mensch, ein erkanntes Gesicht, ein Fahrzeug, ein Objekt
 * ohne bekannten Rahmen oder ein veralteter Roboterzustand verhindern die Unterdrückung.
 */
class FrigateHubRoboter
{
    /** Modul-GUID von RoboHubGeraet (Discovery ohne Verknüpfung). */
    public const ROBOHUB_GUID = '{631DA85B-5985-4C25-B222-E47FDD49E302}';

    /** Hauptversion des Vertrags `robot_activity`, gegen die FrigateHub gebaut ist. */
    public const VERTRAG = 1;

    /** Zuordnung je Kamera (Property RoboterZuordnung). */
    public const AUS = 0;
    public const MAEHER = 1;
    public const SAUGER = 2;
    public const ALLE = 3;
    public const EINZELNE = 4;

    /** Frigate+-Objekt für Rasenmähroboter (Frigate+-Doku, geprüft 10.11.2026; ein Sauger-Objekt gibt es nicht). */
    public const LABEL_MAEHER = 'robot_lawnmower';

    /** Diese Objekte werden nie unterdrückt. */
    public const NIE = ['car', 'motorcycle', 'bicycle', 'bus', 'truck', 'boat', 'train', 'airplane', 'school_bus',
        'garbage_truck', 'license_plate', 'face'];

    /** Mindestanteil des Objektrahmens, der im Roboterrahmen liegen muss. */
    public const UEBERLAPP_PERSON = 0.5;
    public const UEBERLAPP_SONST = 0.3;

    /** Mindest-Nachlauf nach dem Einfahren in die Station (Sekunden), sonst 2 × Abfrageabstand. */
    public const NACHLAUF = 60;

    /** Rahmen gemerkter Objekte so lange aufheben (Reviews kommen auch nach dem Objektende). */
    public const BOXEN_HALTEN = 600;
    public const BOXEN_HOECHSTENS = 200;

    /**
     * Antwort von ROBO_GetActivity() deuten: Ist der Roboter gerade (oder im Nachlauf) außerhalb der Station?
     * Unbekannt, veraltet, falsche Vertragsversion → nicht aktiv (keine Unterdrückung).
     *
     * @return array{aktiv: bool, klasse: string, name: string, grund: string}
     */
    public static function aktivitaet($antwort, int $jetzt): array
    {
        $a = is_string($antwort) ? json_decode($antwort, true) : $antwort;
        $nein = fn (string $grund, string $klasse = '', string $name = '') => ['aktiv' => false, 'klasse' => $klasse, 'name' => $name, 'grund' => $grund];
        if (!is_array($a)) {
            return $nein('keine Antwort');
        }
        $klasse = (string)($a['class'] ?? '');
        $name = (string)($a['name'] ?? '');
        if ((int)explode('.', (string)($a['contractVersion'] ?? '0'))[0] !== self::VERTRAG) {
            return $nein('Vertrag ' . (string)($a['contractVersion'] ?? '?'), $klasse, $name);
        }
        if (!in_array($klasse, ['mower', 'vacuum'], true)) {
            return $nein('unbekannte Klasse', $klasse, $name);
        }
        if (!is_numeric($a['validUntil'] ?? null) || (int)$a['validUntil'] < $jetzt) {
            return $nein('veraltet', $klasse, $name);
        }
        $zustand = is_numeric($a['state'] ?? null) ? (int)$a['state'] : -1;
        if ($zustand < 0) {
            return $nein('Zustand unbekannt', $klasse, $name);
        }
        if (!empty($a['outsideStation'])) {
            return ['aktiv' => true, 'klasse' => $klasse, 'name' => $name, 'grund' => 'unterwegs'];
        }
        // Nachlauf: seit dem Einfahren (Beginn des jetzigen Zustands) noch max(60 s, 2 × Abfrageabstand)
        $nachlauf = max(self::NACHLAUF, 2 * max(0, (int)($a['pollIntervalS'] ?? 0)));
        $seit = is_numeric($a['since'] ?? null) ? (int)$a['since'] : 0;
        if ($seit > 0 && $jetzt - $seit < $nachlauf) {
            return ['aktiv' => true, 'klasse' => $klasse, 'name' => $name, 'grund' => 'Nachlauf'];
        }
        return $nein('in der Station', $klasse, $name);
    }

    /** Gilt die Zuordnung der Kamera für diesen Roboter? */
    public static function zugeordnet(int $zuordnung, array $auswahl, int $instanz, string $klasse): bool
    {
        switch ($zuordnung) {
            case self::MAEHER:
                return $klasse === 'mower';
            case self::SAUGER:
                return $klasse === 'vacuum';
            case self::ALLE:
                return true;
            case self::EINZELNE:
                return in_array($instanz, array_map('intval', $auswahl), true);
        }
        return false;
    }

    /**
     * Objektrahmen aus frigate/events merken (alle Objekte der Kamera), damit ein Review die Rahmen
     * seiner Objekte findet — auch nach deren Ende.
     */
    public static function boxMerken(array $boxen, string $typ, array $after, int $jetzt): array
    {
        $id = (string)($after['id'] ?? '');
        $box = self::box($after['box'] ?? null);
        if ($id !== '' && $box !== null && empty($after['false_positive'])) {
            $boxen[$id] = [
                'label'   => (string)($after['label'] ?? ''),
                'box'     => $box,
                'gesicht' => self::subLabel($after['sub_label'] ?? null) !== '',
                'steht'   => !empty($after['stationary']),
                'ende'    => $typ === 'end' || is_numeric($after['end_time'] ?? null),
                'zuletzt' => $jetzt,
            ];
        } elseif ($id !== '' && isset($boxen[$id]) && ($typ === 'end' || is_numeric($after['end_time'] ?? null))) {
            $boxen[$id]['ende'] = true;
        }
        $boxen = array_filter($boxen, fn ($b) => $jetzt - (int)($b['zuletzt'] ?? 0) < self::BOXEN_HALTEN);
        if (count($boxen) > self::BOXEN_HOECHSTENS) {
            uasort($boxen, fn ($a, $b) => (int)$a['zuletzt'] <=> (int)$b['zuletzt']);
            $boxen = array_slice($boxen, -self::BOXEN_HOECHSTENS, null, true);
        }
        return $boxen;
    }

    /** Erkannter Name bzw. Unterbezeichnung (Frigate: Text oder [Name, Wert]). */
    public static function subLabel($s): string
    {
        if (is_array($s)) {
            $s = $s[0] ?? '';
        }
        return is_string($s) ? trim($s) : '';
    }

    /** Fahrende Mäher laut Frigate+: Rahmen der `robot_lawnmower`-Objekte, die nicht stehen und nicht beendet sind. */
    public static function maeherRahmen(array $boxen): array
    {
        $r = [];
        foreach ($boxen as $b) {
            if (($b['label'] ?? '') === self::LABEL_MAEHER && empty($b['steht']) && empty($b['ende'])) {
                $r[] = $b['box'];
            }
        }
        return $r;
    }

    /**
     * Soll der Alarm/die Erkennung eines Reviews unterdrückt werden?
     *
     * @param array $ids          Objekt-IDs des Reviews (data.detections)
     * @param array $boxen        gemerkte Rahmen (boxMerken)
     * @param array $aktiveRobo   Namen der zugeordneten, aktiven RoboHub-Roboter (leer = keiner)
     * @return array{unterdruecken: bool, grund: string}
     */
    public static function bewerten(array $ids, array $boxen, array $aktiveRobo): array
    {
        $maeher = self::maeherRahmen($boxen);
        $nein = fn (string $grund) => ['unterdruecken' => false, 'grund' => $grund];
        $nurMaeher = $ids !== [] && array_filter($ids, fn ($id) => ($boxen[(string)$id]['label'] ?? '') !== self::LABEL_MAEHER) === [];
        if ($nurMaeher) {
            // Der Mäher selbst ist nie ein Alarm, auch geparkt und ohne RoboHub
            return ['unterdruecken' => true, 'grund' => FrigateHubText::t('Robot lawnmower')];
        }
        if ($maeher === [] && $aktiveRobo === []) {
            return $nein('kein Roboter aktiv');
        }
        if ($ids === []) {
            return $nein('keine Objekte');
        }
        foreach ($ids as $id) {
            $o = $boxen[(string)$id] ?? null;
            if ($o === null) {
                return $nein('Objekt ohne Rahmen');
            }
            $label = (string)$o['label'];
            if ($label === self::LABEL_MAEHER) {
                continue;
            }
            if (in_array($label, self::NIE, true)) {
                return $nein('Fahrzeug/' . $label);
            }
            $person = $label === 'person';
            if ($person && !empty($o['gesicht'])) {
                return $nein('erkannte Person');
            }
            $ueberlappt = false;
            foreach ($maeher as $m) {
                if (self::anteilIn($o['box'], $m) >= ($person ? self::UEBERLAPP_PERSON : self::UEBERLAPP_SONST)) {
                    $ueberlappt = true;
                    break;
                }
            }
            if ($ueberlappt) {
                continue;
            }
            // Ohne Mäher-Rahmen: nur flache Rahmen (Breite ≥ Höhe) bei aktivem RoboHub-Roboter.
            // Ein aufrechter Mensch (Höhe > Breite) meldet immer.
            if ($aktiveRobo !== [] && self::flach($o['box'])) {
                continue;
            }
            return $nein($person ? 'aufrechte Person' : 'Objekt ' . $label);
        }
        $wer = $aktiveRobo !== [] ? implode(', ', $aktiveRobo) : FrigateHubText::t('Robot lawnmower');
        return ['unterdruecken' => true, 'grund' => $wer];
    }

    /** Rahmen [x1, y1, x2, y2] prüfen. */
    public static function box($b): ?array
    {
        if (!is_array($b) || count($b) !== 4) {
            return null;
        }
        $b = array_map('floatval', array_values($b));
        return ($b[2] > $b[0] && $b[3] > $b[1]) ? $b : null;
    }

    /** Breite ≥ Höhe. */
    public static function flach(array $b): bool
    {
        return ($b[2] - $b[0]) >= ($b[3] - $b[1]);
    }

    /** Anteil der Fläche von $a, der in $b liegt (0..1). */
    public static function anteilIn(array $a, array $b): float
    {
        $flaeche = ($a[2] - $a[0]) * ($a[3] - $a[1]);
        if ($flaeche <= 0) {
            return 0.0;
        }
        $w = min($a[2], $b[2]) - max($a[0], $b[0]);
        $h = min($a[3], $b[3]) - max($a[1], $b[1]);
        return ($w > 0 && $h > 0) ? ($w * $h) / $flaeche : 0.0;
    }
}
