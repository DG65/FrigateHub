<?php

declare(strict_types=1);

/**
 * Prüfstand Frigate-Konfigurator — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-konfigurator.php
 *
 * Die API-Antworten sind auf die Felder gekürzt, die eine echte Frigate-0.18.2-Instanz am
 * 28.10.2026 geliefert hat (eine Kamera „Door-Cam"), plus eine zweite, deaktivierte Kamera.
 */

require __DIR__ . '/ips-stub.php';
require __DIR__ . '/../FrigateHubKonfigurator/module.php';

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

class TestKonfigurator extends FrigateHubKonfigurator
{
    public static array $api = [];
    public static array $abgerufen = [];

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }

    public function form(): array
    {
        return json_decode($this->GetConfigurationForm(), true);
    }

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        self::$abgerufen[] = $pfad;
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }
}

/** Formular-Element mit Namen suchen (rekursiv). */
function element(array $elemente, string $name): ?array
{
    foreach ($elemente as $e) {
        if (($e['name'] ?? '') === $name) {
            return $e;
        }
        if (isset($e['items']) && ($t = element($e['items'], $name)) !== null) {
            return $t;
        }
    }
    return null;
}

$config = [
    'mqtt'    => ['topic_prefix' => 'frigate'],
    'cameras' => [
        'Door-Cam' => ['name' => 'Door-Cam', 'enabled' => true, 'detect' => ['enabled' => true, 'width' => 1280, 'height' => 720, 'fps' => 5], 'zones' => []],
        'garten'   => ['name' => 'garten', 'friendly_name' => 'Garten hinten', 'enabled' => false,
            'detect' => ['enabled' => false, 'width' => 640, 'height' => 360, 'fps' => 5], 'zones' => ['beet' => [], 'weg' => []]],
    ],
];
$stats = ['service' => ['version' => '0.18.2-cfa08c5'], 'cameras' => ['Door-Cam' => ['camera_fps' => 5.1], 'garten' => ['camera_fps' => 0.0]]];

/* =================================================================== Bibliothek */

$liste = FrigateHubTopics::kamerasAusConfig($config, $stats);
check('zwei Kameras', count($liste), 2);
check('nach Name sortiert', array_column($liste, 'name'), ['Door-Cam', 'Garten hinten']);
check('friendly_name als Name', $liste[1]['id'], 'garten');
check('Auflösung', $liste[0]['aufloesung'], '1280×720 @ 5 fps');
check('Zonen gezählt', $liste[1]['zonen'], 2);
check('liefert Bilder', [$liste[0]['bilder'], $liste[1]['bilder']], [true, false]);
check('ohne stats: unbekannt', FrigateHubTopics::kamerasAusConfig($config, null)[0]['bilder'], null);
check('deaktiviert erkannt', $liste[1]['aktiv'], false);
check('leere Config', FrigateHubTopics::kamerasAusConfig([], null), []);
check('Präfix aus Config', FrigateHubTopics::praefixAusConfig(['mqtt' => ['topic_prefix' => '/nvr/frigate/']]), 'nvr/frigate');
check('Präfix Standard', FrigateHubTopics::praefixAusConfig([]), 'frigate');

/* =================================================================== Modul */

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 2, 'InstanceStatus' => IS_INACTIVE, 'ModuleInfo' => ['ModuleID' => '{AB2DD3FF-1892-4830-8C35-86982AB8D661}']];
IPSTestState::$instances[2] = ['InstanceID' => 2, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{MQTT}']];
$k = new TestKonfigurator(1);
$k->Create();
$k->ApplyChanges();
check('ohne Adresse: 104', $k->GetStatus(), 104);
check('empfängt nichts', $k->receiveFilter, FrigateHubTopics::sperrfilter());
$f = $k->form();
check('ohne Adresse: Hinweis', strpos(element($f['elements'], 'Suchstatus')['caption'], 'ℹ️') === 0, true);
check('ohne Adresse: keine Abfrage', TestKonfigurator::$abgerufen, []);
check('Zweck-Panel oben', $f['elements'][0]['name'] ?? null, 'PurposeIntroPanel');
check('Ack-Knopf mit eigenem Präfix', strpos((string)json_encode($f), 'FRIGC_AckPurposeIntro($id);') !== false, true);

$k->props(['ApiUrl' => 'http://127.0.0.1:5000/']);
$k->ApplyChanges();
check('mit Adresse: 102', $k->GetStatus(), 102);

TestKonfigurator::$api = [];
$f = $k->form();
check('nicht erreichbar: ⛔', strpos(element($f['elements'], 'Suchstatus')['caption'], '⛔ Frigate unter http://127.0.0.1:5000 nicht erreichbar') === 0, true);
check('nicht erreichbar: leere Liste', element($f['elements'], 'Kameras')['values'], []);

TestKonfigurator::$api = ['/api/config' => $config, '/api/stats' => $stats];
TestKonfigurator::$abgerufen = [];
$f = $k->form();
$werte = element($f['elements'], 'Kameras')['values'];
check('Status mit Version', element($f['elements'], 'Suchstatus')['caption'], '✅ 2 Kameras in Frigate 0.18.2 gefunden.');
check('nur lesende Abrufe', TestKonfigurator::$abgerufen, ['/api/config', '/api/stats']);
check('zwei Zeilen', count($werte), 2);
check('Create-Modul', $werte[0]['create']['moduleID'], '{6BB54171-2F24-4653-9061-F2BC352A9D3B}');
check('Create-Konfiguration', $werte[0]['create']['configuration'], ['Camera' => 'Door-Cam', 'TopicPrefix' => 'frigate', 'ApiUrl' => 'http://127.0.0.1:5000']);
check('Create-Name', $werte[1]['create']['name'], 'Garten hinten');
check('noch nicht angelegt', [$werte[0]['instanceID'], $werte[1]['instanceID']], [0, 0]);
check('Zustand', [$werte[0]['zustand'], $werte[1]['zustand']], ['liefert Bilder', 'in Frigate deaktiviert']);
check('Erkennung', [$werte[0]['erkennung'], $werte[1]['erkennung']], ['an', 'aus']);
// Spalten müssen zu den Zeilen passen
$spalten = array_column(element($f['elements'], 'Kameras')['columns'], 'name');
check('Spalten = Zeilenfelder', array_values(array_diff(array_keys($werte[0]), ['instanceID', 'create'])), $spalten);

// Vorhandene Instanzen: gleiche Kamera unter demselben MQTT-Server
IPSTestState::$instances[10] = ['InstanceID' => 10, 'ConnectionID' => 2, 'Name' => 'Haustür', 'Properties' => ['Camera' => 'Door-Cam'], 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
// … unter einem anderen Broker zählt nicht
IPSTestState::$instances[11] = ['InstanceID' => 11, 'ConnectionID' => 99, 'Name' => 'Garten (anderer Broker)', 'Properties' => ['Camera' => 'garten'], 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
// … und eine, deren Kamera es in Frigate nicht mehr gibt
IPSTestState::$instances[12] = ['InstanceID' => 12, 'ConnectionID' => 2, 'Name' => 'Alte Garage', 'Properties' => ['Camera' => 'garage'], 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$werte = element($k->form()['elements'], 'Kameras')['values'];
check('vorhandene Instanz zugeordnet', $werte[0]['instanceID'], 10);
check('anderer Broker zählt nicht', $werte[1]['instanceID'], 0);
check('verwaiste Instanz gelistet', [count($werte), $werte[2]['instanceID'], $werte[2]['zustand'], $werte[2]['name']], [3, 12, 'nicht mehr in Frigate', 'Alte Garage']);
check('verwaiste Instanz ohne create', isset($werte[2]['create']), false);

// Ohne aktiven MQTT-Server: Warnung
IPSTestState::$instances[2]['InstanceStatus'] = IS_INACTIVE;
check('Warnung ohne aktiven Elternteil', strpos(element($k->form()['elements'], 'Suchstatus')['caption'], 'hängt unter keinem aktiven MQTT-Server') !== false, true);

// Ungültige Adresse
$k->props(['ApiUrl' => 'frigate:5000']);
$k->ApplyChanges();
check('ungültige Adresse: 104', $k->GetStatus(), 104);
check('ungültige Adresse: Hinweis', strpos(element($k->form()['elements'], 'Suchstatus')['caption'], '⚠️ Die Frigate-Adresse ist ungültig') === 0, true);

check('kein IPS_LogMessage im Modul', strpos((string)file_get_contents(__DIR__ . '/../FrigateHubKonfigurator/module.php'), 'IPS_LogMessage'), false);
$mj = json_decode((string)file_get_contents(__DIR__ . '/../FrigateHubKonfigurator/module.json'), true);
check('module.json: Typ Konfigurator, eigener Präfix', [$mj['type'], $mj['prefix'], count($mj['aliases'])], [4, 'FRIGC', 1]);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
