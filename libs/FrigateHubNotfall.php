<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';
require_once __DIR__ . '/FrigateHubRoboter.php';

/**
 * „Person liegt / Sturz" (0.21.0): Hinweis, wenn ein Mensch am Boden liegt und sich nicht mehr bewegt —
 * reines PHP, vom Prüfstand direkt aufgerufen. Konzept: FrigateHub-Notfall-Konzept.md.
 *
 * Datenlage (Frigate-Quellcode dev, geprüft 11.11.2026):
 *  - frigate/events kommt bei jeder deutlichen Bewegung des Fußpunkts (path_update: Unterkante des Rahmens
 *    bewegt sich um ~5 % der Bilddiagonale), bei Zonenwechsel, Wechsel aktiv/stationär und spätestens alle 60 s.
 *    Ein Sturz erzeugt deshalb Meldungen mit neuem Rahmen und `frame_time`.
 *  - `path_data`: Liste [[x, y], frame_time] des Fußpunkts, normiert 0..1 (y = Unterkante / Bildhöhe), ungekürzt.
 *  - Geräusche (frigate/<kamera>/audio/<typ>): u. a. yell (auch Schreien), crying, groan, gasp, thump, bang, smash, breaking.
 *
 * Grundsatz: Eine Kamera misst weder Puls noch Atmung. Erkannt wird nur „Person liegt flach und reglos".
 * Das ist ein Hinweis, kein Notrufsystem.
 */
class FrigateHubNotfall
{
    /** Stufen der Variable „Notfall". */
    public const OK = 0;
    public const LIEGT = 1;
    public const STURZ = 2;
    public const NOTFALL = 3;

    /** Rahmenform: liegend ab Breite ≥ 1,2 × Höhe, aufrecht ab Höhe ≥ 1,5 × Breite. */
    public const LIEGEND = 1.2;
    public const AUFRECHT = 1.5;

    /** Sturz am Rahmen: aufrecht → liegend in höchstens so vielen Sekunden, Oberkante fällt um ≥ 40 % der alten Höhe. */
    public const STURZ_SEKUNDEN = 4.0;
    public const STURZ_FALLHOEHE = 0.4;

    /** Absturz (Leiter, Treppe) am Fußpunkt: ≥ 15 % der Bildhöhe abwärts in ≤ 1,5 s. */
    public const ABSTURZ_HOEHE = 0.15;
    public const ABSTURZ_SEKUNDEN = 1.5;

    /** Ohne Meldung von Frigate so lange gilt eine liegende Person als reglos (Bewegung erzeugt Meldungen). */
    public const RUHE = 15;

    /** Wartezeit nach Sturz oder Hilferuf (statt der eingestellten). */
    public const WARTEZEIT_STURZ = 15;

    /** Ein Geräusch gilt so lange vor/nach dem Hinlegen als zugehörig. */
    public const GERAEUSCH_FENSTER = 60;

    /** Einträge nach so vielen Sekunden verwerfen (auch quittierte). */
    public const VERGESSEN = 1800;

    /** Rufe und Laute einer Person in Not. */
    public const HILFERUFE = ['yell', 'scream', 'crying', 'groan', 'gasp', 'whimper', 'wail', 'shout'];

    /** Aufprall-Geräusche. */
    public const AUFPRALL = ['thump', 'bang', 'smash', 'breaking', 'crash', 'slam'];

    /**
     * Meldung aus frigate/events einarbeiten (nur `person`).
     *
     * @param array $zustand Einträge je Objekt-ID
     * @return array neuer Zustand
     */
    public static function beobachten(array $zustand, string $typ, array $after, float $jetzt): array
    {
        $zustand = array_filter($zustand, fn ($e) => $jetzt - (float)($e['zuletzt'] ?? 0) < self::VERGESSEN);
        $id = (string)($after['id'] ?? '');
        $box = FrigateHubRoboter::box($after['box'] ?? null);
        if ($id === '' || ($after['label'] ?? '') !== 'person' || !empty($after['false_positive'])) {
            return $zustand;
        }
        $ende = $typ === 'end' || is_numeric($after['end_time'] ?? null);
        $e = $zustand[$id] ?? ['aufrecht' => false, 'liegtSeit' => 0.0, 'sturz' => '', 'quittiert' => false, 'box' => null];

        if ($box !== null) {
            $form = self::form($box);
            $bildzeit = is_numeric($after['frame_time'] ?? null) ? (float)$after['frame_time'] : $jetzt;
            if ($form === 'liegend') {
                // Zuletzt aufrecht gesehen vor kurzem (Zwischenbilder „hockt" zählen nicht dagegen)
                $vorher = $e['aufrechtBox'] ?? null;
                if ($e['sturz'] === '' && is_array($vorher)
                    && $bildzeit - (float)($e['aufrechtZeit'] ?? 0) <= self::STURZ_SEKUNDEN
                    && ($box[1] - $vorher[1]) >= self::STURZ_FALLHOEHE * ($vorher[3] - $vorher[1])) {
                    $e['sturz'] = 'sturz';
                }
                if ($e['sturz'] === '' && self::absturz((array)($after['path_data'] ?? []))) {
                    $e['sturz'] = 'absturz';
                }
                if ((float)$e['liegtSeit'] <= 0) {
                    $e['liegtSeit'] = $jetzt;
                }
            } elseif ($form === 'aufrecht') {
                // Wieder auf den Beinen: alles zurück
                $e['liegtSeit'] = 0.0;
                $e['sturz'] = '';
                $e['quittiert'] = false;
                $e['aufrecht'] = true;
                $e['aufrechtBox'] = $box;
                $e['aufrechtZeit'] = $bildzeit;
                // Eine aufrechte Person dort, wo jemand lag, heißt: aufgestanden (Frigate führt sie oft neu)
                foreach ($zustand as $andere => $a) {
                    if ($andere !== $id && (float)($a['liegtSeit'] ?? 0) > 0 && is_array($a['box'] ?? null)
                        && FrigateHubRoboter::anteilIn($a['box'], self::weiter($box)) >= 0.3) {
                        unset($zustand[$andere]);
                    }
                }
            }
            $e['box'] = $box;
        }
        $e['steht'] = !empty($after['stationary']);
        $e['zonen'] = array_values(array_map('strval', (array)($after['current_zones'] ?? [])));
        $e['zuletzt'] = $jetzt;
        $e['ende'] = $ende;
        $e['name'] = FrigateHubRoboter::subLabel($after['sub_label'] ?? null);

        if ($ende && (float)$e['liegtSeit'] <= 0) {
            // Gegangen oder aufrecht verloren: nichts mehr zu beobachten
            unset($zustand[$id]);
            return $zustand;
        }
        // Liegend verloren (Liegende erkennt das Modell schlechter) bleibt stehen, bis jemand dort aufsteht
        $zustand[$id] = $e;
        return $zustand;
    }

    /** Fußpunkt fällt schnell (Leiter, Treppe): ≥ 15 % der Bildhöhe in ≤ 1,5 s, innerhalb der letzten 10 s des Wegs. */
    public static function absturz(array $weg): bool
    {
        $punkte = [];
        foreach ($weg as $p) {
            if (is_array($p) && is_array($p[0] ?? null) && is_numeric($p[0][1] ?? null) && is_numeric($p[1] ?? null)) {
                $punkte[] = [(float)$p[0][1], (float)$p[1]];
            }
        }
        if (count($punkte) < 2) {
            return false;
        }
        $letzte = end($punkte)[1];
        $n = count($punkte);
        for ($i = 0; $i < $n; $i++) {
            if ($letzte - $punkte[$i][1] > 10) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $dt = $punkte[$j][1] - $punkte[$i][1];
                if ($dt > self::ABSTURZ_SEKUNDEN) {
                    break;
                }
                if ($punkte[$j][0] - $punkte[$i][0] >= self::ABSTURZ_HOEHE) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Gesamtlage bewerten.
     *
     * @param array $zustand    aus beobachten()
     * @param int   $wartezeit  Sekunden „liegt reglos“ bis zum Notfall
     * @param array $zonen      nur diese Zonen (leer = ganzes Bild)
     * @param float $geraeusch  Zeitpunkt des letzten Hilferufs/Aufpralls (0 = keiner)
     * @return array{stufe: int, id: string, grund: string, dauer: int}
     */
    public static function bewerten(array $zustand, float $jetzt, int $wartezeit, array $zonen, float $geraeusch = 0.0): array
    {
        $best = ['stufe' => self::OK, 'id' => '', 'grund' => '', 'dauer' => 0];
        foreach ($zustand as $id => $e) {
            $seit = (float)($e['liegtSeit'] ?? 0);
            if ($seit <= 0 || !empty($e['quittiert'])) {
                continue;
            }
            if ($zonen !== [] && array_intersect($zonen, (array)($e['zonen'] ?? [])) === []) {
                continue;
            }
            $dauer = (int)floor($jetzt - $seit);
            $ruf = $geraeusch > 0 && abs($geraeusch - $seit) <= self::GERAEUSCH_FENSTER;
            $sturz = (string)($e['sturz'] ?? '');
            $reglos = !empty($e['steht']) || !empty($e['ende']) || $jetzt - (float)($e['zuletzt'] ?? 0) >= self::RUHE;
            $warten = ($sturz !== '' || $ruf) ? min($wartezeit, self::WARTEZEIT_STURZ) : $wartezeit;
            $gruende = [];
            if ($sturz === 'absturz') {
                $gruende[] = FrigateHubText::t('fast fall (ladder/stairs)');
            } elseif ($sturz === 'sturz') {
                $gruende[] = FrigateHubText::t('fall');
            }
            if ($ruf) {
                $gruende[] = FrigateHubText::t('call for help or impact sound');
            }
            if ($reglos && $dauer >= $warten) {
                $stufe = self::NOTFALL;
            } elseif ($sturz !== '') {
                $stufe = self::STURZ;
            } else {
                $stufe = self::LIEGT;
            }
            if ($stufe > $best['stufe'] || ($stufe === $best['stufe'] && $dauer > $best['dauer'])) {
                $best = ['stufe' => $stufe, 'id' => (string)$id, 'grund' => implode(', ', $gruende), 'dauer' => $dauer];
            }
        }
        return $best;
    }

    /** Sekunden bis zur nächsten Neubewertung (0 = nichts zu beobachten). */
    public static function naechstePruefung(array $zustand): int
    {
        foreach ($zustand as $e) {
            if ((float)($e['liegtSeit'] ?? 0) > 0 && empty($e['quittiert'])) {
                return 5;
            }
        }
        return 0;
    }

    /** Alle derzeit liegenden Personen als gesehen markieren. */
    public static function quittieren(array $zustand): array
    {
        foreach ($zustand as $id => $e) {
            if ((float)($e['liegtSeit'] ?? 0) > 0) {
                $zustand[$id]['quittiert'] = true;
            }
        }
        return $zustand;
    }

    public static function istHilfeGeraeusch(string $typ): bool
    {
        return in_array($typ, self::HILFERUFE, true) || in_array($typ, self::AUFPRALL, true);
    }

    /** 'liegend' | 'aufrecht' | '' (dazwischen: sitzt, hockt, teilweise verdeckt). */
    public static function form(array $b): string
    {
        $w = $b[2] - $b[0];
        $h = $b[3] - $b[1];
        if ($w >= self::LIEGEND * $h) {
            return 'liegend';
        }
        return $h >= self::AUFRECHT * $w ? 'aufrecht' : '';
    }

    /** Rahmen einer stehenden Person auf Liegebreite nach unten erweitern (wo sie gelegen haben kann). */
    private static function weiter(array $b): array
    {
        $h = $b[3] - $b[1];
        return [$b[0] - $h / 2, $b[1], $b[2] + $h / 2, $b[3] + $h / 4];
    }
}
