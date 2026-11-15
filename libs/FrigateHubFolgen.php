<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * „Person folgen" (0.25.0): aus Frigates Personen-Ereignissen aller Kameras den Weg einer Person bilden.
 * Reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Frigate führt jede Kamera getrennt; dieselbe Person bekommt an jeder Kamera ein eigenes Ereignis
 * (/api/events?labels=person: id, camera, start_time, end_time, sub_label). Die Kette hängt Ereignisse
 * aneinander, die kurz nach dem Verlassen der einen Kamera an einer anderen beginnen. Gibt es mehrere
 * Kandidaten, entscheidet in dieser Reihenfolge: gleicher erkannter Name (Gesicht), Aussehen (Frigates
 * Ähnlichkeitssuche, /api/events/search?search_type=similarity, braucht die semantische Suche), Zeit.
 */
class FrigateHubFolgen
{
    /** So viele Sekunden darf zwischen dem Verlassen einer Kamera und dem Auftauchen an der nächsten liegen. */
    public const LUECKE = 45;

    /** Höchstens so viele Abschnitte je Richtung. */
    public const MAX = 12;

    /** Weiter als so viele Sekunden vor/nach dem Startabschnitt wird nicht gesucht. */
    public const SPANNE = 1800;

    /**
     * Personen-Ereignisse in Abschnitte {id, kamera, s, e, name} übersetzen (laufende enden „jetzt").
     *
     * @return array<int, array{id: string, kamera: string, s: int, e: int, name: string}>
     */
    public static function abschnitte(array $events, int $jetzt): array
    {
        $aus = [];
        foreach ($events as $ev) {
            if (!is_array($ev) || !is_numeric($ev['start_time'] ?? null) || !FrigateHubTopics::idGueltig((string)($ev['id'] ?? ''))) {
                continue;
            }
            if (isset($ev['label']) && $ev['label'] !== 'person') {
                continue;
            }
            $kamera = (string)($ev['camera'] ?? '');
            if ($kamera === '') {
                continue;
            }
            $s = (int)floor((float)$ev['start_time']);
            $e = is_numeric($ev['end_time'] ?? null) ? (int)ceil((float)$ev['end_time']) : $jetzt;
            $sub = $ev['sub_label'] ?? null;
            $name = is_array($sub) ? (string)($sub[0] ?? '') : (is_string($sub) ? $sub : '');
            $aus[] = ['id' => (string)$ev['id'], 'kamera' => $kamera, 's' => $s, 'e' => max($s + 1, $e), 'name' => trim($name)];
        }
        usort($aus, fn ($a, $b) => [$a['s'], $a['id']] <=> [$b['s'], $b['id']]);
        return $aus;
    }

    /**
     * Weg der Person bilden, die auf $kamera zwischen $s und $e zu sehen ist.
     *
     * $aehnlich(string $refId, array $kandidatenIds): array<string, float> — Abstand je Ereignis (kleiner = ähnlicher),
     * fehlende IDs gelten als unähnlich; null = Aussehen nicht verfügbar.
     *
     * @return array{segmente: array<int, array>, start: int, aussehen: bool}
     *   segmente: {kamera, id, ids, s, e, art: start|gesicht|aussehen|zeit, name, mehrdeutig}, zeitlich sortiert;
     *   start = Index des Startabschnitts; aussehen = Ähnlichkeitssuche wurde benutzt
     */
    public static function kette(array $abschnitte, string $kamera, int $s, int $e, ?callable $aehnlich = null): array
    {
        $leer = ['segmente' => [], 'start' => 0, 'aussehen' => false];
        // Startabschnitt: alle Ereignisse dieser Kamera, die den Zeitraum berühren (dieselbe Person bekommt
        // nach kurzem Verdecken oft ein neues Ereignis) — das mit der größten Überdeckung ist die Bezugsperson
        $start = null;
        $bester = -1;
        foreach ($abschnitte as $i => $a) {
            if ($a['kamera'] !== $kamera || $a['e'] < $s - 2 || $a['s'] > $e + 2) {
                continue;
            }
            $ueber = min($a['e'], $e) - max($a['s'], $s);
            if ($ueber > $bester) {
                [$bester, $start] = [$ueber, $i];
            }
        }
        if ($start === null) {
            return $leer;
        }
        $benutzt = [$start => true];
        $ref = $abschnitte[$start];
        $kern = self::segment($ref, 'start', false);
        $kern = self::aufnehmen($kern, $abschnitte, $benutzt);
        $genutzt = false;
        $vor = [];
        $nach = [];
        foreach ([1, -1] as $richtung) {
            $cur = $kern;
            for ($n = 0; $n < self::MAX; $n++) {
                $kand = [];
                foreach ($abschnitte as $i => $a) {
                    if (isset($benutzt[$i])) {
                        continue;
                    }
                    $passt = $richtung === 1
                        ? $a['s'] > $cur['s'] && $a['s'] <= $cur['e'] + self::LUECKE && $a['e'] > $cur['e'] && $a['s'] <= $kern['e'] + self::SPANNE
                        : $a['e'] < $cur['e'] && $a['e'] >= $cur['s'] - self::LUECKE && $a['s'] < $cur['s'] && $a['e'] >= $kern['s'] - self::SPANNE;
                    // Dieselbe Kamera zählt nur, wenn die Person sie dazwischen verlassen hatte
                    if ($passt && $a['kamera'] === $cur['kamera'] && ($richtung === 1 ? $a['s'] <= $cur['e'] : $a['e'] >= $cur['s'])) {
                        $passt = false;
                    }
                    if ($passt) {
                        $kand[$i] = $a;
                    }
                }
                if ($kand === []) {
                    break;
                }
                [$wahl, $art, $alternativen] = self::waehlen($kand, $ref, $cur, $richtung, $aehnlich, $genutzt);
                $benutzt[$wahl] = true;
                $neu = self::aufnehmen(self::segment($abschnitte[$wahl], $art, $art === 'zeit' && $alternativen > 1), $abschnitte, $benutzt);
                if ($richtung === 1) {
                    $nach[] = $neu;
                } else {
                    array_unshift($vor, $neu);
                }
                $cur = $neu;
            }
        }
        return ['segmente' => array_merge($vor, [$kern], $nach), 'start' => count($vor), 'aussehen' => $genutzt];
    }

    /** Kandidat wählen: gleicher Name, dann Aussehen, dann zeitlich nächster. @return array{0: int, 1: string, 2: int} Index, Art, Zahl der Alternativen */
    private static function waehlen(array $kand, array $ref, array $cur, int $richtung, ?callable $aehnlich, bool &$genutzt): array
    {
        // zeitlich nächster: vorwärts der früheste Beginn, rückwärts das späteste Ende
        $nachZeit = $kand;
        uasort($nachZeit, fn ($a, $b) => $richtung === 1 ? [$a['s'], $a['id']] <=> [$b['s'], $b['id']] : [$b['e'], $b['id']] <=> [$a['e'], $a['id']]);
        $erster = (int)array_key_first($nachZeit);
        // Echte Alternativen sind nur Kandidaten, die gleichzeitig mit dem nächsten zu sehen sind; wer erst
        // danach auftaucht, ist ein späterer Schritt derselben Kette, keine Konkurrenz
        $e0 = $nachZeit[$erster];
        $nachZeit = array_filter($nachZeit, fn ($a) => $richtung === 1 ? $a['s'] < $e0['e'] : $a['e'] > $e0['s']);
        $n = count($nachZeit);
        if ($n === 1) {
            return [$erster, 'zeit', 1];
        }
        if ($ref['name'] !== '') {
            foreach ($nachZeit as $i => $a) {
                if ($a['name'] === $ref['name']) {
                    return [$i, 'gesicht', $n];
                }
            }
        }
        if ($aehnlich !== null) {
            $abstand = $aehnlich($ref['id'], array_values(array_map(fn ($a) => $a['id'], $nachZeit)));
            if (is_array($abstand) && $abstand !== []) {
                $genutzt = true;
                $best = null;
                foreach ($nachZeit as $i => $a) {
                    $d = $abstand[$a['id']] ?? null;
                    if (is_numeric($d) && ($best === null || (float)$d < $best[1])) {
                        $best = [$i, (float)$d];
                    }
                }
                if ($best !== null) {
                    return [$best[0], 'aussehen', $n];
                }
            }
        }
        return [$erster, 'zeit', $n];
    }

    private static function segment(array $a, string $art, bool $mehrdeutig): array
    {
        return ['kamera' => $a['kamera'], 'id' => $a['id'], 'ids' => [$a['id']], 's' => $a['s'], 'e' => $a['e'],
            'art' => $art, 'name' => $a['name'], 'mehrdeutig' => $mehrdeutig];
    }

    /** Weitere Ereignisse derselben Kamera, die mit dem Abschnitt überlappen (neu vergebene ID), mit aufnehmen. */
    private static function aufnehmen(array $seg, array $abschnitte, array &$benutzt): array
    {
        do {
            $mehr = false;
            foreach ($abschnitte as $i => $a) {
                if (!isset($benutzt[$i]) && $a['kamera'] === $seg['kamera'] && $a['s'] <= $seg['e'] + 2 && $a['e'] >= $seg['s'] - 2
                    && ($seg['name'] === '' || $a['name'] === '' || $a['name'] === $seg['name'])) {
                    $benutzt[$i] = true;
                    $seg['s'] = min($seg['s'], $a['s']);
                    $seg['e'] = max($seg['e'], $a['e']);
                    $seg['ids'][] = $a['id'];
                    if ($seg['name'] === '') {
                        $seg['name'] = $a['name'];
                    }
                    $mehr = true;
                }
            }
        } while ($mehr);
        return $seg;
    }

    /** „kamera|von|bis" der Kachel prüfen. @return array{0: string, 1: int, 2: int}|null */
    public static function anfrage(string $text, array $kameras, int $jetzt): ?array
    {
        $teile = explode('|', $text);
        if (count($teile) !== 3 || !in_array($teile[0], $kameras, true) || !ctype_digit($teile[1]) || !ctype_digit($teile[2])) {
            return null;
        }
        [$s, $e] = [(int)$teile[1], (int)$teile[2]];
        if ($e < $s || $e - $s > 3600 || $s > $jetzt + 60) {
            return null;
        }
        return [$teile[0], $s, $e];
    }
}
