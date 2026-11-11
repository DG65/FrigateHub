<?php

declare(strict_types=1);

/**
 * Prüfstand „Licht bei Erkennung" (0.23.0) — ohne Symcon, ohne Netz, ohne Kamera.
 *
 *   php .tools/test-licht.php
 *
 * ISAPI-Antworten im Format der Annke I91DQ (Firmware V5.7.3, gelesen 12.11.2026).
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

$L = 'FrigateHubLicht';
$t = 1800000000.0;
$ev = fn (string $id, string $label = 'person', array $mehr = []) => $mehr + ['id' => $id, 'label' => $label, 'false_positive' => false, 'end_time' => null, 'current_zones' => ['Einfahrt'], 'stationary' => false];

/* ------------------------------------------------------------- Logik */

$z = $L::beobachten([], 'new', $ev('p1'), $t, ['person'], []);
check('Person → an, in 10 s wieder prüfen', $L::soll($z, $t + 1, 60), ['an' => true, 'pruefen' => 10]);
$z = $L::beobachten($z, 'end', $ev('p1', 'person', ['end_time' => $t + 20]), $t + 20, ['person'], []);
check('Person weg → Nachlauf läuft', $L::soll($z, $t + 30, 60), ['an' => true, 'pruefen' => 50]);
check('Nachlauf vorbei → aus', $L::soll($z, $t + 81, 60), ['an' => false, 'pruefen' => 0]);
check('Nachlauf mindestens 10 s', $L::soll($z, $t + 29, 1)['an'], true);
check('Katze, nur Personen gewählt → nichts', $L::soll($L::beobachten([], 'new', $ev('c1', 'cat'), $t, ['person'], []), $t, 60)['an'], false);
check('Auto gewählt → an', $L::soll($L::beobachten([], 'new', $ev('a1', 'car'), $t, ['person', 'car'], []), $t, 60)['an'], true);
check('geparktes Auto hält nicht an', $L::soll($L::beobachten([], 'update', $ev('a1', 'car', ['stationary' => true]), $t, ['car'], []), $t, 60)['an'], false);
check('stehende Person zählt', $L::soll($L::beobachten([], 'update', $ev('p2', 'person', ['stationary' => true]), $t, ['person'], []), $t, 60)['an'], true);
check('Fehlerkennung zählt nicht', $L::soll($L::beobachten([], 'new', $ev('p3', 'person', ['false_positive' => true]), $t, ['person'], []), $t, 60)['an'], false);
check('Zone passt', $L::soll($L::beobachten([], 'new', $ev('p4'), $t, ['person'], ['Einfahrt']), $t, 60)['an'], true);
check('andere Zone → nichts', $L::soll($L::beobachten([], 'new', $ev('p4'), $t, ['person'], ['Garten']), $t, 60)['an'], false);
$z = $L::beobachten([], 'new', $ev('p5'), $t, ['person'], []);
check('verlorenes Objekt ohne Ende: nach 150 s + Nachlauf aus', [$L::soll($z, $t + 149, 60)['an'], $L::soll($z, $t + 151, 60)['an']], [true, false]);
check('Aufräumen entfernt veraltete', $L::aufraeumen($z, $t + 200)['objekte'], []);

$xml = '<?xml version="1.0" encoding="UTF-8"?><SupplementLight version="2.0" xmlns="http://www.hikvision.com/ver20/XMLSchema"><supplementLightMode>eventIntelligence</supplementLightMode><mixedLightBrightnessRegulatMode>auto</mixedLightBrightnessRegulatMode><highWhiteLightBrightness min="0" max="100">100</highWhiteLightBrightness></SupplementLight>';
check('Modus lesen', $L::modus($xml), 'eventIntelligence');
check('Modus setzen, Rest bleibt', $L::mitModus($xml, 'colorVuWhiteLight'), str_replace('eventIntelligence', 'colorVuWhiteLight', $xml));
check('Modus ohne Feld → null', $L::mitModus('<x/>', 'irLight'), null);
check('Modus mit Sonderzeichen abgelehnt', $L::mitModus($xml, 'a</x>'), null);
$cap = '<SupplementLight><supplementLightMode opt="eventIntelligence,colorVuWhiteLight,irLight,close">eventIntelligence</supplementLightMode></SupplementLight>';
check('Modi aus den Fähigkeiten', $L::modi($cap), ['eventIntelligence', 'colorVuWhiteLight', 'irLight', 'close']);

$yaml = "go2rtc:\n  streams:\n    Door-Cam:\n      - rtsp://root:geheim%2F1@192.168.2.19:554/ufirststream\n    Annke:\n      - rtsp://admin:ab%2Fcd@192.168.2.77:554/Streaming/Channels/101\n      - \"ffmpeg:Annke#audio=opus\"\n    Annke_sub:\n      - rtsp://admin:ab%2Fcd@192.168.2.77:554/Streaming/Channels/102\n\ncameras:\n  Annke:\n    ffmpeg:\n      inputs:\n        - path: rtsp://127.0.0.1:8554/Annke\n";
check('Zugang über go2rtc-Stream, %2F → /', $L::zugangAusFrigate($yaml, ['rtsp://127.0.0.1:8554/Annke', 'rtsp://127.0.0.1:8554/Annke_sub']), ['host' => '192.168.2.77', 'benutzer' => 'admin', 'passwort' => 'ab/cd']);
check('Zugang direkt im Eingang', $L::zugangAusFrigate("  - path: rtsp://admin:x@10.0.0.5:554/s\n", ['rtsp://admin:*****@10.0.0.5:554/s']), ['host' => '10.0.0.5', 'benutzer' => 'admin', 'passwort' => 'x']);
check('nicht die Door-Cam erwischen', $L::zugangAusFrigate($yaml, ['rtsp://127.0.0.1:8554/Annke'])['host'] ?? null, '192.168.2.77');
check('kein Zugang → null', $L::zugangAusFrigate($yaml, ['rtsp://127.0.0.1:8554/Fehlt']), null);

/* ------------------------------------------------------------- Kamera-Instanz */

class TestKamera extends FrigateHubKamera
{
    public static array $api = [];
    public static string $licht = 'eventIntelligence';
    public static array $isapiAufrufe = [];
    public static bool $kameraWeg = false;

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        return self::$api[$pfad] ?? null;
    }

    protected function isapi(string $methode, string $pfad, ?string $inhalt = null): array
    {
        self::$isapiAufrufe[] = [$methode, $pfad, $inhalt === null ? null : FrigateHubLicht::modus($inhalt)];
        if (self::$kameraWeg) {
            return ['code' => 0, 'body' => '', 'fehler' => 'timeout'];
        }
        if ($methode === 'PUT') {
            self::$licht = FrigateHubLicht::modus((string)$inhalt);
            return ['code' => 200, 'body' => '<ResponseStatus><statusCode>1</statusCode></ResponseStatus>', 'fehler' => ''];
        }
        if (strpos($pfad, 'capabilities') !== false) {
            return ['code' => 200, 'body' => '<SupplementLight><supplementLightMode opt="eventIntelligence,colorVuWhiteLight,irLight,close">x</supplementLightMode></SupplementLight>', 'fehler' => ''];
        }
        return ['code' => 200, 'body' => '<SupplementLight><supplementLightMode>' . self::$licht . '</supplementLightMode><x>1</x></SupplementLight>', 'fehler' => ''];
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }

    public function wert(string $ident)
    {
        return $this->GetValue($ident);
    }
}

function paket(string $topic, $payload): string
{
    return (string)json_encode(['DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => is_array($payload) ? json_encode($payload) : $payload]);
}

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'Name' => 'Annke', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
IPSTestState::$instances[40] = ['InstanceID' => 40, 'Name' => 'Standort', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{45E97A63-F870-408A-B259-2933F7EABF74}']];
$tag = IPSTestState::addObject(40, 'IsDay', false, 'Ist Tag');

$k = new TestKamera(1);
$k->Create();
$k->props(['Camera' => 'Annke', 'ApiUrl' => 'http://127.0.0.1:5000']);
$k->ApplyChanges();
$event = fn (string $typ, array $after) => $k->ReceiveData(paket('frigate/events', ['type' => $typ, 'before' => [], 'after' => $after + ['camera' => 'Annke']]));

check('Standard aus: keine Variable, kein Timer', [isset($k->variables['Licht']), $k->timers['Licht']['interval']], [false, 0]);
$event('new', $ev('p1'));
check('aus: Kamera wird nicht angefasst', TestKamera::$isapiAufrufe, []);
check('Panel im Formular', strpos(json_encode(json_decode($k->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Licht bei Erkennung') !== false, true);

// Zugang aus Frigate
TestKamera::$api = ['/api/config' => json_encode(['cameras' => ['Annke' => ['ffmpeg' => ['inputs' => [['path' => 'rtsp://127.0.0.1:8554/Annke']]]]]]), '/api/config/raw' => json_encode($yaml)];
$text = $k->LichtZugangAusFrigate();
check('Zugang übernommen, Passwort nicht im Text', [strpos($text, '✅ Kamerazugang aus Frigate übernommen (192.168.2.77, Benutzer admin).') === 0, strpos($text, 'ab/cd'), strpos($text, 'lässt sich schalten') !== false], [true, false, true]);
check('Passwort im Attribut', json_decode($k->ReadAttributeString('LichtZugang'), true)['passwort'] ?? null, 'ab/cd');
check('Passwort nicht im Protokoll', strpos(implode("\n", IPSTestState::$logMessages) . implode("\n", array_map('json_encode', IPSTestState::$debug)), 'ab/cd'), false);

$k->props(['LichtArt' => FrigateHubLicht::KAMERA, 'LichtNachlauf' => 30]);
$k->ApplyChanges();
TestKamera::$isapiAufrufe = [];
$event('new', $ev('p2'));
check('Person bei Dunkelheit → Weißlicht', [TestKamera::$licht, $k->wert('Licht'), json_decode($k->ReadAttributeString('LichtVorher'), true)], ['colorVuWhiteLight', true, ['modus' => 'eventIntelligence']]);
check('Timer prüft weiter', $k->timers['Licht']['interval'], 10000);
$event('update', $ev('p2'));
check('weitere Meldung: kein erneutes Schreiben', count(array_filter(TestKamera::$isapiAufrufe, fn ($a) => $a[0] === 'PUT')), 1);
$event('end', $ev('p2', 'person', ['end_time' => time()]));
check('Ende → Nachlauf, Licht noch an', [TestKamera::$licht, $k->timers['Licht']['interval'] >= 25000], ['colorVuWhiteLight', true]);
$k->SetBuffer('Licht', (string)json_encode(['objekte' => [], 'letzte' => time() - 31]));
$k->LichtPruefen();
check('Nachlauf vorbei → Modus von vorher', [TestKamera::$licht, $k->wert('Licht'), $k->ReadAttributeString('LichtVorher'), $k->timers['Licht']['interval']], ['eventIntelligence', false, '', 0]);

// Tag: nichts schalten
IPSTestState::$objects[$tag]['value'] = true;
TestKamera::$isapiAufrufe = [];
$event('new', $ev('p3'));
check('tagsüber: Kamera bleibt unberührt', TestKamera::$isapiAufrufe, []);
$k->props(['LichtNurDunkel' => false]);
$k->ApplyChanges();
$event('update', $ev('p3'));
check('„nur bei Dunkelheit“ aus → schaltet auch tags', TestKamera::$licht, 'colorVuWhiteLight');
$k->SetBuffer('Licht', '');
$k->LichtPruefen();
IPSTestState::$objects[$tag]['value'] = false;
$k->props(['LichtNurDunkel' => true]);
$k->ApplyChanges();

// Schon weiß: nichts zurückstellen
TestKamera::$licht = 'colorVuWhiteLight';
TestKamera::$isapiAufrufe = [];
$event('new', $ev('p4'));
check('war schon Weißlicht: nichts geschrieben', count(array_filter(TestKamera::$isapiAufrufe, fn ($a) => $a[0] === 'PUT')), 0);
$k->SetBuffer('Licht', '');
$k->LichtPruefen();
check('war schon Weißlicht: bleibt Weißlicht', TestKamera::$licht, 'colorVuWhiteLight');
TestKamera::$licht = 'irLight';

// Kamera weg beim Zurückschalten
$event('new', $ev('p5'));
TestKamera::$kameraWeg = true;
$k->SetBuffer('Licht', '');
$k->LichtPruefen();
check('Zurückschalten scheitert → neuer Versuch in 30 s, Zustand gemerkt', [$k->timers['Licht']['interval'], json_decode($k->ReadAttributeString('LichtVorher'), true)], [30000, ['modus' => 'irLight']]);
TestKamera::$kameraWeg = false;
$k->LichtPruefen();
check('zweiter Versuch klappt', [TestKamera::$licht, $k->ReadAttributeString('LichtVorher'), $k->timers['Licht']['interval']], ['irLight', '', 0]);

// Von Hand
$k->RequestAction('Licht', true);
check('von Hand an: bleibt an ohne Erkennung', [TestKamera::$licht, $k->wert('Licht')], ['colorVuWhiteLight', true]);
$k->LichtPruefen();
check('von Hand an: Timer schaltet nicht ab', TestKamera::$licht, 'colorVuWhiteLight');
$k->RequestAction('Licht', false);
check('von Hand aus', [TestKamera::$licht, $k->wert('Licht')], ['irLight', false]);

// Ausschalten der Funktion während das Licht an ist
$event('new', $ev('p6'));
$k->props(['LichtArt' => FrigateHubLicht::AUS]);
$k->ApplyChanges();
check('Funktion aus während Licht an → zurückgestellt', [TestKamera::$licht, $k->ReadAttributeString('LichtVorher'), $k->timers['Licht']['interval']], ['irLight', '', 0]);

// Symcon-Variable
$lampe = IPSTestState::addObject(77, 'Status', false, 'Hoflampe');
$dimmer = IPSTestState::addObject(78, 'Intensity', 20, 'Dimmer');
$k->props(['LichtArt' => FrigateHubLicht::VARIABLE, 'LichtVariable' => $lampe]);
$k->ApplyChanges();
$event('new', $ev('p7'));
check('Variable: an', [GetValue($lampe), IPSTestState::$aktionen], [true, [[$lampe, true]]]);
$k->SetBuffer('Licht', '');
$k->LichtPruefen();
check('Variable: zurück auf vorher', GetValue($lampe), false);
IPSTestState::$aktionen = [];
IPSTestState::$objects[$lampe]['value'] = true;
$event('new', $ev('p8'));
$k->SetBuffer('Licht', '');
$k->LichtPruefen();
check('Lampe war schon an: bleibt an, nicht angefasst', [GetValue($lampe), IPSTestState::$aktionen], [true, []]);
$k->props(['LichtVariable' => $dimmer, 'LichtWert' => 80]);
$k->ApplyChanges();
$event('new', $ev('p9'));
check('Dimmer: auf eingestellten Wert', GetValue($dimmer), 80);
$k->SetBuffer('Licht', '');
$k->LichtPruefen();
check('Dimmer: zurück auf 20', GetValue($dimmer), 20);
$k->props(['LichtVariable' => 0]);
$k->ApplyChanges();
$event('new', $ev('p10'));
check('ohne Variable: Hinweis statt Fehler', strpos($k->ReadAttributeString('LichtStatus'), 'keine Variable gewählt') !== false, true);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler > 0 ? 1 : 0);
