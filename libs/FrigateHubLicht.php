<?php

declare(strict_types=1);

/**
 * „Licht bei Erkennung" (0.23.0): Frigate erkennt eine Person (oder ein gewähltes Objekt) → Licht an,
 * nach der Nachlaufzeit wieder in den vorigen Zustand. Reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Zwei Arten von Licht, herstellerneutral gedacht:
 *  - eine Symcon-Variable (Schalter oder Dimmer, z. B. die Hoflampe) — geschaltet per RequestAction,
 *    danach zurück auf den Wert von vorher;
 *  - das eigene Licht der Kamera (Hikvision/Annke „Smart Hybrid Light"/ColorVu) über ISAPI
 *    /ISAPI/Image/channels/1/supplementLight. Modi laut Kamera (Annke I91DQ, Firmware V5.7.3, gelesen
 *    12.11.2026): eventIntelligence (Kamera schaltet selbst bei eigener Erkennung), colorVuWhiteLight
 *    (weißes Licht bei Dunkelheit), irLight (nur Infrarot), close. „An" = colorVuWhiteLight, „aus" = der
 *    Modus von vorher. Die Kamera schaltet das weiße Licht in diesem Modus nur bei Dunkelheit selbst.
 */
class FrigateHubLicht
{
    public const AUS = 0;
    public const KAMERA = 1;
    public const VARIABLE = 2;

    public const MODUS_AN = 'colorVuWhiteLight';
    public const MODUS_STANDARD_AUS = 'irLight';

    /** Ohne Meldung von Frigate gilt ein Objekt nach so vielen Sekunden als weg (Frigate meldet aktive Objekte spätestens alle 60 s). */
    public const VERALTET = 150;

    public const NACHLAUF_MIN = 10;
    public const NACHLAUF_MAX = 1800;

    /**
     * frigate/events einarbeiten.
     *
     * @param array $zustand ['objekte' => [id => zuletzt], 'letzte' => Zeit der letzten passenden Meldung]
     */
    public static function beobachten(array $zustand, string $typ, array $after, float $jetzt, array $objekte, array $zonen): array
    {
        $liste = (array)($zustand['objekte'] ?? []);
        $id = (string)($after['id'] ?? '');
        if ($id === '') {
            return $zustand;
        }
        $passt = in_array((string)($after['label'] ?? ''), $objekte, true)
            && empty($after['false_positive'])
            && ($zonen === [] || array_intersect($zonen, array_map('strval', (array)($after['current_zones'] ?? []))) !== [])
            // Ein geparktes Auto hält das Licht nicht die ganze Nacht an
            && !(!empty($after['stationary']) && (string)($after['label'] ?? '') !== 'person');
        $ende = $typ === 'end' || is_numeric($after['end_time'] ?? null);
        if ($passt && !$ende) {
            $liste[$id] = $jetzt;
            $zustand['letzte'] = $jetzt;
        } elseif (isset($liste[$id])) {
            unset($liste[$id]);
            $zustand['letzte'] = $jetzt;
        }
        $zustand['objekte'] = $liste;
        return $zustand;
    }

    /**
     * Soll das Licht an sein? Und wann wieder prüfen (Sekunden, 0 = nicht nötig).
     *
     * @return array{an: bool, pruefen: int}
     */
    public static function soll(array $zustand, float $jetzt, int $nachlauf): array
    {
        $nachlauf = max(self::NACHLAUF_MIN, min(self::NACHLAUF_MAX, $nachlauf));
        $aktiv = false;
        foreach ((array)($zustand['objekte'] ?? []) as $zuletzt) {
            if ($jetzt - (float)$zuletzt < self::VERALTET) {
                $aktiv = true;
            }
        }
        if ($aktiv) {
            return ['an' => true, 'pruefen' => 10];
        }
        $rest = (float)($zustand['letzte'] ?? 0) + $nachlauf - $jetzt;
        return $rest > 0 ? ['an' => true, 'pruefen' => (int)ceil($rest)] : ['an' => false, 'pruefen' => 0];
    }

    /** Veraltete Einträge entfernen. */
    public static function aufraeumen(array $zustand, float $jetzt): array
    {
        $zustand['objekte'] = array_filter((array)($zustand['objekte'] ?? []), fn ($t) => $jetzt - (float)$t < self::VERALTET);
        return $zustand;
    }

    /** Lichtmodus aus der ISAPI-Antwort, '' = nicht gefunden. */
    public static function modus(string $xml): string
    {
        return preg_match('#<supplementLightMode>\s*([A-Za-z]+)\s*</supplementLightMode>#', $xml, $m) ? $m[1] : '';
    }

    /** Dieselbe ISAPI-Antwort mit anderem Modus (zum Zurückschreiben per PUT), null = kein Modus darin. */
    public static function mitModus(string $xml, string $modus): ?string
    {
        if (!preg_match('/^[A-Za-z]+$/', $modus) || self::modus($xml) === '') {
            return null;
        }
        return (string)preg_replace('#<supplementLightMode>\s*[A-Za-z]+\s*</supplementLightMode>#', '<supplementLightMode>' . $modus . '</supplementLightMode>', $xml, 1);
    }

    /** Erlaubte Modi aus den Fähigkeiten (…/supplementLight/capabilities), [] = unbekannt. */
    public static function modi(string $xml): array
    {
        return preg_match('#<supplementLightMode[^>]*opt="([^"]*)"#', $xml, $m) ? array_values(array_filter(explode(',', $m[1]))) : [];
    }

    /**
     * Zugang zur Kamera aus Frigates Konfiguration (YAML, /api/config/raw): erste rtsp-Adresse mit Benutzer und
     * Passwort, die zu dieser Kamera gehört — direkt in ihren Eingängen oder über den go2rtc-Stream, auf den
     * ein Eingang rtsp://127.0.0.1:8554/<stream> zeigt.
     *
     * @param array $eingaenge Pfade der ffmpeg-Eingänge der Kamera (aus /api/config, Passwort darf maskiert sein)
     * @return array{host: string, benutzer: string, passwort: string}|null
     */
    public static function zugangAusFrigate(string $yaml, array $eingaenge): ?array
    {
        $hosts = [];
        $streams = [];
        foreach ($eingaenge as $pfad) {
            $teile = parse_url((string)$pfad);
            $host = (string)($teile['host'] ?? '');
            if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
                $streams[] = ltrim((string)($teile['path'] ?? ''), '/');
            } elseif ($host !== '') {
                $hosts[] = $host;
            }
        }
        // go2rtc-Streams: „    <name>:" gefolgt von „      - rtsp://…" (Frigate-Konfiguration, eingerückt unter go2rtc.streams)
        foreach ($streams as $stream) {
            if ($stream !== '' && preg_match('#\n\s+' . preg_quote($stream, '#') . ':\s*\n(\s+-\s*["\']?rtsp://[^\n]+\n?)+#', $yaml, $m)
                && preg_match('#rtsp://[^:@/\s]+:[^@\s]+@([^:/\s"\']+)#', $m[0], $h)) {
                $hosts[] = $h[1];
            }
        }
        foreach (array_unique($hosts) as $host) {
            if (preg_match('#rtsp://([^:@/\s]+):([^@\s]+)@' . preg_quote($host, '#') . '[:/"\'\s]#', $yaml, $m)) {
                return ['host' => $host, 'benutzer' => rawurldecode($m[1]), 'passwort' => rawurldecode($m[2])];
            }
        }
        return null;
    }
}
