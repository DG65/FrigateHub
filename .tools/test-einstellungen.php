<?php

declare(strict_types=1);

/**
 * Prüfstand „In Frigate einstellen" (0.17.0) — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-einstellungen.php
 *
 * Die Kamera-Konfiguration entspricht Dietmars Door-Cam aus /api/config (Frigate 0.18.2, 03.11.2026).
 * Der Schreibweg (PUT /api/config/set, update_topic) ist Frigates eigener (BaseSection.tsx).
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

$doorCam = [
    'enabled' => true,
    'ffmpeg'  => ['inputs' => [['path' => 'rtsp://…', 'roles' => ['record', 'detect', 'audio']]]],
    'detect'  => ['fps' => 5, 'stationary' => ['interval' => 50, 'threshold' => 50]],
    'record'  => ['enabled' => true, 'continuous' => ['days' => 0], 'motion' => ['days' => 0],
        'alerts' => ['retain' => ['days' => 10, 'mode' => 'motion']], 'detections' => ['retain' => ['days' => 10, 'mode' => 'motion']]],
    'objects' => ['track' => ['person', 'car', 'bicycle', 'dog', 'cat', 'motorcycle']],
    'review'  => ['alerts' => ['labels' => ['person', 'car'], 'required_zones' => []]],
    'snapshots' => ['enabled' => true, 'retain' => ['default' => 10]],
    'audio'   => ['enabled' => false, 'listen' => ['bark', 'fire_alarm', 'speech', 'yell']],
    'lpr'     => ['enabled' => true],
    'face_recognition' => ['enabled' => true],
    'zones'   => ['Eingangsbereich' => ['objects' => ['person'], 'loitering_time' => 0], 'Carport' => ['objects' => ['person'], 'loitering_time' => 0]],
];
$config = ['cameras' => ['Door-Cam' => $doorCam], 'lpr' => ['enabled' => true], 'face_recognition' => ['enabled' => true]];
$E = 'FrigateHubEinstellungen';

/* ------------------------------------------------------------- lesen */

$w = $E::lesen($doorCam, $config);
check('lesen: Aufbewahrung', [$w['aufnahme'], $w['alarme'], $w['erkennungen'], $w['dauer'], $w['bewegung']], [true, 10.0, 10.0, 0.0, 0.0]);
check('lesen: Objekte und Alarme', [$w['verfolgen'], $w['alarmObjekte'], $w['alarmZonen']], [['person', 'car', 'bicycle', 'dog', 'cat', 'motorcycle'], ['person', 'car'], []]);
check('lesen: Stillstand 50 Bilder bei 5/s = 10 s', [$w['stillstand'], $w['fps']], [10, 5]);
check('lesen: Kennzeichen/Gesichter/Audio', [$w['kennzeichen'], $w['gesichter'], $w['audio'], $w['audioRolle']], [true, true, false, true]);
check('lesen: Zonen', $w['zonen'], ['Eingangsbereich' => ['objekte' => ['person'], 'verweilen' => 0], 'Carport' => ['objekte' => ['person'], 'verweilen' => 0]]);
$ohneLpr = $E::lesen($doorCam, ['lpr' => ['enabled' => false]]);
check('global aus: Kennzeichen/Gesichter null', [$ohneLpr['kennzeichen'], $ohneLpr['gesichter']], [null, null]);
check('ohne Rolle audio', $E::hatAudioRolle(['ffmpeg' => ['inputs' => [['roles' => ['detect']]]]]), false);

/* ------------------------------------------------------------- Änderungen */

$formular = ['aufnahme' => true, 'alarme' => 10, 'erkennungen' => 10, 'dauer' => 0, 'bewegung' => 0, 'verfolgen' => 'person, car, bicycle, dog, cat, motorcycle',
    'alarmObjekte' => 'car,person', 'alarmZonen' => '', 'schnappschuss' => true, 'schnappTage' => 10, 'stillstand' => 10, 'audio' => false,
    'hoeren' => 'bark, fire_alarm, speech, yell', 'kennzeichen' => true, 'gesichter' => true,
    'zonen' => ['Eingangsbereich' => ['objekte' => 'person', 'verweilen' => 0], 'Carport' => ['objekte' => 'person', 'verweilen' => 0]]];
check('unverändert (andere Reihenfolge, Leerzeichen, Zahl als int) → nichts', $E::aenderungen($w, $formular), []);

$a = $E::aenderungen($w, ['alarme' => 14, 'dauer' => 2.5] + $formular);
check('Aufbewahrung: nur die geänderten Felder', $a, ['record' => ['continuous' => ['days' => 2.5], 'alerts' => ['retain' => ['days' => 14]]]]);
$a = $E::aenderungen($w, ['zonen' => ['Carport' => ['objekte' => 'person, car', 'verweilen' => 0], 'Eingangsbereich' => ['objekte' => 'person', 'verweilen' => 30]]] + $formular);
check('Zonen: car im Carport, Verweilen am Eingang', $a, ['zones' => ['Carport' => ['objects' => ['person', 'car']], 'Eingangsbereich' => ['loitering_time' => 30]]]);
$a = $E::aenderungen($w, ['zonen' => ['Neu' => ['objekte' => 'person', 'verweilen' => 5]] + $formular['zonen']] + $formular);
check('unbekannte Zone wird nicht angelegt', $a, []);
$a = $E::aenderungen($w, ['verfolgen' => 'person, car, package', 'alarmZonen' => 'Eingangsbereich', 'stillstand' => 30] + $formular);
check('Objekte, Alarmzonen, Stillstand (30 s × 5 Bilder)', $a, ['objects' => ['track' => ['person', 'car', 'package']], 'review' => ['alerts' => ['required_zones' => ['Eingangsbereich']]],
    'detect' => ['stationary' => ['threshold' => 150]]]);
$a = $E::aenderungen($w, ['audio' => true, 'hoeren' => 'bark, glass', 'kennzeichen' => false, 'schnappschuss' => false] + $formular);
check('Schalter und Geräusche', $a, ['snapshots' => ['enabled' => false], 'lpr' => ['enabled' => false], 'audio' => ['enabled' => true, 'listen' => ['bark', 'glass']]]);
check('Kennzeichen global aus: nicht schreiben', $E::aenderungen($ohneLpr, ['kennzeichen' => true] + $formular + ['x' => 0]), []);
check('Liste: doppelt und leer weg', $E::liste(' person,, car ,person '), ['person', 'car']);
check('Anfrage wie Frigates Oberfläche', $E::anfrage('Door-Cam', 'zones', ['Carport' => ['objects' => ['person', 'car']]]),
    ['requires_restart' => 0, 'update_topic' => 'config/cameras/Door-Cam/zones', 'config_data' => ['cameras' => ['Door-Cam' => ['zones' => ['Carport' => ['objects' => ['person', 'car']]]]]]]);
check('Beschreibung', $E::beschreiben(['record' => 1, 'zones' => 1]), 'Aufnahme, Zonen');

/* ------------------------------------------------------------- Kamera-Instanz */

class TestKamera extends FrigateHubKamera
{
    public static array $api = [];
    public static array $geschrieben = [];
    /** Antwort je Bereich: [code, body] */
    public static array $antwort = [];

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }

    protected function apiSchreiben(string $methode, string $pfad, ?array $json): array
    {
        self::$geschrieben[] = [$methode, $pfad, $json];
        $bereich = substr((string)($json['update_topic'] ?? ''), strrpos((string)($json['update_topic'] ?? ''), '/') + 1);
        return self::$antwort[$bereich] ?? ['code' => 200, 'body' => '{"success":true,"message":"Config successfully updated"}'];
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }
}

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
$k = new TestKamera(1);
$k->Create();
$k->props(['Camera' => 'Door-Cam']);
$k->ApplyChanges();

$panel = fn () => array_values(array_filter(json_decode((string)$k->GetConfigurationForm(), true)['elements'], fn ($e) => strpos((string)($e['caption'] ?? ''), 'In Frigate einstellen') !== false))[0] ?? null;
check('ohne Adresse: Hinweis statt Feldern', strpos(json_encode($panel()['items'], JSON_UNESCAPED_UNICODE), 'braucht es Kamera-ID und Frigate-Adresse') !== false, true);
$form = json_decode((string)$k->GetConfigurationForm(), true);
$titel = array_map(fn ($e) => (string)($e['caption'] ?? ''), $form['elements']);
$pos = fn (string $t) => array_key_first(array_filter($titel, fn ($c) => strpos($c, $t) !== false));
check('Panel steht bei den Fachpanels vor „Formular: Aufbau", nicht in actions', [$pos('In Frigate einstellen') !== null && $pos('In Frigate einstellen') < $pos('Formular: Aufbau'), strpos(json_encode($form['actions'], JSON_UNESCAPED_UNICODE), 'In Frigate einstellen') === false], [true, true]);

$k->props(['ApiUrl' => 'http://127.0.0.1:5000']);
$k->ApplyChanges();
TestKamera::$api['/api/config'] = $config;
$p = $panel();
$felder = [];
$sammeln = function (array $items) use (&$sammeln, &$felder) {
    foreach ($items as $e) {
        if (isset($e['name'])) {
            $felder[$e['name']] = $e;
        }
        if (isset($e['items'])) {
            $sammeln($e['items']);
        }
    }
};
$sammeln($p['items']);
check('Felder mit Werten aus Frigate', [$felder['FE_Alarme']['value'], $felder['FE_Verfolgen']['value'], $felder['FE_Stillstand']['value'], $felder['FE_Zonen']['values'][1]],
    [10, 'person, car, bicycle, dog, cat, motorcycle', 10, ['Zone' => 'Carport', 'Objekte' => 'person', 'Verweilen' => 0]]);
check('Kennzeichen/Gesichter angeboten, Audio bedienbar', [isset($felder['FE_Kennzeichen'], $felder['FE_Gesichter']), $felder['FE_Audio']['enabled']], [true, true]);
$knopf = null;
array_walk_recursive($p, function ($v, $key) use (&$knopf) { if ($key === 'onClick' && strpos((string)$v, 'EinstellungenSpeichern') !== false) { $knopf = $v; } });
check('Speichern-Knopf vorhanden', $knopf !== null, true);

// onClick wie Symcon ausführen: Formularwerte als Variablen, Liste als durchlaufbares Objekt
function FRIG_EinstellungenSpeichern(int $id, string $werte): string
{
    return $GLOBALS['k']->EinstellungenSpeichern($werte);
}
$klick = function (array $aenderung) use ($knopf, $felder) {
    $vars = [];
    foreach ($felder as $name => $f) {
        if (strpos($name, 'FE_') === 0) {
            $vars[$name] = $name === 'FE_Zonen' ? new ArrayIterator($f['values']) : $f['value'];
        }
    }
    foreach ($aenderung as $n => $v) {
        $vars[$n] = $v;
    }
    extract($vars);
    $id = 1;
    ob_start();
    eval($knopf);
    return (string)ob_get_clean();
};
$GLOBALS['k'] = $k;
TestKamera::$geschrieben = [];
check('nichts geändert → nichts geschrieben', [strpos($klick([]), 'Nichts geändert') !== false, TestKamera::$geschrieben], [true, []]);
$zonen = $felder['FE_Zonen']['values'];
$zonen[1]['Objekte'] = 'person, car';
$text = $klick(['FE_Zonen' => new ArrayIterator($zonen), 'FE_Alarme' => 14.0]);
check('car im Carport + 14 Tage: zwei Bereiche, PUT config/set', [count(TestKamera::$geschrieben), TestKamera::$geschrieben[0][0], TestKamera::$geschrieben[0][1],
    array_column(array_column(TestKamera::$geschrieben, 2), 'update_topic')], [2, 'PUT', '/api/config/set', ['config/cameras/Door-Cam/record', 'config/cameras/Door-Cam/zones']]);
check('Meldung: gespeichert', [strpos($text, '✅') === 0, strpos($text, 'Aufnahme, Zonen') !== false], [true, true]);
check('Abgleich danach angestoßen', $k->timers['Abgleich']['interval'] > 0, true);

TestKamera::$geschrieben = [];
TestKamera::$antwort = ['audio' => ['code' => 400, 'body' => '{"success":false,"message":"Error saving config: Camera Door-Cam has audio enabled, but no audio input"}']];
$text = $klick(['FE_Audio' => true, 'FE_Schnappschuss' => false]);
check('Frigate lehnt Audio ab: Grund sichtbar, Schnappschüsse trotzdem gespeichert', [strpos($text, '⚠️') === 0, strpos($text, 'Geräusche: Error saving config: Camera Door-Cam has audio enabled') !== false,
    strpos($text, 'In Frigate gespeichert: Schnappschüsse') !== false], [true, true, true]);
TestKamera::$antwort = ['record' => ['code' => 403, 'body' => '']];
$text = $klick(['FE_Alarme' => 3.0]);
check('ohne Admin-Rolle: verständlicher Hinweis', [strpos($text, '⛔') === 0, strpos($text, 'Rolle „admin"') !== false], [true, true]);
TestKamera::$antwort = [];
TestKamera::$api['/api/config'] = null;
check('Frigate weg beim Speichern: nichts geschrieben', strpos($k->EinstellungenSpeichern('{"alarme":3}'), '⛔') === 0, true);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
