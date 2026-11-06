<?php

declare(strict_types=1);

/**
 * Prüfstand Englisch (0.18.0): Quelltexte sind englisch, Deutsch kommt aus libs/locale.json.
 * Geprüft wird die Übersetzungstabelle (jeder feste FrigateHubText::t()-Text hat eine deutsche Fassung,
 * Platzhalter passen) und dass die Formulare aller drei Module auf Englisch keine deutschen Reste zeigen.
 * Aufruf: php .tools/test-englisch.php (stellt die Sprache selbst auf Englisch).
 */

putenv('FRIGATEHUB_SPRACHE=en');
require __DIR__ . '/ips-stub.php';
require __DIR__ . '/../FrigateHubKamera/module.php';
require __DIR__ . '/../FrigateHubServer/module.php';
require __DIR__ . '/../FrigateHubKonfigurator/module.php';

$fehler = 0;
$geprueft = 0;

function check(string $name, $ist, $soll, string $info = ''): void
{
    global $fehler, $geprueft;
    $geprueft++;
    if ($ist !== $soll) {
        $fehler++;
        echo "✗ {$name}\n    ist:  " . var_export($ist, true) . "\n    soll: " . var_export($soll, true) . ($info !== '' ? "\n    " . $info : '') . "\n";
    }
}

$basis = dirname(__DIR__);
$locale = (string)file_get_contents($basis . '/libs/locale.json');
$de = json_decode($locale, true)['translations']['de'] ?? [];
check('locale.json gültig, deutsche Tabelle vorhanden', is_array($de) && count($de) > 500, true);
$gleich = true;
foreach (['FrigateHubKamera', 'FrigateHubServer', 'FrigateHubKonfigurator'] as $modul) {
    $gleich = $gleich && file_get_contents($basis . '/' . $modul . '/locale.json') === $locale;
}
check('locale.json der drei Module = libs/locale.json (php .tools/locale-bauen.php)', $gleich, true);

/* ------------------------------------------------- jeder feste t()-Text hat Deutsch, Platzhalter passen */
$fehlt = [];
$platz = [];
$ph = fn (string $s): int => preg_match_all('/%(\d+\$)?[sd]/', str_replace('%%', '', $s));
foreach (array_merge(glob($basis . '/libs/*.php'), glob($basis . '/FrigateHub*/module.php')) as $datei) {
    $t = token_get_all((string)file_get_contents($datei));
    $n = count($t);
    for ($i = 0; $i < $n - 4; $i++) {
        if (!(is_array($t[$i]) && $t[$i][1] === 'FrigateHubText' && $t[$i + 1][1] === '::' && $t[$i + 2][1] === 't' && $t[$i + 3] === '('
            && is_array($t[$i + 4]) && $t[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING)) {
            continue;
        }
        $roh = $t[$i + 4][1];
        $text = $roh[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1)) : stripcslashes(substr($roh, 1, -1));
        // Text endet hier? (sonst wird er per . zusammengesetzt – dann nur den Anfang nicht prüfen)
        $folgt = $t[$i + 5] ?? '';
        $folgt = is_array($folgt) ? trim($folgt[1]) === '' ? ($t[$i + 6] ?? '') : $folgt : $folgt;
        if ($folgt === '.') {
            continue;
        }
        if (!isset($de[$text]) && preg_match('/[a-z]{3,}/', $text)) {
            $fehlt[] = basename($datei) . ': ' . $text;
        }
        $werte = 0;
        $tiefe = 0;
        for ($j = $i + 5; $j < $n; $j++) {
            $x = is_array($t[$j]) ? $t[$j][1] : $t[$j];
            if ($x === '(' || $x === '[') {
                $tiefe++;
            } elseif ($x === ')' || $x === ']') {
                if ($tiefe === 0) {
                    break;
                }
                $tiefe--;
            } elseif ($x === ',' && $tiefe === 0) {
                $werte++;
            }
        }
        if ($werte !== $ph($text)) {
            $platz[] = basename($datei) . ': ' . $text . ' (' . $ph($text) . ' Platzhalter, ' . $werte . ' Werte)';
        }
        if (isset($de[$text]) && $ph($de[$text]) !== $ph($text)) {
            $platz[] = 'Deutsch: ' . $text . ' → ' . $de[$text];
        }
    }
}
check('jeder feste FrigateHubText::t()-Text hat eine deutsche Fassung', $fehlt, [], (string)count($fehlt));
// Feste Formulartexte übersetzt die Konsole (Store-Checkliste 25) — jeder braucht trotzdem seinen locale.json-Eintrag
$festOhne = [];
foreach (array_merge(glob($basis . '/libs/*.php'), glob($basis . '/FrigateHub*/module.php')) as $datei) {
    $t = token_get_all((string)file_get_contents($datei));
    $n = count($t);
    for ($i = 0; $i < $n - 4; $i++) {
        if (is_array($t[$i]) && $t[$i][0] === T_CONSTANT_ENCAPSED_STRING && in_array($t[$i][1], ["'caption'", "'confirm'", "'label'", "'suffix'"], true)) {
            $j = $i + 1;
            while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) { $j++; }
            if (!(is_array($t[$j]) && $t[$j][0] === T_DOUBLE_ARROW)) { continue; }
            $j++;
            while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) { $j++; }
            $folgt = $t[$j + 1] ?? '';
            if (is_array($t[$j]) && $t[$j][0] === T_CONSTANT_ENCAPSED_STRING && !(is_string($folgt) && $folgt === '.')) {
                $roh = $t[$j][1];
                $text = $roh[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1)) : stripcslashes(substr($roh, 1, -1));
                if (preg_match('/[a-z]{3,}/', $text) && !isset($de[$text]) && !in_array($text, ['min'], true)) {   // Einheiten gleich in beiden Sprachen
                    $festOhne[] = basename($datei) . ': ' . $text;
                }
            }
        }
    }
}
check('jede feste Formular-Beschriftung (caption/confirm/label/suffix) hat eine deutsche Fassung', $festOhne, [], (string)count($festOhne));
check('Platzhalter passen (Werte = %s im englischen und deutschen Text)', $platz, []);
$deutschLeer = array_keys(array_filter($de, fn ($v) => !is_string($v) || $v === ''));
check('keine leere deutsche Fassung', $deutschLeer, []);

/* ------------------------------------------------- Formulare auf Englisch ohne deutsche Reste */
$texte = function ($x) use (&$texte): array {
    $aus = [];
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            if (in_array($k, ['caption', 'label', 'confirm', 'suffix'], true) && is_string($v)) {
                $aus[] = $v;
            } elseif (is_array($v)) {
                $aus = array_merge($aus, $texte($v));
            }
        }
    }
    return $aus;
};
$deutsch = fn (string $s): bool => (bool)preg_match('/[äöüÄÖÜß„]|\b(und|oder|nicht|der|die|das|ist|mit|bitte|Kamera|Kameras|Übernehmen|übernehmen|Speicher|Aufnahme|Wächter|Meldung|Verbindung|erreichbar|gestört|läuft|seit|Uhr|Anmeldung|Benutzer|Zugang|Einstellungen)\b/u', $s);

class EnKamera extends FrigateHubKamera
{
    public static array $api = [];

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }
}

class EnServer extends FrigateHubServer
{
    public static array $api = [];

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }
}

class EnKonfigurator extends FrigateHubKonfigurator
{
    public static array $api = [];

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $a = self::$api[$pfad] ?? null;
        return is_array($a) ? (string)json_encode($a) : $a;
    }

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }
}

$config = ['mqtt' => ['topic_prefix' => 'frigate'], 'lpr' => ['enabled' => true], 'face_recognition' => ['enabled' => true],
    'cameras' => ['Door-Cam' => ['name' => 'Door-Cam', 'enabled' => true, 'detect' => ['enabled' => true, 'width' => 1280, 'height' => 720, 'fps' => 5, 'stationary' => ['threshold' => 50]],
        'record' => ['enabled' => true, 'continuous' => ['days' => 0], 'alerts' => ['retain' => ['days' => 10]]], 'objects' => ['track' => ['person']],
        'review' => ['alerts' => ['labels' => ['person']]], 'snapshots' => ['enabled' => true], 'audio' => ['enabled' => false],
        'zones' => ['Carport' => ['objects' => ['person'], 'loitering_time' => 0]], 'ffmpeg' => ['inputs' => [['roles' => ['detect', 'record']]]]]]];
$stats = ['cameras' => ['Door-Cam' => ['camera_fps' => 5.1, 'process_fps' => 5.1, 'detection_fps' => 0, 'connection_quality' => 'poor', 'expected_fps' => 5, 'reconnects_last_hour' => 2, 'stalls_last_hour' => 1]],
    'service' => ['uptime' => 6147, 'version' => '0.18.2', 'latest_version' => '0.18.2', 'storage' => ['/media/frigate/recordings' => ['total' => 478159, 'used' => 60262.9, 'free' => 393535.2, 'mount_type' => 'ext4']]]];

$reste = [];
foreach ([false, true] as $mitApi) {
    $api = $mitApi ? ['/api/config' => $config, '/api/stats' => $stats, '/api/profiles' => ['profiles' => [], 'active_profile' => 'none']] : [];
    $props = $mitApi ? ['ApiUrl' => 'http://127.0.0.1:5000'] : [];

    IPSTestState::reset();
    IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}']];
    EnKamera::$api = $api;
    $k = new EnKamera(1);
    $k->Create();
    $k->props($props + ['Camera' => 'Door-Cam']);
    $k->ApplyChanges();
    foreach ($texte(json_decode($k->GetConfigurationForm(), true)) as $s) {
        if ($deutsch($s)) {
            $reste['Kamera: ' . mb_substr($s, 0, 90)] = true;
        }
    }

    IPSTestState::reset();
    IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}']];
    EnServer::$api = $api;
    $s = new EnServer(1);
    $s->Create();
    $s->props($props);
    $s->ApplyChanges();
    foreach ($texte(json_decode($s->GetConfigurationForm(), true)) as $x) {
        if ($deutsch($x)) {
            $reste['Server: ' . mb_substr($x, 0, 90)] = true;
        }
    }

    IPSTestState::reset();
    IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 2, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{AB2DD3FF-1892-4830-8C35-86982AB8D661}']];
    IPSTestState::$instances[2] = ['InstanceID' => 2, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{MQTT}']];
    EnKonfigurator::$api = $api;
    $c = new EnKonfigurator(1);
    $c->Create();
    $c->props($props);
    $c->ApplyChanges();
    foreach ($texte(json_decode($c->GetConfigurationForm(), true)) as $x) {
        if ($deutsch($x)) {
            $reste['Konfigurator: ' . mb_substr($x, 0, 90)] = true;
        }
    }
}
check('Formulare auf Englisch ohne deutsche Reste (Kamera, Server, Konfigurator, mit und ohne Frigate-Adresse)', array_keys($reste), []);

/* ------------------------------------------------- Stichproben: Englisch und Deutsch aus derselben Quelle */
check('englisch: Zustandsname aus Topics', FrigateHubText::t('Motion'), 'Motion');
IPSTestState::useLocale($basis . '/libs', 'de');
FrigateHubText::vergessen();
check('deutsch: dieselbe Quelle über locale.json', FrigateHubText::t('Motion'), $de['Motion'] ?? 'fehlt');

/* ------------------------------------------------- Stolperstein 24: je Text höchstens einmal Translate(), keine Datei lesen */
$fragen = 0;
$deutschFrage = function (string $t) use (&$fragen, $de): string { $fragen++; return $de[$t] ?? $t; };
FrigateHubText::vergessen();
FrigateHubText::setzen(FrigateHubText::mitMerkliste($deutschFrage));
$aus = [];
for ($i = 0; $i < 300; $i++) { $aus[] = FrigateHubText::t('Motion'); $aus[] = FrigateHubText::t('People'); }
check('Deutsch: 600 Übersetzungen, je Text einmal gefragt', [$fragen, $aus[0], $aus[1]], [2, $de['Motion'], $de['People']]);
$fragen = 0;
$englischFrage = function (string $t) use (&$fragen): string { $fragen++; return $t; };
FrigateHubText::vergessen();
FrigateHubText::setzen(FrigateHubText::mitMerkliste($englischFrage));
for ($i = 0; $i < 300; $i++) { FrigateHubText::t('Motion'); FrigateHubText::t('People'); }
check('Englisch: Quelltext, je Text einmal gefragt', [$fragen, FrigateHubText::t('People')], [2, 'People']);
$fehlerFrage = function (string $t): string { throw new RuntimeException('weg'); };
FrigateHubText::vergessen();
FrigateHubText::setzen(FrigateHubText::mitMerkliste($fehlerFrage));
check('Fehler beim Fragen: Quelltext statt Absturz', FrigateHubText::t('People'), 'People');
$code = (string)file_get_contents($basis . '/libs/FrigateHubText.php');
check('FrigateHubText liest keine Datei (Store: Dateizugriff nur als Ausnahme)', preg_match('/file_get_contents|fopen|locale\.json\'/', $code), 0);

echo "{$geprueft} Prüfungen" . ($fehler > 0 ? ", {$fehler} fehlgeschlagen.\n" : ", alle bestanden.\n");
echo ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler > 0 ? 1 : 0);
