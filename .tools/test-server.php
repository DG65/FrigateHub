<?php

declare(strict_types=1);

/**
 * Prüfstand Frigate-Server — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-server.php
 *
 * Die Statistik ist auf die Felder gekürzt, die Dietmars Frigate 0.18.2 am 29.10.2026 per
 * /api/stats geliefert hat (eine Kamera „Door-Cam" mit Verbindung „poor").
 */

require __DIR__ . '/ips-stub.php';
require __DIR__ . '/../FrigateHubServer/module.php';

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

function paket(string $topic, string $payload): string
{
    return (string)json_encode([
        'DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => $payload,
    ]);
}

function nachIdent(array $werte): array
{
    $out = [];
    foreach ($werte as $w) {
        $out[$w['ident']] = $w;
    }
    return $out;
}

$stats = [
    'detectors'  => ['ov' => ['inference_speed' => 13.72, 'detection_start' => 0, 'pid' => 649]],
    'cameras'    => ['Door-Cam' => ['camera_fps' => 5.1, 'process_fps' => 5.1, 'detection_fps' => 0, 'connection_quality' => 'poor',
        'expected_fps' => 5, 'reconnects_last_hour' => 5, 'stalls_last_hour' => 5]],
    'gpu_usages' => ['N100 iGPU' => ['vendor' => 'intel', 'gpu' => '2.51%', 'mem' => '-%']],
    'cpu_usages' => ['frigate.full_system' => ['cpu' => '23.8', 'mem' => '43.4'], '137' => ['cpu' => '2.5', 'cmdline' => 'python3 -u -m frigate']],
    'service'    => ['uptime' => 6147, 'version' => '0.18.2-cfa08c5', 'latest_version' => '0.18.2', 'last_updated' => 1793282319,
        'storage' => ['/media/frigate/recordings' => ['total' => 478159, 'used' => 60262.9, 'free' => 393535.2, 'mount_type' => 'ext4'],
            '/dev/shm' => ['total' => 512, 'used' => 149.4, 'free' => 362.6]]],
];

/* ------------------------------------------------------------- Auswertung */

$w = nachIdent(FrigateHubServerWerte::werte($stats));
$gpu = FrigateHubTopics::ident('GPU', 'N100 iGPU');
check('Idents', array_keys($w), ['Version', 'NeuesteVersion', 'LaeuftSeit', 'CPU', 'Arbeitsspeicher', 'Erkennungszeit_ov', $gpu,
    'AufnahmenBelegt', 'AufnahmenFrei', 'Kameraprobleme', 'Kamerazustand']);
check('Version ohne Build-Kennung', $w['Version']['wert'], '0.18.2');
check('Neueste Version: aktuell', $w['NeuesteVersion']['wert'], 'aktuell');
check('Läuft seit', $w['LaeuftSeit']['wert'], date('d.m.Y H:i', 1793282319 - 6147));
check('CPU und Arbeitsspeicher', [$w['CPU']['wert'], $w['CPU']['einheit'], $w['Arbeitsspeicher']['wert']], [23.8, ' %', 43.4]);
check('Erkennungszeit', [$w['Erkennungszeit_ov']['wert'], $w['Erkennungszeit_ov']['einheit'], $w['Erkennungszeit_ov']['name']], [13.7, ' ms', 'Erkennungszeit ov']);
check('GPU-Last aus „2.51%"', [$w[$gpu]['wert'], $w[$gpu]['name']], [2.5, 'GPU-Last N100 iGPU']);
check('Speicher belegt (MB → %)', $w['AufnahmenBelegt']['wert'], 12.6);
check('Speicher frei (MB → GB)', [$w['AufnahmenFrei']['wert'], $w['AufnahmenFrei']['einheit']], [384.3, ' GB']);
check('gestörte Kamera erkannt', [$w['Kameraprobleme']['wert'], $w['Kamerazustand']['wert']],
    [1, 'Door-Cam: Verbindung schlecht, 5 Neuverbindungen in der letzten Stunde']);
check('Typen', [$w['Version']['typ'], $w['CPU']['typ'], $w['Kameraprobleme']['typ']], ['string', 'float', 'int']);

check('neuere Version', FrigateHubServerWerte::neuesteVersion('0.18.2', '0.19.0'), '0.19.0 verfügbar');
check('ältere „neueste" (Beta läuft)', FrigateHubServerWerte::neuesteVersion('0.19.0', '0.18.2'), 'aktuell');
check('Abfrage aus', FrigateHubServerWerte::neuesteVersion('0.18.2', 'disabled'), 'unbekannt');
check('ohne Angabe', FrigateHubServerWerte::neuesteVersion('0.18.2', null), 'unbekannt');
check('Prozent-Texte', [FrigateHubServerWerte::prozent('2.51%'), FrigateHubServerWerte::prozent('-%'), FrigateHubServerWerte::prozent(''),
    FrigateHubServerWerte::prozent(4)], [2.5, null, null, 4.0]);

$gut = ['a' => ['camera_fps' => 5, 'connection_quality' => 'excellent', 'reconnects_last_hour' => 0],
    'b' => ['camera_fps' => 10, 'connection_quality' => 'fair']];
check('fair ist kein Problem', FrigateHubServerWerte::kameraProbleme($gut), []);
$w = nachIdent(FrigateHubServerWerte::werte(['cameras' => $gut]));
check('alle in Ordnung (Mehrzahl)', [$w['Kameraprobleme']['wert'], $w['Kamerazustand']['wert']], [0, 'Alle 2 Kameras laufen einwandfrei.']);
$w = nachIdent(FrigateHubServerWerte::werte(['cameras' => ['a' => $gut['a']]]));
check('alle in Ordnung (Einzahl)', $w['Kamerazustand']['wert'], 'Die Kamera läuft einwandfrei.');
check('keine Bilder + unbrauchbar + 1 Neuverbindung', FrigateHubServerWerte::kameraProbleme(['x' => ['camera_fps' => 0, 'connection_quality' => 'unusable', 'reconnects_last_hour' => 1]]),
    ['x: liefert keine Bilder, Verbindung unbrauchbar, 1 Neuverbindung in der letzten Stunde']);
check('unbekannte Stufe bleibt stehen', FrigateHubServerWerte::qualitaet('meh'), 'meh');
check('leere Statistik', FrigateHubServerWerte::werte([]), []);
check('kaputte Felder', FrigateHubServerWerte::werte(['service' => ['storage' => ['/media/frigate/recordings' => ['total' => 0, 'used' => 5]]],
    'detectors' => ['x' => 'kaputt'], 'gpu_usages' => ['g' => ['gpu' => '-%']], 'cpu_usages' => ['frigate.full_system' => ['cpu' => 'n/a']]]), []);

/* ------------------------------------------------------------- Modul */

class TestServer extends FrigateHubServer
{
    public static array $api = [];

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        return self::$api[$pfad] ?? null;
    }
}

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}']];
$s = new TestServer(1);
$s->Create();
$s->ApplyChanges();
check('aktiv, Filter ohne Schrägstrich', [$s->GetStatus(), $s->receiveFilter], [102, '.*(stats|available).*']);
check('feste Variable', array_keys($s->variables), ['Verfuegbar']);
check('ohne Adresse kein Abgleich-Timer', $s->timers['Abgleich']['interval'] ?? null, 0);

$s->ReceiveData(paket('frigate/available', 'online'));
check('erreichbar', $s->GetValue('Verfuegbar'), true);
$s->ReceiveData(paket('frigate/available', 'offline'));
check('nicht erreichbar', $s->GetValue('Verfuegbar'), false);
$s->ReceiveData(paket('frigate/Door-Cam/available', 'online'));
check('fremdes Topic mit „available" ignoriert', $s->GetValue('Verfuegbar'), false);

$s->ReceiveData(paket('frigate/stats', (string)json_encode($stats)));
check('ohne Häkchen nur die Kernwerte', array_keys($s->variables), ['Verfuegbar', 'NeuesteVersion', 'AufnahmenFrei', 'Kameraprobleme', 'Kamerazustand']);
$u = FrigateHubServerWerte::uebersicht(json_decode($s->GetBuffer('Statistik'), true));
check('Übersicht aus gepufferter Statistik', $u['zeilen'], ['Frigate 0.18.2 (aktuell) · läuft seit ' . date('d.m.Y H:i', 1793282319 - 6147),
    'CPU-Last 23,8 % · Arbeitsspeicher 43,4 %', 'Erkennungszeit ov 13,7 ms · GPU-Last N100 iGPU 2,5 %', 'Speicher Aufnahmen: 12,6 % belegt, 384,3 GB frei']);
check('Übersicht je Kamera', $u['kameras'], [['kamera' => 'Door-Cam', 'bilder' => '5,1 von 5', 'erkennungen' => '0,0', 'verbindung' => 'schlecht', 'neu' => '5', 'aussetzer' => '5']]);
check('Puffer ohne Prozessliste', array_keys(json_decode($s->GetBuffer('Statistik'), true)['cpu_usages']), ['frigate.full_system']);
$form = json_decode((string)$s->GetConfigurationForm(), true);
$ue = null;
foreach ($form['elements'] as $e) { if (($e['caption'] ?? '') === '📊  Übersicht') { $ue = $e; } }
check('Formular: Übersicht mit Kameraliste', [$ue !== null, strpos($ue['items'][0]['caption'] ?? '', 'Stand der letzten Statistik') === 0,
    end($ue['items'])['values'][0]['verbindung'] ?? null], [true, true, 'schlecht']);
$s->props(['LeistungAlsVariablen' => true]);
$s->ApplyChanges();
$s->ReceiveData(paket('frigate/stats', (string)json_encode($stats)));
check('mit Häkchen alle Variablen', count($s->variables), 12);
check('CPU mit Einheit und Stellen', [$s->GetValue('CPU'), $s->variables['CPU']['presentation']['SUFFIX'], $s->variables['CPU']['presentation']['DIGITS']], [23.8, ' %', 1]);
check('Text ohne Einheit/Stellen', isset($s->variables['Version']['presentation']['SUFFIX']) || isset($s->variables['Version']['presentation']['DIGITS']), false);
check('Ganzzahl ohne Stellen', isset($s->variables['Kameraprobleme']['presentation']['DIGITS']), false);
check('Positionen', [$s->variables['Version']['position'], $s->variables['Erkennungszeit_ov']['position'], $s->variables['Kamerazustand']['position']], [2, 20, 51]);

// Name und Position gehören nach dem Anlegen dem Nutzer
$s->variables['CPU']['caption'] = 'Prozessor';
$s->variables['CPU']['position'] = 99;
$stats2 = $stats;
$stats2['cpu_usages']['frigate.full_system']['cpu'] = '50';
$stats2['cameras']['Door-Cam']['connection_quality'] = 'good';
$s->ReceiveData(paket('frigate/stats', (string)json_encode($stats2)));
check('neuer Wert, Name/Position bleiben', [$s->GetValue('CPU'), $s->variables['CPU']['caption'], $s->variables['CPU']['position']], [50.0, 'Prozessor', 99]);
check('Kamera wieder in Ordnung', [$s->GetValue('Kameraprobleme'), $s->GetValue('Kamerazustand')], [0, 'Die Kamera läuft einwandfrei.']);
$s->ReceiveData(paket('frigate/stats', 'kein json'));
check('kaputte Statistik ändert nichts', $s->GetValue('CPU'), 50.0);

// Häkchen wieder aus: vorhandene Leistungs-Variablen bleiben (gehören dem Nutzer) und aktuell
$s->props(['LeistungAlsVariablen' => false]);
$stats4 = $stats2;
$stats4['cpu_usages']['frigate.full_system']['cpu'] = '61';
$s->ReceiveData(paket('frigate/stats', (string)json_encode($stats4)));
check('Häkchen aus: Variable bleibt und wird aktualisiert', [count($s->variables), $s->GetValue('CPU')], [12, 61.0]);
unset($s->variables['CPU']);
$s->ReceiveData(paket('frigate/stats', (string)json_encode($stats4)));
check('gelöschte Leistungs-Variable kommt nicht wieder', isset($s->variables['CPU']), false);

// Anderer Präfix
$s->props(['TopicPrefix' => '/frigate2/']);
$s->ApplyChanges();
$s->ReceiveData(paket('frigate/available', 'online'));
check('alter Präfix zählt nicht mehr', $s->GetValue('Verfuegbar'), false);
$s->ReceiveData(paket('frigate2/available', 'online'));
check('neuer Präfix (Schrägstriche bereinigt)', $s->GetValue('Verfuegbar'), true);
$s->props(['TopicPrefix' => 'frigate']);

// API
check('ohne Adresse: Hinweis', strpos($s->ZustandAnfordern(), 'ℹ️') === 0, true);
$s->props(['ApiUrl' => 'http://127.0.0.1:5000']);
$s->ApplyChanges();
check('mit Adresse: Abgleich-Timer', $s->timers['Abgleich']['interval'] ?? null, 2000);
TestServer::$api = [];
check('API nicht erreichbar: ⛔', strpos($s->ZustandAnfordern(), '⛔') === 0, true);
$s->ReceiveData(paket('frigate/available', 'offline'));
$stats3 = $stats;
$stats3['service']['latest_version'] = '0.19.1';
TestServer::$api = ['/api/stats' => (string)json_encode($stats3)];
$text = $s->ZustandAnfordern();
check('API: Werte geholt, erreichbar', [strpos($text, '✅') === 0, $s->GetValue('NeuesteVersion'), $s->GetValue('Verfuegbar')], [true, '0.19.1 verfügbar', true]);
check('Timer nach Abgleich aus', $s->timers['Abgleich']['interval'] ?? null, 0);

// Neustart
IPSTestState::$sentPackets = [];
check('Neustart ohne MQTT-Instanz: ⛔', [strpos($s->Neustarten(), '⛔') === 0, count(IPSTestState::$sentPackets)], [true, 0]);
IPSTestState::$instances[95] = ['InstanceID' => 95, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}']];
IPSTestState::$instances[1]['ConnectionID'] = 95;
$s->Neustarten();
$p = IPSTestState::$sentPackets[0] ?? [];
check('Neustart: frigate/restart, nie gespeichert', [$p['Topic'] ?? '', $p['Retain'] ?? null, $p['QualityOfService'] ?? null], ['frigate/restart', false, 0]);

// Formular und Modulbeschreibung
$form = (string)$s->GetConfigurationForm();
check('Formular: Neustart mit Rückfrage', strpos($form, '"confirm"') !== false && strpos($form, 'FRIGS_Neustarten($id)') !== false, true);
check('Formular: eigener Präfix', strpos($form, 'FRIGS_AckPurposeIntro($id);') !== false, true);
check('kein IPS_LogMessage im Modul', strpos((string)file_get_contents(__DIR__ . '/../FrigateHubServer/module.php'), 'IPS_LogMessage'), false);
$mj = json_decode((string)file_get_contents(__DIR__ . '/../FrigateHubServer/module.json'), true);
check('module.json: Gerät, FRIGS, unter MQTT', [$mj['type'], $mj['prefix'], $mj['parentRequirements']], [3, 'FRIGS', ['{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}']]);
check('Anmeldung wird mit dem Server geteilt', strpos((string)file_get_contents(__DIR__ . '/../libs/FrigateHubApi.php'), "'" . $mj['id'] . "' => 'FRIGS'") !== false, true);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
