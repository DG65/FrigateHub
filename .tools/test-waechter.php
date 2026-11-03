<?php

declare(strict_types=1);

/**
 * Prüfstand Aufnahme-Wächter — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-waechter.php
 *
 * Die Protokollzeilen sind die echten Zeilen aus Dietmars Frigate 0.18.2 beim Abriss der
 * Aufnahme-SSD am 31.10.2026 (19:12:54): ab 19:12:59 alle 5 s zwei Fehlerzeilen im Wechsel.
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

$GLOBALS['gepusht'] = [];
function VISU_PostNotificationEx(int $id, string $titel, string $text, string $icon, string $ton, int $ziel)
{
    $GLOBALS['gepusht'][] = [$id, $titel, $text, $ton, $ziel];
    return 4711;
}

/** Protokollzeile im Format von /api/logs/frigate (s6-Zeitstempel, zwei Leerzeichen, Frigate-Zeile). */
function zeile(int $t, string $nachricht, string $quelle = 'frigate.record.maintainer      ERROR   '): string
{
    return date('Y-m-d H:i:s', $t) . '.307860235  [' . date('Y-m-d H:i:s', $t) . '] ' . $quelle . ': ' . $nachricht;
}

/** Der Ausfall: je 5 s „Error occurred …" und „[Errno 5] …" (wie am 31.10.2026). */
function ausfall(int $von, int $bis): array
{
    $z = [];
    for ($t = $von; $t <= $bis; $t += 5) {
        $z[] = zeile($t, 'Error occurred when attempting to maintain recording cache');
        $z[] = zeile($t, "[Errno 5] Input/output error: '/media/frigate/recordings'");
    }
    return $z;
}

$W = 'FrigateHubWaechter';
$t0 = mktime(19, 12, 0, 10, 31, 2026);
$normal = [zeile($t0 - 600, 'Preparing Frigate...', 'frigate.app                    INFO    '),
    zeile($t0 - 30, 'Camera processor started for Door-Cam', 'frigate.camera.maintainer      INFO    ')];

/* ------------------------------------------------------------- Speicher */

$ssd = ['service' => ['storage' => ['/media/frigate/recordings' => ['total' => 478159, 'used' => 61052.6, 'free' => 392745.6, 'mount_type' => 'ext4']]]];
$sp = $W::speicher($ssd);
check('Speicher aus /api/stats', $sp, ['total' => 478159.0, 'typ' => 'ext4']);
check('Speicher fehlt in der Statistik', $W::speicher(['service' => []]), null);
check('Speicher 0 MB gilt nicht', $W::speicher(['service' => ['storage' => ['/media/frigate/recordings' => ['total' => 0]]]]), null);
check('Text', $W::speicherText($sp), '467 GB (ext4)');
check('kleine Schwankung ist kein Wechsel', $W::speicherGewechselt($sp, ['total' => 478159 * 1.019, 'typ' => 'ext4']), false);
check('andere Größe ist ein Wechsel', $W::speicherGewechselt($sp, ['total' => 59000.0, 'typ' => 'ext4']), true);
check('andere Art ist ein Wechsel', $W::speicherGewechselt($sp, ['total' => 478159.0, 'typ' => 'overlay']), true);
check('fehlende Art zählt nicht', $W::speicherGewechselt($sp, ['total' => 478159.0, 'typ' => '']), false);

/* ------------------------------------------------------------- Protokoll */

$leer = ['ts' => '', 'n' => 0];
$p = $W::protokoll(array_merge($normal, ausfall($t0 + 59, $t0 + 70)), '', $leer);
check('erste Prüfung merkt nur den Stand', [$p['fehler'], $p['neueste']], [0, date('Y-m-d H:i:s', $t0 + 69) . '.307860235']);
$gesehen = date('Y-m-d H:i:s', $t0 - 30) . '.307860235';
$p = $W::protokoll(array_merge($normal, ausfall($t0 + 59, $t0 + 115)), $gesehen, $leer);
check('Ausfall 31.10.: 12 × 5 s → 12 Fehler (Errno-Zeile zählt nicht doppelt)', $p['fehler'], 12);
$p2 = $W::protokoll(array_merge($normal, ausfall($t0 + 59, $t0 + 175)), $p['neueste'], $p['wiederholt']);
check('nur neue Zeilen zählen', $p2['fehler'], 12);
$p3 = $W::protokoll(array_merge($normal, ausfall($t0 + 59, $t0 + 175)), $p2['neueste'], $p2['wiederholt']);
check('nichts Neues → 0', $p3['fehler'], 0);
$ruhig = $W::protokoll([zeile($t0, 'Recording maintenance ok', 'frigate.record.maintainer      INFO    ')], '', $leer);
check('Info-Zeile ist kein Fehler', $W::protokoll([zeile($t0 + 1, 'alles gut', 'x INFO ')], $ruhig['neueste'], $leer)['fehler'], 0);
check('Unable to store … zählt', $W::istSchreibfehler(zeile($t0, 'Unable to store recording segment /tmp/cache/Door-Cam@20261031191300+0100.mp4')), true);
check('Fremde Zeile mit „error" zählt nicht', $W::istSchreibfehler(zeile($t0, 'Error getting frame', 'frigate.video ERROR')), false);

// Frigate fasst gleiche Zeilen hintereinander zusammen; die Zusammenfassung wächst mit
$erste = zeile($t0 + 60, 'Unable to store recording segment /tmp/cache/x.mp4');
$wdh = fn (int $n) => date('Y-m-d H:i:s', $t0 + 60) . '.307860235  [LOGGING] Last message repeated ' . $n . ' times';
$a = $W::protokoll([$normal[1], $erste, $wdh(4)], $gesehen, $leer);
check('Wiederholung: 1 + 4', $a['fehler'], 5);
$b = $W::protokoll([$normal[1], $erste, $wdh(9)], $a['neueste'], $a['wiederholt']);
check('Wiederholung wächst: nur der Zuwachs', $b['fehler'], 5);
$c = $W::protokoll([$normal[1], zeile($t0 + 61, 'Camera ok', 'frigate.video INFO'), $wdh(9)], $b['neueste'], $b['wiederholt']);
check('Wiederholung einer harmlosen Zeile zählt nicht', $c['fehler'], 0);
check('Zeile ohne Zeitstempel übergangen', $W::protokoll(['kaputt', '', 'Error occurred when attempting to maintain recording cache'], $gesehen, $leer)['fehler'], 0);

/* ------------------------------------------------------------- Einstellungen und Alarme */

$config = ['cameras' => [
    // Dietmars Door-Cam (0.18.2): keine Daueraufnahme, Alarme 10 Tage
    'Door-Cam' => ['enabled' => true, 'record' => ['enabled' => true, 'continuous' => ['days' => 0], 'alerts' => ['retain' => ['days' => 10]]]],
    'Garten'   => ['enabled' => true, 'record' => ['enabled' => true, 'continuous' => ['days' => 3], 'alerts' => ['retain' => ['days' => 0]]]],
    'Keller'   => ['enabled' => true, 'record' => ['enabled' => false, 'continuous' => ['days' => 3]]],
    'Aus'      => ['enabled' => false, 'record' => ['enabled' => true, 'continuous' => ['days' => 3], 'alerts' => ['retain' => ['days' => 10]]]],
    'Alt'      => ['record' => ['enabled' => true, 'retain' => ['days' => 2, 'mode' => 'all'], 'events' => ['retain' => ['default' => 10]]]],
]];
check('Kameras aus /api/config', $W::kameras($config), [
    'Door-Cam' => ['aufnahme' => true, 'dauer' => false, 'alarme' => true],
    'Garten'   => ['aufnahme' => true, 'dauer' => true, 'alarme' => false],
    'Keller'   => ['aufnahme' => false, 'dauer' => false, 'alarme' => false],
    'Aus'      => ['aufnahme' => false, 'dauer' => false, 'alarme' => false],
    'Alt'      => ['aufnahme' => true, 'dauer' => true, 'alarme' => true],
]);

check('Fenster beim ersten Mal', $W::alarmFenster([], 10000), [10000 - 120 - 600, 10000 - 120]);
check('Fenster schließt an', $W::alarmFenster(['alarmeBis' => 9800], 10000), [9800, 9880]);
$reviews = [
    ['camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => 9000.5, 'end_time' => 9850.2],
    ['camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => 9850.0, 'end_time' => null],        // läuft noch
    ['camera' => 'Door-Cam', 'severity' => 'detection', 'start_time' => 9810.0, 'end_time' => 9820.0],
    ['camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => 9700.0, 'end_time' => 9790.0],      // schon geprüft
    ['camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => 9870.0, 'end_time' => 9890.0],      // noch zu frisch
];
check('nur abgeschlossene Alarme im Fenster', $W::alarme($reviews, 9800, 9880), [['kamera' => 'Door-Cam', 'start' => 9000.5, 'ende' => 9850.2]]);
check('Film: Teilstücke mit Inhalt', $W::hatFilm([['segment_size' => 0], ['segment_size' => 1.8]]), true);
check('kein Film: keine Teilstücke', $W::hatFilm([]), false);
check('kein Film: nur leere Teilstücke', $W::hatFilm([['segment_size' => 0.0]]), false);
check('ältere Frigate ohne segment_size', $W::hatFilm([['start_time' => 1, 'end_time' => 2]]), true);
check('neueste Aufnahme', $W::neuesteAufnahme([['end_time' => 5.5], ['end_time' => 9.0], ['end_time' => 'x']]), 9.0);

/* ------------------------------------------------------------- Bewertung */

$gut = ['erreichbar' => true, 'speicher' => $sp, 'protokoll' => ['fehler' => 0, 'neueste' => 'a', 'wiederholt' => $leer], 'alarme' => [], 'alarmeBis' => $t0, 'dauer' => []];
$e = $W::bewerten([], $gut, $t0);
check('alles gut: keine Meldung, Speicher gemerkt', [$e['probleme'], $e['meldung'], $e['zustand']['speicher']], [[], null, $sp]);
check('Zustandstext gut', $W::zustandText($e['probleme']), 'Frigate zeichnet auf, keine Störung erkannt.');

// Abriss wie am 31.10.: 12 Fehler in einer Minute
$z = $e['zustand'];
$e = $W::bewerten($z, ['protokoll' => ['fehler' => 12, 'neueste' => 'b', 'wiederholt' => $leer]] + $gut, $t0 + 60);
check('Schreibfehler → Störung', [$e['meldung']['titel'] ?? null, $e['meldung']['text'] ?? null, $e['meldung']['art'] ?? null],
    ['⛔ Frigate-Aufnahme gestört', 'Frigate kann Aufnahmen nicht speichern: Schreibfehler seit 19:13.', 'stoerung']);
check('Titel passt in 32 Bytes', strlen($e['meldung']['titel']) <= 32, true);
$e = $W::bewerten($e['zustand'], ['protokoll' => ['fehler' => 24, 'neueste' => 'c', 'wiederholt' => $leer]] + $gut, $t0 + 120);
check('anhaltend: keine zweite Meldung', [$e['meldung'], array_keys($e['probleme'])], [null, ['schreibfehler']]);
$e = $W::bewerten($e['zustand'], ['protokoll' => ['fehler' => 2, 'neueste' => 'd', 'wiederholt' => $leer]] + $gut, $t0 + 180);
check('vereinzelte Fehler halten die Störung', array_keys($e['probleme']), ['schreibfehler']);
$e = $W::bewerten($e['zustand'], $gut, $t0 + 240);
check('eine gute Prüfung reicht nicht', [$e['meldung'], array_keys($e['probleme'])], [null, ['schreibfehler']]);
$e = $W::bewerten($e['zustand'], $gut, $t0 + 300);
check('zwei gute Prüfungen → Entwarnung', [$e['probleme'], $e['meldung']['art'] ?? null], [[], 'entwarnung']);
$e = $W::bewerten($e['zustand'], $gut, $t0 + 360);
check('danach Ruhe', $e['meldung'], null);
$z = $e['zustand'];

// Unter dem Schwellwert bleibt es still
$e = $W::bewerten($z, ['protokoll' => ['fehler' => 2, 'neueste' => 'e', 'wiederholt' => $leer]] + $gut, $t0 + 400);
check('2 Fehler sind noch keine Störung', [$e['probleme'], $e['meldung']], [[], null]);

// Protokoll nicht lesbar: übrige Prüfungen laufen
$e = $W::bewerten($z, ['protokoll' => null] + $gut, $t0 + 400);
check('Protokoll nicht lesbar: keine Störung daraus', $e['probleme'], []);

// Frigate antwortet nicht
$e = $W::bewerten($z, ['erreichbar' => false], $t0 + 1000);
check('Stille 0 s: noch keine Störung', [$e['probleme'], $e['meldung']], [[], null]);
$e = $W::bewerten($e['zustand'], ['erreichbar' => false], $t0 + 1179);
check('Stille 179 s: noch keine Störung', $e['probleme'], []);
$e = $W::bewerten($e['zustand'], ['erreichbar' => false], $t0 + 1180);
check('Stille 180 s → Störung', [$e['meldung']['text'] ?? null], ['Frigate antwortet seit 19:28 nicht.']);
$e = $W::bewerten($e['zustand'], $gut, $t0 + 1240);
check('antwortet wieder → Entwarnung', [$e['probleme'], $e['meldung']['art'] ?? null], [[], 'entwarnung']);
$e = $W::bewerten($e['zustand'], ['erreichbar' => false], $t0 + 1300);
$e = $W::bewerten($e['zustand'], $gut, $t0 + 1360);
check('kurzer Neustart (60 s) bleibt still', [$e['probleme'], $e['meldung'], isset($e['zustand']['stilleSeit'])], [[], null, false]);
$z = $e['zustand'];

// Speicher vertauscht (SSD fehlt, Frigate schreibt auf die Systemplatte)
$e = $W::bewerten($z, ['speicher' => ['total' => 117000.0, 'typ' => 'ext4']] + $gut, $t0 + 1400);
check('Speicher gewechselt → Störung', $e['meldung']['text'] ?? null, 'Aufnahmespeicher gewechselt: jetzt 114 GB (ext4) statt 467 GB (ext4).');
$e = $W::bewerten($e['zustand'], $gut, $t0 + 1460);
check('Speicher wieder da → Entwarnung', [$e['probleme'], $e['meldung']['art'] ?? null], [[], 'entwarnung']);

// Alarm ohne Film, dann Alarm mit Film
$e = $W::bewerten($e['zustand'], ['alarme' => [['kamera' => 'Door-Cam', 'ende' => $t0 + 1500, 'film' => false]], 'alarmeBis' => $t0 + 1500] + $gut, $t0 + 1620);
check('Alarm ohne Film → Störung', [$e['meldung']['text'] ?? null, $e['zustand']['alarmeBis']], ['Alarm ohne Aufnahme: Door-Cam 19:37.', $t0 + 1500]);
$e = $W::bewerten($e['zustand'], ['alarme' => []] + $gut, $t0 + 1680);
check('ohne neuen Alarm bleibt sie stehen', array_keys($e['probleme']), ['alarm']);
$e = $W::bewerten($e['zustand'], ['alarme' => [['kamera' => 'Door-Cam', 'ende' => $t0 + 1700, 'film' => true]]] + $gut, $t0 + 1820);
check('nächster Alarm mit Film → Entwarnung', [$e['probleme'], $e['meldung']['art'] ?? null], [[], 'entwarnung']);
$e2 = $W::bewerten($e['zustand'], ['alarme' => [['kamera' => 'Door-Cam', 'ende' => $t0 + 1900, 'film' => false]]] + $gut, $t0 + 2000);
$e2 = $W::bewerten($e2['zustand'], $gut, $t0 + 1900 + 86400);
check('Alarm ohne Film erlischt nach 24 h', $e2['probleme'], []);

// Daueraufnahme steht
$e = $W::bewerten($e['zustand'], ['dauer' => ['Garten' => (float)($t0 + 2000)]] + $gut, $t0 + 2300);
check('Daueraufnahme 300 s alt: noch gut', $e['probleme'], []);
$e = $W::bewerten($e['zustand'], ['dauer' => ['Garten' => (float)($t0 + 2000)]] + $gut, $t0 + 2301);
check('Daueraufnahme 301 s alt → Störung', $e['meldung']['text'] ?? null, 'Daueraufnahme steht: Garten seit 19:45.');
check('ohne jede Aufnahme', $W::bewerten([], ['dauer' => ['Garten' => 0.0]] + $gut, $t0)['probleme']['dauer'] ?? null, 'Daueraufnahme steht: Garten.');

// Zweite Störung kommt hinzu: neue Meldung mit beiden
$e = $W::bewerten($e['zustand'], ['dauer' => ['Garten' => (float)($t0 + 2000)], 'protokoll' => ['fehler' => 8, 'neueste' => 'f', 'wiederholt' => $leer]] + $gut, $t0 + 2361);
check('neue Störung dazu: Meldung mit beiden, Reihenfolge fest', $e['meldung']['text'] ?? null,
    'Frigate kann Aufnahmen nicht speichern: Schreibfehler seit 19:51. Daueraufnahme steht: Garten seit 19:45.');
$z = $e['zustand'];
$lauf = ['dauer' => ['Garten' => (float)($t0 + 2000)], 'protokoll' => ['fehler' => 8, 'neueste' => 'g', 'wiederholt' => $leer]] + $gut;
$e = $W::bewerten($z, $lauf, $t0 + 2361 + 86399);
check('vor 24 h keine Erinnerung', $e['meldung'], null);
$e = $W::bewerten($e['zustand'], $lauf, $t0 + 2361 + 86400);
check('nach 24 h Erinnerung, Zeit mit Datum', [$e['meldung']['art'] ?? null, $e['meldung']['titel'] ?? null, $e['meldung']['text'] ?? null],
    ['erinnerung', '⏰ Frigate-Aufnahme gestört', 'Weiterhin: Frigate kann Aufnahmen nicht speichern: Schreibfehler seit 31.10. 19:51. Daueraufnahme steht: Garten seit 31.10. 19:45.']);

// Während Frigate schweigt, bleiben andere Störungen stehen
$e = $W::bewerten($z, ['erreichbar' => false], $t0 + 2400);
check('Stille friert andere Störungen ein', array_keys($e['probleme']), ['schreibfehler', 'dauer']);

/* ------------------------------------------------------------- Modul */

class TestWaechter extends FrigateHubServer
{
    /** @var callable(string):?string */
    public static $api;

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }

    protected function apiRoh(string $pfad, string $accept): ?string
    {
        // Ganzzahl = HTTP-Fehler (der Grund steht im privaten $apiFehler des Traits, hier nur „keine Antwort")
        $antwort = (self::$api)($pfad);
        return is_int($antwort) ? null : $antwort;
    }

    protected function apiDurchreichen(string $pfad, string $range, int $zeitlimit): array
    {
        return ['code' => 404, 'body' => '', 'kopf' => []];
    }
}

$jetzt = time();
$protokoll = array_merge($normal);
$aufrufe = [];
$frigate = [
    'stats'   => $ssd + ['cameras' => ['Door-Cam' => ['camera_fps' => 5.0], 'Garten' => ['camera_fps' => 0]]],
    'log'     => fn () => json_encode(['totalLines' => count($protokoll), 'lines' => array_slice($protokoll, -300)]),
    'config'  => ['cameras' => ['Door-Cam' => $config['cameras']['Door-Cam'], 'Garten' => $config['cameras']['Garten']]],
    'reviews' => [],
    'film'    => [['segment_size' => 2.1, 'end_time' => $jetzt]],
];
TestWaechter::$api = function (string $pfad) use (&$frigate, &$protokoll, &$aufrufe) {
    $aufrufe[] = $pfad;
    if ($frigate['stats'] === null) {
        return null;
    }
    if ($pfad === '/api/stats') {
        return json_encode($frigate['stats']);
    }
    if (strpos($pfad, '/api/logs/frigate') === 0) {
        return is_int($frigate['log']) ? $frigate['log'] : json_encode(['totalLines' => count($protokoll), 'lines' => array_slice($protokoll, -300)]);
    }
    if ($pfad === '/api/config') {
        return json_encode($frigate['config']);
    }
    if (strpos($pfad, '/api/review?') === 0) {
        return json_encode($frigate['reviews']);
    }
    if (preg_match('#^/api/[^/]+/recordings\?#', $pfad)) {
        return json_encode($frigate['film']);
    }
    return 404;
};

IPSTestState::reset();
IPSTestState::$instances[1] = ['InstanceID' => 1, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}']];
IPSTestState::$instances[42569] = ['InstanceID' => 42569, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE, 'ModuleInfo' => ['ModuleID' => '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}']];
$s = new TestWaechter(1);
$s->Create();
$s->ApplyChanges();
check('ohne Adresse: Wächter aus, keine Variablen dafür', [$s->timers['Waechter']['interval'], isset($s->variables['AufnahmeOk'])], [0, false]);
check('ohne Adresse: Hinweis', strpos($s->AufnahmePruefen(), 'ℹ️ Der Aufnahme-Wächter ist aus: er braucht die Frigate-Adresse.') === 0, true);

$s->props(['ApiUrl' => 'http://127.0.0.1:5000']);
$s->ApplyChanges();
check('mit Adresse: erste Prüfung nach 3 s, zwei Variablen', [$s->timers['Waechter']['interval'], isset($s->variables['AufnahmeOk']), isset($s->variables['Aufnahmezustand'])], [3000, true, true]);

$text = $s->AufnahmePruefen();
check('danach jede Minute', $s->timers['Waechter']['interval'], 60000);
check('erste Prüfung: in Ordnung', [$s->GetValue('AufnahmeOk'), $s->GetValue('Aufnahmezustand'), strpos($text, '✅') === 0], [true, 'Frigate zeichnet auf, keine Störung erkannt.', true]);
check('Protokoll mit negativem Start', in_array('/api/logs/frigate?start=-300', $aufrufe, true), true);
check('Garten liefert keine Bilder: Daueraufnahme nicht abgefragt', count(preg_grep('#^/api/Garten/recordings#', $aufrufe)), 0);
check('Speicher gemerkt', strpos(json_encode(json_decode((string)$s->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Gemerkter Aufnahmespeicher: 467 GB (ext4).') !== false, true);

// Ohne Ziel: Störung nur in Variablen und Meldungsprotokoll
$protokoll = array_merge($normal, ausfall($jetzt - 50, $jetzt));
$s->AufnahmePruefen();
check('Abriss: Variable gestört', [$s->GetValue('AufnahmeOk'), strpos($s->GetValue('Aufnahmezustand'), 'Frigate kann Aufnahmen nicht speichern') === 0], [false, true]);
check('ohne Ziel kein Push, aber Protokolleintrag', [$GLOBALS['gepusht'], strpos(end(IPSTestState::$logMessages), '⛔ Frigate-Aufnahme gestört: ') === 0], [[], true]);

// Mit Ziel: Entwarnung kommt an
$s->props(['PushZiele' => json_encode([['Ziel' => 42569]])]);
$s->ApplyChanges();
$protokoll[] = zeile($jetzt + 1, 'Camera ok', 'frigate.video INFO');
$s->AufnahmePruefen();
$protokoll[] = zeile($jetzt + 2, 'Camera ok 2', 'frigate.video INFO');
$s->AufnahmePruefen();
check('Entwarnung an die Kachel-Visualisierung, Antippen öffnet den Server', $GLOBALS['gepusht'][0] ?? null,
    [42569, '✅ Frigate-Aufnahme in Ordnung', 'Die Störung ist behoben, Frigate zeichnet wieder auf.', 'alarm', 1]);
check('Probemeldung', [strpos($s->PushTesten(), '✅') === 0, $GLOBALS['gepusht'][1][2] ?? null], [true, 'Probemeldung vom Aufnahme-Wächter – so kommt eine Störung an.']);

// Alarm ohne Film
$GLOBALS['gepusht'] = [];
$frigate['reviews'] = [['camera' => 'Door-Cam', 'severity' => 'alert', 'start_time' => $jetzt - 400, 'end_time' => $jetzt - 300]];
$frigate['film'] = [];
// Das Fenster schließt an die letzte Prüfung an — so tun, als läge sie 10 min zurück
$z = json_decode($s->ReadAttributeString('Waechter'), true);
$s->WriteAttributeString('Waechter', json_encode(['alarmeBis' => $jetzt - 1000] + $z));
$s->AufnahmePruefen();
check('Alarm ohne Film gemeldet', strpos($GLOBALS['gepusht'][0][2] ?? '', 'Alarm ohne Aufnahme: Door-Cam ') === 0, true);
check('Alarm-Abfrage nur für Kameras mit Alarm-Aufnahmen', count(preg_grep('#^/api/review\?severity=alert&limit=50&cameras=Door-Cam&after=#', $aufrufe)) > 0, true);
$frigate['reviews'] = [];
$frigate['film'] = [['segment_size' => 2.1, 'end_time' => $jetzt]];

// Protokoll nicht lesbar (Port 8971 ohne admin)
$frigate['log'] = 403;
$text = $s->AufnahmePruefen();
check('Protokoll 403: Hinweis auf die Rolle admin', strpos($text, 'auf Port 8971 braucht der Frigate-Benutzer die Rolle „admin"') !== false, true);
$frigate['log'] = null;

// Speicher gewechselt, dann übernommen
$GLOBALS['gepusht'] = [];
$frigate['stats']['service']['storage']['/media/frigate/recordings']['total'] = 117000;
$s->AufnahmePruefen();
check('Speicherwechsel gemeldet', strpos($GLOBALS['gepusht'][0][2] ?? '', 'Aufnahmespeicher gewechselt: jetzt 114 GB (ext4)') !== false, true);
$text = $s->SpeicherUebernehmen();
check('Speicher übernommen, nur der Alarm ohne Film steht noch', [$text, $s->GetValue('Aufnahmezustand')],
    ['✅ Jetziger Aufnahmespeicher übernommen: Gemerkter Aufnahmespeicher: 114 GB (ext4).', 'Alarm ohne Aufnahme: Door-Cam ' . date('H:i', $jetzt - 300) . '.']);

// Frigate antwortet nicht: Variable bleibt bis zur Schwelle in Ordnung
$frigate['stats'] = null;
$text = $s->AufnahmePruefen();
check('keine Antwort: Hinweis, noch keine neue Störung', [strpos($s->GetValue('Aufnahmezustand'), 'antwortet') === false, strpos($text, 'nicht erreichbar') !== false], [true, true]);

// Häkchen aus
$s->props(['WaechterAktiv' => false]);
$s->ApplyChanges();
check('Häkchen aus: Timer aus, Variablen bleiben', [$s->timers['Waechter']['interval'], isset($s->variables['AufnahmeOk'])], [0, true]);

// Formular: Panel steht hinter „Frigate-Server"
$s->props(['WaechterAktiv' => true]);
$el = json_decode((string)$s->GetConfigurationForm(), true)['elements'];
$caps = array_values(array_map(fn ($e) => (string)$e['caption'], array_filter($el, fn ($e) => ($e['type'] ?? '') === 'ExpansionPanel' && preg_match('/Frigate-Server|Aufnahme-Wächter/u', (string)$e['caption']))));
check('Formular: Wächter-Panel nach dem Server-Panel', $caps, ['🖥️  Frigate-Server', '🎥  Aufnahme-Wächter']);

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
