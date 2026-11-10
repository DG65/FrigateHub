<?php

declare(strict_types=1);

/**
 * Prüfstand „Roboter im Bild" (0.20.0) — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-roboter.php
 *
 * RoboHubs Vertrag `robot_activity` 1.0 (abgestimmt 10.11.2026) als Attrappe ROBO_GetActivity().
 * Rahmen im Format von frigate/events (`box` = [x1, y1, x2, y2] in Pixeln des Erkennungsbilds).
 */

require __DIR__ . '/ips-stub.php';
require __DIR__ . '/../FrigateHubKamera/module.php';

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

$R = 'FrigateHubRoboter';
$t = 1800000000;

/** Antwort von ROBO_GetActivity() im Vertragsformat. */
function aktivitaet(array $mehr = []): array
{
    global $t;
    return $mehr + ['contractVersion' => '1.0', 'class' => 'mower', 'name' => 'LiDAX', 'state' => 1, 'stateVariableID' => 1,
        'moving' => true, 'outsideStation' => true, 'since' => $t - 600, 'updatedAt' => $t - 10, 'realtime' => true,
        'pollIntervalS' => 30, 'validUntil' => $t + 300];
}

/* ------------------------------------------------------------- Roboterzustand */

check('mäht → aktiv', $R::aktivitaet(aktivitaet(), $t)['aktiv'], true);
check('als JSON-Text', $R::aktivitaet(json_encode(aktivitaet()), $t)['aktiv'], true);
check('Pause auf dem Rasen (outsideStation) → aktiv', $R::aktivitaet(aktivitaet(['state' => 2, 'moving' => false]), $t)['aktiv'], true);
check('veraltet (validUntil vorbei) → nicht aktiv', $R::aktivitaet(aktivitaet(['validUntil' => $t - 1]), $t), ['aktiv' => false, 'klasse' => 'mower', 'name' => 'LiDAX', 'grund' => 'veraltet']);
check('ohne validUntil → nicht aktiv (im Zweifel melden)', $R::aktivitaet(array_diff_key(aktivitaet(), ['validUntil' => 0]), $t)['aktiv'], false);
check('Vertrag 2.x → nicht aktiv', $R::aktivitaet(aktivitaet(['contractVersion' => '2.0']), $t)['grund'], 'Vertrag 2.0');
check('Vertrag 1.1 (Ergänzung) → gilt', $R::aktivitaet(aktivitaet(['contractVersion' => '1.1']), $t)['aktiv'], true);
check('unbekannte Klasse → nicht aktiv', $R::aktivitaet(aktivitaet(['class' => 'pool']), $t)['aktiv'], false);
check('Zustand unbekannt → nicht aktiv', $R::aktivitaet(aktivitaet(['state' => -1]), $t)['aktiv'], false);
check('keine Antwort', $R::aktivitaet(null, $t)['aktiv'], false);
$station = ['state' => 4, 'moving' => false, 'outsideStation' => false];
check('gerade eingefahren → Nachlauf', $R::aktivitaet(aktivitaet($station + ['since' => $t - 30]), $t)['grund'], 'Nachlauf');
check('Nachlauf mindestens 60 s', $R::aktivitaet(aktivitaet($station + ['since' => $t - 61, 'pollIntervalS' => 10]), $t)['aktiv'], false);
check('Nachlauf 2 × Abfrageabstand', $R::aktivitaet(aktivitaet($station + ['since' => $t - 100, 'pollIntervalS' => 60]), $t)['aktiv'], true);
check('lange in der Station → nicht aktiv', $R::aktivitaet(aktivitaet($station + ['since' => $t - 3600]), $t)['grund'], 'in der Station');

/* ------------------------------------------------------------- Zuordnung */

check('Mäher-Zuordnung: Mäher ja', $R::zugeordnet($R::MAEHER, [], 5, 'mower'), true);
check('Mäher-Zuordnung: Sauger nein', $R::zugeordnet($R::MAEHER, [], 5, 'vacuum'), false);
check('Sauger-Zuordnung', $R::zugeordnet($R::SAUGER, [], 5, 'vacuum'), true);
check('alle', $R::zugeordnet($R::ALLE, [], 5, 'vacuum'), true);
check('einzelne: ausgewählt', $R::zugeordnet($R::EINZELNE, [5], 5, 'mower'), true);
check('einzelne: nicht ausgewählt', $R::zugeordnet($R::EINZELNE, [6], 5, 'mower'), false);
check('aus', $R::zugeordnet($R::AUS, [5], 5, 'mower'), false);

/* ------------------------------------------------------------- Rahmen und Bewertung */

check('Anteil: ganz drin', $R::anteilIn([10, 10, 20, 20], [0, 0, 100, 100]), 1.0);
check('Anteil: halb', $R::anteilIn([0, 0, 20, 10], [10, 0, 100, 100]), 0.5);
check('Anteil: daneben', $R::anteilIn([0, 0, 10, 10], [20, 20, 30, 30]), 0.0);
check('flach', [$R::flach([0, 0, 100, 40]), $R::flach([0, 0, 40, 100])], [true, false]);
check('ungültiger Rahmen', [$R::box([1, 2, 3]), $R::box([5, 5, 1, 1]), $R::box(null)], [null, null, null]);

$ev = fn (string $id, string $label, array $box, array $mehr = []) => $mehr + ['id' => $id, 'label' => $label, 'box' => $box];
$b = [];
$b = $R::boxMerken($b, 'new', $ev('hund', 'dog', [100, 400, 220, 460]), $t);           // flach, typische Mäher-Fehldeutung
$b = $R::boxMerken($b, 'new', $ev('flach', 'person', [100, 400, 230, 450]), $t);        // flache „Person"
$b = $R::boxMerken($b, 'new', $ev('mensch', 'person', [500, 100, 560, 300]), $t);       // aufrecht
$b = $R::boxMerken($b, 'new', $ev('auto', 'car', [0, 0, 300, 120]), $t);
$b = $R::boxMerken($b, 'new', $ev('dietmar', 'person', [100, 400, 230, 450], ['sub_label' => ['Dietmar', 0.9]]), $t);
$b = $R::boxMerken($b, 'new', $ev('katze', 'cat', [300, 200, 330, 260]), $t);           // aufrecht sitzende Katze
$b = $R::boxMerken($b, 'new', $ev('falsch', 'dog', [0, 0, 10, 5], ['false_positive' => true]), $t);
check('Rahmen gemerkt, falsch erkannte nicht', [count($b), isset($b['falsch']), $b['dietmar']['gesicht']], [6, false, true]);

$robo = ['LiDAX'];
check('kein Roboter aktiv → melden', $R::bewerten(['hund'], $b, []), ['unterdruecken' => false, 'grund' => 'kein Roboter aktiv']);
check('RoboHub aktiv, flacher Hund → unterdrückt', $R::bewerten(['hund'], $b, $robo), ['unterdruecken' => true, 'grund' => 'LiDAX']);
check('RoboHub aktiv, flache Person ohne Gesicht → unterdrückt', $R::bewerten(['flach'], $b, $robo)['unterdruecken'], true);
check('RoboHub aktiv, aufrechte Person → melden', $R::bewerten(['mensch'], $b, $robo), ['unterdruecken' => false, 'grund' => 'aufrechte Person']);
check('Mensch + Hund im selben Alarm → melden', $R::bewerten(['hund', 'mensch'], $b, $robo)['unterdruecken'], false);
check('erkanntes Gesicht → melden, auch flach', $R::bewerten(['dietmar'], $b, $robo)['grund'], 'erkannte Person');
check('Fahrzeug → nie unterdrückt', $R::bewerten(['auto'], $b, $robo)['grund'], 'Fahrzeug/car');
check('aufrechte Katze → melden', $R::bewerten(['katze'], $b, $robo)['unterdruecken'], false);
check('Objekt ohne Rahmen → melden', $R::bewerten(['unbekannt'], $b, $robo)['grund'], 'Objekt ohne Rahmen');
check('keine Objekte → melden', $R::bewerten([], $b, $robo)['unterdruecken'], false);

// Frigate+: fahrender Mäher als eigenes Objekt
$m = $R::boxMerken($b, 'new', $ev('maeher', 'robot_lawnmower', [80, 380, 260, 470]), $t);
check('Mäher-Rahmen nur fahrend', count($R::maeherRahmen($m)), 1);
check('Frigate+: Hund im Mäher-Rahmen → unterdrückt ohne RoboHub', $R::bewerten(['hund'], $m, []), ['unterdruecken' => true, 'grund' => 'Mähroboter']);
check('Frigate+: aufrechte Person weit weg → melden', $R::bewerten(['mensch'], $m, [])['unterdruecken'], false);
$m2 = $R::boxMerken($m, 'new', $ev('ueber', 'person', [100, 300, 150, 460]), $t);   // aufrecht, ~ 71 % im Mäher-Rahmen
check('Frigate+: Person zu ≥ 50 % im Mäher-Rahmen → unterdrückt', $R::bewerten(['ueber'], $m2, [])['unterdruecken'], true);
$m3 = $R::boxMerken($m, 'new', $ev('halb', 'person', [100, 100, 150, 460]), $t);   // aufrecht, nur 25 % im Rahmen
check('Frigate+: Person nur zu 25 % im Mäher-Rahmen → melden', $R::bewerten(['halb'], $m3, [])['unterdruecken'], false);
check('nur der Mäher selbst → nie Alarm', $R::bewerten(['maeher'], $m, []), ['unterdruecken' => true, 'grund' => 'Mähroboter']);
$geparkt = $R::boxMerken($b, 'update', $ev('maeher', 'robot_lawnmower', [80, 380, 260, 470], ['stationary' => true]), $t);
check('geparkter Mäher: Rahmen zählt nicht (Mensch läuft an der Garage vorbei)', [$R::maeherRahmen($geparkt), $R::bewerten(['hund'], $geparkt, [])['unterdruecken']], [[], false]);
check('geparkter Mäher allein → trotzdem kein Alarm', $R::bewerten(['maeher'], $geparkt, [])['unterdruecken'], true);
$weg = $R::boxMerken($m, 'end', $ev('maeher', 'robot_lawnmower', [80, 380, 260, 470], ['end_time' => $t]), $t);
check('beendeter Mäher: Rahmen zählt nicht', $R::maeherRahmen($weg), []);
check('alte Rahmen werden vergessen', isset($R::boxMerken($b, 'new', $ev('neu', 'dog', [0, 0, 20, 10]), $t + 601)['hund']), false);

/* ------------------------------------------------------------- Kamera-Instanz */

class TestKamera extends FrigateHubKamera
{
    protected function apiRoh(string $pfad, string $accept): ?string
    {
        return null;
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }
}

$GLOBALS['gepusht'] = [];
function VISU_PostNotificationEx(int $id, string $titel, string $text, string $icon, string $ton, int $ziel)
{
    $GLOBALS['gepusht'][] = [$id, $titel, $text];
    return 4711;
}

$GLOBALS['robo'] = [];
function ROBO_GetActivity(int $id): string
{
    return (string)json_encode($GLOBALS['robo'][$id] ?? null);
}

function paket(string $topic, $payload): string
{
    return (string)json_encode([
        'DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => is_array($payload) ? json_encode($payload) : $payload,
    ]);
}

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'Name' => 'Garten', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
IPSTestState::$instances[30] = ['InstanceID' => 30, 'Name' => 'Dietmar', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}']];
IPSTestState::$instances[50] = ['InstanceID' => 50, 'Name' => 'Mäher', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => FrigateHubRoboter::ROBOHUB_GUID]];
IPSTestState::$instances[51] = ['InstanceID' => 51, 'Name' => 'Sauger', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => FrigateHubRoboter::ROBOHUB_GUID]];
$jetzt = time();
$GLOBALS['robo'][50] = ['contractVersion' => '1.0', 'class' => 'mower', 'name' => 'LiDAX', 'state' => 1, 'moving' => true, 'outsideStation' => true,
    'since' => $jetzt - 600, 'updatedAt' => $jetzt, 'realtime' => true, 'pollIntervalS' => 30, 'validUntil' => $jetzt + 300];
$GLOBALS['robo'][51] = ['contractVersion' => '1.0', 'class' => 'vacuum', 'name' => 'Narwal', 'state' => 4, 'moving' => false, 'outsideStation' => false,
    'since' => $jetzt - 7200, 'updatedAt' => $jetzt, 'realtime' => false, 'pollIntervalS' => 300, 'validUntil' => $jetzt + 900];

$k = new TestKamera(1);
$k->Create();
$k->props(['Camera' => 'Garten', 'PushZiele' => json_encode([['Ziel' => 30]]), 'PushPause' => 0]);
$k->ApplyChanges();
$an = fn (string $topic, $inhalt) => $k->ReceiveData(paket($topic, $inhalt));
$wert = fn (string $ident) => $k->variables[$ident]['value'] ?? '(fehlt)';
$objekt = function (string $id, string $label, array $box, array $mehr = []) use ($an): void {
    $an('frigate/events', ['type' => 'new', 'before' => [], 'after' => $mehr + ['id' => $id, 'camera' => 'Garten', 'label' => $label, 'box' => $box,
        'false_positive' => false, 'start_time' => time() - 2, 'end_time' => null, 'current_zones' => [], 'entered_zones' => [], 'stationary' => false]]);
};
$review = function (string $id, array $objekte, string $typ = 'new', string $stufe = 'alert') use ($an): void {
    $an('frigate/reviews', ['type' => $typ, 'before' => [], 'after' => ['id' => $id, 'camera' => 'Garten', 'severity' => $stufe,
        'start_time' => time() - 2, 'data' => ['objects' => ['dog'], 'detections' => $objekte, 'zones' => [], 'audio' => [], 'sub_labels' => []]]]);
};

// Standard aus: verhält sich wie bisher
$objekt('h1', 'dog', [100, 400, 220, 460]);
$an('frigate/Garten/review_status', 'ALERT');
$review('r1', ['h1']);
check('Zuordnung aus (Standard): Alarm + Push wie bisher', [$wert('Alarm'), count($GLOBALS['gepusht'])], [true, 1]);
check('Zuordnung aus: keine Rahmen gemerkt', $k->GetBuffer('RoboterBoxen'), '');
$an('frigate/Garten/review_status', 'NONE');

// Zuordnung „alle Mäher“
$k->props(['RoboterZuordnung' => FrigateHubRoboter::MAEHER]);
$k->ApplyChanges();
$GLOBALS['gepusht'] = [];
$heute = $wert('Heute_alarme');
$objekt('h2', 'dog', [100, 400, 220, 460]);
$an('frigate/Garten/review_status', 'ALERT');
$review('r2', ['h2']);
check('Mäher mäht, flacher Hund: kein Push, kein Alarm', [$GLOBALS['gepusht'], $wert('Alarm')], [[], false]);
check('Erkennung bleibt sichtbar', $wert('Erkennung'), true);
check('unterdrückt mit Grund protokolliert', strpos((string)$wert('RoboterUnterdrueckt'), 'Roboter aktiv: LiDAX') !== false, true);
check('Tageszähler Alarme unverändert', $wert('Heute_alarme'), $heute);

// Mensch kommt dazu (update desselben Reviews) → jetzt doch Alarm
$objekt('p2', 'person', [500, 100, 560, 300]);
$review('r2', ['h2', 'p2'], 'update');
check('aufrechter Mensch kommt dazu: Alarm + Push', [$wert('Alarm'), count($GLOBALS['gepusht'])], [true, 1]);
check('Tageszähler zählt den Alarm einmal', $wert('Heute_alarme'), is_int($heute) ? $heute + 1 : 1);
$review('r2', ['h2', 'p2'], 'update');
check('weiteres update: nicht doppelt gezählt', $wert('Heute_alarme'), is_int($heute) ? $heute + 1 : 1);
$an('frigate/Garten/review_status', 'NONE');
check('Ende: Alarm aus', $wert('Alarm'), false);

// Mäher in der Station (lange) → normaler Alarm
$GLOBALS['robo'][50] = ['outsideStation' => false, 'state' => 4, 'moving' => false, 'since' => $jetzt - 3600] + $GLOBALS['robo'][50];
$GLOBALS['gepusht'] = [];
$objekt('h3', 'dog', [100, 400, 220, 460]);
$an('frigate/Garten/review_status', 'ALERT');
$review('r3', ['h3']);
check('Mäher in der Station: Alarm + Push', [$wert('Alarm'), count($GLOBALS['gepusht'])], [true, 1]);
$an('frigate/Garten/review_status', 'NONE');

// Veralteter Zustand → im Zweifel melden
$GLOBALS['robo'][50] = ['outsideStation' => true, 'state' => 1, 'validUntil' => $jetzt - 5] + $GLOBALS['robo'][50];
$GLOBALS['gepusht'] = [];
$objekt('h4', 'dog', [100, 400, 220, 460]);
$review('r4', ['h4']);
check('veralteter Roboterzustand: Push', count($GLOBALS['gepusht']), 1);

// Sauger ist nicht zugeordnet (nur Mäher)
$GLOBALS['robo'][50]['validUntil'] = $jetzt + 300;
$GLOBALS['robo'][50]['outsideStation'] = false;
$GLOBALS['robo'][50]['since'] = $jetzt - 3600;
$GLOBALS['robo'][51] = ['outsideStation' => true, 'state' => 1] + $GLOBALS['robo'][51];
$GLOBALS['gepusht'] = [];
$objekt('h5', 'dog', [100, 400, 220, 460]);
$review('r5', ['h5']);
check('nur Sauger unterwegs, Kamera nur für Mäher: Push', count($GLOBALS['gepusht']), 1);

// Einzelne: Sauger ausgewählt
$k->props(['RoboterZuordnung' => FrigateHubRoboter::EINZELNE, 'RoboterAuswahl' => json_encode([['InstanceID' => 51, 'Name' => 'Sauger', 'Aktiv' => true], ['InstanceID' => 50, 'Name' => 'Mäher', 'Aktiv' => false]])]);
$k->ApplyChanges();
$GLOBALS['gepusht'] = [];
$objekt('h6', 'cat', [100, 400, 220, 460]);
$review('r6', ['h6']);
check('einzelne: ausgewählter Sauger unterwegs → kein Push', $GLOBALS['gepusht'], []);
check('Grund nennt den Sauger', strpos((string)$wert('RoboterUnterdrueckt'), 'Narwal') !== false, true);

// Erkennung (detection) eines Roboters geht auch nicht an OpenHomeAlarm
$GLOBALS['gepusht'] = [];
$objekt('h7', 'dog', [100, 400, 220, 460]);
$review('r7', ['h7'], 'new', 'detection');
check('Erkennung: Protokoll statt Meldung', strpos((string)$wert('RoboterUnterdrueckt'), 'Narwal') !== false && $GLOBALS['gepusht'] === [], true);

// Formular
$form = json_decode((string)$k->GetConfigurationForm(), true);
$texte = json_encode($form, JSON_UNESCAPED_UNICODE);
check('Formular: Panel „Roboter im Bild" mit beiden gefundenen Robotern', [strpos($texte, 'Roboter im Bild') !== false, strpos($texte, '2 Roboter aus RoboHub gefunden') !== false], [true, true]);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
