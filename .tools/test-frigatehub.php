<?php

declare(strict_types=1);

/**
 * Prüfstand FrigateHub — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-frigatehub.php
 *
 * Die Topics der Kamera „Door-Cam" sind die, die eine echte Frigate-0.18.2-Instanz am
 * 28.10.2026 an Symcons MQTT-Server geschickt hat. Die JSON-Beispiele für events/reviews
 * folgen der Frigate-MQTT-Doku (0.18).
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

function paket(string $topic, string $payload): string
{
    return (string)json_encode([
        'DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => $payload,
    ]);
}

class TestKamera extends FrigateHubKamera
{
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
}

/** Alle Labels eines Formulars rekursiv einsammeln. */
function labels(array $elemente): array
{
    $out = [];
    foreach ($elemente as $e) {
        if (isset($e['caption']) && ($e['type'] ?? '') === 'Label') {
            $out[] = $e['caption'];
        }
        if (isset($e['items'])) {
            $out = array_merge($out, labels($e['items']));
        }
    }
    return $out;
}

/* =================================================================== Bibliothek */

$z = ['einfahrt'];
check('available', FrigateHubTopics::deuten('frigate/available', 'frigate', 'Door-Cam', $z)['art'], 'verfuegbar');
check('fremde Kamera', FrigateHubTopics::deuten('frigate/garten/person', 'frigate', 'Door-Cam', $z), null);
check('fremdes Präfix', FrigateHubTopics::deuten('zigbee2mqtt/Door-Cam/person', 'frigate', 'Door-Cam', $z), null);
check('Personenzähler', FrigateHubTopics::deuten('frigate/Door-Cam/person', 'frigate', 'Door-Cam', $z), ['art' => 'objekt', 'label' => 'person']);
check('all = Objekt', FrigateHubTopics::deuten('frigate/Door-Cam/all', 'frigate', 'Door-Cam', $z)['label'], 'all');
check('aktive Objekte', FrigateHubTopics::deuten('frigate/Door-Cam/person/active', 'frigate', 'Door-Cam', $z)['art'], 'aktiv');
check('Bewegung ≠ Bewegungserkennung', FrigateHubTopics::deuten('frigate/Door-Cam/motion', 'frigate', 'Door-Cam', $z)['art'], 'bewegung');
check('motion/state = Schalter', FrigateHubTopics::deuten('frigate/Door-Cam/motion/state', 'frigate', 'Door-Cam', $z)['schluessel'], 'motion');
check('detect/state', FrigateHubTopics::deuten('frigate/Door-Cam/detect/state', 'frigate', 'Door-Cam', $z)['name'], 'Objekterkennung');
check('Maske', FrigateHubTopics::deuten('frigate/Door-Cam/motion_mask/motion_mask_1/state', 'frigate', 'Door-Cam', $z)['schluessel'], 'motion_mask/motion_mask_1');
check('unbekannter Zustand ignoriert', FrigateHubTopics::deuten('frigate/Door-Cam/motion_threshold/state', 'frigate', 'Door-Cam', $z)['art'], 'ignorieren');
check('status/detect', FrigateHubTopics::deuten('frigate/Door-Cam/status/detect', 'frigate', 'Door-Cam', $z), ['art' => 'status', 'rolle' => 'detect']);
check('review_status', FrigateHubTopics::deuten('frigate/Door-Cam/review_status', 'frigate', 'Door-Cam', $z)['art'], 'pruefstatus');
check('Zonen-Zähler', FrigateHubTopics::deuten('frigate/einfahrt/car', 'frigate', 'Door-Cam', $z), ['art' => 'zone', 'zone' => 'einfahrt', 'label' => 'car']);
check('unbekannte Zone', FrigateHubTopics::deuten('frigate/garage/car', 'frigate', 'Door-Cam', $z), null);
check('Geräusch', FrigateHubTopics::deuten('frigate/Door-Cam/audio/bark', 'frigate', 'Door-Cam', $z)['name'], 'Geräusch Bellen');
check('audio/state = Schalter', FrigateHubTopics::deuten('frigate/Door-Cam/audio/state', 'frigate', 'Door-Cam', $z)['art'], 'schalter');
check('Pegel ignoriert', FrigateHubTopics::deuten('frigate/Door-Cam/audio/dBFS', 'frigate', 'Door-Cam', $z)['art'], 'ignorieren');
check('Schnappschuss ignoriert', FrigateHubTopics::deuten('frigate/Door-Cam/person/snapshot', 'frigate', 'Door-Cam', $z)['art'], 'ignorieren');
check('eigenes Präfix', FrigateHubTopics::deuten('cam/nvr/Door-Cam/person', '/cam/nvr/', 'Door-Cam', $z)['art'], 'objekt');
check('Set-Topic', FrigateHubTopics::setTopic('frigate', 'Door-Cam', 'zone/einfahrt'), 'frigate/Door-Cam/zone/einfahrt/set');
check('ON', FrigateHubTopics::anAus('ON'), true);
check('off klein', FrigateHubTopics::anAus('off'), false);
check('Unsinn', FrigateHubTopics::anAus('1'), null);
check('Zahl', FrigateHubTopics::zahl('3'), 3);
check('keine Zahl', FrigateHubTopics::zahl('3.5'), null);
check('Ident sauber', FrigateHubTopics::ident('Objekt', 'person'), 'Objekt_person');
check('Ident mit Bindestrich bekommt Hash', (bool)preg_match('/^Zone_a_b_[0-9a-f]{4}$/', FrigateHubTopics::ident('Zone', 'a-b')), true);
check('a-b ≠ a_b', FrigateHubTopics::ident('Zone', 'a-b') !== FrigateHubTopics::ident('Zone', 'a_b'), true);

// Empfangsfilter: muss die echten Pakete treffen — egal ob Symcon Schrägstriche maskiert.
$filter = '/' . FrigateHubTopics::empfangsfilter('Door-Cam', ['einfahrt']) . '/';
check('Filter ohne Schrägstrich', strpos(FrigateHubTopics::empfangsfilter('Door-Cam', ['einfahrt']), '/'), false);
check('Filter trifft Kamera', (bool)preg_match($filter, paket('frigate/Door-Cam/person', '1')), true);
check('Filter trifft maskierte Schrägstriche', (bool)preg_match($filter, '{"Topic":"frigate\/Door-Cam\/person"}'), true);
check('Filter trifft Zone', (bool)preg_match($filter, paket('frigate/einfahrt/car', '1')), true);
check('Filter trifft events', (bool)preg_match($filter, paket('frigate/events', '{}')), true);
check('Filter lässt Zigbee durch nicht', (bool)preg_match($filter, paket('zigbee2mqtt/Toaster', '{"state":"ON"}')), false);
check('Sperrfilter', (bool)preg_match('/' . FrigateHubTopics::sperrfilter() . '/', paket('frigate/available', 'online')), false);

$event = ['label' => 'person', 'sub_label' => ['Dietmar', 0.91], 'top_score' => 0.874, 'start_time' => mktime(18, 5, 12, 10, 28, 2026),
    'current_zones' => ['einfahrt'], 'entered_zones' => ['einfahrt', 'weg']];
check('Event-Text', FrigateHubTopics::eventText($event), '28.10.2026 18:05:12 – Person „Dietmar“ (87 %) in einfahrt, weg');
$review = ['start_time' => mktime(18, 5, 12, 10, 28, 2026), 'data' => ['objects' => ['person-verified', 'car'], 'zones' => ['einfahrt'], 'audio' => [], 'sub_labels' => []]];
check('Review-Text', FrigateHubTopics::reviewText($review), '28.10.2026 18:05:12 – Person, Auto in einfahrt');

/* =================================================================== Modul */

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_INACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$k = new TestKamera(1);
$k->Create();
$k->ApplyChanges();
check('ohne Kamera: Status 104', $k->GetStatus(), 104);
check('ohne Kamera: Sperrfilter', $k->receiveFilter, FrigateHubTopics::sperrfilter());
check('feste Variablen da', isset($k->variables['Verfuegbar'], $k->variables['Alarm'], $k->variables['LetztesObjekt']), true);
$k->ReceiveData(paket('frigate/Door-Cam/person', '2'));
check('ohne Kamera nichts angelegt', isset($k->variables['Objekt_person']), false);

$k->props(['Camera' => 'Door-Cam']);
$k->ApplyChanges();
check('mit Kamera: aktiv', $k->GetStatus(), 102);
check('Filter gesetzt', $k->receiveFilter, FrigateHubTopics::empfangsfilter('Door-Cam', []));

// Die echten 30 Topics vom 28.10.2026 (Werte typisch gesetzt)
$echt = [
    'frigate/Door-Cam/all' => '0', 'frigate/Door-Cam/all/active' => '0', 'frigate/Door-Cam/audio/state' => 'OFF',
    'frigate/Door-Cam/audio_transcription/state' => 'OFF', 'frigate/Door-Cam/birdseye/state' => 'ON',
    'frigate/Door-Cam/birdseye_mode/state' => 'OBJECTS', 'frigate/Door-Cam/detect/state' => 'ON',
    'frigate/Door-Cam/enabled/state' => 'ON', 'frigate/Door-Cam/improve_contrast/state' => 'ON',
    'frigate/Door-Cam/motion' => 'OFF', 'frigate/Door-Cam/motion/state' => 'ON',
    'frigate/Door-Cam/motion_contour_area/state' => '10',
    'frigate/Door-Cam/motion_mask/motion_mask_1/state' => 'ON', 'frigate/Door-Cam/motion_mask/motion_mask_2/state' => 'ON',
    'frigate/Door-Cam/motion_threshold/state' => '30', 'frigate/Door-Cam/object_descriptions/state' => 'OFF',
    'frigate/Door-Cam/object_mask/object_mask_1/state' => 'ON', 'frigate/Door-Cam/person' => '1',
    'frigate/Door-Cam/person/active' => '1', 'frigate/Door-Cam/ptz_autotracker/state' => 'OFF',
    'frigate/Door-Cam/recordings/state' => 'ON', 'frigate/Door-Cam/review_alerts/state' => 'ON',
    'frigate/Door-Cam/review_descriptions/state' => 'OFF', 'frigate/Door-Cam/review_detections/state' => 'ON',
    'frigate/Door-Cam/snapshots/state' => 'ON', 'frigate/Door-Cam/status/detect' => 'online',
    'frigate/available' => 'online', 'frigate/model_state' => '{}', 'frigate/profile/state' => 'none', 'frigate/stats' => '{}',
];
foreach ($echt as $t => $p) {
    $k->ReceiveData(paket($t, $p));
}
check('Frigate erreichbar', $k->GetValue('Verfuegbar'), true);
check('Kamera online', $k->GetValue('Online'), true);
check('Personen = 1', $k->GetValue('Objekt_person'), 1);
check('Personen-Name', $k->variables['Objekt_person']['caption'], 'Personen');
check('Objekte gesamt', $k->variables['Objekt_all']['caption'], 'Objekte gesamt');
check('kein Zähler für active', isset($k->variables['Objekt_active']), false);
check('Bewegung aus', $k->GetValue('Bewegung'), false);
check('Schalter Erkennung an', $k->GetValue('Schalter_detect'), true);
check('Schalter Aufnahme schaltbar', isset($k->enabledActions['Schalter_recordings']), true);
check('Schalter Bewegungserkennung (motion/state)', $k->GetValue('Schalter_motion'), true);
$maske = FrigateHubTopics::ident('Schalter', 'motion_mask/motion_mask_1');
check('Maske als Schalter', $k->variables[$maske]['caption'] ?? null, 'Bewegungsmaske motion_mask_1');
check('birdseye_mode nicht als Schalter', count(array_filter(array_keys($k->variables), fn ($i) => strpos($i, 'birdseye_mode') !== false)), 0);
check('Schwellwert nicht als Schalter', count(array_filter(array_keys($k->variables), fn ($i) => strpos($i, 'threshold') !== false)), 0);
check('Status/Profil nicht als Objekt', isset($k->variables['Objekt_status']) || isset($k->variables['Objekt_profile']), false);
$schalter = array_filter(array_keys($k->variables), fn ($i) => strpos($i, 'Schalter_') === 0);
check('Anzahl Schalter aus echten Topics', count($schalter), 17);

// Positionen: Schalter, Objekte, Zonen getrennt
check('Position Schalter', $k->variables['Schalter_detect']['position'], 100);
check('Position Objekt', $k->variables['Objekt_person']['position'], 200);

// Pegel ändert sich → Wert folgt, Variable wird nicht neu beschrieben
$k->variables['Objekt_person']['caption'] = 'Leute an der Tür';
$k->ReceiveData(paket('frigate/Door-Cam/person', '3'));
check('Zähler folgt', $k->GetValue('Objekt_person'), 3);
check('Umbenennung bleibt', $k->variables['Objekt_person']['caption'], 'Leute an der Tür');

// Prüfstatus
$k->ReceiveData(paket('frigate/Door-Cam/review_status', 'DETECTION'));
check('Erkennung an', $k->GetValue('Erkennung'), true);
check('Alarm aus bei DETECTION', $k->GetValue('Alarm'), false);
$k->ReceiveData(paket('frigate/Door-Cam/review_status', 'ALERT'));
check('Alarm an', $k->GetValue('Alarm'), true);
$k->ReceiveData(paket('frigate/Door-Cam/review_status', 'NONE'));
check('Alarm wieder aus', $k->GetValue('Alarm') || $k->GetValue('Erkennung'), false);

// Bewegung
$k->ReceiveData(paket('frigate/Door-Cam/motion', 'ON'));
check('Bewegung an', $k->GetValue('Bewegung'), true);

// Fremde Kamera, fremde Zone
$k->ReceiveData(paket('frigate/Garten/person', '5'));
check('fremde Kamera ändert nichts', $k->GetValue('Objekt_person'), 3);
$k->ReceiveData(paket('frigate/einfahrt/car', '1'));
check('unbekannte Zone ignoriert', isset($k->variables[FrigateHubTopics::ident('Zone', 'einfahrt', 'car')]), false);

// Event lernt Zone, danach kommen Zonen-Zähler durch
$ev = ['type' => 'new', 'before' => [], 'after' => ['id' => '1730135112.1-abc', 'camera' => 'Door-Cam', 'label' => 'car',
    'top_score' => 0.81, 'start_time' => mktime(18, 5, 12, 10, 28, 2026), 'current_zones' => ['einfahrt'], 'entered_zones' => ['einfahrt']]];
$k->ReceiveData(paket('frigate/events', (string)json_encode($ev)));
check('Letztes Objekt', $k->GetValue('LetztesObjekt'), '28.10.2026 18:05:12 – Auto (81 %) in einfahrt');
check('Event-ID gemerkt', $k->ReadAttributeString('LetzteEventId'), '1730135112.1-abc');
check('Zone gelernt', json_decode($k->ReadAttributeString('Zonen'), true), ['einfahrt']);
check('Filter um Zone erweitert', $k->receiveFilter, FrigateHubTopics::empfangsfilter('Door-Cam', ['einfahrt']));
$k->ReceiveData(paket('frigate/einfahrt/car', '1'));
$zid = FrigateHubTopics::ident('Zone', 'einfahrt', 'car');
check('Zonen-Zähler', $k->GetValue($zid), 1);
check('Zonen-Name', $k->variables[$zid]['caption'], 'einfahrt: Autos');
check('Position Zone', $k->variables[$zid]['position'], 300);

// Event einer anderen Kamera
$ev2 = $ev;
$ev2['after']['camera'] = 'Garten';
$ev2['after']['current_zones'] = ['beet'];
$k->ReceiveData(paket('frigate/events', (string)json_encode($ev2)));
check('fremdes Event lernt keine Zone', json_decode($k->ReadAttributeString('Zonen'), true), ['einfahrt']);

// Update-Events überschreiben den Text nicht
$ev3 = $ev;
$ev3['type'] = 'update';
$ev3['after']['label'] = 'dog';
$k->ReceiveData(paket('frigate/events', (string)json_encode($ev3)));
check('update ändert Letztes Objekt nicht', $k->GetValue('LetztesObjekt'), '28.10.2026 18:05:12 – Auto (81 %) in einfahrt');

// Reviews
$rv = ['type' => 'new', 'before' => [], 'after' => ['id' => 'r1', 'camera' => 'Door-Cam', 'severity' => 'alert',
    'start_time' => mktime(18, 6, 0, 10, 28, 2026), 'end_time' => null,
    'data' => ['objects' => ['person'], 'zones' => ['haustuer'], 'audio' => [], 'sub_labels' => []]]];
$k->ReceiveData(paket('frigate/reviews', (string)json_encode($rv)));
check('Letzter Alarm', $k->GetValue('LetzterAlarm'), '28.10.2026 18:06:00 – Person in haustuer');
check('Review lernt Zone', json_decode($k->ReadAttributeString('Zonen'), true), ['einfahrt', 'haustuer']);
$rv['after']['severity'] = 'detection';
$rv['after']['data']['objects'] = ['cat'];
$k->ReceiveData(paket('frigate/reviews', (string)json_encode($rv)));
check('Letzte Erkennung', $k->GetValue('LetzteErkennung'), '28.10.2026 18:06:00 – Katze in haustuer');
check('Alarmtext bleibt', $k->GetValue('LetzterAlarm'), '28.10.2026 18:06:00 – Person in haustuer');

// Geräusch
$k->ReceiveData(paket('frigate/Door-Cam/audio/bark', 'ON'));
check('Geräusch Bellen', $k->GetValue(FrigateHubTopics::ident('Geraeusch', 'bark')), true);

/* =================================================================== Schalten */

// Ohne aktive MQTT-Instanz: nichts senden, sichtbar loggen, Wert unverändert
IPSTestState::$sentPackets = [];
$k->RequestAction('Schalter_detect', false);
check('ohne Eltern nichts gesendet', count(IPSTestState::$sentPackets), 0);
check('Fehler geloggt', count(IPSTestState::$logMessages) > 0, true);
check('Wert unverändert', $k->GetValue('Schalter_detect'), true);

IPSTestState::$instances[1]['ConnectionID'] = 2;
IPSTestState::$instances[2] = ['InstanceID' => 2, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{MQTT}']];
$k->RequestAction('Schalter_detect', false);
$gesendet = IPSTestState::$sentPackets[0] ?? [];
check('Set-Topic gesendet', $gesendet['Topic'] ?? null, 'frigate/Door-Cam/detect/set');
check('Payload OFF', $gesendet['Payload'] ?? null, 'OFF');
check('nicht retained', $gesendet['Retain'] ?? null, false);
check('DataID senden', $gesendet['DataID'] ?? null, '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}');
check('Variable wartet auf Bestätigung', $k->GetValue('Schalter_detect'), true);
$k->ReceiveData(paket('frigate/Door-Cam/detect/state', 'OFF'));
check('Bestätigung übernommen', $k->GetValue('Schalter_detect'), false);

IPSTestState::$sentPackets = [];
$k->RequestAction($maske, false);
check('Masken-Set-Topic', IPSTestState::$sentPackets[0]['Topic'] ?? null, 'frigate/Door-Cam/motion_mask/motion_mask_1/set');

$fehlerVorher = $fehler;
try {
    $k->RequestAction('Objekt_person', 1);
    check('Nicht-Schalter wirft', 'keine Ausnahme', 'Ausnahme');
} catch (Exception $e) {
    check('Nicht-Schalter wirft', true, true);
}

// Nach Modul-Neuinstallation (Attribut leer): einfache Schalter trotzdem bedienbar
$k->WriteAttributeString('Schalter', '{}');
IPSTestState::$sentPackets = [];
$k->RequestAction('Schalter_recordings', false);
check('Rückfall einfacher Schlüssel', IPSTestState::$sentPackets[0]['Topic'] ?? null, 'frigate/Door-Cam/recordings/set');

check('ZustandAnfordern sendet onConnect', strpos($k->ZustandAnfordern(), '✅') === 0 && (end(IPSTestState::$sentPackets)['Topic'] ?? '') === 'frigate/onConnect', true);
check('ZonenVergessen', strpos($k->ZonenVergessen(), '✅ 2 Zone(n)') === 0 && $k->ReadAttributeString('Zonen') === '[]', true);

/* =================================================================== Formular */

$form = $k->form();
$alle = labels($form['elements']);
check('Zweck-Panel oben', $form['elements'][0]['name'] ?? null, 'PurposeIntroPanel');
check('News-Panel danach', $form['elements'][1]['name'] ?? null, 'NewsPanel');
check('Lizenz-Panel unten', end($form['elements'])['caption'] ?? null, '🧡  Über dieses Modul');
check('Empfangsstatus live', count(array_filter($alle, fn ($l) => strpos($l, '✅ Zuletzt Nachricht von Frigate') === 0)), 1);
check('Topics gelistet', count(array_filter($alle, fn ($l) => strpos($l, 'frigate/Door-Cam/detect/state') !== false)), 1);
check('Status 104 beschriftet', in_array(104, array_column($form['status'], 'code'), true), true);
$json = json_encode($form);
check('Link-Buttons mit link:true', substr_count($json, '"link":true') >= 3, true);
check('kein IPS_LogMessage im Modul', strpos((string)file_get_contents(__DIR__ . '/../FrigateHubKamera/module.php'), 'IPS_LogMessage'), false);

// Darstellungen: Wertanzeige-OPTIONS nur mit erlaubten Schlüsseln (SUITE.md Stolperstein 9)
$erlaubt = ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue'];
$schluesselFehler = 0;
foreach ($k->variables as $ident => $v) {
    $p = $v['presentation'];
    if (($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION && isset($p['OPTIONS'])) {
        if ($v['type'] !== VARIABLETYPE_BOOLEAN && $v['type'] !== VARIABLETYPE_STRING) {
            $schluesselFehler++;
        }
        foreach (json_decode($p['OPTIONS'], true) as $o) {
            $schluesselFehler += count(array_diff(array_keys($o), $erlaubt)) + count(array_diff($erlaubt, array_keys($o)));
        }
    }
    if (($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION && !isset($k->enabledActions[$ident])) {
        $schluesselFehler++;
    }
}
check('Darstellungs-Schlüssel', $schluesselFehler, 0);

$k->AckPurposeIntro();
$k->AckNews();
$form = $k->form();
check('nach Bestätigen Zweck weg', ($form['elements'][0]['caption'] ?? '') === '📖  Dokumentation & Hilfe', true);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
