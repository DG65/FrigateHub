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
    /** Antworten der Frigate-API je Pfad (Array = JSON, String = roh); fehlt = nicht erreichbar. */
    public static array $api = [];
    public static array $abgerufen = [];

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        self::$abgerufen[] = $pfad;
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }

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

    public function eintraege(): array
    {
        return (array)json_decode($this->ReadAttributeString('Ereignisse'), true);
    }
}

/** Alle Labels eines Formulars rekursiv einsammeln. */
final class TestPush
{
    public static $ergebnis = true;
}

function VISU_PostNotificationEx(int $id, string $titel, string $text, string $icon, string $ton, int $ziel)
{
    $GLOBALS['gepusht'][] = ['VISU', $id, $titel, $text, $icon, $ton, $ziel];
    return TestPush::$ergebnis ? 4711 : false;
}

function WFC_PushNotification(int $id, string $titel, string $text, string $ton, int $ziel): bool
{
    $GLOBALS['gepusht'][] = ['WFC', $id, $titel, $text, $ton, $ziel];
    return TestPush::$ergebnis;
}

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
check('Masken-Ident ohne Doppelung', FrigateHubTopics::schalterIdent('motion_mask/motion_mask_1'), 'Schalter_motion_mask_1');
check('Masken-Name', FrigateHubTopics::schalterName('object_mask/object_mask_1'), 'Objektmaske 1');
check('Zonen-Schalter', [FrigateHubTopics::schalterIdent('zone/einfahrt'), FrigateHubTopics::schalterName('zone/einfahrt')], ['Schalter_zone_einfahrt', 'Zone einfahrt']);
check('einfacher Schalter-Ident', FrigateHubTopics::schalterIdent('detect'), 'Schalter_detect');
check('Bildvorschau JPEG', FrigateHubTopics::kurzInhalt('ignorieren', "\xFF\xD8\xFF\xE0\x00\x10JFIF"), '(Bild, 10 Bytes)');
check('Bildvorschau wie live (UTF-8-umkodiert)', FrigateHubTopics::kurzInhalt('ignorieren', mb_convert_encoding("\xFF\xD8\xFF\xE0\x00\x10JFIF", 'UTF-8', 'ISO-8859-1')), '(Bild, 10 Bytes)');
check('Binärdaten', FrigateHubTopics::kurzInhalt('objekt', "\x00\x81\xFE"), '(Binärdaten, 3 Bytes)');
check('Text gekürzt', FrigateHubTopics::kurzInhalt('objekt', str_repeat('ä', 50)), str_repeat('ä', 40));
check('JSON-Hinweis', FrigateHubTopics::kurzInhalt('events', '{"a":1}'), '(JSON, 7 Zeichen)');
check('Schnappschuss-Topic', FrigateHubTopics::deuten('frigate/Door-Cam/person/snapshot', 'frigate', 'Door-Cam', $z), ['art' => 'schnappschuss', 'label' => 'person']);
$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00" . implode('', array_map('chr', range(0, 255))) . "\xFF\xD9";
check('Bild roh', FrigateHubTopics::bildBytes($jpeg), $jpeg);
check('Bild wie live (UTF-8-umkodiert) verlustfrei zurück', FrigateHubTopics::bildBytes(mb_convert_encoding($jpeg, 'UTF-8', 'ISO-8859-1')), $jpeg);
check('kein Bild', FrigateHubTopics::bildBytes('{"a":1}'), null);
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
$manuell = ['start_time' => mktime(20, 9, 15, 10, 28, 2026), 'data' => ['objects' => ['on_demand'], 'zones' => [], 'audio' => [], 'sub_labels' => []]];
check('manueller Alarm', FrigateHubTopics::reviewText($manuell), '28.10.2026 20:09:15 – manuell ausgelöst');

// /api/config einer echten Frigate-0.18.2-Kamera (28.10.2026, gekürzt auf die ausgewerteten Felder)
$kameraConfig = [
    'name' => 'Door-Cam', 'enabled' => true,
    'audio' => ['enabled' => false], 'audio_transcription' => ['enabled' => false, 'live_enabled' => false],
    'birdseye' => ['enabled' => true, 'mode' => 'objects'], 'detect' => ['enabled' => true, 'fps' => 5],
    'motion' => ['enabled' => true, 'threshold' => 50, 'improve_contrast' => true, 'mask' => [
        'motion_mask_1' => ['friendly_name' => 'Motion Mask 1', 'enabled' => true],
        'motion_mask_2' => ['friendly_name' => 'Motion Mask 2', 'enabled' => false],
    ]],
    'objects' => ['track' => ['person'], 'mask' => ['object_mask_1' => ['friendly_name' => 'Object Mask 1', 'enabled' => true]],
        'genai' => ['enabled' => false]],
    'record' => ['enabled' => true], 'snapshots' => ['enabled' => true],
    'review' => ['alerts' => ['enabled' => true], 'detections' => ['enabled' => false], 'genai' => ['enabled' => false]],
    'onvif' => ['host' => '192.0.2.19', 'autotracking' => ['enabled' => false]],
    'notifications' => ['enabled' => false],
    'zones' => ['einfahrt' => ['friendly_name' => 'Einfahrt', 'enabled' => true]],
    'face_recognition' => ['enabled' => true], 'lpr' => ['enabled' => true],
];
$ausConfig = FrigateHubTopics::schalterAusConfig($kameraConfig);
check('Config: 18 Schalter (15 + 2 Masken + 1 Objektmaske + 1 Zone − notifications)', count($ausConfig), 18);
check('Config: Kontrast', $ausConfig['improve_contrast'] ?? null, true);
check('Config: Erkennungen melden aus', $ausConfig['review_detections'] ?? null, false);
check('Config: Maske 2 aus', $ausConfig['motion_mask/motion_mask_2'] ?? null, false);
check('Config: Zone', $ausConfig['zone/einfahrt'] ?? null, true);
check('Config: kein notifications', isset($ausConfig['notifications']), false);
check('Config: keine Gesichtserkennung als Schalter', isset($ausConfig['face_recognition']), false);
check('Config: Zonen', FrigateHubTopics::zonenAusConfig($kameraConfig), ['einfahrt']);
check('Config: leere Kamera', FrigateHubTopics::schalterAusConfig([]), []);

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
$maske = 'Schalter_motion_mask_1';
check('Maske als Schalter', $k->variables[$maske]['caption'] ?? null, 'Bewegungsmaske 1');
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

check('Masken-Ident ohne Attribut nicht geraten', (function () use ($k, $maske) {
    try {
        $k->RequestAction($maske, true);
        return 'gesendet';
    } catch (Exception $e) {
        return 'Ausnahme';
    }
})(), 'Ausnahme');

check('ohne Adresse kein Abgleich-Timer', $k->timers['Abgleich']['interval'] ?? null, 0);
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
// Live-Form: jedes JPEG-Byte als Zeichen U+00XX in UTF-8 (Symcons MQTT-Server, 28.10.2026)
$k->ReceiveData(paket('frigate/Door-Cam/person/snapshot', mb_convert_encoding("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96), 'UTF-8', 'ISO-8859-1')));
$alle = labels($k->form()['elements']);
check('Schnappschuss im Panel als Bild', count(array_filter($alle, fn ($l) => strpos($l, 'person/snapshot  =  (Bild, 100 Bytes)') !== false)), 1);
$mid = IPS_GetObjectIDByIdent('Schnappschuss', 1);
check('Medienobjekt angelegt', $mid !== false && IPSTestState::$media[$mid]['type'] === MEDIATYPE_IMAGE, true);
check('Medium: Inhalt = JPEG-Bytes', base64_decode((string)IPSTestState::$media[$mid]['content']), "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 96));
check('Medium: gecacht, eigene Datei', [IPSTestState::$media[$mid]['cached'], IPSTestState::$media[$mid]['file']], [true, 'media/FrigateHub_1.jpg']);
check('Medium: Name/Position', [IPSTestState::$media[$mid]['name'], IPSTestState::$media[$mid]['pos']], ['Schnappschuss', 9]);
$k->ReceiveData(paket('frigate/Door-Cam/car/snapshot', mb_convert_encoding($jpeg, 'UTF-8', 'ISO-8859-1')));
check('zweites Bild: dasselbe Medium', [count(IPSTestState::$media), base64_decode((string)IPSTestState::$media[$mid]['content'])], [1, $jpeg]);
check('Bildstatus nennt Objekt', strpos(labels($k->form()['elements'])[array_key_first(array_filter(labels($k->form()['elements']), fn ($l) => strpos($l, '✅ Zuletzt: Schnappschuss') === 0))] ?? '', 'Schnappschuss Auto (Ausschnitt) vom') !== false, true);
$k->ReceiveData(paket('frigate/Door-Cam/dog/snapshot', 'kaputt'));
check('kein JPEG → Medium unverändert', base64_decode((string)IPSTestState::$media[$mid]['content']), $jpeg);
check('BildHolen ohne Adresse', strpos($k->BildHolen(), 'ℹ️') === 0, true);
check('Abgleich-Hinweis ohne Adresse', count(array_filter($alle, fn ($l) => strpos($l, 'ℹ️ Kein HTTP-Abgleich') === 0)), 1);
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

/* =================================================================== HTTP-Abgleich */

IPSTestState::reset();
IPSTestState::$instances[5] = ['InstanceID' => 5, 'ConnectionID' => 0, 'InstanceStatus' => IS_INACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$h = new TestKamera(5);
$h->Create();
$h->props(['Camera' => 'Door-Cam', 'ApiUrl' => 'http://127.0.0.1:5000/']);
$h->ApplyChanges();
check('mit Adresse: Abgleich geplant', $h->timers['Abgleich']['interval'], 2000);
check('Timer ruft ZustandAnfordern', $h->timers['Abgleich']['script'], 'FRIG_ZustandAnfordern($_IPS[\'TARGET\']);');

TestKamera::$api = [];
$text = $h->ZustandAnfordern();
check('nicht erreichbar → ⛔', strpos($text, '⛔') === 0, true);
check('nicht erreichbar → Warnung im Log', count(IPSTestState::$logMessages) > 0, true);
check('Timer nach Abgleich aus', $h->timers['Abgleich']['interval'], 0);
check('nicht erreichbar: keine Schalter', count(array_filter(array_keys($h->variables), fn ($i) => strpos($i, 'Schalter_') === 0)), 0);

TestKamera::$api = ['/api/config' => ['cameras' => ['Garten' => $kameraConfig]]];
check('unbekannte Kamera nennt vorhandene', $h->ZustandAnfordern(), '⛔ Frigate kennt keine Kamera „Door-Cam". Vorhanden: Garten.');

$livebild = "\xFF\xD8\xFF\xDBLIVE\xFF\xD9";
TestKamera::$api = [
    '/api/config' => ['cameras' => ['Door-Cam' => $kameraConfig]],
    '/api/stats'  => ['cameras' => ['Door-Cam' => ['camera_fps' => 5.1, 'detection_fps' => 0.3]], 'service' => ['uptime' => 43]],
    '/api/Door-Cam/latest.jpg?height=720&h=720' => $livebild,
];
TestKamera::$abgerufen = [];
$text = $h->ZustandAnfordern();
check('Abgleich ✅', strpos($text, '✅ Abgleich am ') === 0 && strpos($text, '18 Schalter, 1 Zone(n), Kamera liefert Bilder') !== false, true);
check('Pfade abgerufen (Livebild, weil noch kein Medium; Ereignisse, weil Leiste leer)', TestKamera::$abgerufen, ['/api/config', '/api/go2rtc/streams', '/api/stats', '/api/Door-Cam/latest.jpg?height=720&h=720', '/api/review?cameras=Door-Cam&limit=8']);
$hm = IPS_GetObjectIDByIdent('Schnappschuss', 5);
check('Abgleich legt Medium mit Livebild an', base64_decode((string)(IPSTestState::$media[$hm]['content'] ?? '')), $livebild);
// MQTT-Schnappschuss mit Adresse: Vollbild über die API statt Ausschnitt
$vollbild = "\xFF\xD8\xFF\xE0VOLL-1280x720\xFF\xD9";
TestKamera::$api['/api/Door-Cam/person/snapshot.jpg'] = $vollbild;
TestKamera::$abgerufen = [];
$h->ReceiveData(paket('frigate/Door-Cam/person/snapshot', mb_convert_encoding($jpeg, 'UTF-8', 'ISO-8859-1')));
check('Vollbild abgerufen', TestKamera::$abgerufen, ['/api/Door-Cam/person/snapshot.jpg']);
check('Vollbild im Medium', base64_decode((string)IPSTestState::$media[$hm]['content']), $vollbild);
check('Vollbild-Status ohne „Ausschnitt"', strpos($h->ReadAttributeString('Bildstatus'), 'Schnappschuss Person vom') === 0, true);
// API liefert kein Bild → MQTT-Ausschnitt, sichtbar markiert
TestKamera::$api['/api/Door-Cam/person/snapshot.jpg'] = '<html>404</html>';
$h->ReceiveData(paket('frigate/Door-Cam/person/snapshot', mb_convert_encoding($jpeg, 'UTF-8', 'ISO-8859-1')));
check('Rückfall auf Ausschnitt', base64_decode((string)IPSTestState::$media[$hm]['content']), $jpeg);
check('Rückfall im Status', strpos($h->ReadAttributeString('Bildstatus'), '(Ausschnitt, Vollbild nicht erreichbar)') !== false, true);
unset(TestKamera::$api['/api/Door-Cam/person/snapshot.jpg']);
// Ein Schnappschuss einer Erkennung darf der nächste Abgleich nicht mit dem Livebild überschreiben
TestKamera::$abgerufen = [];
$h->ZustandAnfordern();
check('Abgleich holt kein Livebild mehr', in_array('/api/Door-Cam/latest.jpg?height=720&h=720', TestKamera::$abgerufen, true), false);
check('Schnappschuss bleibt', base64_decode((string)IPSTestState::$media[$hm]['content']), $jpeg);
check('BildHolen per Knopf', strpos($h->BildHolen(), '✅ Livebild vom') === 0 && base64_decode((string)IPSTestState::$media[$hm]['content']) === $livebild, true);
check('BildHolen meldet ins Formular', in_array('Bildstatus', array_column($h->formFieldUpdates, 0), true), true);
TestKamera::$api['/api/Door-Cam/latest.jpg?height=720&h=720'] = '<html>404</html>';
check('BildHolen ohne JPEG', $h->BildHolen(), '⛔ Kein Bild von Frigate (Antwort ist kein JPEG).');
TestKamera::$api['/api/Door-Cam/latest.jpg?height=720&h=720'] = $livebild;
check('Abgleich: erreichbar', $h->GetValue('Verfuegbar'), true);
check('Abgleich: online', $h->GetValue('Online'), true);
check('Abgleich: Kontrast', $h->GetValue('Schalter_improve_contrast'), true);
check('Abgleich: Maske 2 aus', $h->GetValue('Schalter_motion_mask_2'), false);
check('Abgleich: Maskenname', $h->variables['Schalter_object_mask_1']['caption'], 'Objektmaske 1');
check('Abgleich: Zone gelernt', json_decode($h->ReadAttributeString('Zonen'), true), ['einfahrt']);
check('Abgleich: Filter mit Zone', $h->receiveFilter, FrigateHubTopics::empfangsfilter('Door-Cam', ['einfahrt']));
check('Abgleich: Formular nachgezogen', in_array('Abgleichstatus', array_column($h->formFieldUpdates, 0), true), true);
check('Abgleich: Text gemerkt', $h->ReadAttributeString('Abgleichstatus'), $text);

// Gleiche Variable, egal ob zuerst MQTT oder Abgleich
$h->ReceiveData(paket('frigate/Door-Cam/motion_mask/motion_mask_2/state', 'ON'));
check('MQTT trifft Abgleich-Variable', $h->GetValue('Schalter_motion_mask_2'), true);
check('keine doppelten Masken', count(array_filter(array_keys($h->variables), fn ($i) => strpos($i, 'motion_mask') !== false)), 2);

// Schalten nach Abgleich (Ident→Schlüssel aus dem Abgleich gemerkt)
IPSTestState::$instances[5]['ConnectionID'] = 6;
IPSTestState::$instances[6] = ['InstanceID' => 6, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{MQTT}']];
IPSTestState::$sentPackets = [];
$h->RequestAction('Schalter_zone_einfahrt', false);
check('Zone schalten nach Abgleich', IPSTestState::$sentPackets[0]['Topic'] ?? null, 'frigate/Door-Cam/zone/einfahrt/set');

// Schonfrist: API hinkt MQTT hinterher (live 28.10.2026) — frisch bestätigter Schalter bleibt
$h->ReceiveData(paket('frigate/Door-Cam/improve_contrast/state', 'OFF'));
$text = $h->ZustandAnfordern();
check('Schonfrist: MQTT-Stand bleibt', $h->GetValue('Schalter_improve_contrast'), false);
check('Schonfrist im Text', strpos($text, 'davon 3 gerade erst geschaltet') !== false, true);
$h->RequestAction('Schalter_detect', false);
check('Schonfrist nach eigenem Schaltbefehl', strpos($h->ZustandAnfordern(), 'davon 4 gerade erst geschaltet') !== false, true);
$h->SetBuffer('Frisch', (string)json_encode(['improve_contrast' => time() - 61]));
$h->ZustandAnfordern();
check('nach Ablauf der Schonfrist gilt die API', $h->GetValue('Schalter_improve_contrast'), true);

// Kamera liefert keine Bilder
TestKamera::$api['/api/stats'] = ['cameras' => ['Door-Cam' => ['camera_fps' => 0.0]]];
check('keine Bilder', strpos($h->ZustandAnfordern(), 'Kamera liefert keine Bilder') !== false && $h->GetValue('Online') === false, true);

// Ungültige Adresse: kein Abgleich, sichtbarer Hinweis
$h->props(['ApiUrl' => 'frigate:5000']);
$h->ApplyChanges();
check('ungültige Adresse: kein Timer', $h->timers['Abgleich']['interval'], 0);
check('ungültige Adresse: Hinweis', count(array_filter(labels($h->form()['elements']), fn ($l) => strpos($l, '⚠️ Die Frigate-Adresse ist ungültig') === 0)), 1);
check('Validierung im Formular', preg_match('#' . str_replace('#', '\\#', array_values(array_filter(
    json_decode(json_encode($h->form()['elements']), true)[3]['items'] ?? [], fn ($e) => ($e['name'] ?? '') === 'ApiUrl'))[0]['validate'] ?? 'x') . '#', 'http://192.168.1.20:5000'), 1);

/* =================================================================== Push bei Alarm */

check('Kürzen: kurz bleibt', FrigateHubTopics::kuerzenBytes('Door-Cam', 32), 'Door-Cam');
$lang = '🚨 ' . str_repeat('ä', 20);
check('Kürzen: höchstens 32 Bytes', strlen(FrigateHubTopics::kuerzenBytes($lang, 32)) <= 32, true);
check('Kürzen: gültiges UTF-8', mb_check_encoding(FrigateHubTopics::kuerzenBytes($lang, 32), 'UTF-8'), true);
check('Kürzen: kein Zeichen zerschnitten', FrigateHubTopics::kuerzenBytes($lang, 32), '🚨 ' . str_repeat('ä', 13));

IPSTestState::$instances[20] = ['InstanceID' => 20, 'Name' => 'Haustür', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
IPSTestState::$instances[30] = ['InstanceID' => 30, 'Name' => 'Dietmar', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}']];
IPSTestState::$instances[31] = ['InstanceID' => 31, 'Name' => 'WebFront', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}']];
IPSTestState::$instances[32] = ['InstanceID' => 32, 'Name' => 'Lampe', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{FREMD}']];
$p = new TestKamera(20);
$p->Create();
$p->props(['Camera' => 'Door-Cam']);
$p->ApplyChanges();
$alarm = function (string $id, string $stufe = 'alert', string $typ = 'new') use ($p): void {
    $p->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => $typ, 'before' => [], 'after' => [
        'id' => $id, 'camera' => 'Door-Cam', 'severity' => $stufe, 'start_time' => mktime(18, 6, 0, 10, 28, 2026),
        'data' => ['objects' => ['person'], 'zones' => ['haustuer'], 'audio' => [], 'sub_labels' => []]]])));
};

$gepusht = [];
$alarm('a1');
check('ohne Ziel kein Push', $gepusht, []);
check('ohne Ziel: Status', in_array('Push ist aus (kein Ziel eingetragen).', labels($p->form()['elements']), true), true);

$p->props(['PushZiele' => json_encode([['Ziel' => 30], ['Ziel' => 31], ['Ziel' => 30]]), 'PushPause' => 0]);
$p->ApplyChanges();
$alarm('a2');
check('Alarm: zwei Ziele, doppeltes nur einmal', count($gepusht), 2);
check('Kachel: Ex-Fassung mit Titel', $gepusht[0] ?? null, ['VISU', 30, '🚨 Haustür', '28.10.2026 18:06:00 – Person in haustuer', 'Alert', 'alarm', 0]);
check('WebFront: WFC mit eigener Instanz', $gepusht[1] ?? null, ['WFC', 31, '🚨 Haustür', '28.10.2026 18:06:00 – Person in haustuer', 'alarm', 20]);
check('Status: gesendet', strpos($p->ReadAttributeString('Pushstatus'), 'an Dietmar, WebFront gesendet') !== false, true);

$gepusht = [];
$alarm('a2', 'alert', 'update');
check('derselbe Alarm nur einmal', $gepusht, []);
$alarm('a3', 'detection');
check('Erkennung: kein Push', $gepusht, []);
$alarm('a3', 'alert', 'update');
check('Aufstieg zum Alarm: Push', count($gepusht), 2);

$p->props(['PushPause' => 60]);
$p->ApplyChanges();
$gepusht = [];
$alarm('a4');
check('Mindestabstand: kein Push', $gepusht, []);
$p->WriteAttributeString('LetzterPush', (string)json_encode(['id' => 'a3', 'zeit' => time() - 61]));
$alarm('a5');
check('nach Mindestabstand: Push', count($gepusht), 2);

$p->props(['PushZiele' => json_encode([['Ziel' => 30], ['Ziel' => 32]]), 'PushTon' => 'bell']);
$p->ApplyChanges();
$gepusht = [];
$text = $p->PushTesten();
check('Probemeldung: Ton', $gepusht[0][5] ?? null, 'bell');
check('Probemeldung: Teilfehler sichtbar', strpos($text, '⚠️') === 0 && strpos($text, 'Lampe (keine Visualisierung)') !== false, true);
check('Probemeldung: Formular aktualisiert', end($p->formFieldUpdates), ['Pushstatus', 'caption', $text]);
TestPush::$ergebnis = false;
$p->props(['PushZiele' => json_encode([['Ziel' => 30]])]);
$p->ApplyChanges();
check('Zustellung scheitert: ⛔', strpos($p->PushTesten(), '⛔') === 0, true);
TestPush::$ergebnis = true;

/* =================================================================== Gesichter, Kennzeichen, KI */

$OBJ = '1793287117.388128-gynf0d';
$REV = '1793287116.988998-qk9qd8';
check('Topic tracked_object_update', FrigateHubTopics::deuten('frigate/tracked_object_update', 'frigate', 'Door-Cam', []), ['art' => 'objektupdate']);
check('Filter enthält tracked_object_update', strpos(FrigateHubTopics::empfangsfilter('Door-Cam', []), 'tracked_object_update') !== false, true);
check('Gesicht', FrigateHubTopics::objektUpdate(['type' => 'face', 'id' => $OBJ, 'name' => 'Dietmar', 'score' => 0.874, 'camera' => 'Door-Cam', 'timestamp' => 1793287120.1]),
    ['art' => 'gesicht', 'id' => $OBJ, 'kamera' => 'Door-Cam', 'text' => 'Dietmar (87 %)', 'zeit' => date('d.m.Y H:i:s', 1793287120)]);
check('Gesicht ohne Namen (unter Schwelle) → nichts', FrigateHubTopics::objektUpdate(['type' => 'face', 'id' => $OBJ, 'name' => null, 'score' => 0.4, 'camera' => 'Door-Cam']), null);
check('Kennzeichen mit Namen', FrigateHubTopics::objektUpdate(['type' => 'lpr', 'id' => $OBJ, 'plate' => 'OG-DG 65', 'name' => 'Tesla', 'score' => 0.91, 'camera' => 'Door-Cam'])['text'], 'OG-DG 65 – Tesla (91 %)');
check('Kennzeichen ohne Namen', FrigateHubTopics::objektUpdate(['type' => 'lpr', 'id' => $OBJ, 'plate' => 'B-X 1', 'name' => null, 'camera' => 'c'])['text'], 'B-X 1');
check('Klassifizierung', FrigateHubTopics::objektUpdate(['type' => 'classification', 'id' => $OBJ, 'sub_label' => 'Paketbote', 'model' => 'kleidung', 'score' => 0.8, 'camera' => 'c'])['text'], 'Paketbote [kleidung] (80 %)');
check('Beschreibung ohne Kamera', array_intersect_key((array)FrigateHubTopics::objektUpdate(['type' => 'description', 'id' => $OBJ, 'description' => 'Eine Person klingelt.']), ['kamera' => 1, 'text' => 1]),
    ['kamera' => '', 'text' => 'Eine Person klingelt.']);
check('kaputte ID → nichts', FrigateHubTopics::objektUpdate(['type' => 'face', 'id' => '../x', 'name' => 'A']), null);
$meta = ['title' => 'Person an der Haustür', 'shortSummary' => 'Eine Person geht zur Haustür, klingelt und wartet kurz.', 'scene' => 'lang', 'potential_threat_level' => 1, 'confidence' => 0.8];
check('KI-Einschätzung', FrigateHubTopics::reviewKi(['data' => ['metadata' => $meta]]), ['titel' => 'Person an der Haustür', 'zusammenfassung' => 'Eine Person geht zur Haustür, klingelt und wartet kurz.', 'stufe' => 1]);
check('ohne Einschätzung (metadata null, live 29.10.)', FrigateHubTopics::reviewKi(['data' => ['metadata' => null]]), null);
check('Stufe begrenzt', FrigateHubTopics::reviewKi(['data' => ['metadata' => ['title' => 'x', 'potential_threat_level' => 7]]])['stufe'], 2);

$ki = new TestKamera(21);
IPSTestState::$instances[21] = ['InstanceID' => 21, 'Name' => 'Haustür', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$ki->Create();
$ki->props(['Camera' => 'Door-Cam', 'PushZiele' => json_encode([['Ziel' => 30]]), 'PushPause' => 0]);
$ki->ApplyChanges();
$objekt = fn (array $u) => $ki->ReceiveData(paket('frigate/tracked_object_update', (string)json_encode($u)));
$objekt(['type' => 'face', 'id' => $OBJ, 'name' => 'Dietmar', 'score' => 0.87, 'camera' => 'Garten', 'timestamp' => 1793287120]);
check('Gesicht fremder Kamera ignoriert', isset($ki->variables['ErkanntePerson']), false);
$objekt(['type' => 'face', 'id' => $OBJ, 'name' => 'Dietmar', 'score' => 0.87, 'camera' => 'Door-Cam', 'timestamp' => 1793287120]);
check('Erkannte Person', [$ki->GetValue('ErkanntePerson'), $ki->variables['ErkanntePerson']['position']], [date('d.m.Y H:i:s', 1793287120) . ' – Dietmar (87 %)', 9]);
$objekt(['type' => 'lpr', 'id' => $OBJ, 'plate' => 'OG-DG 65', 'name' => null, 'camera' => 'Door-Cam', 'timestamp' => 1793287121]);
check('Kennzeichen', strpos($ki->GetValue('Kennzeichen'), '– OG-DG 65') !== false, true);
$objekt(['type' => 'description', 'id' => $OBJ, 'description' => 'Eine Person klingelt.']);
check('Beschreibung zu unbekanntem Objekt ignoriert', isset($ki->variables['KIBeschreibung']), false);
$review = function (string $typ, string $stufe, $metadata) use ($ki, $OBJ, $REV): void {
    $ki->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => $typ, 'before' => [], 'after' => [
        'id' => $REV, 'camera' => 'Door-Cam', 'severity' => $stufe, 'start_time' => 1793287116.98,
        'data' => ['objects' => ['person'], 'zones' => ['Eingangsbereich'], 'detections' => [$OBJ], 'sub_labels' => [], 'audio' => [], 'metadata' => $metadata]]])));
};
$gepusht = [];
$review('new', 'detection', null);
$objekt(['type' => 'description', 'id' => $OBJ, 'description' => 'Eine Person klingelt.']);
check('Beschreibung zu eigenem Objekt', strpos($ki->GetValue('KIBeschreibung'), 'Eine Person klingelt.') !== false, true);
check('Erkennung ohne KI: kein Push', $gepusht, []);
$review('end', 'detection', array_merge($meta, ['potential_threat_level' => 0]));
check('KI unauffällig bei Erkennung: kein Push, Variablen gesetzt', [$gepusht, $ki->GetValue('KIStufe'), strpos($ki->GetValue('KIBeschreibung'), 'Person an der Haustür: Eine Person geht') !== false], [[], 0, true]);
check('KI-Stufe als INTERVALS (Integer)', [$ki->variables['KIStufe']['type'], isset($ki->variables['KIStufe']['presentation']['INTERVALS']), isset($ki->variables['KIStufe']['presentation']['OPTIONS'])], [VARIABLETYPE_INTEGER, true, false]);
$review('update', 'detection', $meta);
check('KI verdächtig bei Erkennung: Push', [count($gepusht), $gepusht[0][2] ?? null, $gepusht[0][3] ?? null], [1, '⚠️ Haustür: verdächtig', $meta['shortSummary']]);
$review('update', 'detection', $meta);
check('dasselbe Review nur einmal', count($gepusht), 1);

$REV2 = '1793287200.111111-abc123';
$gepusht = [];
$ki->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => 'new', 'before' => [], 'after' => [
    'id' => $REV2, 'camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => 1793287200, 'data' => ['objects' => ['person'], 'zones' => [], 'detections' => [], 'audio' => [], 'sub_labels' => [], 'metadata' => null]]])));
$ki->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => 'end', 'before' => [], 'after' => [
    'id' => $REV2, 'camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => 1793287200, 'end_time' => 1793287230,
    'data' => ['objects' => ['person'], 'zones' => [], 'detections' => [], 'audio' => [], 'sub_labels' => [], 'metadata' => array_merge($meta, ['potential_threat_level' => 0])]]])));
check('Alarm, dann KI nachgereicht', array_map(fn ($g) => $g[2], $gepusht), ['🚨 Haustür', '🤖 Haustür']);
check('Kachel-Eintrag mit KI', $ki->eintraege()[0]['ki']['titel'] ?? null, 'Person an der Haustür');
$ki->props(['PushKI' => false]);
$ki->ApplyChanges();
$ki->WriteAttributeString('LetzterKiPush', '');
$gepusht = [];
$review('update', 'detection', $meta);
check('PushKI aus: keine KI-Meldung', $gepusht, []);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
