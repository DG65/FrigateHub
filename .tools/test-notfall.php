<?php

declare(strict_types=1);

/**
 * Prüfstand „Person liegt / Sturz" (0.21.0) — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-notfall.php
 *
 * Ereignisse im Format von frigate/events (Frigate dev, geprüft 11.11.2026): `box` in Pixeln des Erkennungsbilds
 * (hier 1280×720), `path_data` = [[x, y normiert], frame_time] des Fußpunkts.
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

$N = 'FrigateHubNotfall';
$t = 1800000000.0;
$AUFRECHT = [600, 200, 680, 480];   // 80 × 280
$LIEGT = [520, 420, 760, 500];      // 240 × 80
$HOCKT = [580, 330, 700, 480];      // 120 × 150

function ev(string $id, array $box, float $zeit, array $mehr = []): array
{
    return $mehr + ['id' => $id, 'camera' => 'Flur', 'label' => 'person', 'box' => $box, 'frame_time' => $zeit,
        'false_positive' => false, 'end_time' => null, 'current_zones' => ['Treppe'], 'stationary' => false];
}

/* ------------------------------------------------------------- Form */

check('Form aufrecht', $N::form($AUFRECHT), 'aufrecht');
check('Form liegend', $N::form($LIEGT), 'liegend');
check('Form hockt = weder noch', $N::form($HOCKT), '');

/* ------------------------------------------------------------- Sturz */

$z = $N::beobachten([], 'new', ev('p1', $AUFRECHT, $t), $t);
$z = $N::beobachten($z, 'update', ev('p1', $HOCKT, $t + 0.6), $t + 0.6);
$z = $N::beobachten($z, 'update', ev('p1', $LIEGT, $t + 1.2), $t + 1.2);
check('aufrecht → (hockt) → liegend in 1,2 s = Sturz', $z['p1']['sturz'], 'sturz');
check('direkt danach: Stufe Sturz', $N::bewerten($z, $t + 2, 60, [])['stufe'], $N::STURZ);
$z = $N::beobachten($z, 'update', ev('p1', $LIEGT, $t + 12, ['stationary' => true]), $t + 12);
check('Sturz + 15 s reglos = Notfall (statt 60 s)', $N::bewerten($z, $t + 17, 60, [])['stufe'], $N::NOTFALL);
check('Grund nennt den Sturz', $N::bewerten($z, $t + 17, 60, [])['grund'], 'Sturz');
check('vorher noch Sturz', $N::bewerten($z, $t + 10, 60, [])['stufe'], $N::STURZ);

// Hinlegen langsam (Yoga, Sonnenbad): kein Sturz, normale Wartezeit
$z = $N::beobachten([], 'new', ev('p2', $AUFRECHT, $t), $t);
$z = $N::beobachten($z, 'update', ev('p2', $HOCKT, $t + 5), $t + 5);
$z = $N::beobachten($z, 'update', ev('p2', $LIEGT, $t + 12, ['stationary' => true]), $t + 12);
check('langsam hingelegt: kein Sturz', $z['p2']['sturz'], '');
check('nach 30 s: liegt', $N::bewerten($z, $t + 42, 60, [])['stufe'], $N::LIEGT);
check('nach 60 s reglos: Notfall', $N::bewerten($z, $t + 73, 60, [])['stufe'], $N::NOTFALL);

// Liegt, bewegt sich aber (Updates kommen, nicht stationär)
$z = $N::beobachten([], 'new', ev('p3', $LIEGT, $t), $t);
$z = $N::beobachten($z, 'update', ev('p3', $LIEGT, $t + 70), $t + 70);
check('liegt, bewegt sich (frische Meldung): kein Notfall', $N::bewerten($z, $t + 72, 60, [])['stufe'], $N::LIEGT);
check('… 15 s ohne Meldung = reglos', $N::bewerten($z, $t + 86, 60, [])['stufe'], $N::NOTFALL);

/* ------------------------------------------------------------- Leiter / Treppe */

$weg = [[[0.5, 0.40], $t], [[0.5, 0.42], $t + 0.4], [[0.5, 0.55], $t + 0.8], [[0.5, 0.68], $t + 1.2]];
check('Fußpunkt fällt 26 % in 0,8 s = Absturz', $N::absturz($weg), true);
check('Treppe langsam hinunter: kein Absturz', $N::absturz([[[0.5, 0.40], $t], [[0.5, 0.48], $t + 1.5], [[0.5, 0.56], $t + 3]]), false);
check('zu wenig Punkte', $N::absturz([[[0.5, 0.4], $t]]), false);
check('kaputte Daten', $N::absturz([['x'], null, [[0.5], 1]]), false);
// Auf der Leiter (Rahmen schmal, hoch im Bild) → am Boden flach; die Zwischenbilder kennt Frigate nicht
$z = $N::beobachten([], 'new', ev('p4', [600, 50, 660, 250], $t - 30), $t - 30);
$z = $N::beobachten($z, 'update', ev('p4', $LIEGT, $t + 1.2, ['path_data' => $weg]), $t + 1.2);
check('Leitersturz erkannt (Fußpunkt)', $z['p4']['sturz'] !== '', true);

/* ------------------------------------------------------------- Geräusche, Zonen, Aufstehen */

$z = $N::beobachten([], 'new', ev('p5', $LIEGT, $t, ['stationary' => true]), $t);
check('ohne Ruf: 20 s = liegt', $N::bewerten($z, $t + 20, 60, [])['stufe'], $N::LIEGT);
check('mit Hilferuf: 20 s = Notfall', $N::bewerten($z, $t + 20, 60, [], $t + 3)['stufe'], $N::NOTFALL);
check('Ruf lange vorher zählt nicht', $N::bewerten($z, $t + 20, 60, [], $t - 600)['stufe'], $N::LIEGT);
check('Hilfe-Geräusche', [$N::istHilfeGeraeusch('yell'), $N::istHilfeGeraeusch('thump'), $N::istHilfeGeraeusch('speech'), $N::istHilfeGeraeusch('bark')], [true, true, false, false]);
check('andere Zone: ignoriert', $N::bewerten($z, $t + 90, 60, ['Einfahrt'])['stufe'], $N::OK);
check('passende Zone: zählt', $N::bewerten($z, $t + 90, 60, ['Einfahrt', 'Treppe'])['stufe'], $N::NOTFALL);
$z2 = $N::beobachten($z, 'update', ev('p5', $AUFRECHT, $t + 30), $t + 30);
check('steht wieder auf: alles gut', $N::bewerten($z2, $t + 90, 60, [])['stufe'], $N::OK);
// Liegend verloren, dann als neue Person aufrecht an derselben Stelle
$z3 = $N::beobachten($z, 'end', ev('p5', $LIEGT, $t + 5, ['end_time' => $t + 5]), $t + 5);
check('liegend verloren: bleibt beobachtet', $N::bewerten($z3, $t + 90, 60, [])['stufe'], $N::NOTFALL);
$z3 = $N::beobachten($z3, 'new', ev('p6', [610, 220, 690, 500], $t + 40), $t + 40);
check('neue aufrechte Person an der Stelle = aufgestanden', isset($z3['p5']), false);
// Aufrecht gegangen: nichts merken
$z4 = $N::beobachten([], 'new', ev('p7', $AUFRECHT, $t), $t);
$z4 = $N::beobachten($z4, 'end', ev('p7', $AUFRECHT, $t + 9, ['end_time' => $t + 9]), $t + 9);
check('aufrecht gegangen: vergessen', $z4, []);
check('Hund liegt: kein Thema', $N::beobachten([], 'new', ['label' => 'dog'] + ev('d1', $LIEGT, $t), $t), []);
check('Quittiert: OK bis zum Aufstehen', $N::bewerten($N::quittieren($z), $t + 90, 60, [])['stufe'], $N::OK);
check('Timer nur bei liegender Person', [$N::naechstePruefung($z), $N::naechstePruefung($z4), $N::naechstePruefung($N::quittieren($z))], [5, 0, 0]);

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

function paket(string $topic, $payload): string
{
    return (string)json_encode([
        'DataID' => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}', 'PacketType' => 3, 'QualityOfService' => 0,
        'Retain' => false, 'Topic' => $topic, 'Payload' => is_array($payload) ? json_encode($payload) : $payload,
    ]);
}

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'Name' => 'Flur', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
IPSTestState::$instances[30] = ['InstanceID' => 30, 'Name' => 'Dietmar', 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}']];

$k = new TestKamera(1);
$k->Create();
$k->props(['Camera' => 'Flur', 'PushZiele' => json_encode([['Ziel' => 30]]), 'PushPause' => 600]);
$k->ApplyChanges();
$an = fn (string $topic, $inhalt) => $k->ReceiveData(paket($topic, $inhalt));
$wert = fn (string $ident) => $k->variables[$ident]['value'] ?? '(fehlt)';
$jetzt = (float)time();

// Standard aus
$an('frigate/events', ['type' => 'new', 'after' => ev('a1', $LIEGT, $jetzt - 120, ['stationary' => true])]);
check('Standard aus: keine Variable, kein Timer', [$wert('Notfall'), $k->timers['Notfall']['interval'] ?? null], ['(fehlt)', 0]);

$k->props(['NotfallAn' => true]);
$k->ApplyChanges();
check('eingeschaltet: Variable „Notfall" = alles gut', $wert('Notfall'), 0);
$an('frigate/events', ['type' => 'new', 'after' => ev('a2', $LIEGT, $jetzt, ['stationary' => true])]);
check('liegt gerade: Stufe 1, Timer 5 s', [$wert('Notfall'), $k->timers['Notfall']['interval'] ?? null], [1, 5000]);
check('noch kein Push', $GLOBALS['gepusht'], []);
// Hilferuf → Wartezeit 15 s; Puffer zurückdatieren statt warten
$an('frigate/Flur/audio/yell', 'ON');
$z = json_decode($k->GetBuffer('Notfall'), true);
$z['a2']['liegtSeit'] = $jetzt - 20;
$k->SetBuffer('Notfall', json_encode($z));
$k->NotfallPruefen();
check('liegt 20 s + Hilferuf: Notfall', $wert('Notfall'), 3);
check('Push mit 🆘 trotz Mindestabstand', [count($GLOBALS['gepusht']), $GLOBALS['gepusht'][0][1] ?? ''], [1, '🆘 Flur']);
check('Text', strpos((string)$wert('NotfallText'), 'reglos') !== false && strpos((string)$wert('NotfallText'), 'Hilferuf') !== false, true);
$k->NotfallPruefen();
check('nur einmal gemeldet', count($GLOBALS['gepusht']), 1);
check('Quittieren', [$k->NotfallQuittieren(), $wert('Notfall'), $k->timers['Notfall']['interval'] ?? null], ['✅ Quittiert.', 0, 0]);
$an('frigate/events', ['type' => 'update', 'after' => ev('a2', $AUFRECHT, $jetzt + 1)]);
check('aufgestanden: alles gut', $wert('Notfall'), 0);

$form = json_decode((string)$k->GetConfigurationForm(), true);
$texte = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Formular: Panel mit Hinweis auf Grenzen', [strpos($texte, 'Person liegt / Sturz') !== false, strpos($texte, 'kein Notrufsystem') !== false], [true, true]);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
