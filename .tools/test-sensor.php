<?php

declare(strict_types=1);

/**
 * Prüfstand „Kamera als Sensor" (0.16.0) — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-sensor.php
 *
 * Nachrichten im Format von Frigate 0.18.2 (docs/integrations/mqtt.md): frigate/events mit
 * stationary/current_zones/average_estimated_speed/recognized_license_plate,
 * frigate/tracked_object_update (face/lpr), frigate/<kamera>/classification/<modell>,
 * frigate/triggers, frigate/<kamera>/audio/transcription.
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

function paket(string $topic, $payload): string
{
    return (string)json_encode([
        'DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => is_array($payload) ? json_encode($payload) : $payload,
    ]);
}

/** frigate/events-Nachricht. */
function ereignis(string $typ, string $id, string $label, array $mehr = []): array
{
    return ['type' => $typ, 'before' => [], 'after' => $mehr + ['id' => $id, 'camera' => 'Door-Cam', 'label' => $label, 'false_positive' => false,
        'start_time' => time() - 5, 'end_time' => $typ === 'end' ? time() : null, 'current_zones' => [], 'entered_zones' => [], 'stationary' => false]];
}

$S = 'FrigateHubSensor';
$t = 1000000;

/* ------------------------------------------------------------- reine Auswertung */

$o = [];
$e = $S::nachfuehren($o, 'new', ['id' => 'a', 'label' => 'car', 'current_zones' => ['Carport']], $t);
check('neues Objekt', [$e['neu'], $e['objekte']['a']['label'], $e['objekte']['a']['zonen'], $e['objekte']['a']['steht']], [true, 'car', ['Carport' => $t], 0]);
$e = $S::nachfuehren($e['objekte'], 'update', ['id' => 'a', 'label' => 'car', 'current_zones' => ['Carport'], 'stationary' => true], $t + 20);
check('steht ab dem ersten stationary, Zonen-Eintritt bleibt', [$e['neu'], $e['objekte']['a']['steht'], $e['objekte']['a']['zonen']['Carport']], [false, $t + 20, $t]);
$e = $S::nachfuehren($e['objekte'], 'update', ['id' => 'a', 'label' => 'car', 'current_zones' => ['Carport'], 'stationary' => true], $t + 90);
check('steht seit bleibt beim ersten Zeitpunkt', $e['objekte']['a']['steht'], $t + 20);
$e2 = $S::nachfuehren($e['objekte'], 'update', ['id' => 'a', 'label' => 'car', 'current_zones' => [], 'stationary' => false], $t + 100);
check('fährt los: steht 0, Zone verlassen', [$e2['objekte']['a']['steht'], $e2['objekte']['a']['zonen']], [0, []]);
$e = $S::nachfuehren($e['objekte'], 'new', ['id' => 'b', 'label' => 'car', 'current_zones' => ['Carport'], 'stationary' => true], $t + 100);
check('stehen: ältestes Auto zählt, je Klasse und Zone', $S::stehen($e['objekte']), ['klasse' => ['car' => $t + 20], 'zone' => ['Carport|car' => $t + 20]]);
$e = $S::nachfuehren($e['objekte'], 'end', ['id' => 'a', 'label' => 'car', 'end_time' => $t + 200], $t + 200);
check('Ende liefert das Objekt und entfernt es', [$e['ende']['label'] ?? null, isset($e['objekte']['a'])], ['car', false]);
check('falsch erkannt wird nicht geführt', isset($S::nachfuehren([], 'new', ['id' => 'x', 'label' => 'person', 'false_positive' => true], $t)['objekte']['x']), false);
$alt = ['z' => ['label' => 'cat', 'zonen' => [], 'steht' => 0, 'zuletzt' => $t - 30000]];
check('verwaiste Objekte (kein end) verschwinden nach 6 h', isset($S::nachfuehren($alt, 'new', ['id' => 'n', 'label' => 'dog'], $t)['objekte']['z']), false);
$viele = [];
for ($i = 0; $i < 70; $i++) {
    $viele = $S::nachfuehren($viele, 'new', ['id' => 'o' . $i, 'label' => 'person'], $t + $i)['objekte'];
}
check('höchstens 60 Objekte, die ältesten fliegen', [count($viele), isset($viele['o0']), isset($viele['o69'])], [60, false, true]);

$p = ['p' => ['label' => 'person', 'zonen' => ['Eingangsbereich' => $t], 'steht' => 0, 'zuletzt' => $t],
    'h' => ['label' => 'dog', 'zonen' => ['Garten' => $t - 100], 'steht' => 0, 'zuletzt' => $t]];
check('Verweilen: 29 s nein', $S::verweilen($p, $t + 29, 30, ['person']), ['Eingangsbereich' => false]);
check('Verweilen: 30 s ja, Hund zählt nicht', $S::verweilen($p, $t + 30, 30, ['person']), ['Eingangsbereich' => true]);
check('Verweilen: alle Klassen', $S::verweilen($p, $t + 1, 30, []), ['Eingangsbereich' => false, 'Garten' => true]);
check('nächste Verweil-Prüfung', $S::naechsteVerweilPruefung($p, $t + 5, 30, ['person']), $t + 30);
check('keine offene Prüfung', $S::naechsteVerweilPruefung($p, $t + 31, 30, ['person']), 0);

$st = $S::zaehlen([], 'person', '2026-11-03');
$st = $S::zaehlen($st, 'person', '2026-11-03');
$st = $S::zaehlen($st, 'car', '2026-11-03');
check('Tageszähler', $st, ['tag' => '2026-11-03', 'zaehler' => ['person' => 2, 'car' => 1]]);
check('neuer Tag fängt bei 1 an', $S::zaehlen($st, 'person', '2026-11-04'), ['tag' => '2026-11-04', 'zaehler' => ['person' => 1]]);

check('Fahrzeug mit bekanntem Namen', $S::fahrzeug(['recognized_license_plate' => 'OG-DG 65', 'sub_label' => ['Tesla', 0.93]]), ['kennzeichen' => 'OG-DG 65', 'name' => 'Tesla']);
check('Fahrzeug ohne Namen', $S::fahrzeug(['recognized_license_plate' => 'KA-X 1', 'sub_label' => null]), ['kennzeichen' => 'KA-X 1', 'name' => '']);
check('ohne Kennzeichen kein Fahrzeug', $S::fahrzeug(['sub_label' => ['Tesla', 0.9]]), null);

/* ------------------------------------------------------------- Sabotage */

function jpeg(callable $farbe): string
{
    $b = imagecreatetruecolor(64, 36);
    for ($y = 0; $y < 36; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $g = (int)$farbe($x, $y);
            imagesetpixel($b, $x, $y, imagecolorallocate($b, $g, $g, $g));
        }
    }
    ob_start();
    imagejpeg($b, null, 90);
    return (string)ob_get_clean();
}

if (!function_exists('imagecreatetruecolor')) {
    echo "ℹ️ GD fehlt — Sabotage-Prüfungen übersprungen.\n";
} else {
    $szene = jpeg(fn ($x, $y) => ($x < 32 ? 60 : 200) + ($y < 18 ? 20 : 0));          // „Haus links, Himmel rechts"
    $heller = jpeg(fn ($x, $y) => ($x < 32 ? 90 : 230) + ($y < 18 ? 20 : 0));         // dieselbe Szene, Sonne
    $verdreht = jpeg(fn ($x, $y) => ($x < 32 ? 200 : 60) + ($y < 18 ? 0 : 20));       // Kamera gedreht
    $schwarz = jpeg(fn ($x, $y) => 12);                                                // abgeklebt
    $k = $S::kennwerte($szene);
    check('Kennwerte: Raster 8×8, Streuung deutlich', [count($k['raster']), $k['streuung'] > 50], [64, true]);
    check('kein JPEG → null', $S::kennwerte('kein bild'), null);
    check('Abstand ohne Helligkeit: Sonne zählt nicht', $S::abstand($k['raster'], $S::kennwerte($heller)['raster']) < 10, true);
    check('Abstand: verdreht deutlich', $S::abstand($k['raster'], $S::kennwerte($verdreht)['raster']) >= $S::VERSTELLT, true);

    $z = [];
    for ($i = 0; $i < 3; $i++) {
        $r = $S::sabotage($z, $S::kennwerte($szene), 14);
        $z = $r['zustand'];
    }
    check('Lernen: 3 Bilder, unauffällig', [$z['ansicht'][14]['n'], $r['auffaellig']], [3, false]);
    $r = $S::sabotage($z, $S::kennwerte($schwarz), 3);
    check('andere Stunde ungelernt: schwarz (Nacht?) unauffällig', [$r['auffaellig'], $r['zustand']['grund']], [false, '']);
    $zz = $z;
    foreach ([1, 2] as $i) {
        $r = $S::sabotage($zz, $S::kennwerte($schwarz), 14);
        $zz = $r['zustand'];
        check('abgeklebt: ' . $i . '. Mal noch keine Meldung', $r['auffaellig'], false);
    }
    $r = $S::sabotage($zz, $S::kennwerte($schwarz), 14);
    check('abgeklebt: 3. Mal Meldung', [$r['auffaellig'], $r['text']], [true, 'Bild fast einfarbig — Kamera abgedeckt, verdreckt oder gestört?']);
    check('Auffälliges wird nicht gelernt', $r['zustand']['ansicht'][14]['n'], 3);
    $zz = $z;
    for ($i = 0; $i < 3; $i++) {
        $r = $S::sabotage($zz, $S::kennwerte($verdreht), 14);
        $zz = $r['zustand'];
    }
    check('verdreht: nach 3 Prüfungen gemeldet', [$r['auffaellig'], $r['zustand']['grund']], [true, 'verstellt']);
    $r = $S::sabotage($zz, $S::kennwerte($heller), 14);
    check('wieder normal (heller Tag): sofort unauffällig', [$r['auffaellig'], $r['zustand']['folge']], [false, 0]);
    $zz = $z;
    $r = $S::sabotage($zz, $S::kennwerte($schwarz), 14);
    $r = $S::sabotage($r['zustand'], $S::kennwerte($verdreht), 14);
    check('Grund wechselt: Zählung beginnt neu', $r['zustand']['folge'], 1);
    // Noch nicht genug gelernt (1 Bild): nichts melden, auch wenn das nächste anders aussieht
    $r = $S::sabotage([], $S::kennwerte($szene), 9);
    $zz = $r['zustand'];
    for ($i = 0; $i < 3; $i++) {
        $r = $S::sabotage($zz, $S::kennwerte($verdreht), 9);
        $zz = $r['zustand'];
    }
    check('erst ab 3 gelernten Bildern vergleichen', [$r['auffaellig'], $r['zustand']['ansicht'][9]['n']], [false, 3]);
    // Nachts ohne Licht ist auch das freie Bild fast einfarbig: dann ist „einfarbig" kein Befund
    $nacht = jpeg(fn ($x, $y) => 10 + ($x % 2));
    $zz = [];
    for ($i = 0; $i < 3; $i++) {
        $zz = $S::sabotage($zz, $S::kennwerte($nacht), 2)['zustand'];
    }
    for ($i = 0; $i < 3; $i++) {
        $r = $S::sabotage($zz, $S::kennwerte($schwarz), 2);
        $zz = $r['zustand'];
    }
    check('dunkle Nachtstunde: schwarz ist normal', [$r['auffaellig'], $r['zustand']['grund']], [false, '']);
}

/* ------------------------------------------------------------- Kamera-Instanz */

class TestKamera extends FrigateHubKamera
{
    public static array $api = [];

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        foreach (self::$api as $muster => $antwort) {
            if (strpos($pfad, $muster) === 0) {
                return $antwort;
            }
        }
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

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
IPSTestState::$instances[42569] = ['InstanceID' => 42569, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}']];
$k = new TestKamera(1);
$k->Create();
$k->props(['Camera' => 'Door-Cam']);
$k->ApplyChanges();
$an = fn (string $topic, $inhalt) => $k->ReceiveData(paket($topic, $inhalt));
$wert = fn (string $ident) => $k->variables[$ident]['value'] ?? '(fehlt)';

check('Timer: Tageswechsel an, Bildprüfung ohne Adresse aus', [$k->timers['Tageswechsel']['interval'] > 0, $k->timers['Bildpruefung']['interval']], [true, 0]);
check('Filter enthält triggers', strpos($k->receiveFilter, 'triggers') !== false, true);

// Zustandsklassifizierung
$an('frigate/Door-Cam/classification/garagentor', 'open');
check('Zustand aus Klassifizierung', [$wert('Zustand_garagentor'), $k->variables['Zustand_garagentor']['caption'] ?? null], ['open', 'Zustand garagentor']);
$an('frigate/Door-Cam/classification/garagentor', 'closed');
check('Zustand ändert sich', $wert('Zustand_garagentor'), 'closed');
$an('frigate/Garten/classification/garagentor', 'open');
check('fremde Kamera ändert nichts', $wert('Zustand_garagentor'), 'closed');

// Auto fährt in den Carport und bleibt stehen
$an('frigate/events', ereignis('new', 'car1', 'car', ['current_zones' => ['Carport'], 'entered_zones' => ['Carport']]));
check('neues Auto: Tageszähler', [$wert('Heute_car'), $k->variables['Heute_car']['caption'] ?? null], [1, 'Heute: Autos']);
$an('frigate/events', ereignis('update', 'car1', 'car', ['current_zones' => ['Carport'], 'stationary' => true]));
$seit = $wert('Steht_car');
check('Auto steht seit: Zeitpunkt, je Zone', [$seit > 0, $wert('Steht_Carport_car') === $seit, $k->variables['Steht_Carport_car']['caption'] ?? null], [true, true, 'Carport: Auto steht seit']);
$an('frigate/events', ereignis('update', 'car1', 'car', ['current_zones' => ['Carport'], 'stationary' => true, 'recognized_license_plate' => 'OG-DG 65', 'sub_label' => ['Tesla', 0.93]]));
check('bekanntes Kennzeichen → Fahrzeugname', $wert('Fahrzeug'), 'Tesla (OG-DG 65)');
$an('frigate/events', ereignis('end', 'car1', 'car', ['current_zones' => ['Carport'], 'stationary' => true]));
check('Auto weg: steht seit → 0 („-")', [$wert('Steht_car'), $wert('Steht_Carport_car')], [0, 0]);
check('bekanntes Kennzeichen ist nicht unbekannt', isset($k->variables['UnbekanntesKennzeichen']), false);

// Fremdes Kennzeichen über tracked_object_update
$an('frigate/events', ereignis('new', 'car2', 'car'));
$an('frigate/tracked_object_update', ['type' => 'lpr', 'id' => 'car2', 'name' => null, 'plate' => 'KA-X 1', 'score' => 0.9, 'camera' => 'Door-Cam', 'timestamp' => time()]);
check('unbekanntes Kennzeichen sofort als Fahrzeug', $wert('Fahrzeug'), 'KA-X 1');
$an('frigate/events', ereignis('end', 'car2', 'car', ['average_estimated_speed' => 23.44]));
check('am Ende: unbekanntes Kennzeichen (Zeitpunkt)', $wert('UnbekanntesKennzeichen') > 0, true);
check('Geschwindigkeit mit Einheit', [$wert('Geschwindigkeit'), $k->variables['Geschwindigkeit']['presentation']['SUFFIX'] ?? null, strpos((string)$wert('GeschwindigkeitObjekt'), 'Auto, ') === 0], [23.4, ' km/h', true]);

// Person: Gesicht erst unbekannt, dann erkannt → nicht unbekannt
$an('frigate/events', ereignis('new', 'p1', 'person', ['current_zones' => ['Eingangsbereich']]));
$an('frigate/tracked_object_update', ['type' => 'face', 'id' => 'p1', 'name' => null, 'score' => 0.3, 'camera' => 'Door-Cam', 'timestamp' => time()]);
$an('frigate/tracked_object_update', ['type' => 'face', 'id' => 'p1', 'name' => 'Dietmar', 'score' => 0.91, 'camera' => 'Door-Cam', 'timestamp' => time()]);
check('Name der erkannten Person', $wert('PersonName'), 'Dietmar');
$an('frigate/events', ereignis('end', 'p1', 'person'));
check('erkannte Person ist nicht unbekannt', isset($k->variables['UnbekanntesGesicht']), false);
$an('frigate/events', ereignis('new', 'p2', 'person'));
$an('frigate/tracked_object_update', ['type' => 'face', 'id' => 'p2', 'name' => null, 'score' => 0.2, 'camera' => 'Door-Cam', 'timestamp' => time()]);
$an('frigate/events', ereignis('end', 'p2', 'person'));
check('nur Versuche ohne Treffer → unbekanntes Gesicht', $wert('UnbekanntesGesicht') > 0, true);
$an('frigate/events', ereignis('new', 'p3', 'person'));
$an('frigate/events', ereignis('end', 'p3', 'person'));
check('ohne Gesichtsversuch bleibt es beim alten Zeitpunkt', $wert('Heute_person'), 3);

// Verweilen: Person steht 30 s im Eingangsbereich (Frigate schickt dann nichts mehr — der Timer prüft)
$an('frigate/events', ereignis('new', 'p4', 'person', ['current_zones' => ['Eingangsbereich']]));
check('gerade angekommen: Variable noch nicht nötig', isset($k->variables['Verweilt_Eingangsbereich']), false);
check('Verweil-Timer auf die Schwelle', $k->timers['Verweilen']['interval'] >= 29000 && $k->timers['Verweilen']['interval'] <= 30500, true);
$o = json_decode($k->GetBuffer('Objekte'), true);
$o['p4']['zonen']['Eingangsbereich'] = time() - 31;
$k->SetBuffer('Objekte', json_encode($o));
$k->SensorPruefen();
check('nach 30 s: verweilt', [$wert('Verweilt_Eingangsbereich'), $k->variables['Verweilt_Eingangsbereich']['caption'] ?? null], [true, 'Eingangsbereich: verweilt']);
check('kein offener Verweil-Timer mehr', $k->timers['Verweilen']['interval'], 0);
$an('frigate/events', ereignis('end', 'p4', 'person'));
check('geht weg: frei', $wert('Verweilt_Eingangsbereich'), false);
$k->props(['VerweilSekunden' => 0]);
$k->ApplyChanges();
$an('frigate/events', ereignis('new', 'p5', 'person', ['current_zones' => ['Eingangsbereich']]));
check('Verweilen aus: kein Timer', $k->timers['Verweilen']['interval'], 0);
$an('frigate/events', ereignis('end', 'p5', 'person'));
$k->props(['VerweilSekunden' => 30]);
$k->ApplyChanges();

// Paket liegt, dann weg
$an('frigate/events', ereignis('new', 'pk', 'package', ['stationary' => true]));
check('Paket liegt seit', $wert('Steht_package') > 0, true);
$an('frigate/events', ereignis('end', 'pk', 'package', ['stationary' => true]));
check('Paket entfernt (Zeitpunkt)', [$wert('PaketEntfernt') > 0, $wert('Steht_package')], [true, 0]);

// Alarm, Auslöser, Sprache
$an('frigate/reviews', ['type' => 'new', 'before' => [], 'after' => ['id' => 'r1', 'camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => time(), 'end_time' => null,
    'data' => ['objects' => ['person'], 'zones' => [], 'detections' => ['p6'], 'audio' => []]]]);
$an('frigate/reviews', ['type' => 'update', 'before' => [], 'after' => ['id' => 'r1', 'camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => time(), 'end_time' => null,
    'data' => ['objects' => ['person'], 'zones' => [], 'detections' => ['p6'], 'audio' => []]]]);
check('Alarme heute: nur das neue zählt', $wert('Heute_alarme'), 1);
$an('frigate/triggers', ['name' => 'roter Transporter', 'camera' => 'Door-Cam', 'event_id' => 'x', 'type' => 'thumbnail', 'score' => 0.85]);
check('Auslöser mit Leerzeichen: Ident mit Hash, Zeitpunkt', [$wert(FrigateHubTopics::ident('Ausloeser', 'roter Transporter')) > 0, $k->variables[FrigateHubTopics::ident('Ausloeser', 'roter Transporter')]['caption'] ?? null], [true, 'Auslöser roter Transporter']);
$an('frigate/triggers', ['name' => 'nachbar', 'camera' => 'Garten', 'event_id' => 'y', 'type' => 'description', 'score' => 0.9]);
check('Auslöser fremder Kamera ignoriert', isset($k->variables['Ausloeser_nachbar']), false);
$an('frigate/Door-Cam/audio/transcription', 'Hallo, ist jemand zu Hause?');
check('gesprochener Text', strpos((string)$wert('Gesprochen'), ' – Hallo, ist jemand zu Hause?') !== false, true);

// Tageswechsel
$k->Tageswechsel();
check('Mitternacht: Zähler auf 0', [$wert('Heute_person'), $wert('Heute_car'), $wert('Heute_alarme')], [0, 0, 0]);
$an('frigate/events', ereignis('new', 'p7', 'person'));
check('neuer Tag zählt von 1', $wert('Heute_person'), 1);
$k->props(['Tageszaehler' => false]);
$k->ApplyChanges();
$an('frigate/events', ereignis('new', 'p8', 'person'));
check('Tageszähler aus: zählt nicht, Timer aus', [$wert('Heute_person'), $k->timers['Tageswechsel']['interval']], [1, 0]);

// Bildprüfung mit Adresse
$k->props(['ApiUrl' => 'http://127.0.0.1:5000', 'PushZiele' => json_encode([['Ziel' => 42569]])]);
$k->ApplyChanges();
check('Bildprüfung alle 5 min', $k->timers['Bildpruefung']['interval'], 300000);
if (function_exists('imagecreatetruecolor')) {
    TestKamera::$api = ['/api/Door-Cam/latest.jpg' => $szene];
    for ($i = 0; $i < 3; $i++) {
        $k->BildPruefen();
    }
    check('gelernt, unauffällig', [$wert('BildAuffaellig'), $wert('Bildzustand')], [false, 'Bild unauffällig.']);
    TestKamera::$api = ['/api/Door-Cam/latest.jpg' => $schwarz];
    $k->BildPruefen();
    $k->BildPruefen();
    check('2× schwarz: noch still', [$wert('BildAuffaellig'), $GLOBALS['gepusht']], [false, []]);
    $text = $k->BildPruefen();
    check('3× schwarz: auffällig, gemeldet einmal', [$wert('BildAuffaellig'), count($GLOBALS['gepusht']), strpos($text, '⚠️') === 0], [true, 1, true]);
    $k->BildPruefen();
    check('weiter auffällig: keine zweite Meldung', count($GLOBALS['gepusht']), 1);
    check('Neu lernen verwirft Ansichten', [strpos($k->BildNeuLernen(), '✅') === 0, $k->ReadAttributeString('Sabotage')], [true, '{}']);
}
TestKamera::$api = [];
check('kein Bild: Prüfung ausgesetzt', strpos($k->BildPruefen(), 'Kein Bild von Frigate') !== false, true);
$an('frigate/Door-Cam/motion', 'ON');
$k->WriteAttributeInteger('BewegungSeit', time() - 16 * 60);
check('Dauerbewegung ab 15 min', strpos($k->BildPruefen(), 'Dauerbewegung seit') !== false, true);
$an('frigate/Door-Cam/motion', 'OFF');
check('Bewegung aus: Beginn vergessen', $k->ReadAttributeInteger('BewegungSeit'), 0);

// Formular
$form = (string)$k->GetConfigurationForm();
check('Formular: Panel „Kamera als Sensor"', strpos($form, json_encode('🧠  Kamera als Sensor')) !== false || strpos($form, 'Kamera als Sensor') !== false, true);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
