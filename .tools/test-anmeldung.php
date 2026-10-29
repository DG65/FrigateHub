<?php

declare(strict_types=1);

/**
 * Prüfstand Anmeldung an Frigate (Port 8971) — ohne Symcon, ohne Netz.
 *
 *   php .tools/test-anmeldung.php
 *
 * Nachgebildet nach Frigates frigate/api/auth.py (POST /api/login setzt ein JWT als Cookie,
 * 401 bei falschen Daten, 404 bei abgeschalteter Anmeldung) und nginx auth_request.conf
 * (verlängertes Token kommt per Set-Cookie zurück). Port 8971 bei Dietmar: 401 ohne Anmeldung,
 * selbst signiertes Zertifikat (live 29.10.2026).
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

function jwt(int $exp): string
{
    $b = fn (array $a) => rtrim(strtr(base64_encode((string)json_encode($a)), '+/', '-_'), '=');
    return $b(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $b(['sub' => 'admin', 'role' => 'admin', 'exp' => $exp]) . '.c2lnbmF0dXI';
}

/** Kamera mit nachgebildetem Frigate hinter Port 8971. */
class TestAnmeldung extends FrigateHubKamera
{
    /** @var TestAnmeldung[] */
    public static array $alle = [];
    public static string $passwort = 'geheim-123';
    public static string $cookieName = 'frigate_token';
    public static bool $anmeldungAus = false;
    /** Gültiges Token in Frigate (wer es schickt, kommt durch). */
    public static array $gueltig = [];
    /** Nächste Antwort setzt dieses neue Token (Verlängerung). */
    public static ?string $verlaengern = null;
    public array $anfragen = [];

    public function props(array $p): void
    {
        foreach ($p as $k => $v) {
            $this->TEST_SetProperty($k, $v);
        }
    }

    public function attribute(): array
    {
        return $this->attributes;
    }

    protected function apiAnfrage(string $methode, string $pfad, array $kopf, ?string $inhalt): array
    {
        $this->anfragen[] = [$methode, $pfad, array_values($kopf), $inhalt];
        if ($pfad === '/api/login') {
            if (self::$anmeldungAus) {
                return ['code' => 404, 'body' => '{"message":"Authentication is disabled"}', 'cookies' => [], 'fehler' => ''];
            }
            $d = json_decode((string)$inhalt, true);
            if (($d['user'] ?? '') !== 'admin' || ($d['password'] ?? '') !== self::$passwort) {
                return ['code' => 401, 'body' => '{"message":"Login failed"}', 'cookies' => [], 'fehler' => ''];
            }
            $t = jwt(time() + 86400);
            self::$gueltig[] = $t;
            return ['code' => 200, 'body' => '', 'cookies' => [self::$cookieName => $t], 'fehler' => ''];
        }
        $token = '';
        foreach ($kopf as $k) {
            if (strpos($k, 'Cookie: ' . self::$cookieName . '=') === 0) {
                $token = substr($k, strlen('Cookie: ' . self::$cookieName . '='));
            }
        }
        if (!in_array($token, self::$gueltig, true)) {
            return ['code' => 401, 'body' => '{"message":"Unauthorized"}', 'cookies' => [], 'fehler' => ''];
        }
        $cookies = [];
        if (self::$verlaengern !== null) {
            $cookies[self::$cookieName] = self::$verlaengern;
            self::$gueltig[] = self::$verlaengern;
            self::$verlaengern = null;
        }
        $body = $pfad === '/api/version' ? '0.18.2' : (string)json_encode(['cameras' => ['Door-Cam' => []], 'service' => ['version' => '0.18.2']]);
        return ['code' => 200, 'body' => $body, 'cookies' => $cookies, 'fehler' => ''];
    }
}

/** Nachbildung der Symcon-Instanzfunktion für das Teilen der Anmeldung. */
function FRIG_AnmeldungTeilen(int $id): string
{
    return TestAnmeldung::$alle[$id]->AnmeldungTeilen();
}

function neu(int $id, array $props): TestAnmeldung
{
    IPSTestState::$instances[$id] = ['InstanceID' => $id, 'ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE,
        'ModuleInfo' => ['ModuleID' => '{6BB54171-2F24-4653-9061-F2BC352A9D3B}'], 'Properties' => $props];
    $i = new TestAnmeldung($id);
    $i->Create();
    $i->props($props);
    TestAnmeldung::$alle[$id] = $i;
    return $i;
}

$URL = 'https://192.168.2.7:8971';
IPSTestState::reset();

/* ------------------------------------------------------------- ohne Anmeldung */
$a = neu(1, ['Camera' => 'Door-Cam', 'ApiUrl' => $URL]);
$a->ApplyChanges();
check('ohne Zugang: Timer aus', $a->timers['Anmeldung']['interval'], 0);
check('Status: nicht angemeldet', strpos($a->ZustandAnfordern(), 'Frigate verlangt eine Anmeldung') !== false, true);
check('ohne Zugang kein Cookie gesendet', count(array_filter($a->anfragen[0][2], fn ($k) => strpos($k, 'Cookie:') === 0)), 0);

/* ------------------------------------------------------------- Anmelden */
check('leeres Passwort', $a->Anmelden('admin', ''), '⚠️ Bitte Benutzer und Passwort eintragen.');
check('falsches Passwort', $a->Anmelden('admin', 'falsch'), '⛔ Frigate lehnt Benutzer oder Passwort ab.');
check('nach Fehlschlag kein Zugang', $a->AnmeldungTeilen(), '');
$text = $a->Anmelden(' admin ', TestAnmeldung::$passwort);
check('Anmeldung ok', strpos($text, '✅ Angemeldet als admin, Zugang gültig bis ') === 0, true);
$login = array_values(array_filter($a->anfragen, fn ($r) => $r[1] === '/api/login'));
check('Login per POST mit JSON', [end($login)[0], json_decode((string)end($login)[3], true)], ['POST', ['user' => 'admin', 'password' => TestAnmeldung::$passwort]]);
check('Timer an (10 min)', $a->timers['Anmeldung']['interval'], 600000);
check('Passwortfeld geleert', in_array(['ApiPasswort', 'value', ''], $a->formFieldUpdates, true), true);
check('Passwort nirgends gespeichert', strpos((string)json_encode($a->attribute()), TestAnmeldung::$passwort), false);
check('Statuszeile aktualisiert', end($a->formFieldUpdates), ['Anmeldestatus', 'caption', $text]);

/* ------------------------------------------------------------- mit Zugang */
$a->anfragen = [];
check('Abgleich mit Zugang', strpos($a->ZustandAnfordern(), '✅') === 0, true);
check('Cookie gesendet', in_array('Cookie: frigate_token=' . TestAnmeldung::$gueltig[0], $a->anfragen[0][2], true), true);

// Verlängerung per Set-Cookie
$neuesToken = jwt(time() + 2 * 86400);
TestAnmeldung::$verlaengern = $neuesToken;
$a->AnmeldungAuffrischen();
check('Auffrischen fragt /api/version', end($a->anfragen)[1], '/api/version');
check('verlängertes Token übernommen', json_decode($a->ReadAttributeString('ApiZugang'), true)['token'], $neuesToken);
check('Ablauf aus dem JWT', json_decode($a->ReadAttributeString('ApiZugang'), true)['ablauf'], time() + 2 * 86400);

/* ------------------------------------------------------------- Teilen */
$b = neu(2, ['Camera' => 'Garten', 'ApiUrl' => $URL . '/']);
$b->ApplyChanges();
check('Geschwister übernimmt Zugang', $b->ZustandAnfordern() !== '' && in_array('Cookie: frigate_token=' . $neuesToken, $b->anfragen[0][2], true), true);
check('Geschwister: Timer an', $b->timers['Anmeldung']['interval'], 600000);
$c = neu(3, ['Camera' => 'Door-Cam', 'ApiUrl' => 'https://192.168.2.99:8971']);
$c->ApplyChanges();
$c->ZustandAnfordern();
check('andere Adresse teilt nicht', count(array_filter($c->anfragen[0][2], fn ($k) => strpos($k, 'Cookie:') === 0)), 0);
$d = neu(4, ['Camera' => 'Door-Cam', 'ApiUrl' => 'http://192.168.2.7:5000']);
$d->ApplyChanges();
$d->ZustandAnfordern();
check('Port 5000 holt keinen Zugang', $d->ReadAttributeString('ApiZugang'), '');
check('Port 5000: Status', strpos((string)json_encode(json_decode($d->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Keine Anmeldung nötig (Port 5000).') !== false, true);

/* ------------------------------------------------------------- Ablauf */
TestAnmeldung::$gueltig = [];
$text = $a->ZustandAnfordern();
check('abgelaufen: Zugang verworfen', $a->ReadAttributeString('ApiZugang'), '');
check('abgelaufen: Timer aus', $a->timers['Anmeldung']['interval'], 0);
check('abgelaufen: Hinweis', strpos($text, 'Anmeldung abgelaufen') !== false, true);
check('abgelaufen: Meldung im Log', in_array('Frigate-Anmeldung abgelaufen, bitte in der Instanz neu anmelden', IPSTestState::$logMessages, true), true);
check('abgelaufen: Statuszeile', strpos($a->GetConfigurationForm(), 'abgelaufen') !== false, true);
// Kein erneutes Teilen eines Tokens, das Frigate schon abgelehnt hat? b hält noch seine Kopie — die ist
// ebenfalls ungültig und wird beim ersten Abruf von b verworfen.
$b->ZustandAnfordern();
check('Geschwister verwirft ungültige Kopie', $b->ReadAttributeString('ApiZugang'), '');

// Zeitlich abgelaufenes Token zählt nicht
$a->WriteAttributeString('ApiZugang', (string)json_encode(['cookie' => 'frigate_token', 'token' => jwt(time() - 10), 'benutzer' => 'admin', 'ablauf' => time() - 10]));
check('zeitlich abgelaufen = kein Zugang', $a->AnmeldungTeilen(), '');

/* ------------------------------------------------------------- Übernehmen mit Passwort */
IPSTestState::$setProperties = [];
IPSTestState::$appliedChanges = [];
$a->props(['ApiBenutzer' => 'admin', 'ApiPasswort' => TestAnmeldung::$passwort]);
$a->ApplyChanges();
check('Übernehmen meldet an', $a->AnmeldungTeilen() !== '', true);
check('Übernehmen leert Passwort-Property', IPSTestState::$setProperties, [[1, 'ApiPasswort', '']]);
check('und übernimmt erneut', IPSTestState::$appliedChanges, [1]);

/* ------------------------------------------------------------- Sonderfälle */
TestAnmeldung::$cookieName = 'mein_token';
$e = neu(5, ['Camera' => 'Door-Cam', 'ApiUrl' => 'https://frigate.local:8971']);
$e->ApplyChanges();
$e->Anmelden('admin', TestAnmeldung::$passwort);
check('eigener Cookie-Name (auth.cookie_name)', json_decode($e->ReadAttributeString('ApiZugang'), true)['cookie'] ?? null, 'mein_token');
$e->anfragen = [];
$e->ZustandAnfordern();
check('eigener Cookie-Name wird gesendet', strpos(implode("\n", $e->anfragen[0][2]), 'Cookie: mein_token=') !== false, true);
check('Abmelden', [$e->Abmelden(), $e->timers['Anmeldung']['interval']], ['Nicht angemeldet.', 0]);
TestAnmeldung::$anmeldungAus = true;
check('Anmeldung in Frigate abgeschaltet', strpos($e->Anmelden('admin', 'x'), 'ℹ️ Frigate hat die Anmeldung') === 0, true);
check('ohne Adresse', (neu(6, ['Camera' => 'X']))->Anmelden('admin', 'x'), '⚠️ Erst eine gültige Frigate-Adresse eintragen und übernehmen.');

echo "\n" . ($geprueft - $fehler) . " von {$geprueft} Prüfungen bestanden.\n";
exit($fehler === 0 ? 0 : 1);
