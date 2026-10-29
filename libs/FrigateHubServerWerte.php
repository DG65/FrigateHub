<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * Wertet Frigates Statistik aus (MQTT frigate/stats bzw. /api/stats — derselbe Inhalt) — reines
 * PHP, vom Prüfstand direkt aufgerufen.
 *
 * Feldnamen und Einheiten an Frigate 0.18.2 geprüft (29.10.2026): Speicherangaben in MB,
 * Prozente teils als Text („2.51%", „23.8"), Erkennungszeit in ms.
 */
class FrigateHubServerWerte
{
    // Positionen: feste Werte oben, dann Detektoren, GPUs, Speicher, Kameras.
    public const POS_DETEKTOR = 20;
    public const POS_GPU      = 30;

    /** Frigates connection_quality → Klartext. Unbekannte Stufen erscheinen unverändert. */
    private const QUALITAET = [
        'excellent' => 'sehr gut',
        'good'      => 'gut',
        'fair'      => 'mittel',
        'poor'      => 'schlecht',
        'unusable'  => 'unbrauchbar',
    ];

    /** Ab diesen Stufen gilt eine Kamera als gestört. */
    private const QUALITAET_PROBLEM = ['poor', 'unusable'];

    /**
     * Alle Werte, die die Server-Instanz zeigt.
     *
     * @return array<int,array{ident:string,name:string,typ:string,wert:mixed,einheit:string,stellen:int,pos:int}>
     *   typ: 'float' | 'int' | 'string'
     */
    public static function werte(array $s): array
    {
        $out = [];
        $dienst = is_array($s['service'] ?? null) ? $s['service'] : [];

        $version = self::version($dienst['version'] ?? null);
        if ($version !== '') {
            $out[] = self::wert('Version', 'Version', 'string', $version, '', 0, 2);
            $out[] = self::wert('NeuesteVersion', 'Neueste Version', 'string', self::neuesteVersion($version, $dienst['latest_version'] ?? null), '', 0, 3);
        }
        if (is_numeric($dienst['uptime'] ?? null)) {
            $stand = is_numeric($dienst['last_updated'] ?? null) ? (int)$dienst['last_updated'] : time();
            $out[] = self::wert('LaeuftSeit', 'Läuft seit', 'string', date('d.m.Y H:i', $stand - (int)$dienst['uptime']), '', 0, 4);
        }

        $system = $s['cpu_usages']['frigate.full_system'] ?? null;
        if (is_array($system)) {
            if (($cpu = self::prozent($system['cpu'] ?? null)) !== null) {
                $out[] = self::wert('CPU', 'CPU-Last', 'float', $cpu, ' %', 1, 10);
            }
            if (($ram = self::prozent($system['mem'] ?? null)) !== null) {
                $out[] = self::wert('Arbeitsspeicher', 'Arbeitsspeicher', 'float', $ram, ' %', 1, 11);
            }
        }

        $i = 0;
        foreach ((array)($s['detectors'] ?? []) as $name => $d) {
            if (is_array($d) && is_numeric($d['inference_speed'] ?? null)) {
                $out[] = self::wert(FrigateHubTopics::ident('Erkennungszeit', (string)$name), 'Erkennungszeit ' . $name, 'float',
                    round((float)$d['inference_speed'], 1), ' ms', 1, self::POS_DETEKTOR + min(9, $i++));
            }
        }

        $i = 0;
        foreach ((array)($s['gpu_usages'] ?? []) as $name => $g) {
            if (is_array($g) && ($last = self::prozent($g['gpu'] ?? null)) !== null) {
                $out[] = self::wert(FrigateHubTopics::ident('GPU', (string)$name), 'GPU-Last ' . $name, 'float', $last, ' %', 1, self::POS_GPU + min(9, $i++));
            }
        }

        $aufnahmen = $dienst['storage']['/media/frigate/recordings'] ?? null;
        if (is_array($aufnahmen) && is_numeric($aufnahmen['total'] ?? null) && (float)$aufnahmen['total'] > 0) {
            if (is_numeric($aufnahmen['used'] ?? null)) {
                $out[] = self::wert('AufnahmenBelegt', 'Speicher Aufnahmen belegt', 'float',
                    round((float)$aufnahmen['used'] / (float)$aufnahmen['total'] * 100, 1), ' %', 1, 40);
            }
            if (is_numeric($aufnahmen['free'] ?? null)) {
                $out[] = self::wert('AufnahmenFrei', 'Speicher Aufnahmen frei', 'float', round((float)$aufnahmen['free'] / 1024, 1), ' GB', 1, 41);
            }
        }

        if (is_array($s['cameras'] ?? null)) {
            $probleme = self::kameraProbleme($s['cameras']);
            $anzahl = count($s['cameras']);
            $out[] = self::wert('Kameraprobleme', 'Gestörte Kameras', 'int', count($probleme), '', 0, 50);
            $out[] = self::wert('Kamerazustand', 'Kamerazustand', 'string', $probleme === []
                ? ($anzahl === 1 ? 'Die Kamera läuft einwandfrei.' : 'Alle ' . $anzahl . ' Kameras laufen einwandfrei.')
                : implode(' · ', $probleme), '', 0, 51);
        }
        return $out;
    }

    /**
     * Eine Zeile je gestörter Kamera: liefert keine Bilder oder Verbindung schlecht/unbrauchbar.
     *
     * @return string[]
     */
    public static function kameraProbleme(array $kameras): array
    {
        $out = [];
        foreach ($kameras as $name => $k) {
            if (!is_array($k)) {
                continue;
            }
            $teile = [];
            if (is_numeric($k['camera_fps'] ?? null) && (float)$k['camera_fps'] <= 0) {
                $teile[] = 'liefert keine Bilder';
            }
            $q = (string)($k['connection_quality'] ?? '');
            if (in_array($q, self::QUALITAET_PROBLEM, true)) {
                $teile[] = 'Verbindung ' . self::qualitaet($q);
            }
            if ($teile === []) {
                continue;
            }
            $neu = is_numeric($k['reconnects_last_hour'] ?? null) ? (int)$k['reconnects_last_hour'] : 0;
            if ($neu > 0) {
                $teile[] = $neu . ($neu === 1 ? ' Neuverbindung' : ' Neuverbindungen') . ' in der letzten Stunde';
            }
            $out[] = $name . ': ' . implode(', ', $teile);
        }
        return $out;
    }

    public static function qualitaet(string $stufe): string
    {
        return self::QUALITAET[$stufe] ?? $stufe;
    }

    /** „0.18.2-cfa08c5" → „0.18.2". */
    public static function version($roh): string
    {
        return is_string($roh) ? (string)preg_replace('/-.*$/', '', trim($roh)) : '';
    }

    /** Text für „Neueste Version": „aktuell", „0.19.0 verfügbar" oder „unbekannt". */
    public static function neuesteVersion(string $version, $neueste): string
    {
        $neueste = self::version($neueste);
        if ($neueste === '' || !preg_match('/^\d+(\.\d+)*$/', $neueste)) {
            return 'unbekannt';
        }
        return version_compare($neueste, $version, '>') ? $neueste . ' verfügbar' : 'aktuell';
    }

    /** „2.51%", „23.8", 4 → Zahl; „-%", „" → null. */
    public static function prozent($roh): ?float
    {
        if (is_int($roh) || is_float($roh)) {
            return round((float)$roh, 1);
        }
        $t = rtrim(trim((string)$roh), '%');
        return is_numeric($t) ? round((float)$t, 1) : null;
    }

    private static function wert(string $ident, string $name, string $typ, $wert, string $einheit, int $stellen, int $pos): array
    {
        return ['ident' => $ident, 'name' => $name, 'typ' => $typ, 'wert' => $wert, 'einheit' => $einheit, 'stellen' => $stellen, 'pos' => $pos];
    }
}
