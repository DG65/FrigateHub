<?php

declare(strict_types=1);

/**
 * „Filmsequenz zur Meldung" (0.22.0): nach dem Foto eine zweite E-Mail mit bewegtem Bild (GIF) oder
 * Videoclip (MP4) — reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Symcon hängt je E-Mail genau ein Medienobjekt an (SMTP_SendMailMedia, Doku geprüft 11.11.2026). Das Foto
 * bleibt deshalb die schnelle erste Meldung, der Film folgt, sobald Frigate ihn liefern kann.
 *
 * Frigate-Quellcode v0.18.2, frigate/api/media.py (geprüft 11.11.2026):
 *  - /api/<kamera>/start/<a>/end/<b>/preview.gif: aus den Vorschaubildern (~8 Bilder/s abgespielt, klein).
 *    Liegt <a> vor der laufenden vollen Stunde, liest Frigate die fertige Stunden-Vorschau — die gibt es für die
 *    laufende Stunde noch nicht (404). Dann ab Stundenbeginn neu versuchen.
 *  - /api/<kamera>/start/<a>/end/<b>/clip.mp4: aus den Aufnahmen, ohne Umrechnung (volle Auflösung, groß).
 *    Aufnahmen liegen in Abschnitten von ~10 s vor, deshalb später holen. Ohne Aufnahme: HTTP 400.
 */
class FrigateHubFilm
{
    public const AUS = 0;
    public const GIF = 1;
    public const MP4 = 2;

    /** Sekunden vor dem Meldezeitpunkt, die der Film schon zeigt. */
    public const VORLAUF = 3;

    /** Wartezeit nach Filmende, bis Frigate das Material hat (GIF: Vorschaubilder sofort, MP4: Abschnitt fertig). */
    public const PUFFER_GIF = 5;
    public const PUFFER_MP4 = 25;

    /** Neuer Versuch nach so vielen Sekunden, höchstens so oft. */
    public const NOCHMAL = 30;
    public const VERSUCHE = 3;

    /** Größer verschickt FrigateHub nicht (viele Postfächer nehmen höchstens 20–25 MB). */
    public const MAX_BYTES = 15 * 1024 * 1024;

    public const SEKUNDEN_MIN = 5;
    public const SEKUNDEN_MAX = 60;

    /**
     * Auftrag anlegen.
     *
     * @return array{art:int,start:int,ende:int,faellig:int,versuch:int,titel:string,text:string}
     */
    public static function auftrag(int $art, float $ereignis, int $sekunden, float $jetzt, string $titel, string $text): array
    {
        $sekunden = max(self::SEKUNDEN_MIN, min(self::SEKUNDEN_MAX, $sekunden));
        $start = (int)floor($ereignis) - self::VORLAUF;
        $ende = $start + $sekunden;
        $faellig = $ende + ($art === self::MP4 ? self::PUFFER_MP4 : self::PUFFER_GIF);
        return ['art' => $art, 'start' => $start, 'ende' => $ende, 'faellig' => max((int)ceil($jetzt), $faellig),
            'versuch' => 0, 'titel' => $titel, 'text' => $text];
    }

    /** API-Pfad für den Auftrag (ab Versuch 2 beim GIF ab Stundenbeginn, falls der Start davor liegt). */
    public static function pfad(string $kamera, array $a, float $jetzt): string
    {
        $start = (int)$a['start'];
        if ((int)$a['art'] === self::GIF && (int)$a['versuch'] > 0) {
            $stunde = (int)(floor($jetzt / 3600) * 3600);
            if ($start < $stunde && (int)$a['ende'] > $stunde) {
                $start = $stunde;
            }
        }
        $datei = (int)$a['art'] === self::MP4 ? 'clip.mp4' : 'preview.gif';
        return '/api/' . rawurlencode($kamera) . '/start/' . $start . '/end/' . (int)$a['ende'] . '/' . $datei;
    }

    /** Nächster Versuch (Auftrag) oder null = aufgeben. */
    public static function nochmal(array $a, float $jetzt): ?array
    {
        $a['versuch'] = (int)$a['versuch'] + 1;
        if ($a['versuch'] >= self::VERSUCHE) {
            return null;
        }
        $a['faellig'] = (int)ceil($jetzt) + self::NOCHMAL;
        return $a;
    }

    /** Sekunden bis zur Fälligkeit (mindestens 1). */
    public static function warten(array $a, float $jetzt): int
    {
        return max(1, (int)$a['faellig'] - (int)floor($jetzt));
    }

    /** Antwort prüfen: '' = in Ordnung, sonst Grund (englischer Text, übersetzbar). */
    public static function pruefen(int $art, string $daten): string
    {
        if ($daten === '') {
            return 'empty';
        }
        if (strlen($daten) > self::MAX_BYTES) {
            return 'too large';
        }
        if ($art === self::GIF) {
            return strncmp($daten, 'GIF8', 4) === 0 ? '' : 'not a GIF';
        }
        // MP4: Kasten „ftyp" an Stelle 4
        return substr($daten, 4, 4) === 'ftyp' ? '' : 'not an MP4';
    }

    public static function endung(int $art): string
    {
        return $art === self::MP4 ? 'mp4' : 'gif';
    }
}
