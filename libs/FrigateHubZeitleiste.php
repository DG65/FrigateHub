<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * Zeitleiste der Übersichtskachel (0.24.0): je Kamera die Zeiträume mit Aufnahme und die Ereignisse
 * (Alarme/Erkennungen) in einem Fenster. Reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Quellen (Frigate 0.18.2): /api/<kamera>/recordings?after=&before= liefert Teilstücke von ~10 s
 * (start_time, end_time); /api/review?cameras=&after=&before= die Ereignisse mit severity.
 */
class FrigateHubZeitleiste
{
    /** Längstes Fenster je Abfrage (s): 26 h, damit „letzte 24 h" mit Rand passt und die Antwort klein bleibt. */
    public const FENSTER_MAX = 93600;

    /** Lücken zwischen Aufnahmestücken bis zu so vielen Sekunden gelten als durchgehend. */
    public const LUECKE = 15;

    /** „von-bis" (Unix-Sekunden) prüfen; bis darf höchstens 60 s in der Zukunft liegen. */
    public static function fenster(string $text, int $jetzt): ?array
    {
        if (!preg_match('/^(\d{9,11})-(\d{9,11})\z/', $text, $m)) {
            return null;
        }
        [$von, $bis] = [(int)$m[1], (int)$m[2]];
        if ($bis <= $von || $bis - $von > self::FENSTER_MAX || $von > $jetzt) {
            return null;
        }
        return [$von, min($bis, $jetzt + 60)];
    }

    /**
     * Aufnahmestücke zu durchgehenden Abschnitten zusammenfassen.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function abdeckung(array $aufnahmen, int $von, int $bis, int $luecke = self::LUECKE): array
    {
        $stuecke = [];
        foreach ($aufnahmen as $a) {
            if (!is_array($a) || !is_numeric($a['start_time'] ?? null)) {
                continue;
            }
            $s = (float)$a['start_time'];
            $e = is_numeric($a['end_time'] ?? null) ? (float)$a['end_time'] : $s + (float)($a['duration'] ?? 0);
            $s = max($von, (int)floor($s));
            $e = min($bis, (int)ceil($e));
            if ($e > $s) {
                $stuecke[] = [$s, $e];
            }
        }
        usort($stuecke, fn ($a, $b) => $a[0] <=> $b[0]);
        $aus = [];
        foreach ($stuecke as $st) {
            $n = count($aus);
            if ($n > 0 && $st[0] - $aus[$n - 1][1] <= $luecke) {
                $aus[$n - 1][1] = max($aus[$n - 1][1], $st[1]);
            } else {
                $aus[] = $st;
            }
        }
        return $aus;
    }

    /**
     * Ereignisse für die Zeitleiste: kurz und ohne Nebensächliches.
     *
     * @return array<int, array{id: string, s: int, e: ?int, a: bool, t: string, z: array}>
     */
    public static function ereignisse(array $reviews, int $von, int $bis): array
    {
        $aus = [];
        foreach ($reviews as $r) {
            if (!is_array($r)) {
                continue;
            }
            $e = FrigateHubTopics::reviewEintrag($r);
            if ($e === null) {
                continue;
            }
            $ende = $e['ende'] ?? null;
            if (($ende ?? PHP_INT_MAX) < $von || $e['start'] > $bis) {
                continue;
            }
            $aus[] = [
                'id' => $e['id'],
                's'  => (int)$e['start'],
                'e'  => $ende === null ? null : (int)$ende,
                'a'  => $e['stufe'] === 'alarm',
                't'  => (string)$e['text'],
                'z'  => array_values(array_map('strval', (array)$e['zonen'])),
            ];
        }
        usort($aus, fn ($a, $b) => $a['s'] <=> $b['s']);
        return $aus;
    }
}
