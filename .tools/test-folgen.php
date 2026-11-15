<?php

declare(strict_types=1);

/**
 * Prüfstand „Person folgen" (0.25.0): libs/FrigateHubFolgen.php.
 * Ereignisfelder wie /api/events?labels=person in Frigate 0.18.2 (live 15.11.2026: id, camera, label,
 * start_time, end_time, zones, sub_label).
 */

require __DIR__ . '/ips-stub.php';
require __DIR__ . '/../libs/FrigateHubFolgen.php';

$fehler = 0;
$geprueft = 0;

function check(string $name, $ist, $soll): void
{
    global $fehler, $geprueft;
    $geprueft++;
    if ($ist !== $soll) {
        $fehler++;
        echo "✗ {$name}\n    ist:  " . var_export($ist, true) . "\n    soll: " . var_export($soll, true) . "\n";
    }
}

$F = 'FrigateHubFolgen';
$T = 1794769000;
$ev = fn (string $kamera, int $s, ?int $e, string $id, $sub = null, string $label = 'person') =>
    ['id' => ($T + $s) . '.1-' . $id, 'camera' => $kamera, 'label' => $label, 'start_time' => $T + $s + 0.4, 'end_time' => $e === null ? null : $T + $e + 0.2, 'sub_label' => $sub];
$wege = fn (array $k) => array_map(fn ($g) => $g['kamera'] . ':' . $g['art'] . ($g['mehrdeutig'] ? '?' : ''), $k['segmente']);

/* ------------------------------------------------------------- Abschnitte */
$a = $F::abschnitte([$ev('Eingang', 0, 10, 'a'), $ev('Annke', 5, null, 'b'), $ev('Annke', 1, 2, 'c', null, 'car'), ['id' => '../x', 'camera' => 'A', 'start_time' => 1], 'kaputt'], $T + 100);
check('Abschnitte: nur Personen mit gültiger ID, laufende bis jetzt, sortiert', array_map(fn ($x) => [$x['kamera'], $x['s'] - $T, $x['e'] - $T], $a), [['Eingang', 0, 11], ['Annke', 5, 100]]);
check('Name aus sub_label (Text oder [Name, Score])', [
    $F::abschnitte([$ev('A', 0, 1, 'a', 'Dietmar')], $T)[0]['name'], $F::abschnitte([$ev('A', 0, 1, 'a', ['Dietmar', 0.91])], $T)[0]['name']], ['Dietmar', 'Dietmar']);

/* ------------------------------------------------------------- Kette */
// Eingang → Annke → Garten, dazwischen jeweils ein paar Sekunden
$weg = $F::abschnitte([$ev('Eingang', 0, 20, 'a'), $ev('Annke', 28, 50, 'b'), $ev('Garten', 60, 90, 'c')], $T + 1000);
$k = $F::kette($weg, 'Annke', $T + 30, $T + 45);
check('Kette in beide Richtungen, Start in der Mitte', [$wege($k), $k['start'], $k['aussehen']], [['Eingang:zeit', 'Annke:start', 'Garten:zeit'], 1, false]);
check('Kette nur ab Start vorwärts, wenn davor nichts', $wege($F::kette($weg, 'Eingang', $T, $T + 5)), ['Eingang:start', 'Annke:zeit', 'Garten:zeit']);
check('Keine Person auf der Kamera im Zeitraum → leer', $F::kette($weg, 'Garten', $T, $T + 10)['segmente'], []);
check('Lücke über 45 s reißt die Kette', $wege($F::kette($F::abschnitte([$ev('Eingang', 0, 20, 'a'), $ev('Annke', 70, 80, 'b')], $T), 'Eingang', $T, $T + 5)), ['Eingang:start']);
// Gleichzeitig auf zwei Kameras (Sichtbereiche überlappen) zählt
check('Überlappende Sichtbereiche', $wege($F::kette($F::abschnitte([$ev('Eingang', 0, 20, 'a'), $ev('Annke', 15, 40, 'b')], $T), 'Eingang', $T, $T + 5)), ['Eingang:start', 'Annke:zeit']);
// Neue ID auf derselben Kamera (kurz verdeckt) wird aufgenommen, nicht als eigener Abschnitt
$k = $F::kette($F::abschnitte([$ev('Eingang', 0, 10, 'a'), $ev('Eingang', 9, 20, 'a2'), $ev('Annke', 25, 40, 'b')], $T), 'Eingang', $T, $T + 5);
check('Zweite ID derselben Kamera aufgenommen', [$wege($k), count($k['segmente'][0]['ids']), $k['segmente'][0]['e'] - $T], [['Eingang:start', 'Annke:zeit'], 2, 21]);
// Zurück auf dieselbe Kamera nach dem Verlassen
check('Rückkehr zur ersten Kamera', $wege($F::kette($F::abschnitte([$ev('Eingang', 0, 10, 'a'), $ev('Annke', 15, 30, 'b'), $ev('Eingang', 35, 50, 'c')], $T), 'Eingang', $T, $T + 5)),
    ['Eingang:start', 'Annke:zeit', 'Eingang:zeit']);
// Zwei Kandidaten: ohne Name/Aussehen nach Zeit, als mehrdeutig markiert
$zwei = $F::abschnitte([$ev('Eingang', 0, 20, 'a'), $ev('Annke', 30, 50, 'b'), $ev('Garten', 25, 50, 'c')], $T);
check('Zwei Kandidaten ohne Hilfe: frühester, mehrdeutig', $wege($F::kette($zwei, 'Eingang', $T, $T + 5)), ['Eingang:start', 'Garten:zeit?']);
// mit Aussehen: Annke ähnlicher
$fragen = [];
$aehnlich = function (string $ref, array $ids) use (&$fragen, $T) { $fragen[] = [$ref, $ids]; return [($T + 30) . '.1-b' => 0.02, ($T + 25) . '.1-c' => 0.11]; };
$k = $F::kette($zwei, 'Eingang', $T, $T + 5, $aehnlich);
check('Aussehen entscheidet, Bezug ist die Startperson', [$wege($k), $k['aussehen'], $fragen[0][0], count($fragen[0][1])], [['Eingang:start', 'Annke:aussehen'], true, ($T) . '.1-a', 2]);
check('Eindeutiger Fall fragt Frigate nicht', [count($fragen), $F::kette($weg, 'Eingang', $T, $T + 5, $aehnlich)['aussehen'], count($fragen)], [1, false, 1]);
check('Aussehen nicht verfügbar → Zeit', $wege($F::kette($zwei, 'Eingang', $T, $T + 5, fn () => [])), ['Eingang:start', 'Garten:zeit?']);
// Name schlägt Aussehen
$namen = $F::abschnitte([$ev('Eingang', 0, 20, 'a', 'Dietmar'), $ev('Annke', 30, 50, 'b'), $ev('Garten', 25, 50, 'c', 'Dietmar')], $T);
check('Gleicher Name schlägt Aussehen', $wege($F::kette($namen, 'Eingang', $T, $T + 5, $aehnlich)), ['Eingang:start', 'Garten:gesicht']);
// Endlosschleife ausgeschlossen, Spanne begrenzt
$viele = [];
for ($i = 0; $i < 40; $i++) {
    $viele[] = $ev($i % 2 ? 'Annke' : 'Eingang', $i * 20, $i * 20 + 15, 'v' . $i);
}
check('Höchstens 12 Abschnitte je Richtung', count($F::kette($F::abschnitte($viele, $T), 'Eingang', $T, $T + 5)['segmente']), 13);

/* ------------------------------------------------------------- Anfrage */
check('Anfrage gültig', $F::anfrage('Annke|100|200', ['Annke'], 1000), ['Annke', 100, 200]);
check('Anfrage: fremde Kamera, Unsinn, zu lang, Zukunft', [
    $F::anfrage('Garten|100|200', ['Annke'], 1000), $F::anfrage('Annke|x|200', ['Annke'], 1000), $F::anfrage('Annke|100', ['Annke'], 1000),
    $F::anfrage('Annke|100|4000', ['Annke'], 9000), $F::anfrage('Annke|5000|5001', ['Annke'], 1000), $F::anfrage('Annke|200|100', ['Annke'], 1000)], [null, null, null, null, null, null]);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler > 0 ? 1 : 0);
