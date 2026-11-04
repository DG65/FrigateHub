<?php
/**
 * Fügt .tools/locale-teile/*.json (je {"Englisch": "Deutsch"}) zu libs/locale.json zusammen und legt
 * sie in alle drei Modulordner (Symcon liest locale.json nur dort). Widersprüche brechen ab.
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$de = [];
$fehler = [];
foreach (glob(__DIR__ . '/locale-teile/*.json') as $teil) {
    $t = json_decode((string)file_get_contents($teil), true, 512, JSON_THROW_ON_ERROR);
    foreach ($t as $en => $deutsch) {
        if (isset($de[$en]) && $de[$en] !== $deutsch) {
            $fehler[] = basename($teil) . ': „' . $en . '“ → „' . $deutsch . '“ statt „' . $de[$en] . '“';
        }
        $de[$en] = $deutsch;
    }
}
if ($fehler !== []) {
    fwrite(STDERR, "Widersprüche:\n" . implode("\n", $fehler) . "\n");
    exit(1);
}
ksort($de, SORT_STRING);
$json = json_encode(['translations' => ['de' => (object)$de]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents(__DIR__ . '/../libs/locale.json', $json);
foreach (['FrigateHubKamera', 'FrigateHubServer', 'FrigateHubKonfigurator'] as $modul) {
    file_put_contents(__DIR__ . '/../' . $modul . '/locale.json', $json);
}
echo count($de) . " Einträge, locale.json für 3 Module geschrieben\n";
