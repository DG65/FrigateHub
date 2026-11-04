<?php

declare(strict_types=1);

/**
 * Zugriff auf Frigates HTTP-API — gemeinsam für Kamera, Konfigurator und Server.
 *
 * Zwei Wege: Port 5000 ohne Anmeldung (http) oder Port 8971 mit Anmeldung (https). Die Anmeldung
 * läuft wie bei Frigates Weboberfläche: POST /api/login, Frigate setzt ein JWT als Cookie
 * (Name aus auth.cookie_name, Standard frigate_token). Das Passwort dient nur dieser Anmeldung und
 * wird nicht gespeichert (Verbund-Konvention Zugangsdaten), das Token liegt im Attribut ApiZugang.
 *
 * Frigate verlängert ein Cookie-Token von selbst, sobald es in die letzte refresh_time vor Ablauf
 * kommt (Standard 30 min von 24 h), und schickt das neue per Set-Cookie mit — auch über nginx
 * auf Port 8971 (auth_request.conf, geprüft 29.10.2026). Der Timer „Anmeldung" fragt deshalb alle
 * 10 min /api/version ab, damit das Token nie verfällt, solange Symcon läuft.
 *
 * Instanzen mit derselben Frigate-Adresse teilen sich die Anmeldung: Fehlt einer Instanz das Token,
 * übernimmt sie eins von einer anderen (z. B. vom Konfigurator, an dem man sich angemeldet hat).
 */
trait FrigateHubApi
{
    /** Grund des letzten fehlgeschlagenen API-Aufrufs. */
    private string $apiFehler = '';

    /** Art des letzten Fehlschlags, sprachneutral: '', 'https' (http auf https-Port), 'abgelaufen', 'anmeldung' (401). */
    private string $apiFehlerArt = '';

    /** Takt, in dem das Token aufgefrischt wird (ms). Muss unter Frigates refresh_time liegen. */
    private static int $FRIGATEHUB_AUFFRISCHEN = 600000;

    /** Modul-GUID → Präfix aller FrigateHub-Module mit API-Zugang (für das Teilen der Anmeldung). */
    private static array $FRIGATEHUB_API_MODULE = [
        '{6BB54171-2F24-4653-9061-F2BC352A9D3B}' => 'FRIG',
        '{AB2DD3FF-1892-4830-8C35-86982AB8D661}' => 'FRIGC',
        '{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}' => 'FRIGS',
    ];

    /** In Create() aufrufen. */
    protected function apiRegistrieren(): void
    {
        $this->RegisterPropertyString('ApiUrl', '');
        $this->RegisterPropertyString('ApiBenutzer', '');
        // Das Passwort ist bewusst KEINE Property (Store-Review: Module schreiben keine Properties):
        // Feld ohne Property im Aktionsbereich, geht nur an Anmelden() und wird nie gespeichert.
        $this->RegisterPropertyBoolean('ZertifikatPruefen', false);
        $this->RegisterAttributeString('ApiZugang', '');
        $this->RegisterAttributeString('Anmeldefehler', '');
        $this->RegisterTimer('Anmeldung', 0, $this->frigatehubPrefix() . '_AnmeldungAuffrischen($_IPS[\'TARGET\']);');
    }

    /**
     * In ApplyChanges() aufrufen: Auffrisch-Timer der Anmeldung. Rückgabe immer false (früher: true,
     * wenn ein gespeichertes Passwort ein erneutes ApplyChanges auslöste — das gibt es nicht mehr).
     */
    protected function apiUebernehmen(): bool
    {
        $this->SetTimerInterval('Anmeldung', $this->apiZugang() !== null ? self::$FRIGATEHUB_AUFFRISCHEN : 0);
        return false;
    }

    /** Formularelemente für Adresse und Anmeldung. */
    protected function apiFormular(string $hinweis): array
    {
        $p = $this->frigatehubPrefix();
        return [
            ['type' => 'Label', 'caption' => FrigateHubText::t('Frigate address: either Frigate\'s API without sign-in (port 5000, e.g. http://192.168.1.20:5000 — must be exposed in the Docker command) or the web interface with sign-in (port 8971, e.g. https://192.168.1.20:8971). %s', $hinweis)],
            ['type' => 'ValidationTextBox', 'name' => 'ApiUrl', 'caption' => FrigateHubText::t('Frigate address (http://…:5000 or https://…:8971)'), 'validate' => '^$|^https?://[^\\s/]+/?$'],
            ['type' => 'Label', 'caption' => FrigateHubText::t('Port 8971 only: enter the Frigate user here; signing in happens in the panel “🔑 Sign in to Frigate”.')],
            ['type' => 'ValidationTextBox', 'name' => 'ApiBenutzer', 'caption' => FrigateHubText::t('Frigate user')],
            ['type' => 'CheckBox', 'name' => 'ZertifikatPruefen', 'caption' => FrigateHubText::t('Verify certificate (leave off for Frigate\'s own certificate)')],
            ['type' => 'Label', 'name' => 'Anmeldestatus', 'caption' => $this->anmeldestatus()],
        ];
    }

    /**
     * Anmelde-Panel: Passwortfeld ohne Property (wird nie gespeichert, „Übernehmen“ übergeht es), Anmelden/Abmelden.
     */
    protected function apiAnmeldePanel(): array
    {
        $p = $this->frigatehubPrefix();
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🔑  Sign in to Frigate (port 8971 only)'),
            'items' => [
                ['type' => 'Label', 'caption' => FrigateHubText::t('Enter the user in the field “Frigate user”, the password here, then “Sign in”. The password is not stored, only Frigate\'s access token, which is renewed automatically. Other FrigateHub instances with the same address share the sign-in.')],
                ['type' => 'PasswordTextBox', 'name' => 'ApiPasswort', 'caption' => FrigateHubText::t('Password (not stored)')],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Sign in'), 'onClick' => 'echo ' . $p . '_Anmelden($id, $ApiBenutzer, $ApiPasswort);'],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Sign out'), 'onClick' => 'echo ' . $p . '_Abmelden($id);'],
                ]],
            ],
        ];
    }

    /** Bei Frigate anmelden (Port 8971). Rückgabe: Text fürs Formular. */
    public function Anmelden(string $Benutzer, string $Passwort): string
    {
        $this->UpdateFormField('ApiPasswort', 'value', '');
        $benutzer = trim($Benutzer);
        if ($this->apiUrl() === '') {
            return $this->anmeldungMelden(FrigateHubText::t('⚠️ First enter a valid Frigate address and apply.'));
        }
        if ($benutzer === '' || $Passwort === '') {
            return $this->anmeldungMelden(FrigateHubText::t('⚠️ Please enter user and password.'));
        }
        $a = $this->apiAnfrage('POST', '/api/login', ['Content-Type: application/json', 'Accept: application/json'],
            (string)json_encode(['user' => $benutzer, 'password' => $Passwort]));
        if ($a['code'] === 404) {
            return $this->anmeldungMelden(FrigateHubText::t('ℹ️ Frigate has disabled authentication at this address — it works without user and password.'));
        }
        if ($a['code'] === 401) {
            return $this->anmeldungMelden(FrigateHubText::t('⛔ Frigate rejects the user or password.'));
        }
        if (self::httpAufHttps($a)) {
            return $this->anmeldungMelden(FrigateHubText::t('⛔ This port expects https:// — change the address to %s, apply and sign in again.', $this->httpsVorschlag()));
        }
        if ($a['code'] === 429) {
            return $this->anmeldungMelden(FrigateHubText::t('⛔ Too many sign-in attempts — Frigate is blocking briefly, please wait a moment.'));
        }
        if ($a['code'] !== 200) {
            return $this->anmeldungMelden(FrigateHubText::t('⛔ Sign-in failed (%s).', $a['code'] > 0 ? 'HTTP ' . $a['code'] : $a['fehler']));
        }
        $zugang = self::zugangAusCookies($a['cookies'], $benutzer);
        if ($zugang === null) {
            return $this->anmeldungMelden(FrigateHubText::t('⛔ Frigate signed in but did not send an access token.'));
        }
        $this->zugangSpeichern($zugang);
        $this->WriteAttributeString('Anmeldefehler', '');
        $this->UpdateFormField('Anmeldestatus', 'caption', $this->anmeldestatus());
        return $this->anmeldestatus();
    }

    /** Zugangsschlüssel verwerfen. */
    public function Abmelden(): string
    {
        $this->WriteAttributeString('ApiZugang', '');
        $this->WriteAttributeString('Anmeldefehler', '');
        $this->SetTimerInterval('Anmeldung', 0);
        $this->UpdateFormField('Anmeldestatus', 'caption', $this->anmeldestatus());
        return $this->anmeldestatus();
    }

    /** Timer: einmal anfragen, damit Frigate das Token rechtzeitig verlängert. */
    public function AnmeldungAuffrischen(): void
    {
        if ($this->apiUrl() === '' || $this->apiZugang() === null) {
            $this->SetTimerInterval('Anmeldung', 0);
            return;
        }
        $this->apiRoh('/api/version', 'text/plain');
    }

    /** Für andere FrigateHub-Instanzen mit derselben Adresse: gültiger Zugang als JSON oder ''. */
    public function AnmeldungTeilen(): string
    {
        $zugang = $this->apiZugang();
        return $zugang === null ? '' : (string)json_encode($zugang);
    }

    /* ---------------------------------------------------------------- intern */

    /** Frigate-Adresse ohne Schrägstrich am Ende, leer wenn nicht (gültig) eingetragen. */
    private function apiUrl(): string
    {
        $url = rtrim(trim((string)$this->ReadPropertyString('ApiUrl')), '/');
        return preg_match('#^https?://[^\s/]+$#', $url) ? $url : '';
    }

    /** Satz fürs Formular zum letzten Fehlschlag: Anmeldung fehlt/abgelaufen oder nicht erreichbar. */
    private function apiFehlerText(): string
    {
        if ($this->apiFehlerArt === 'https') {
            return FrigateHubText::t('Frigate at %s expects https:// — please change the address to %s and apply.', $this->apiUrl(), $this->httpsVorschlag());
        }
        if ($this->apiFehlerArt === 'abgelaufen') {
            return FrigateHubText::t('Frigate at %s responds, but the sign-in has expired — please sign in again.', $this->apiUrl());
        }
        if ($this->apiFehlerArt === 'anmeldung') {
            return FrigateHubText::t('Frigate at %s responds, but requires a sign-in — enter user and password and click “Sign in”.', $this->apiUrl());
        }
        return FrigateHubText::t('Frigate at %s not reachable (%s). Is the address correct, and is the port exposed in the Docker command?', $this->apiUrl(), $this->apiFehler);
    }

    /** GET auf Frigates API, JSON-Antwort als Array oder null (Grund in $apiFehler). */
    private function apiHolen(string $pfad): ?array
    {
        $antwort = $this->apiRoh($pfad, 'application/json');
        if ($antwort === null) {
            return null;
        }
        $json = json_decode($antwort, true);
        if (!is_array($json)) {
            $this->apiFehler = FrigateHubText::t('Response is not JSON');
            $this->apiFehlerArt = '';
            return null;
        }
        return $json;
    }

    /**
     * GET auf Frigates API mit Anmeldung, soweit vorhanden. Antwort roh oder null (Grund in
     * $apiFehler). Eigene Methode, damit der Prüfstand sie ersetzen kann.
     */
    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $zugang = $this->apiZugang() ?? $this->zugangUebernehmen();
        $a = $this->apiAnfrage('GET', $pfad, ['Accept: ' . $accept] + ($zugang !== null ? [1 => 'Cookie: ' . $zugang['cookie'] . '=' . $zugang['token']] : []), null);
        if ($zugang !== null) {
            // Frigate verlängert das Token per Set-Cookie, wenn es bald abläuft.
            $neu = self::zugangAusCookies($a['cookies'], $zugang['benutzer'], $zugang['cookie']);
            if ($neu !== null && $neu['token'] !== $zugang['token']) {
                $this->zugangSpeichern($neu);
            }
        }
        if ($a['code'] === 0) {
            $this->apiFehler = $a['fehler'] !== '' ? $a['fehler'] : FrigateHubText::t('no response');
            $this->apiFehlerArt = '';
            return null;
        }
        if ($a['code'] === 401) {
            if ($zugang !== null) {
                $this->WriteAttributeString('ApiZugang', '');
                $this->SetTimerInterval('Anmeldung', 0);
                $this->WriteAttributeString('Anmeldefehler', FrigateHubText::t('⛔ Sign-in expired on %s — please sign in again.', date(FrigateHubText::t('Y-m-d H:i'))));
                $this->LogMessage(FrigateHubText::t('Frigate sign-in expired, please sign in again in the instance'), KL_WARNING);
                $this->apiFehler = 'HTTP 401, ' . FrigateHubText::t('sign-in expired — please sign in again');
                $this->apiFehlerArt = 'abgelaufen';
            } else {
                $this->apiFehler = 'HTTP 401, ' . FrigateHubText::t('Frigate requires a sign-in — enter user and password and click “Sign in”');
                $this->apiFehlerArt = 'anmeldung';
            }
            return null;
        }
        if (self::httpAufHttps($a)) {
            $this->apiFehler = 'HTTP 400, ' . FrigateHubText::t('port expects https');
            $this->apiFehlerArt = 'https';
            return null;
        }
        if ($a['code'] !== 200) {
            $this->apiFehler = 'HTTP ' . $a['code'];
            $this->apiFehlerArt = '';
            return null;
        }
        return (string)$a['body'];
    }

    /**
     * Schreibender Aufruf an Frigates API (POST/DELETE, JSON) mit Anmeldung, soweit vorhanden:
     * Review gesehen, Ereignis behalten, Export. Prüfstand-Haken.
     *
     * @return array{code:int,body:string}
     */
    protected function apiSchreiben(string $methode, string $pfad, ?array $json): array
    {
        $zugang = $this->apiZugang() ?? $this->zugangUebernehmen();
        $kopf = ['Content-Type: application/json', 'Accept: application/json'];
        if ($zugang !== null) {
            $kopf[] = 'Cookie: ' . $zugang['cookie'] . '=' . $zugang['token'];
        }
        $url = $this->apiUrl() . $pfad;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_POSTFIELDS     => $json === null ? '' : (string)json_encode($json),
            CURLOPT_HTTPHEADER     => $kopf,
        ]);
        if (strpos($url, 'https://') === 0 && !$this->ReadPropertyBoolean('ZertifikatPruefen')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $antwort = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $antwort === false ? 0 : $code, 'body' => $antwort === false ? '' : (string)$antwort];
    }

    /**
     * WebRTC-Verbindungsaufbau mit Frigates go2rtc: SDP-Angebot des Browsers hin, SDP-Antwort
     * zurück (POST /api/go2rtc/webrtc?src=…, in Frigates nginx für die Home-Assistant-Integration,
     * auf Port 8971 mit Anmeldung). Das Video selbst läuft danach direkt zwischen Browser und
     * go2rtc (Port 8555). Prüfstand-Haken.
     *
     * @return array{code:int,body:string}
     */
    protected function apiWebrtc(string $stream, string $angebot): array
    {
        $zugang = $this->apiZugang() ?? $this->zugangUebernehmen();
        $kopf = ['Content-Type: application/sdp', 'Accept: application/sdp'];
        if ($zugang !== null) {
            $kopf[] = 'Cookie: ' . $zugang['cookie'] . '=' . $zugang['token'];
        }
        $url = $this->apiUrl() . '/api/go2rtc/webrtc?src=' . rawurlencode($stream);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $angebot,
            CURLOPT_HTTPHEADER     => $kopf,
        ]);
        if (strpos($url, 'https://') === 0 && !$this->ReadPropertyBoolean('ZertifikatPruefen')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $antwort = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $antwort === false ? 0 : $code, 'body' => $antwort === false ? '' : (string)$antwort];
    }

    /**
     * Bild oder Clip von Frigate holen, um es über den WebHook an den Browser weiterzureichen.
     * Reicht einen Range-Kopf durch (Safari/iOS spielt Videos nur mit Teilabrufen ab).
     * Prüfstand-Haken.
     *
     * @return array{code:int,body:string,kopf:array<string,string>}
     */
    protected function apiDurchreichen(string $pfad, string $range, int $zeitlimit): array
    {
        $zugang = $this->apiZugang() ?? $this->zugangUebernehmen();
        $kopf = [];
        if ($zugang !== null) {
            $kopf[] = 'Cookie: ' . $zugang['cookie'] . '=' . $zugang['token'];
        }
        if ($range !== '') {
            $kopf[] = 'Range: ' . $range;
        }
        $antwortKopf = [];
        $url = $this->apiUrl() . $pfad;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $zeitlimit,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_HEADERFUNCTION => function ($ch, string $zeile) use (&$antwortKopf): int {
                if (preg_match('/^(content-type|content-range|content-length|accept-ranges):\s*(.+?)\s*$/i', $zeile, $m)) {
                    $antwortKopf[strtolower($m[1])] = $m[2];
                }
                return strlen($zeile);
            },
        ]);
        if (strpos($url, 'https://') === 0 && !$this->ReadPropertyBoolean('ZertifikatPruefen')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $antwort = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $antwort === false ? 0 : $code, 'body' => $antwort === false ? '' : (string)$antwort, 'kopf' => $antwortKopf];
    }

    /**
     * Eine HTTP-Anfrage, ohne Auswertung. curl statt Sys_GetURLContentEx (SUITE.md Stolperstein 11).
     * Prüfstand-Haken für die Anmeldung.
     *
     * @return array{code:int,body:?string,cookies:array<string,string>,fehler:string}
     */
    protected function apiAnfrage(string $methode, string $pfad, array $kopf, ?string $inhalt): array
    {
        $url = $this->apiUrl() . $pfad;
        $cookies = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => array_values($kopf),
            CURLOPT_HEADERFUNCTION => function ($ch, string $zeile) use (&$cookies): int {
                if (preg_match('/^set-cookie:\s*([^=;\s]+)=([^;\r\n]*)/i', $zeile, $m)) {
                    $cookies[$m[1]] = $m[2];
                }
                return strlen($zeile);
            },
        ]);
        if ($methode === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$inhalt);
        }
        if (strpos($url, 'https://') === 0 && !$this->ReadPropertyBoolean('ZertifikatPruefen')) {
            // Frigate erzeugt für Port 8971 ein eigenes, selbst signiertes Zertifikat.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $antwort = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        return ['code' => $antwort === false ? 0 : $code, 'body' => $antwort === false ? null : (string)$antwort,
            'cookies' => $cookies, 'fehler' => $fehler];
    }

    /**
     * Eigener gültiger Zugang oder null.
     *
     * @return array{cookie:string,token:string,benutzer:string,ablauf:int}|null
     */
    private function apiZugang(): ?array
    {
        $z = json_decode($this->ReadAttributeString('ApiZugang'), true);
        if (!is_array($z) || !isset($z['cookie'], $z['token'], $z['ablauf'])) {
            return null;
        }
        // Ein Zugang gehört zu einem Frigate-Server: nach Wechsel von Host oder Port nicht mehr senden.
        if (isset($z['server']) && $z['server'] !== $this->apiServer()) {
            return null;
        }
        return (int)$z['ablauf'] > time() + 60 ? $z : null;
    }

    /** Zugang einer anderen FrigateHub-Instanz mit derselben Adresse übernehmen. */
    private function zugangUebernehmen(): ?array
    {
        $eigene = $this->apiUrl();
        if ($eigene === '' || strpos($eigene, 'https://') !== 0) {
            return null;
        }
        foreach (self::$FRIGATEHUB_API_MODULE as $guid => $praefix) {
            $funktion = $praefix . '_AnmeldungTeilen';
            if (!function_exists($funktion)) {
                continue;
            }
            foreach (IPS_GetInstanceListByModuleID($guid) as $id) {
                if ($id === $this->InstanceID || rtrim(trim((string)IPS_GetProperty($id, 'ApiUrl')), '/') !== $eigene) {
                    continue;
                }
                $z = json_decode((string)@$funktion($id), true);
                if (is_array($z) && isset($z['cookie'], $z['token'], $z['ablauf']) && (int)$z['ablauf'] > time() + 60) {
                    $this->zugangSpeichern($z);
                    return $z;
                }
            }
        }
        return null;
    }

    private function zugangSpeichern(array $zugang): void
    {
        $zugang['server'] = $this->apiServer();
        $this->WriteAttributeString('ApiZugang', (string)json_encode($zugang));
        $this->WriteAttributeString('Anmeldefehler', '');
        $this->SetTimerInterval('Anmeldung', self::$FRIGATEHUB_AUFFRISCHEN);
    }

    private function anmeldungMelden(string $text): string
    {
        $this->WriteAttributeString('Anmeldefehler', $text);
        $this->UpdateFormField('Anmeldestatus', 'caption', $text);
        return $text;
    }

    private function anmeldestatus(): string
    {
        if (strpos($this->apiUrl(), 'http://') === 0 && substr($this->apiUrl(), -5) === ':8971') {
            return FrigateHubText::t('⚠️ Port 8971 needs https:// — change the address to %s and apply.', $this->httpsVorschlag());
        }
        $zugang = $this->apiZugang();
        if ($zugang !== null) {
            return FrigateHubText::t('✅ Signed in as %s, access valid until %s (renewed automatically).', $zugang['benutzer'], date(FrigateHubText::t('Y-m-d H:i'), $zugang['ablauf']));
        }
        $fehler = $this->ReadAttributeString('Anmeldefehler');
        if ($fehler !== '') {
            return $fehler;
        }
        return strpos($this->apiUrl(), 'https://') === 0 ? FrigateHubText::t('Not signed in.') : FrigateHubText::t('No sign-in needed (port 5000).');
    }

    /** Host und Port der Frigate-Adresse, ohne Schema. */
    private function apiServer(): string
    {
        return (string)preg_replace('#^https?://#', '', $this->apiUrl());
    }

    private function httpsVorschlag(): string
    {
        return 'https://' . $this->apiServer();
    }

    /** nginx-Antwort, wenn http an einen https-Port geht (Frigate Port 8971, live 29.10.2026). */
    private static function httpAufHttps(array $a): bool
    {
        return $a['code'] === 400 && stripos((string)$a['body'], 'plain HTTP request was sent to HTTPS port') !== false;
    }

    /**
     * Zugang aus den Set-Cookies einer Antwort: das Cookie mit einem JWT (drei Teile, exp im Inhalt).
     *
     * @return array{cookie:string,token:string,benutzer:string,ablauf:int}|null
     */
    private static function zugangAusCookies(array $cookies, string $benutzer, string $name = ''): ?array
    {
        foreach ($cookies as $cookie => $wert) {
            if ($name !== '' && $cookie !== $name) {
                continue;
            }
            $ablauf = self::jwtAblauf((string)$wert);
            if ($ablauf !== null) {
                return ['cookie' => (string)$cookie, 'token' => (string)$wert, 'benutzer' => $benutzer, 'ablauf' => $ablauf];
            }
        }
        return null;
    }

    /** exp aus einem JWT (ohne Signaturprüfung — die macht Frigate). */
    private static function jwtAblauf(string $jwt): ?int
    {
        $teile = explode('.', $jwt);
        if (count($teile) !== 3) {
            return null;
        }
        $inhalt = json_decode((string)base64_decode(strtr($teile[1], '-_', '+/'), false), true);
        return is_array($inhalt) && is_numeric($inhalt['exp'] ?? null) ? (int)$inhalt['exp'] : null;
    }
}
