<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubText.php';
require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';
require_once __DIR__ . '/../libs/FrigateHubServerWerte.php';
require_once __DIR__ . '/../libs/FrigateHubWebhook.php';
require_once __DIR__ . '/../libs/FrigateHubPush.php';
require_once __DIR__ . '/../libs/FrigateHubWaechter.php';

/**
 * Der Frigate-Server selbst: erreichbar, Version (mit Hinweis auf eine neuere), Laufzeit,
 * CPU/Arbeitsspeicher, Erkennungszeit je Detektor, GPU-Last, Speicher der Aufnahmen und eine
 * Sammelmeldung über gestörte Kameras. Dazu Frigate neu starten.
 *
 * Aufnahme-Wächter (0.15.0, nur mit Frigate-Adresse): prüft jede Minute über die API, ob Frigate
 * aufzeichnet (Bewertung in libs/FrigateHubWaechter.php), und meldet Störung, Erinnerung und
 * Entwarnung an die eingetragenen Ziele.
 *
 * Quelle ist frigate/stats, das Frigate im Takt von mqtt.stats_interval (Standard 60 s) schickt,
 * und frigate/available. Mit Frigate-Adresse holt die Instanz die Werte nach dem Übernehmen und
 * auf Knopfdruck zusätzlich über /api/stats (derselbe Inhalt) — sonst stünden sie bis zu einer
 * Minute leer.
 */
class FrigateHubServer extends IPSModule
{
    use FrigateHubFormular;
    use FrigateHubApi;
    use FrigateHubWebhook;
    use FrigateHubPush;


    private const KAMERA_GUID = '{6BB54171-2F24-4653-9061-F2BC352A9D3B}';

    /** Variablen der Kamera-Instanzen, die die Übersichtskachel zeigt (Änderung → neu senden). */
    private const KACHEL_IDENTS = ['Online', 'Alarm', 'Bewegung', 'Objekt_person'];

    /** Alle Texte der Übersichtskachel (module.html, Funktion T); übersetzt in den Kacheldaten als „texte“. */
    private const KACHEL_TEXTE = ['⛔ Frigate not reachable', '%s camera', '%s cameras', '%s alarm', '%s alarms', 'Enter the Frigate address in the instance to see the live images.', '🚨 Alarm', '%s person', '%s people', 'offline', 'Connection %s'];

    /** Datenschnittstelle „senden" (Publish an den Broker), auf MQTT Client und Server gleich. */
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    /** Wartezeit nach dem Übernehmen bis zum Abgleich (ms) — ApplyChanges nicht blockieren. */
    private const ABGLEICH_VERZOEGERUNG = 2000;

    /** Takt des Aufnahme-Wächters (ms). */
    private const WAECHTER_TAKT = 60000;

    /** So viele Protokollzeilen liest der Wächter je Prüfung (die jüngsten). */
    private const PROTOKOLL_ZEILEN = 300;

    /** Meldeziele wie in der Kamera (SUITE.md Stolperstein 22). */
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const KACHEL_GUID   = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const SMTP_GUID     = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    /** Töne laut Symcon-Doku, für WebFront und Kachel-Visualisierung gleich. */
    private const PUSH_TOENE = ['alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full',
        'happy', 'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Reine Klassen (libs) übersetzen über diesen Übersetzer, siehe FrigateHubText
        FrigateHubText::setzen(FrigateHubText::mitMerkliste(fn (string $text): string => $this->Translate($text)));
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TopicPrefix', 'frigate');
        // Leistungswerte (CPU, GPU, Erkennungszeit …) stehen im Formular; als Variablen nur auf Wunsch
        $this->RegisterPropertyBoolean('LeistungAlsVariablen', false);
        $this->apiRegistrieren();
        $this->RegisterAttributeInteger('LetzteStatistik', 0);
        $this->RegisterAttributeString('Abgleichstatus', '');
        // Übersichtskachel aller Kameras
        $this->RegisterPropertyInteger('KachelTakt', 5);
        $this->RegisterAttributeString('HookSchluessel', '');
        $this->RegisterAttributeBoolean('Birdseye', false);
        $this->registerFormAttributes();
        $this->formAufbauRegistrieren();
        $this->SetVisualizationType(1);

        // Aufnahme-Wächter: an, sobald eine Frigate-Adresse eingetragen ist; Meldungen nur mit Ziel
        $this->RegisterPropertyBoolean('WaechterAktiv', true);
        $this->RegisterPropertyString('PushZiele', '[]');
        $this->RegisterPropertyString('PushTon', 'alarm');
        $this->RegisterAttributeString('Waechter', '{}');
        $this->RegisterAttributeString('Waechterstatus', '');
        $this->RegisterAttributeString('Pushstatus', '');
        // Frigate-Profile (0.18+): verfügbare Namen → Anzeigename, aus /api/profiles
        $this->RegisterAttributeString('Profile', '{}');

        $this->RegisterTimer('Abgleich', 0, 'FRIGS_ZustandAnfordern($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Waechter', 0, 'FRIGS_AufnahmePruefen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->apiUebernehmen()) {
            return;
        }

        $this->variableAnlegen('Verfuegbar', FrigateHubText::t('Frigate reachable'), VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => (string)json_encode([
                ['Value' => false, 'Caption' => FrigateHubText::t('not reachable'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0xE53935],
                ['Value' => true, 'Caption' => FrigateHubText::t('reachable'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x43A047],
            ]),
        ], 1);
        // Schrägstrichfreie Merkmale (CometWiFi-Fund); welches Topic gemeint ist, prüft ReceiveData.
        $this->SetReceiveDataFilter('.*(stats|available|profile).*');
        $this->SetVisualizationType(1);
        if ($this->ReadAttributeString('HookSchluessel') === '') {
            $this->WriteAttributeString('HookSchluessel', bin2hex(random_bytes(16)));
        }
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->webhookEintragen($this->hookPfad());
            $this->kamerasBeobachten();
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }
        $this->kachelSenden(true);
        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('Abgleich', $this->apiUrl() !== '' ? self::ABGLEICH_VERZOEGERUNG : 0);
        if ($this->waechterAn()) {
            $this->variableAnlegen('AufnahmeOk', FrigateHubText::t('Recording OK'), VARIABLETYPE_BOOLEAN, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS'      => (string)json_encode([
                    ['Value' => false, 'Caption' => FrigateHubText::t('faulty'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0xE53935],
                    ['Value' => true, 'Caption' => FrigateHubText::t('OK'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x43A047],
                ]),
            ], 60);
            $this->variableAnlegen('Aufnahmezustand', FrigateHubText::t('Recording state'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 61);
        }
        // Erste Prüfung gleich nach dem Übernehmen — sonst stünde „Aufnahme in Ordnung" bis zu einer Minute auf „gestört"
        $this->SetTimerInterval('Waechter', $this->waechterAn() ? self::ABGLEICH_VERZOEGERUNG + 1000 : 0);
    }

    public function Destroy()
    {
        $this->webhookAufraeumen($this->hookPfad());
        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->webhookEintragen($this->hookPfad());
            $this->kamerasBeobachten();
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->kachelSenden();
        }
    }

    /* ==================================================================== Übersichtskachel */

    public function GetVisualizationTile()
    {
        return file_get_contents(__DIR__ . '/module.html') . '<script>handleMessage(' . json_encode($this->kachelDaten(false)) . ');</script>';
    }

    /** WebHook: Livebilder aller Kameras und Birdseye, eigenständige Seite für IPSView. Nur mit Schlüssel. */
    public function ProcessHookData()
    {
        $get = array_map(fn ($v) => is_string($v) ? $v : '', (array)$_GET);
        $schluessel = $this->ReadAttributeString('HookSchluessel');
        if ($schluessel === '' || !hash_equals($schluessel, $get['k'] ?? '')) {
            $this->hookAntwort(403, 'text/plain; charset=utf-8', FrigateHubText::t('Access denied.'));
            return;
        }
        if (isset($get['daten'])) {
            $this->hookAntwort(200, 'application/json; charset=utf-8', (string)json_encode($this->kachelDaten(true)));
            return;
        }
        if (isset($get['seite'])) {
            $daten = (string)json_encode($this->kachelDaten(true));
            $abruf = json_encode($this->hookPfad() . '?k=' . $schluessel . '&daten=1');
            $this->hookAntwort(200, 'text/html; charset=utf-8', file_get_contents(__DIR__ . '/module.html')
                . '<script>var seiteStand=' . json_encode($daten) . ';handleMessage(seiteStand);'
                . 'setInterval(function(){if(document.hidden){return;}fetch(' . $abruf . ',{cache:"no-store"}).then(function(r){return r.ok?r.text():null;})'
                . '.then(function(t){if(t&&t!==seiteStand){seiteStand=t;handleMessage(t);}}).catch(function(){});},10000);</script>');
            return;
        }
        $h = max(120, min(1080, (int)($get['h'] ?? 360)));
        $pfad = null;
        if (($get['bild'] ?? '') === 'birdseye' && $this->ReadAttributeBoolean('Birdseye')) {
            $pfad = '/api/birdseye/latest.jpg?' . FrigateHubTopics::hoehe($h);
        } elseif (($get['bild'] ?? '') === 'live' && in_array($get['kamera'] ?? '', $this->kameraNamen(), true)) {
            $pfad = '/api/' . rawurlencode($get['kamera']) . '/latest.jpg?' . FrigateHubTopics::hoehe($h);
        }
        if ($pfad === null || $this->apiUrl() === '') {
            $this->hookAntwort(404, 'text/plain; charset=utf-8', FrigateHubText::t('Unknown.'));
            return;
        }
        $a = $this->apiDurchreichen($pfad, '', 8);
        if ($a['code'] !== 200) {
            $this->hookAntwort($a['code'] === 404 ? 404 : 502, 'text/plain; charset=utf-8', FrigateHubText::t('Frigate returns no image.'));
            return;
        }
        $this->hookAntwort(200, $a['kopf']['content-type'] ?? 'image/jpeg', $a['body'], 'no-store');
    }

    /** Vertragsversion von FRIGS_GetCameras() (Major nur bei Bruch erhöhen, SUITE.md-Regel). */
    public const VERTRAG = '1.1';

    /**
     * FRIGS_GetCameras($id): neutrale Liste der Kameras dieser Frigate-Instanz für andere Module
     * (Alarmanlagen wie OpenHomeAlarm, Regelwerke): Variablen-IDs für Melder und Schalter sowie die
     * Meldefunktion. Nur Kameras, für die es eine FrigateHub-Kamera-Instanz gibt. 0 = gibt es nicht.
     * 1.1 (rein ergänzend): melder.verweilen/geraeusche/unbekanntesGesicht/unbekanntesKennzeichen,
     * sabotage, stoerung.offline (Online: false = Störung) / stoerung.aufnahme (AufnahmeOk der
     * Server-Instanz: false = Störung), zustaende. Profile schaltet FRIGS_ProfilSetzen().
     */
    public function GetCameras(): array
    {
        $kameras = [];
        foreach ($this->kameraInstanzen() as $kamera => $id) {
            $v = fn (string $ident): int => (int)(@IPS_GetObjectIDByIdent($ident, $id) ?: 0);
            $zonen = [];
            $verweilen = [];
            $geraeusche = [];
            $zustaende = [];
            foreach (IPS_GetChildrenIDs($id) as $kind) {
                $o = IPS_GetObject($kind);
                if ($o['ObjectType'] !== 2) {
                    continue;
                }
                if (preg_match('/^Zone_(.+)_person(?:_[0-9a-f]{4})?$/', $o['ObjectIdent'], $m)) {
                    $zonen[] = ['zone' => $m[1], 'name' => $o['ObjectName'], 'personen' => $kind];
                } elseif (preg_match('/^Verweilt_(.+?)(?:_[0-9a-f]{4})?$/', $o['ObjectIdent'], $m)) {
                    $verweilen[] = ['zone' => $m[1], 'name' => $o['ObjectName'], 'variable' => $kind];
                } elseif (preg_match('/^Geraeusch_(.+?)(?:_[0-9a-f]{4})?$/', $o['ObjectIdent'], $m)) {
                    $geraeusche[] = ['typ' => $m[1], 'name' => $o['ObjectName'], 'variable' => $kind];
                } elseif (preg_match('/^Zustand_(.+?)(?:_[0-9a-f]{4})?$/', $o['ObjectIdent'], $m)) {
                    $zustaende[] = ['modell' => $m[1], 'name' => $o['ObjectName'], 'variable' => $kind];
                }
            }
            $kameras[] = [
                'contractVersion' => self::VERTRAG,
                'kamera'          => (string)$kamera,
                'name'            => IPS_GetName($id),
                'instanz'         => $id,
                'melder'          => ['alarm' => $v('Alarm'), 'erkennung' => $v('Erkennung'), 'bewegung' => $v('Bewegung'), 'personen' => $v('Objekt_person'),
                    'online' => $v('Online'), 'zonen' => $zonen,
                    // 1.1: Verweilen je Zone (besserer Scharf-Melder), Geräusche, unbekanntes Gesicht/Kennzeichen (Zeitpunkt)
                    'verweilen' => $verweilen, 'geraeusche' => $geraeusche,
                    'unbekanntesGesicht' => $v('UnbekanntesGesicht'), 'unbekanntesKennzeichen' => $v('UnbekanntesKennzeichen')],
                // 1.1: Sabotage (Bild abgedeckt/verstellt/Dauerbewegung) und Störungen für Störungseingänge
                'sabotage'        => $v('BildAuffaellig'),
                'stoerung'        => ['offline' => $v('Online'), 'aufnahme' => (int)(@$this->GetIDForIdent('AufnahmeOk') ?: 0)],
                'zustaende'       => $zustaende,
                'schalter'        => ['aufnahme' => $v('Schalter_recordings'), 'objekterkennung' => $v('Schalter_detect'), 'schnappschuesse' => $v('Schalter_snapshots')],
                'bild'            => $v('Schnappschuss'),
                'meldung'         => 'FRIG_MeldungMitSchnappschuss',
            ];
        }
        return $kameras;
    }

    /**
     * FRIGS_MeldungLetzteKamera($id, $Text, $Livebild): Meldung mit Bild der Kamera, die zuletzt
     * einen Alarm (sonst eine Erkennung) hatte — für Alarmaktionen, die nicht wissen, welcher Melder
     * ausgelöst hat (z. B. OpenHomeAlarm mit mehreren Kameras). Rückgabe: Text.
     */
    public function MeldungLetzteKamera(string $Text, bool $Livebild): string
    {
        $beste = 0;
        $zeit = 0;
        foreach ($this->kameraInstanzen() as $id) {
            foreach (['LetzterAlarm' => 0, 'LetzteErkennung' => -60] as $ident => $abzug) {
                $v = @IPS_GetObjectIDByIdent($ident, $id);
                // Ein Alarm zählt vor einer gleichzeitigen Erkennung (60 s Vorsprung)
                $t = $v === false || GetValue($v) === '' ? 0 : IPS_GetVariable($v)['VariableUpdated'] + $abzug;
                if ($t > $zeit) {
                    [$beste, $zeit] = [$id, $t];
                }
            }
        }
        if ($beste === 0) {
            return FrigateHubText::t('ℹ️ No camera with an alarm or detection found.');
        }
        if (!function_exists('FRIG_MeldungMitSchnappschuss')) {
            return FrigateHubText::t('⛔ FrigateHub camera not available.');
        }
        return IPS_GetName($beste) . ': ' . FRIG_MeldungMitSchnappschuss($beste, $Text, $Livebild);
    }

    /** FRIGS_KachelSchluesselErneuern($id): alte Bild- und Seitenadressen werden ungültig. */
    public function KachelSchluesselErneuern(): string
    {
        $this->WriteAttributeString('HookSchluessel', bin2hex(random_bytes(16)));
        $this->kachelSenden(true);
        return FrigateHubText::t('✅ New key generated. Enter the new address in IPSView.');
    }

    /** FRIGS_SeiteAdresse($id): Adresse der eigenständigen Übersicht für IPSView/Browser. */
    public function SeiteAdresse(): string
    {
        return 'http://<Symcon-IP>:3777' . $this->hookPfad() . '?seite=1&k=' . $this->ReadAttributeString('HookSchluessel');
    }

    protected function hookAntwort(int $code, string $typ, string $inhalt, string $cache = 'no-store'): void
    {
        http_response_code($code);
        header('Content-Type: ' . $typ);
        header('Cache-Control: ' . $cache);
        header('Content-Length: ' . strlen($inhalt));
        echo $inhalt;
    }

    private function hookPfad(): string
    {
        return '/hook/frigatehubserver' . $this->InstanceID;
    }

    /** Kamera-Instanzen unter demselben MQTT-Elternteil: Kamera-ID → Instanz. */
    private function kameraInstanzen(): array
    {
        $eltern = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $out = [];
        foreach (IPS_GetInstanceListByModuleID(self::KAMERA_GUID) as $id) {
            // Während einer Modulaktualisierung ist die Kamera kurz nicht ansprechbar
            // („InstanceInterface is not available", live 29.10.2026) — dann überspringen.
            try {
                $kamera = trim((string)@IPS_GetProperty($id, 'Camera'));
                $passt = $kamera !== '' && (int)@IPS_GetInstance($id)['ConnectionID'] === $eltern;
            } catch (Throwable $e) {
                continue;
            }
            if ($passt && !isset($out[$kamera])) {
                $out[$kamera] = $id;
            }
        }
        return $out;
    }

    /** Kameras laut letzter Statistik plus angelegte Kamera-Instanzen. */
    private function kameraNamen(): array
    {
        $stats = json_decode($this->GetBuffer('Statistik'), true);
        $namen = array_keys((array)($stats['cameras'] ?? []));
        return array_values(array_unique(array_merge(array_map('strval', $namen), array_keys($this->kameraInstanzen()))));
    }

    /** Auf Änderungen der wichtigsten Kamera-Variablen hören, damit die Kachel sofort folgt. */
    private function kamerasBeobachten(): void
    {
        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            if (in_array(VM_UPDATE, $nachrichten, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
        }
        foreach ($this->kameraInstanzen() as $id) {
            foreach (self::KACHEL_IDENTS as $ident) {
                $v = @IPS_GetObjectIDByIdent($ident, $id);
                if ($v !== false) {
                    $this->RegisterMessage($v, VM_UPDATE);
                }
            }
        }
    }

    private function kachelSenden(bool $immer = false): void
    {
        $json = (string)json_encode($this->kachelDaten(false));
        if (!$immer && $this->GetBuffer('KachelHash') === md5($json)) {
            return;
        }
        $this->SetBuffer('KachelHash', md5($json));
        $this->UpdateVisualizationValue($json);
    }

    private function kachelDaten(bool $eigen): array
    {
        $stats = json_decode($this->GetBuffer('Statistik'), true);
        $instanzen = $this->kameraInstanzen();
        $wert = function (?int $instanz, string $ident) {
            $v = $instanz === null ? false : @IPS_GetObjectIDByIdent($ident, $instanz);
            return $v === false ? null : GetValue($v);
        };
        $kameras = [];
        foreach ($this->kameraNamen() as $name) {
            $s = $stats['cameras'][$name] ?? [];
            $id = $instanzen[$name] ?? null;
            $kameras[] = [
                'kamera'     => $name,
                'name'       => $id !== null ? IPS_GetName($id) : $name,
                'online'     => $wert($id, 'Online') ?? (is_numeric($s['camera_fps'] ?? null) ? (float)$s['camera_fps'] > 0 : null),
                'alarm'      => (bool)$wert($id, 'Alarm'),
                'bewegung'   => (bool)$wert($id, 'Bewegung'),
                'personen'   => (int)$wert($id, 'Objekt_person'),
                'verbindung' => in_array((string)($s['connection_quality'] ?? ''), ['poor', 'unusable'], true) ? FrigateHubServerWerte::qualitaet((string)$s['connection_quality']) : '',
            ];
        }
        usort($kameras, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return [
            'hook'       => $this->hookPfad(),
            'k'          => $this->ReadAttributeString('HookSchluessel'),
            'api'        => $this->apiUrl() !== '',
            'takt'       => max(1, min(60, $this->ReadPropertyInteger('KachelTakt'))),
            'birdseye'   => $this->ReadAttributeBoolean('Birdseye'),
            'verfuegbar' => @$this->GetIDForIdent('Verfuegbar') !== false ? $this->GetValue('Verfuegbar') : null,
            'kameras'    => $kameras,
            'eigen'      => $eigen,
            'name'       => $eigen ? IPS_GetName($this->InstanceID) : '',
            'texte'      => array_combine(self::KACHEL_TEXTE, array_map([FrigateHubText::class, 't'], self::KACHEL_TEXTE)),
        ];
    }

    /* ==================================================================== Empfangen */

    public function ReceiveData($JSONString)
    {
        $paket = json_decode((string)$JSONString, true);
        if (!is_array($paket) || !isset($paket['Topic'])) {
            return '';
        }
        $praefix = FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix'));
        $topic = (string)$paket['Topic'];
        $payload = (string)($paket['Payload'] ?? '');
        if ($topic === $praefix . '/available') {
            $this->SetValue('Verfuegbar', trim($payload) === 'online');
        } elseif ($topic === $praefix . '/profile/state') {
            $this->profilAnzeigen(trim($payload));
        } elseif ($topic === $praefix . '/stats') {
            $stats = json_decode($payload, true);
            if (is_array($stats)) {
                $this->anwenden($stats);
            } else {
                $this->SendDebug('Statistik kein JSON', substr($payload, 0, 200), 0);
            }
        }
        return '';
    }

    /* ==================================================================== Öffentlich */

    /** FRIGS_ZustandAnfordern($id): Werte sofort über /api/stats holen. Rückgabe: Text fürs Formular. */
    public function ZustandAnfordern(): string
    {
        $this->SetTimerInterval('Abgleich', 0);
        if ($this->apiUrl() === '') {
            return FrigateHubText::t('ℹ️ Without a Frigate address, the values only arrive via MQTT (frigate/stats, every minute by default).');
        }
        $stats = $this->apiHolen('/api/stats');
        if ($stats === null) {
            $text = '⛔ ' . date(FrigateHubText::t('Y-m-d H:i:s')) . ': ' . $this->apiFehlerText();
            $this->WriteAttributeString('Abgleichstatus', $text);
            $this->UpdateFormField('Abgleichstatus', 'caption', $text);
            return $text;
        }
        $this->anwenden($stats);
        // Antwortet die API, läuft Frigate — auch wenn frigate/available noch nicht kam.
        $this->SetValue('Verfuegbar', true);
        // Profile: frigate/profile/state ist retained, Symcons MQTT-Server gibt das neuen Kindern nicht weiter
        $profile = $this->apiHolen('/api/profiles');
        if (is_array($profile)) {
            $namen = [];
            foreach ((array)($profile['profiles'] ?? []) as $p) {
                if (is_array($p) && (string)($p['name'] ?? '') !== '') {
                    $namen[(string)$p['name']] = (string)($p['friendly_name'] ?? $p['name']);
                }
            }
            $this->WriteAttributeString('Profile', (string)json_encode($namen));
            if ($namen !== [] || @$this->GetIDForIdent('Profil') !== false) {
                $this->profilAnzeigen((string)($profile['active_profile'] ?? 'none'));
            }
        }
        // Birdseye nur anbieten, wenn Frigate es liefert (sonst 404 „Camera not found", live 29.10.2026)
        $this->WriteAttributeBoolean('Birdseye', $this->apiDurchreichen('/api/birdseye/latest.jpg?height=120&h=120', '', 5)['code'] === 200);
        $this->kamerasBeobachten();
        $this->kachelSenden();
        $text = FrigateHubText::t('✅ %s: Values fetched via Frigate\'s API.', date(FrigateHubText::t('Y-m-d H:i:s')));
        $this->WriteAttributeString('Abgleichstatus', $text);
        $this->UpdateFormField('Abgleichstatus', 'caption', $text);
        return $text;
    }

    /**
     * FRIGS_Neustarten($id): Frigate neu starten (MQTT frigate/restart). Frigate beendet sich dann;
     * Docker startet es neu, wenn restart: unless-stopped o. ä. gesetzt ist.
     */
    public function Neustarten(): string
    {
        if (!$this->HasActiveParent()) {
            return FrigateHubText::t('⛔ No active MQTT instance above — the command cannot reach Frigate.');
        }
        $praefix = FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix'));
        $this->SendDataToParent((string)json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,   // nie speichern: Frigate würde sonst bei jedem Verbinden neu starten
            'Topic'            => $praefix . '/restart',
            'Payload'          => 'restart',   // Frigate wertet den Inhalt nicht aus
        ], JSON_UNESCAPED_SLASHES));
        $this->LogMessage(FrigateHubText::t('Frigate restart requested'), KL_NOTIFY);
        return FrigateHubText::t('🔄 Restart requested. Frigate is usually back after 1–2 minutes ("Frigate reachable").');
    }

    /**
     * FRIGS_ProfilSetzen($id, $Profil): Frigate-Profil aktivieren (Name wie in Frigate, '' oder
     * „none" = keins) — z. B. „away" beim Scharfschalten der Alarmanlage. Rückgabe: Text.
     */
    public function ProfilSetzen(string $Profil): string
    {
        if (!$this->HasActiveParent()) {
            return FrigateHubText::t('⛔ No active MQTT instance above — the command cannot reach Frigate.');
        }
        $name = trim($Profil) === '' ? 'none' : trim($Profil);
        $bekannt = (array)json_decode($this->ReadAttributeString('Profile'), true);
        if ($name !== 'none' && $bekannt !== [] && !isset($bekannt[$name])) {
            return FrigateHubText::t('⛔ Frigate has no profile "%s". Available: %s.', $name, implode(', ', array_keys($bekannt)));
        }
        $this->SendDataToParent((string)json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix')) . '/profile/set',
            'Payload'          => $name,
        ], JSON_UNESCAPED_SLASHES));
        return FrigateHubText::t('✅ Profile "%s" requested; Frigate confirms via frigate/profile/state.', $name === 'none' ? FrigateHubText::t('none')
            : ($bekannt[$name] ?? $name));
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Profil') {
            echo $this->ProfilSetzen((string)$Value);
            return;
        }
        throw new Exception(FrigateHubText::t('Unknown action: %s', $Ident));
    }

    /** Aktives Profil in der Variablen „Frigate-Profil" (Anzeigename, „keins" bei none). */
    private function profilAnzeigen(string $name): void
    {
        $bekannt = (array)json_decode($this->ReadAttributeString('Profile'), true);
        $optionen = [['Value' => 'none', 'Caption' => FrigateHubText::t('none'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1]];
        foreach ($bekannt as $schluessel => $anzeige) {
            $optionen[] = ['Value' => (string)$schluessel, 'Caption' => (string)$anzeige, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1];
        }
        if (@$this->GetIDForIdent('Profil') === false) {
            $this->MaintainVariable('Profil', FrigateHubText::t('Frigate profile'), VARIABLETYPE_STRING, [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => (string)json_encode($optionen)], 70, true);
            $this->EnableAction('Profil');
        }
        $this->SetValue('Profil', $name === '' ? 'none' : $name);
    }

    /* ==================================================================== Aufnahme-Wächter */

    /**
     * FRIGS_AufnahmePruefen($id): prüft, ob Frigate aufzeichnet, setzt „Aufnahme in Ordnung" und
     * „Aufnahmezustand" und meldet Störung, Erinnerung und Entwarnung. Läuft jede Minute.
     * Rückgabe: Text fürs Formular.
     */
    public function AufnahmePruefen(): string
    {
        if (!$this->waechterAn()) {
            $this->SetTimerInterval('Waechter', 0);
            return $this->apiUrl() === '' ? FrigateHubText::t('ℹ️ The recording monitor is off: it needs the Frigate address.')
                : FrigateHubText::t('ℹ️ The recording monitor is off: the "Monitor recording" checkbox is not ticked.');
        }
        $this->SetTimerInterval('Waechter', self::WAECHTER_TAKT);
        $jetzt = time();
        $z = json_decode($this->ReadAttributeString('Waechter'), true);
        $z = is_array($z) ? $z : [];
        $hinweise = [];
        $e = FrigateHubWaechter::bewerten($z, $this->waechterBeobachten($z, $jetzt, $hinweise), $jetzt);
        $this->WriteAttributeString('Waechter', (string)json_encode($e['zustand'], JSON_UNESCAPED_UNICODE));
        $zustand = FrigateHubWaechter::zustandText($e['probleme']);
        if (@$this->GetIDForIdent('AufnahmeOk') !== false) {
            $this->SetValue('AufnahmeOk', $e['probleme'] === []);
            $this->SetValue('Aufnahmezustand', $zustand);
        }
        if ($e['meldung'] !== null) {
            $m = $e['meldung'];
            $this->LogMessage($m['titel'] . ': ' . $m['text'], $m['art'] === 'entwarnung' ? KL_NOTIFY : KL_WARNING);
            if ($this->pushZiele() !== []) {
                $this->pushSenden($m['titel'], $m['text']);
            }
        }
        $text = ($e['probleme'] === [] ? '✅ ' : '⛔ ') . date(FrigateHubText::t('Y-m-d H:i:s')) . ': ' . $zustand . ($hinweise === [] ? '' : ' ⚠️ ' . implode(' ', $hinweise));
        $this->WriteAttributeString('Waechterstatus', $text);
        $this->UpdateFormField('Waechterstatus', 'caption', $text);
        $this->UpdateFormField('Speicherstand', 'caption', $this->speicherstand());
        $this->UpdateFormField('Pushstatus', 'caption', $this->pushstatus());
        return $text;
    }

    /** FRIGS_SpeicherUebernehmen($id): nach einem gewollten Speichertausch den jetzigen als richtig merken. */
    public function SpeicherUebernehmen(): string
    {
        $z = json_decode($this->ReadAttributeString('Waechter'), true);
        $z = is_array($z) ? $z : [];
        unset($z['speicher']);
        $this->WriteAttributeString('Waechter', (string)json_encode($z, JSON_UNESCAPED_UNICODE));
        $text = $this->AufnahmePruefen();
        return strpos($text, 'ℹ️') === 0 ? $text : FrigateHubText::t('✅ Current recording storage accepted: %s', $this->speicherstand());
    }

    /** Probemeldung an die Ziele des Wächters. Rückgabe: Text fürs Formular. */
    public function PushTesten(): string
    {
        $text = $this->pushSenden('🔔 ' . IPS_GetName($this->InstanceID), FrigateHubText::t('Test message from the recording monitor – this is how a fault arrives.'));
        $this->UpdateFormField('Pushstatus', 'caption', $this->pushstatus());
        return $text;
    }

    /** Der Wächter verschickt nur Text, kein Bild. */
    protected function pushBild(bool $livebild): int
    {
        return -1;
    }

    private function waechterAn(): bool
    {
        return $this->ReadPropertyBoolean('WaechterAktiv') && $this->apiUrl() !== '';
    }

    /**
     * Beobachtungen für FrigateHubWaechter::bewerten() über Frigates API sammeln.
     *
     * @param string[] $hinweise Prüfungen, die nicht möglich waren (fürs Formular)
     */
    private function waechterBeobachten(array $z, int $jetzt, array &$hinweise): array
    {
        $stats = $this->apiHolen('/api/stats');
        if ($stats === null) {
            $hinweise[] = $this->apiFehlerText();
            return ['erreichbar' => false];
        }
        $b = ['erreichbar' => true, 'speicher' => FrigateHubWaechter::speicher($stats), 'protokoll' => null];

        // Protokoll: nur die jüngsten Zeilen (negativer Start, Frigate schneidet per Python-Slice)
        $log = $this->apiHolen('/api/logs/frigate?start=-' . self::PROTOKOLL_ZEILEN);
        if ($log === null || !is_array($log['lines'] ?? null)) {
            $hinweise[] = FrigateHubText::t('Frigate\'s log is not readable (%s) — on port 8971 the Frigate user needs the "admin" role. Without it, the monitor cannot detect write errors.',
                $log === null ? $this->apiFehler : FrigateHubText::t('unexpected response'));
        } else {
            $alt = is_array($z['protokoll'] ?? null) ? $z['protokoll'] : [];
            $wiederholt = is_array($alt['wiederholt'] ?? null) ? $alt['wiederholt'] + ['ts' => '', 'n' => 0] : ['ts' => '', 'n' => 0];
            $b['protokoll'] = FrigateHubWaechter::protokoll(array_slice($log['lines'], -self::PROTOKOLL_ZEILEN), (string)($alt['neueste'] ?? ''), $wiederholt);
        }

        $config = $this->apiHolen('/api/config');
        if ($config === null) {
            $hinweise[] = FrigateHubText::t('Frigate\'s configuration is not readable — alarms and continuous recording not checked.');
            return $b;
        }
        $kameras = FrigateHubWaechter::kameras($config);

        // Alarme, die seit der letzten Prüfung zu Ende gingen: haben sie einen Film?
        [$nach, $bis] = FrigateHubWaechter::alarmFenster($z, $jetzt);
        $alarmKameras = array_keys(array_filter($kameras, fn ($k) => $k['alarme']));
        $b['alarme'] = [];
        $vollstaendig = true;
        if ($alarmKameras !== []) {
            $reviews = $this->apiHolen('/api/review?severity=alert&limit=50&cameras=' . rawurlencode(implode(',', $alarmKameras)) . '&after=' . $nach . '&before=' . $bis);
            $vollstaendig = $reviews !== null;
            foreach (FrigateHubWaechter::alarme((array)$reviews, $nach, $bis) as $a) {
                if (!in_array($a['kamera'], $alarmKameras, true)) {
                    continue;
                }
                $aufnahmen = $this->apiHolen('/api/' . rawurlencode($a['kamera']) . '/recordings?after=' . (int)floor($a['start']) . '&before=' . (int)ceil($a['ende']));
                if ($aufnahmen === null) {
                    $vollstaendig = false;   // nächste Prüfung versucht dasselbe Fenster noch einmal
                    break;
                }
                $b['alarme'][] = ['kamera' => $a['kamera'], 'ende' => $a['ende'], 'film' => FrigateHubWaechter::hatFilm($aufnahmen)];
            }
        }
        if ($vollstaendig) {
            $b['alarmeBis'] = $bis;
        } else {
            $b['alarme'] = [];
        }

        // Daueraufnahme: nur Kameras, die Bilder liefern
        $b['dauer'] = [];
        foreach ($kameras as $name => $k) {
            $fps = $stats['cameras'][$name]['camera_fps'] ?? null;
            if (!$k['dauer'] || !is_numeric($fps) || (float)$fps <= 0) {
                continue;
            }
            $aufnahmen = $this->apiHolen('/api/' . rawurlencode($name) . '/recordings?after=' . ($jetzt - 900) . '&before=' . $jetzt);
            if ($aufnahmen !== null) {
                $b['dauer'][$name] = FrigateHubWaechter::neuesteAufnahme($aufnahmen);
            }
        }
        return $b;
    }

    private function waechterstatus(): string
    {
        if (!$this->waechterAn()) {
            return $this->apiUrl() === '' ? FrigateHubText::t('ℹ️ Off: the monitor needs the Frigate address (panel "Frigate server").') : FrigateHubText::t('ℹ️ Off.');
        }
        $text = $this->ReadAttributeString('Waechterstatus');
        return $text !== '' ? $text : FrigateHubText::t('ℹ️ Not checked yet — happens within a minute.');
    }

    private function speicherstand(): string
    {
        $z = json_decode($this->ReadAttributeString('Waechter'), true);
        $s = is_array($z['speicher'] ?? null) ? $z['speicher'] : null;
        return $s === null ? FrigateHubText::t('Recording storage: not memorized yet.') : FrigateHubText::t('Memorized recording storage: %s.', FrigateHubWaechter::speicherText($s));
    }

    /* ==================================================================== Formular */

    /** Fachpanels mit festem Schlüssel (Reihenfolge = Standard) für „🪟 Formular: Aufbau“. */
    protected function frigatehubFachpanels(): array
    {
        return [
            'uebersicht' => $this->uebersichtPanel(),
            'kachel' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🖥️  Tile and IPSView'),
                'items' => [
                    ['type' => 'Label', 'caption' => FrigateHubText::t('This instance is also an overview tile of all cameras of this Frigate installation: live image per camera, alarm, persons, disrupted connection; plus Birdseye if Frigate provides it. A tap enlarges. The images need the Frigate address.')],
                    ['type' => 'NumberSpinner', 'name' => 'KachelTakt', 'caption' => FrigateHubText::t('Reload live images every'), 'suffix' => 's', 'minimum' => 1, 'maximum' => 60],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('IPSView or browser: enter the address from "Show address for IPSView" as a web page element (replace <Symcon-IP>). Reachable without a Symcon login, therefore only with a key; a new key invalidates old addresses.')],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => FrigateHubText::t('Show address for IPSView'), 'onClick' => 'echo FRIGS_SeiteAdresse($id);'],
                        ['type' => 'Button', 'caption' => FrigateHubText::t('Renew tile key'), 'onClick' => 'echo FRIGS_KachelSchluesselErneuern($id);'],
                    ]],
                ],
            ],
            'anmeldung' => $this->apiAnmeldePanel(),
            'server' => [
                'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => FrigateHubText::t('🖥️  Frigate server'),
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'TopicPrefix', 'caption' => FrigateHubText::t('MQTT prefix (Frigate: mqtt.topic_prefix, default "frigate")')],
                    ['type' => 'Label', 'name' => 'Empfangsstatus', 'caption' => $this->empfangsstatus()],
                    ...$this->apiFormular(FrigateHubText::t('Optional: with an address, the values are available right after applying instead of only with the next statistics via MQTT.')),
                    ['type' => 'Label', 'name' => 'Abgleichstatus', 'caption' => $this->abgleichstatus()],
                    ['type' => 'CheckBox', 'name' => 'LeistungAlsVariablen', 'caption' => FrigateHubText::t('Performance values additionally as variables (CPU, memory, GPU, inference time, version, uptime, storage used) — for archive and charts')],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('For other modules: FRIGS_GetCameras($id) returns all cameras with the variables for sensors and switches (contract %s). FRIGS_MeldungLetzteKamera($id, "Text", true) sends the image of the camera with the most recent alarm — a single alarm action for all cameras.', self::VERTRAG)],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => FrigateHubText::t('Query values now'), 'onClick' => 'echo FRIGS_ZustandAnfordern($id);'],
                        ['type' => 'Button', 'caption' => FrigateHubText::t('🔄 Restart Frigate'), 'onClick' => 'echo FRIGS_Neustarten($id);',
                            'confirm' => FrigateHubText::t('Restart Frigate now? Detection and recording pause for about 1–2 minutes.')],
                    ]],
                ],
            ],
            'waechter' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🎥  Recording monitor'),
                'items' => [
                    ['type' => 'Label', 'caption' => FrigateHubText::t('Checks every minute via Frigate\'s API whether Frigate is really recording and reports if not — even if Frigate itself reports "running". Detects: Frigate no longer responds (3 min), write errors when saving recordings (e.g. disconnected USB drive), a changed recording storage (drive missing, Frigate writes to the system drive), alarms without recording and stalled continuous recording.')],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('Needs the Frigate address. The monitor reads write errors from Frigate\'s log; on port 8971 the Frigate user needs the "admin" role for this. If Symcon runs on the same machine as Frigate, nothing can warn when that machine fails.')],
                    ['type' => 'CheckBox', 'name' => 'WaechterAktiv', 'caption' => FrigateHubText::t('Monitor recording')],
                    ['type' => 'Label', 'name' => 'Waechterstatus', 'caption' => $this->waechterstatus()],
                    ['type' => 'Label', 'name' => 'Speicherstand', 'caption' => $this->speicherstand()],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('A message on a fault, a daily reminder while it persists, and an all-clear. Without a target, faults only appear in the variables "Recording OK" and "Recording state" and in the message log.')],
                    $this->pushZielListe(),
                    ['type' => 'Select', 'name' => 'PushTon', 'caption' => FrigateHubText::t('Sound'), 'options' => $this->tonOptionen()],
                    ['type' => 'Label', 'name' => 'Pushstatus', 'caption' => $this->pushstatus()],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => FrigateHubText::t('Check now'), 'onClick' => 'echo FRIGS_AufnahmePruefen($id);'],
                        ['type' => 'Button', 'caption' => FrigateHubText::t('Send test message'), 'onClick' => 'echo FRIGS_PushTesten($id);'],
                        ['type' => 'Button', 'caption' => FrigateHubText::t('Accept current storage'), 'onClick' => 'echo FRIGS_SpeicherUebernehmen($id);',
                            'confirm' => FrigateHubText::t('Memorize the current recording storage as correct? Only after an intended drive replacement.')],
                    ]],
                ],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => FrigateHubText::t('Ready')],
        ];
        $teile = $this->formAufbauTeilen($this->frigatehubFachpanels());
        return $this->frigatehubFormular($teile['elements'], $teile['actions'], $status);
    }

    protected function frigatehubPrefix(): string
    {
        return 'FRIGS';
    }

    protected function frigatehubZweck(): array
    {
        return [
            FrigateHubText::t('Monitors the Frigate server: reachable, version and whether a newer one exists, CPU, memory, inference time, GPU load and free storage for recordings.'),
            FrigateHubText::t('Reports faulty cameras (no images, poor connection) in a variable — good as a trigger for a notification. Frigate can be restarted from here.'),
            FrigateHubText::t('The recording monitor reports when Frigate stops recording — even if Frigate itself reports "running", e.g. after a disconnected recording drive.'),
        ];
    }

    protected function frigatehubDoku(): array
    {
        return [
            FrigateHubText::t('Create under the same MQTT server or MQTT client as the cameras (the configurator offers the server too). One instance per Frigate server.'),
            FrigateHubText::t('The values come from frigate/stats; Frigate sends it at the mqtt.stats_interval rate (default 60 s). "Frigate reachable" follows frigate/available and drops immediately when Frigate loses the MQTT connection.'),
            FrigateHubText::t('As variables (for notifications and archive): Frigate reachable, Latest version, Recording storage free, Faulty cameras and Camera state. Version, uptime, CPU, memory, GPU, inference time and storage used are shown in the overview above; as variables only with the checkbox "Performance values additionally as variables".'),
            FrigateHubText::t('The overview fetches the values fresh from Frigate when the form is opened (with a Frigate address), otherwise it shows the last statistics via MQTT. Per camera: frames/s (of expected), detections/s, connection quality, reconnects and stalls in the last hour.'),
            FrigateHubText::t('"Latest version" comes from Frigate itself (it queries GitHub); if it says "unknown", this query is disabled in Frigate or there is no internet.'),
            FrigateHubText::t('"Faulty cameras" counts cameras that deliver no images or whose connection Frigate rates as poor or unusable; "Camera state" says which ones and why.'),
            FrigateHubText::t('"Restart Frigate" sends frigate/restart. Frigate then exits and Docker restarts it (restart: unless-stopped). Without this Docker setting, Frigate stays off.'),
            FrigateHubText::t('Recording monitor (with Frigate address, every minute): "Frigate not responding" after 3 min without a response. "Write errors" from Frigate\'s log (from 3 new errors when storing recordings in one check, resolved after 2 checks without) — on port 8971 only with the Frigate role "admin". "Recording storage changed" when the size (over 2 %) or type of the storage under /media/frigate/recordings deviates from the memorized state; after an intended replacement, "Accept current storage".'),
            FrigateHubText::t('"Alarm without recording": an alarm ended 2 min ago and has no recording (only cameras that record alarms); the message stays until the next alarm with a recording, at most 24 h. "Continuous recording stalled": newest recording older than 5 min (only cameras with continuous recording that deliver images). Without continuous recording, hours without a new recording are normal and not an error.'),
            FrigateHubText::t('Messages: one for every new fault, a daily reminder while it persists, and an all-clear. Variables "Recording OK" and "Recording state" for your own automations. If Symcon runs on the same machine as Frigate, nothing can warn if the whole machine fails.'),
        ];
    }

    /* ==================================================================== intern */

    private function anwenden(array $stats): void
    {
        $this->WriteAttributeInteger('LetzteStatistik', time());
        $this->SetBuffer('Statistik', (string)json_encode(FrigateHubServerWerte::kuerzen($stats)));
        $this->kachelSenden();
        $alle = $this->ReadPropertyBoolean('LeistungAlsVariablen');
        foreach (FrigateHubServerWerte::werte($stats) as $w) {
            if (!$w['kern'] && !$alle) {
                // Behält der Nutzer eine solche Variable (früher angelegt), bleibt sie aktuell.
                if (@$this->GetIDForIdent($w['ident']) !== false) {
                    $this->SetValue($w['ident'], $w['wert']);
                }
                continue;
            }
            $typ = ['float' => VARIABLETYPE_FLOAT, 'int' => VARIABLETYPE_INTEGER, 'string' => VARIABLETYPE_STRING][$w['typ']];
            $darstellung = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
            if ($w['einheit'] !== '') {
                $darstellung['SUFFIX'] = $w['einheit'];
            }
            if ($typ === VARIABLETYPE_FLOAT) {
                $darstellung['DIGITS'] = $w['stellen'];
            }
            $this->variableAnlegen($w['ident'], (string)FrigateHubText::wert($w['name']), $typ, $darstellung, $w['pos']);
            $this->SetValue($w['ident'], $w['wert']);
        }
    }

    /**
     * Legt eine Variable an, wenn es sie noch nicht gibt. Bestehende bleiben unangetastet: Name
     * und Position gehören dann dem Nutzer (SUITE.md 9m), und jede Minute MaintainVariable wäre
     * ein Update-Sturm.
     */
    private function variableAnlegen(string $ident, string $name, int $typ, array $darstellung, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->MaintainVariable($ident, $name, $typ, $darstellung, $position, true);
        }
    }

    /** Panel „Übersicht": beim Öffnen frisch über die API, sonst aus der letzten MQTT-Statistik. */
    private function uebersichtPanel(): array
    {
        $stats = null;
        $quelle = '';
        if ($this->apiUrl() !== '') {
            $stats = $this->apiHolen('/api/stats');
            if ($stats !== null) {
                $this->SetBuffer('Statistik', (string)json_encode(FrigateHubServerWerte::kuerzen($stats)));
                $quelle = FrigateHubText::t('As of %s, fetched fresh from Frigate on opening.', date('H:i:s'));
            }
        }
        if ($stats === null) {
            $gepuffert = json_decode($this->GetBuffer('Statistik'), true);
            $ts = $this->ReadAttributeInteger('LetzteStatistik');
            if (is_array($gepuffert) && $gepuffert !== []) {
                $stats = $gepuffert;
                $quelle = FrigateHubText::t('As of the last statistics from %s.', date(FrigateHubText::t('Y-m-d H:i:s'), $ts));
            }
        }
        if ($stats === null) {
            return ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => FrigateHubText::t('📊  Overview'), 'items' => [
                ['type' => 'Label', 'caption' => FrigateHubText::t('ℹ️ No statistics from Frigate yet. They arrive via MQTT every minute by default; with a Frigate address immediately on opening.')],
            ]];
        }
        $u = FrigateHubServerWerte::uebersicht($stats);
        $items = [['type' => 'Label', 'caption' => $quelle, 'italic' => true]];
        foreach ($u['zeilen'] as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        if ($u['kameras'] !== []) {
            $items[] = [
                'type' => 'List', 'name' => 'KameraUebersicht', 'caption' => FrigateHubText::t('Cameras'), 'rowCount' => min(8, max(2, count($u['kameras']))),
                'add' => false, 'delete' => false,
                'columns' => [
                    ['caption' => FrigateHubText::t('Camera'), 'name' => 'kamera', 'width' => 'auto'],
                    ['caption' => FrigateHubText::t('Frames/s'), 'name' => 'bilder', 'width' => '110px'],
                    ['caption' => FrigateHubText::t('Detections/s'), 'name' => 'erkennungen', 'width' => '120px'],
                    ['caption' => FrigateHubText::t('Connection'), 'name' => 'verbindung', 'width' => '120px'],
                    ['caption' => FrigateHubText::t('Reconnects/h'), 'name' => 'neu', 'width' => '150px'],
                    ['caption' => FrigateHubText::t('Stalls/h'), 'name' => 'aussetzer', 'width' => '110px'],
                ],
                'values' => $u['kameras'],
            ];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => FrigateHubText::t('📊  Overview'), 'items' => $items];
    }

    private function empfangsstatus(): string
    {
        if (!$this->HasActiveParent()) {
            return FrigateHubText::t('⚠️ No active MQTT instance above — the instance must be attached to Symcon\'s MQTT server or an MQTT client that Frigate sends to.');
        }
        $ts = $this->ReadAttributeInteger('LetzteStatistik');
        return $ts === 0
            ? FrigateHubText::t('ℹ️ No statistics from Frigate yet. They arrive every minute by default; are MQTT server and prefix correct?')
            : FrigateHubText::t('✅ Last statistics on %s.', date(FrigateHubText::t('Y-m-d H:i:s'), $ts));
    }

    private function abgleichstatus(): string
    {
        if ($this->apiUrl() === '') {
            return trim((string)$this->ReadPropertyString('ApiUrl')) === ''
                ? FrigateHubText::t('ℹ️ No Frigate address: values via MQTT only.')
                : FrigateHubText::t('⚠️ The Frigate address is invalid — expected e.g. http://192.168.1.20:5000.');
        }
        $text = $this->ReadAttributeString('Abgleichstatus');
        return $text !== '' ? $text : FrigateHubText::t('ℹ️ Not queried yet — happens shortly after applying.');
    }
}
