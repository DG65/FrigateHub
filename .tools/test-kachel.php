<?php

declare(strict_types=1);

/**
 * Prüfstand Kachel und WebHook der Frigate-Kamera — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-kachel.php
 *
 * Review- und ID-Formate wie von Dietmars Frigate 0.18.2 am 29.10.2026 geliefert
 * (/api/review, /api/events/<id>/…, /clips/review/thumb-<kamera>-<id>.webp).
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

class TestKachel extends FrigateHubKamera
{
    public static array $api = [];
    /** Antwort für apiDurchreichen je Pfad; fehlt = 404. */
    public static array $frigate = [];
    public static array $durchgereicht = [];
    public array $antworten = [];
    public static string $eingang = '';
    public static array $webrtc = [];
    public static array $webrtcAntwort = ['code' => 201, 'body' => "v=0\r\no=- 1 1 IN IP4 0.0.0.0\r\n"];
    public int $gesendet = 0;

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }

    protected function apiDurchreichen(string $pfad, string $range, int $zeitlimit): array
    {
        self::$durchgereicht[] = [$pfad, $range, $zeitlimit];
        return self::$frigate[$pfad] ?? ['code' => 404, 'body' => '', 'kopf' => []];
    }

    protected function hookEingang(): string
    {
        return self::$eingang;
    }

    protected function apiWebrtc(string $stream, string $angebot): array
    {
        self::$webrtc[] = [$stream, $angebot];
        return self::$webrtcAntwort;
    }

    protected function hookAntwort(int $code, array $kopf, string $inhalt): void
    {
        $this->antworten[] = ['code' => $code, 'kopf' => $kopf, 'inhalt' => $inhalt];
    }

    protected function UpdateVisualizationValue(string $value): void
    {
        $this->gesendet++;
        parent::UpdateVisualizationValue($value);
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }

    public function hook(array $get, array $server = []): array
    {
        $_GET = $get;
        $_SERVER = $server;
        $this->antworten = [];
        $this->ProcessHookData();
        return $this->antworten[0];
    }

    public function daten(): array
    {
        return json_decode(IPSTestState::$visualization, true);
    }
}

$ID = '1793259219.675514-nm2in4';
$EV = '1793259219.675514-v5y8hh';
$review = ['id' => $ID, 'camera' => 'Door-Cam', 'start_time' => 1793259219.675514, 'end_time' => 1793259224, 'severity' => 'alert',
    'thumb_path' => '/media/frigate/clips/review/thumb-Door-Cam-' . $ID . '.webp',
    'data' => ['detections' => [$EV], 'objects' => ['on_demand'], 'verified_objects' => [], 'sub_labels' => [], 'zones' => [], 'audio' => []]];

/* ------------------------------------------------------------- reine Hilfen */

check('ID gültig', FrigateHubTopics::idGueltig($ID), true);
foreach (['', '../../api/config', $ID . '/../x', $ID . '.webp', '1793259219-abc', 'abc.123-x', $ID . "\n"] as $schlecht) {
    check('ID ungültig: ' . json_encode($schlecht), FrigateHubTopics::idGueltig($schlecht), false);
}
$e = FrigateHubTopics::reviewEintrag($review);
check('Eintrag aus echtem Review', $e, ['id' => $ID, 'start' => 1793259219, 'ende' => 1793259224, 'stufe' => 'alarm',
    'text' => 'manuell ausgelöst', 'zonen' => [], 'ereignis' => $EV]);
check('Eintrag: Erkennung, läuft noch', array_intersect_key((array)FrigateHubTopics::reviewEintrag(['id' => '1793259300.1-ab', 'start_time' => 1793259300.1,
    'end_time' => null, 'severity' => 'detection', 'data' => ['objects' => ['person', 'car'], 'zones' => ['einfahrt']]]), ['stufe' => 1, 'ende' => 1, 'text' => 1, 'zonen' => 1]),
    ['ende' => null, 'stufe' => 'erkennung', 'text' => 'Person, Auto', 'zonen' => ['einfahrt']]);
check('Eintrag: kaputte ID', FrigateHubTopics::reviewEintrag(['id' => '../x', 'start_time' => 1]), null);
check('Eintrag: Ereignis-ID geprüft', FrigateHubTopics::reviewEintrag(['id' => $ID, 'start_time' => 1, 'data' => ['detections' => ['../../x']]])['ereignis'], '');
$liste = [];
for ($i = 0; $i < 10; $i++) {
    $liste = FrigateHubTopics::reviewsMerken($liste, ['id' => 'r' . $i, 'start' => 1000 + $i], 8);
}
check('Liste: höchstens 8, neueste zuerst', array_column($liste, 'id'), ['r9', 'r8', 'r7', 'r6', 'r5', 'r4', 'r3', 'r2']);
$liste = FrigateHubTopics::reviewsMerken($liste, ['id' => 'r5', 'start' => 1005, 'ende' => 1010], 8);
check('Liste: gleiche ID ersetzt', [count($liste), $liste[4]['ende'] ?? null], [8, 1010]);

/* ------------------------------------------------------------- Modul */

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
IPSTestState::$instances[90] = ['InstanceID' => 90, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'],
    'Properties' => ['Hooks' => json_encode([['Hook' => '/hook/anderes', 'TargetID' => 5]])]];
$k = new TestKachel(1);
$k->Create();
$k->props(['Camera' => 'Door-Cam']);
$k->ApplyChanges();
$schluessel = $k->ReadAttributeString('HookSchluessel');
check('Schlüssel: 32 Hex-Zeichen', (bool)preg_match('/^[0-9a-f]{32}$/', $schluessel), true);
check('WebHook eingetragen, fremder bleibt', json_decode((string)end(IPSTestState::$setProperties)[2], true),
    [['Hook' => '/hook/anderes', 'TargetID' => 5], ['Hook' => '/hook/frigatehub1', 'TargetID' => 1]]);
$k->ApplyChanges();
check('Schlüssel bleibt beim Übernehmen', $k->ReadAttributeString('HookSchluessel'), $schluessel);
$d = $k->daten();
check('Daten: Hook und Schlüssel', [$d['hook'], $d['k']], ['/hook/frigatehub1', $schluessel]);
check('ohne Adresse: kein Livebild', [$d['api'], $d['live']], [false, 0]);

// Ohne Schlüssel nichts
check('Hook ohne Schlüssel: 403', $k->hook(['bild' => 'live'])['code'], 403);
check('Hook falscher Schlüssel: 403', $k->hook(['k' => str_repeat('0', 32), 'bild' => 'live'])['code'], 403);
check('Hook Schlüssel als Array: 403', $k->hook(['k' => [$schluessel], 'bild' => 'live'])['code'], 403);
check('ohne Adresse: Livebild 404', $k->hook(['k' => $schluessel, 'bild' => 'live'])['code'], 404);
check('ohne Adresse: kein Schnappschuss → 404', $k->hook(['k' => $schluessel, 'bild' => 'schnappschuss'])['code'], 404);

// Schnappschuss per MQTT, dann über den Hook
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x11", 60) . "\xFF\xD9";
$k->ReceiveData(paket('frigate/Door-Cam/person/snapshot', mb_convert_encoding($jpeg, 'UTF-8', 'ISO-8859-1')));
$a = $k->hook(['k' => $schluessel, 'bild' => 'schnappschuss']);
check('Schnappschuss über Hook', [$a['code'], $a['kopf']['Content-Type'], $a['inhalt'] === $jpeg], [200, 'image/jpeg', true]);
check('Daten: Schnappschuss-Text', strpos($k->daten()['schnappschuss'], 'Ausschnitt') !== false, true);
check('Hook ohne Adresse fragt Frigate nie', TestKachel::$durchgereicht, []);

/* ------------------------------------------------------------- mit Frigate-Adresse */

$k->props(['ApiUrl' => 'http://127.0.0.1:5000']);
$k->ApplyChanges();
check('mit Adresse: Livebild alle 2 s', [$k->daten()['api'], $k->daten()['live']], [true, 2]);
$k->props(['KachelLiveTakt' => 600]);
$k->ApplyChanges();
check('Takt höchstens 60 s', $k->daten()['live'], 60);
$k->props(['KachelLiveTakt' => 2]);
$k->ApplyChanges();

TestKachel::$frigate['/api/Door-Cam/latest.jpg?height=480&h=480&bbox=1'] = ['code' => 200, 'body' => 'JPEG', 'kopf' => ['content-type' => 'image/jpeg']];
$a = $k->hook(['k' => $schluessel, 'bild' => 'live']);
check('Livebild mit Rahmen', [$a['code'], $a['inhalt'], $a['kopf']['Cache-Control']], [200, 'JPEG', 'no-store']);
$k->hook(['k' => $schluessel, 'bild' => 'live', 'rahmen' => '0', 'h' => '5000']);
check('ohne Rahmen, Höhe begrenzt', end(TestKachel::$durchgereicht)[0], '/api/Door-Cam/latest.jpg?height=1080&h=1080');
$k->hook(['k' => $schluessel, 'bild' => 'live', 'h' => '-5']);
check('Höhe mindestens 120', end(TestKachel::$durchgereicht)[0], '/api/Door-Cam/latest.jpg?height=120&h=120&bbox=1');
$k->hook(['k' => $schluessel, 'bild' => 'review', 'id' => $ID]);
check('Review-Vorschau', end(TestKachel::$durchgereicht)[0], '/clips/review/thumb-Door-Cam-' . $ID . '.webp');
$k->hook(['k' => $schluessel, 'bild' => 'vorschau', 'id' => $EV]);
check('Objekt-Vorschau', end(TestKachel::$durchgereicht)[0], '/api/events/' . $EV . '/thumbnail.jpg');
$k->hook(['k' => $schluessel, 'bild' => 'ereignis', 'id' => $EV, 'h' => '1080']);
check('Ereignis-Schnappschuss', end(TestKachel::$durchgereicht)[0], '/api/events/' . $EV . '/snapshot.jpg?height=1080&h=1080');

// Keine fremden Pfade
$vorher = count(TestKachel::$durchgereicht);
foreach ([['bild' => 'review', 'id' => '../../api/config'], ['bild' => 'ereignis', 'id' => $EV . '/../../../api/config'],
    ['clip' => '../api/stats'], ['bild' => 'config'], ['pfad' => '/api/config'], []] as $get) {
    check('abgelehnt: ' . json_encode($get), $k->hook(['k' => $schluessel] + $get)['code'], 404);
}
check('abgelehnte Anfragen erreichen Frigate nicht', count(TestKachel::$durchgereicht), $vorher);

// Clip: einmal von Frigate holen, stückweise ausliefern (Symcon gab 1,7 MB leer aus; Frigate kennt kein Range)
foreach (glob(sys_get_temp_dir() . '/frigatehub_1_*.mp4') ?: [] as $f) { unlink($f); }
$mp4 = '';
for ($i = 0; strlen($mp4) < 1258291; $i++) { $mp4 .= sprintf('%08d', $i); }
$mp4 = substr($mp4, 0, 1258291);
TestKachel::$frigate['/api/events/' . $EV . '/clip.mp4'] = ['code' => 200, 'body' => $mp4, 'kopf' => ['content-type' => 'video/mp4']];
$vorher = count(TestKachel::$durchgereicht);
$a = $k->hook(['k' => $schluessel, 'clip' => $EV]);
check('Clip ohne Range: erstes Stück 206', [$a['code'], $a['kopf']['Content-Range'], $a['kopf']['Accept-Ranges'], $a['kopf']['Content-Type'], $a['inhalt'] === substr($mp4, 0, 524288)],
    [206, 'bytes 0-524287/1258291', 'bytes', 'video/mp4', true]);
check('Clip bei Frigate ohne Range, 60 s', array_slice(end(TestKachel::$durchgereicht), 0), ['/api/events/' . $EV . '/clip.mp4', '', 60]);
$a = $k->hook(['k' => $schluessel, 'clip' => $EV], ['HTTP_RANGE' => 'bytes=524288-']);
check('Clip: zweites Stück', [$a['kopf']['Content-Range'], $a['inhalt'] === substr($mp4, 524288, 524288)], ['bytes 524288-1048575/1258291', true]);
$a = $k->hook(['k' => $schluessel, 'clip' => $EV], ['HTTP_RANGE' => 'bytes=1048576-']);
check('Clip: letztes Stück', [$a['kopf']['Content-Range'], $a['inhalt'] === substr($mp4, 1048576)], ['bytes 1048576-1258290/1258291', true]);
$a = $k->hook(['k' => $schluessel, 'clip' => $EV], ['HTTP_RANGE' => 'bytes=0-1']);
check('Clip: Safari-Probe 0-1', [$a['kopf']['Content-Range'], $a['inhalt']], ['bytes 0-1/1258291', '00']);
$a = $k->hook(['k' => $schluessel, 'clip' => $EV], ['HTTP_RANGE' => 'bytes=-8']);
check('Clip: letzte 8 Bytes', $a['inhalt'], substr($mp4, -8));
check('Clip: außerhalb → 416', $k->hook(['k' => $schluessel, 'clip' => $EV], ['HTTP_RANGE' => 'bytes=9999999-'])['code'], 416);
check('Clip nur einmal bei Frigate geholt', count(TestKachel::$durchgereicht) - $vorher, 1);
foreach (glob(sys_get_temp_dir() . '/frigatehub_1_*.mp4') ?: [] as $f) { touch($f, time() - 700); }
$k->hook(['k' => $schluessel, 'clip' => $EV]);
check('alter Clip wird neu geholt', count(TestKachel::$durchgereicht) - $vorher, 2);
TestKachel::$frigate['/api/events/1793259000.1-weg/clip.mp4'] = ['code' => 404, 'body' => '', 'kopf' => []];
check('Clip fehlt bei Frigate → 404', $k->hook(['k' => $schluessel, 'clip' => '1793259000.1-weg'])['code'], 404);
check('Bereich: Unsinn → erstes Stück', FrigateHubTopics::bereich('bytes=abc', 100, 40), [0, 39]);
check('Bereich: rückwärts → ungültig', FrigateHubTopics::bereich('bytes=5-2', 100, 40), null);
check('Bereich: leere Datei', FrigateHubTopics::bereich('', 0, 40), null);
check('Bereich: Ende begrenzt', FrigateHubTopics::bereich('bytes=90-500', 100, 40), [90, 99]);
$k->hook(['k' => $schluessel, 'bild' => 'live']);
check('Bild: kein Range an Frigate', end(TestKachel::$durchgereicht)[1], '');
TestKachel::$frigate['/api/events/' . $EV . '/thumbnail.jpg'] = ['code' => 500, 'body' => 'kaputt', 'kopf' => []];
$a = $k->hook(['k' => $schluessel, 'bild' => 'vorschau', 'id' => $EV]);
check('Frigate-Fehler → 502 ohne Inhalt von Frigate', [$a['code'], strpos($a['inhalt'], 'kaputt')], [502, false]);
TestKachel::$frigate['/api/events/' . $EV . '/thumbnail.jpg'] = ['code' => 0, 'body' => '', 'kopf' => []];
check('Frigate nicht erreichbar → 502', $k->hook(['k' => $schluessel, 'bild' => 'vorschau', 'id' => $EV])['code'], 502);

/* ------------------------------------------------------------- Daten für die Kachel */

$k->gesendet = 0;
$k->ReceiveData(paket('frigate/Door-Cam/person', '2'));
$k->ReceiveData(paket('frigate/Door-Cam/car', '0'));
$k->ReceiveData(paket('frigate/Door-Cam/all', '2'));
check('Zähler: nur > 0, ohne „gesamt"', $k->daten()['zaehler'], [['name' => 'Personen', 'anzahl' => 2]]);
$k->ReceiveData(paket('frigate/Door-Cam/car', '1'));
check('Zähler: Einzahl bei 1', $k->daten()['zaehler'][1] ?? null, ['name' => 'Auto', 'anzahl' => 1]);
$k->ReceiveData(paket('frigate/Door-Cam/car', '0'));
$n = $k->gesendet;
$k->ReceiveData(paket('frigate/Door-Cam/person', '2'));
check('unveränderte Daten werden nicht erneut gesendet', $k->gesendet, $n);
$k->ReceiveData(paket('frigate/Door-Cam/person', '0'));
check('Zähler auf 0 verschwindet', $k->daten()['zaehler'], []);
check('Schalter unbekannt = null', $k->daten()['schalter'], ['detect' => null, 'recordings' => null, 'snapshots' => null]);
$k->ReceiveData(paket('frigate/Door-Cam/detect/state', 'ON'));
$k->ReceiveData(paket('frigate/Door-Cam/recordings/state', 'OFF'));
check('Schalter aus MQTT', $k->daten()['schalter'], ['detect' => true, 'recordings' => false, 'snapshots' => null]);
$k->ReceiveData(paket('frigate/Door-Cam/review_status', 'ALERT'));
check('Alarm in den Daten', [$k->daten()['alarm'], $k->daten()['erkennung']], [true, true]);

// Ereignisse aus MQTT
$k->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => 'new', 'before' => [], 'after' => ['end_time' => null] + $review])));
$ev = $k->daten()['ereignisse'];
check('Ereignis aus MQTT', [count($ev), $ev[0]['id'], $ev[0]['dauer'], $ev[0]['zeit']], [1, $ID, null, date(date('Ymd', 1793259219) === date('Ymd') ? 'H:i' : 'd.m. H:i', 1793259219)]);
$k->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => 'end', 'before' => [], 'after' => $review])));
check('Ende ersetzt den Eintrag', [count($k->daten()['ereignisse']), $k->daten()['ereignisse'][0]['dauer']], [1, 5]);
$fremd = $review;
$fremd['camera'] = 'Garten';
$fremd['id'] = '1793259999.1-zz';
$k->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => 'new', 'before' => [], 'after' => $fremd])));
check('fremde Kamera nicht in der Leiste', count($k->daten()['ereignisse']), 1);
$heute = $review;
$heute['id'] = sprintf('%d.5-heute1', time() - 60);
$heute['start_time'] = time() - 60;
$k->ReceiveData(paket('frigate/reviews', (string)json_encode(['type' => 'new', 'before' => [], 'after' => $heute])));
check('heute nur Uhrzeit', $k->daten()['ereignisse'][0]['zeit'], date('H:i', time() - 60));

// Leere Leiste füllt der Abgleich aus der API, eine volle nicht
$k2 = new TestKachel(2);
IPSTestState::$instances[2] = ['InstanceID' => 2, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$k2->Create();
$k2->props(['Camera' => 'Door-Cam', 'ApiUrl' => 'http://127.0.0.1:5000']);
$k2->ApplyChanges();
TestKachel::$api = ['/api/config' => ['cameras' => ['Door-Cam' => ['enabled' => true]]], '/api/stats' => ['cameras' => ['Door-Cam' => ['camera_fps' => 5.0]]],
    '/api/Door-Cam/latest.jpg?height=720&h=720' => $jpeg, '/api/review?cameras=Door-Cam&limit=8' => [$review, $fremd]];
$k2->ZustandAnfordern();
check('Abgleich füllt die Leiste (nur eigene Kamera)', array_column($k2->daten()['ereignisse'], 'id'), [$ID]);
TestKachel::$api['/api/review?cameras=Door-Cam&limit=8'] = [];
$k2->ZustandAnfordern();
check('volle Leiste bleibt', count($k2->daten()['ereignisse']), 1);

/* ------------------------------------------------------------- Livevideo (WebRTC über go2rtc) */

$k2s = $k2->ReadAttributeString('HookSchluessel');
check('ohne go2rtc-Stream: kein Video', $k2->daten()['video'], false);
check('ohne Stream: WebRTC 404', $k2->hook(['k' => $k2s, 'webrtc' => '1'])['code'], 404);
check('Formular: kein Stream erklärt', strpos((string)json_encode(json_decode($k2->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Kein Livevideo: Frigates go2rtc hat') !== false, true);
TestKachel::$api['/api/config'] = ['cameras' => ['Door-Cam' => ['enabled' => true, 'live' => ['streams' => ['Haupt' => 'tuer_haupt']]]]];
TestKachel::$api['/api/go2rtc/streams'] = ['tuer_haupt' => ['producers' => []], 'andere' => []];
$k2->ZustandAnfordern();
check('Stream aus live.streams erkannt', [$k2->ReadAttributeString('Livestream'), $k2->daten()['video']], ['tuer_haupt', true]);
check('Formular: Livevideo bereit', strpos((string)json_encode(json_decode($k2->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), '✅ Livevideo über go2rtc-Stream „tuer_haupt') !== false, true);
TestKachel::$eingang = 'kein sdp';
check('WebRTC: Unsinn → 400', $k2->hook(['k' => $k2s, 'webrtc' => '1'])['code'], 400);
TestKachel::$eingang = 'v=0' . str_repeat('x', 40000);
check('WebRTC: zu groß → 400', $k2->hook(['k' => $k2s, 'webrtc' => '1'])['code'], 400);
check('ungültige Angebote erreichen go2rtc nicht', TestKachel::$webrtc, []);
TestKachel::$eingang = "v=0\r\no=- 42 2 IN IP4 127.0.0.1\r\n";
$a = $k2->hook(['k' => $k2s, 'webrtc' => '1']);
check('WebRTC: Angebot an den richtigen Stream', TestKachel::$webrtc, [['tuer_haupt', TestKachel::$eingang]]);
check('WebRTC: Antwort durchgereicht', [$a['code'], $a['kopf']['Content-Type'], $a['inhalt']], [200, 'application/sdp', TestKachel::$webrtcAntwort['body']]);
check('WebRTC ohne Schlüssel: 403', $k2->hook(['webrtc' => '1'])['code'], 403);
TestKachel::$webrtcAntwort = ['code' => 404, 'body' => 'stream not found'];
check('go2rtc-Fehler → 502', $k2->hook(['k' => $k2s, 'webrtc' => '1'])['code'], 502);
TestKachel::$webrtcAntwort = ['code' => 200, 'body' => '<html>Login</html>'];
check('keine SDP-Antwort → 502', $k2->hook(['k' => $k2s, 'webrtc' => '1'])['code'], 502);
$k2->props(['KachelVideo' => false]);
$k2->ApplyChanges();
check('Video ausgeschaltet', [$k2->daten()['video'], $k2->hook(['k' => $k2s, 'webrtc' => '1'])['code']], [false, 404]);
TestKachel::$api['/api/go2rtc/streams'] = [];
$k2->props(['KachelVideo' => true]);
$k2->ZustandAnfordern();
check('Stream verschwunden → kein Video', $k2->daten()['video'], false);

/* ------------------------------------------------------------- Eigenständige Seite (IPSView) */

$ks = $k->ReadAttributeString('HookSchluessel');
check('Seite ohne Schlüssel: 403', $k->hook(['seite' => '1'])['code'], 403);
check('Daten ohne Schlüssel: 403', $k->hook(['daten' => '1'])['code'], 403);
check('Schalten ohne Schlüssel: 403', $k->hook(['schalten' => 'Schalter_detect', 'wert' => '0'], ['REQUEST_METHOD' => 'POST'])['code'], 403);
$seite = $k->hook(['seite' => '1', 'k' => $ks]);
check('Seite: 200 HTML ohne Zwischenspeicher', [$seite['code'], $seite['kopf']['Content-Type'], $seite['kopf']['Cache-Control']],
    [200, 'text/html; charset=utf-8', 'no-store']);
check('Seite: Kachel-HTML mit Startdaten und Abfrage', [strpos($seite['inhalt'], '<div class="bild" id="bild">') !== false,
    strpos($seite['inhalt'], 'handleMessage(seiteStand)') !== false, strpos($seite['inhalt'], 'function requestAction(') !== false,
    strpos($seite['inhalt'], 'setInterval(') !== false], [true, true, true, true]);
// Startdaten müssen zeichengleich mit ?daten=1 sein, sonst zeichnet die Seite nach 5 s grundlos neu
preg_match('/var seiteStand=("(?:[^"\\\\]|\\\\.)*");/', $seite['inhalt'], $m);
check('Seite: Startdaten = Abfragedaten', json_decode($m[1] ?? '""'), $k->hook(['daten' => '1', 'k' => $ks])['inhalt']);
$d = json_decode($k->hook(['daten' => '1', 'k' => $ks])['inhalt'], true);
check('Seiten-Daten: eigen, Name, ohne Freigabe nicht bedienbar', [$d['eigen'], $d['name'] !== '', $d['bedienung'], $d['k']], [true, true, false, $ks]);
check('Kachel-Daten: nicht eigen, bedienbar', [$k->daten()['eigen'], $k->daten()['name'], $k->daten()['bedienung']], [false, '', true]);
IPSTestState::$sentPackets = [];
$a = $k->hook(['schalten' => 'Schalter_detect', 'wert' => '0', 'k' => $ks], ['REQUEST_METHOD' => 'POST']);
check('Schalten ohne Freigabe: 403, nichts gesendet', [$a['code'], json_decode($a['inhalt'], true)['ok'], count(IPSTestState::$sentPackets)], [403, false, 0]);
$k->props(['SeiteBedienung' => true]);
check('mit Freigabe: Daten bedienbar', json_decode($k->hook(['daten' => '1', 'k' => $ks])['inhalt'], true)['bedienung'], true);
check('Schalten per GET: 405', $k->hook(['schalten' => 'Schalter_detect', 'wert' => '0', 'k' => $ks], ['REQUEST_METHOD' => 'GET'])['code'], 405);
check('fremder Schalter: 400', $k->hook(['schalten' => 'Schalter_motion', 'wert' => '0', 'k' => $ks], ['REQUEST_METHOD' => 'POST'])['code'], 400);
check('Schalter ohne Variable: 400', $k->hook(['schalten' => 'Schalter_snapshots', 'wert' => '1', 'k' => $ks], ['REQUEST_METHOD' => 'POST'])['code'], 400);
check('ungültiger Wert: 400', $k->hook(['schalten' => 'Schalter_detect', 'wert' => 'true', 'k' => $ks], ['REQUEST_METHOD' => 'POST'])['code'], 400);
check('nichts gesendet', count(IPSTestState::$sentPackets), 0);
$a = $k->hook(['schalten' => 'Schalter_detect', 'wert' => '0', 'k' => $ks], ['REQUEST_METHOD' => 'POST']);
check('ohne MQTT-Instanz: 502 statt scheinbarem Erfolg', [$a['code'], count(IPSTestState::$sentPackets)], [502, 0]);
IPSTestState::$instances[95] = ['InstanceID' => 95, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}']];
IPSTestState::$instances[1]['ConnectionID'] = 95;
$a = $k->hook(['schalten' => 'Schalter_detect', 'wert' => '0', 'k' => $ks], ['REQUEST_METHOD' => 'POST']);
IPSTestState::$instances[1]['ConnectionID'] = 0;
$p = IPSTestState::$sentPackets[0] ?? [];
check('Schalten: 200 und MQTT detect/set OFF', [$a['code'], $p['Topic'] ?? '', $p['Payload'] ?? ''], [200, 'frigate/Door-Cam/detect/set', 'OFF']);
check('Variable folgt erst Frigates Bestätigung', $k->GetValue('Schalter_detect'), true);
$k->props(['SeiteBedienung' => false]);
check('Formular: IPSView-Knopf statt Schlüssel', [strpos((string)$k->GetConfigurationForm(), 'FRIG_SeiteAdresse') !== false,
    strpos($k->SeiteAdresse(), '/hook/frigatehub1?seite=1&k=' . $ks) !== false], [true, true]);

/* ------------------------------------------------------------- Schlüssel, Kachel, Formular */

$text = $k->KachelSchluesselErneuern();
check('neuer Schlüssel', [strpos($text, '✅') === 0, $k->ReadAttributeString('HookSchluessel') !== $schluessel], [true, true]);
check('alter Schlüssel gilt nicht mehr', $k->hook(['k' => $schluessel, 'bild' => 'live'])['code'], 403);
check('Kachel bekommt neuen Schlüssel', $k->daten()['k'], $k->ReadAttributeString('HookSchluessel'));
$kachel = $k->GetVisualizationTile();
check('Kachel: HTML mit Startdaten', [strpos($kachel, '<div class="bild" id="bild">') !== false, strpos($kachel, '<script>handleMessage({') !== false], [true, true]);
$form = json_encode(json_decode($k->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Formular: Kachel-Panel mit Hook', strpos($form, 'Die Bilder laufen über den WebHook /hook/frigatehub1') !== false, true);
check('Formular: kein Schlüssel im Formular', strpos($form, $k->ReadAttributeString('HookSchluessel')), false);

// Symcon-Start: Hook erst bei KR_READY
IPSTestState::$runlevel = 10101;
IPSTestState::$setProperties = [];
$k3 = new TestKachel(3);
IPSTestState::$instances[3] = ['InstanceID' => 3, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$k3->Create();
$k3->ApplyChanges();
check('beim Start: Hook wartet', [IPSTestState::$setProperties, isset(IPSTestState::$registeredMessages[0][IPS_KERNELMESSAGE])], [[], true]);
IPSTestState::$runlevel = KR_READY;
$k3->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
check('KR_READY: Hook eingetragen', count(IPSTestState::$setProperties), 1);

foreach (glob(sys_get_temp_dir() . '/frigatehub_*.mp4') ?: [] as $f) { unlink($f); }

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
