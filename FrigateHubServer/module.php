<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';
require_once __DIR__ . '/../libs/FrigateHubServerWerte.php';
require_once __DIR__ . '/../libs/FrigateHubWebhook.php';

/**
 * Der Frigate-Server selbst: erreichbar, Version (mit Hinweis auf eine neuere), Laufzeit,
 * CPU/Arbeitsspeicher, Erkennungszeit je Detektor, GPU-Last, Speicher der Aufnahmen und eine
 * Sammelmeldung über gestörte Kameras. Dazu Frigate neu starten.
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

    private const KAMERA_GUID = '{6BB54171-2F24-4653-9061-F2BC352A9D3B}';

    /** Variablen der Kamera-Instanzen, die die Übersichtskachel zeigt (Änderung → neu senden). */
    private const KACHEL_IDENTS = ['Online', 'Alarm', 'Bewegung', 'Objekt_person'];

    /** Datenschnittstelle „senden" (Publish an den Broker), auf MQTT Client und Server gleich. */
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    /** Wartezeit nach dem Übernehmen bis zum Abgleich (ms) — ApplyChanges nicht blockieren. */
    private const ABGLEICH_VERZOEGERUNG = 2000;

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
        $this->SetVisualizationType(1);

        $this->RegisterTimer('Abgleich', 0, 'FRIGS_ZustandAnfordern($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->apiUebernehmen()) {
            return;
        }

        $this->variableAnlegen('Verfuegbar', 'Frigate erreichbar', VARIABLETYPE_BOOLEAN, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => (string)json_encode([
                ['Value' => false, 'Caption' => 'nicht erreichbar', 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0xE53935],
                ['Value' => true, 'Caption' => 'erreichbar', 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x43A047],
            ]),
        ], 1);
        // Schrägstrichfreie Merkmale (CometWiFi-Fund); welches Topic gemeint ist, prüft ReceiveData.
        $this->SetReceiveDataFilter('.*(stats|available).*');
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
            $this->hookAntwort(403, 'text/plain; charset=utf-8', 'Kein Zugriff.');
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
            $this->hookAntwort(404, 'text/plain; charset=utf-8', 'Unbekannt.');
            return;
        }
        $a = $this->apiDurchreichen($pfad, '', 8);
        if ($a['code'] !== 200) {
            $this->hookAntwort($a['code'] === 404 ? 404 : 502, 'text/plain; charset=utf-8', 'Frigate liefert kein Bild.');
            return;
        }
        $this->hookAntwort(200, $a['kopf']['content-type'] ?? 'image/jpeg', $a['body'], 'no-store');
    }

    /** Vertragsversion von FRIGS_GetCameras() (Major nur bei Bruch erhöhen, SUITE.md-Regel). */
    public const VERTRAG = '1.0';

    /**
     * FRIGS_GetCameras($id): neutrale Liste der Kameras dieser Frigate-Instanz für andere Module
     * (Alarmanlagen wie OpenHomeAlarm, Regelwerke): Variablen-IDs für Melder und Schalter sowie die
     * Meldefunktion. Nur Kameras, für die es eine FrigateHub-Kamera-Instanz gibt. 0 = gibt es nicht.
     */
    public function GetCameras(): array
    {
        $kameras = [];
        foreach ($this->kameraInstanzen() as $kamera => $id) {
            $v = fn (string $ident): int => (int)(@IPS_GetObjectIDByIdent($ident, $id) ?: 0);
            $zonen = [];
            foreach (IPS_GetChildrenIDs($id) as $kind) {
                $o = IPS_GetObject($kind);
                if ($o['ObjectType'] === 2 && preg_match('/^Zone_(.+)_person(?:_[0-9a-f]{4})?$/', $o['ObjectIdent'], $m)) {
                    $zonen[] = ['zone' => $m[1], 'name' => $o['ObjectName'], 'personen' => $kind];
                }
            }
            $kameras[] = [
                'contractVersion' => self::VERTRAG,
                'kamera'          => (string)$kamera,
                'name'            => IPS_GetName($id),
                'instanz'         => $id,
                'melder'          => ['alarm' => $v('Alarm'), 'erkennung' => $v('Erkennung'), 'bewegung' => $v('Bewegung'), 'personen' => $v('Objekt_person'),
                    'online' => $v('Online'), 'zonen' => $zonen],
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
            return 'ℹ️ Keine Kamera mit Alarm oder Erkennung gefunden.';
        }
        if (!function_exists('FRIG_MeldungMitSchnappschuss')) {
            return '⛔ FrigateHub-Kamera nicht ansprechbar.';
        }
        return IPS_GetName($beste) . ': ' . FRIG_MeldungMitSchnappschuss($beste, $Text, $Livebild);
    }

    /** FRIGS_KachelSchluesselErneuern($id): alte Bild- und Seitenadressen werden ungültig. */
    public function KachelSchluesselErneuern(): string
    {
        $this->WriteAttributeString('HookSchluessel', bin2hex(random_bytes(16)));
        $this->kachelSenden(true);
        return '✅ Neuer Schlüssel erzeugt. In IPSView die neue Adresse eintragen.';
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
            return 'ℹ️ Ohne Frigate-Adresse kommen die Werte nur per MQTT (frigate/stats, standardmäßig jede Minute).';
        }
        $stats = $this->apiHolen('/api/stats');
        if ($stats === null) {
            $text = '⛔ ' . date('d.m.Y H:i:s') . ': ' . $this->apiFehlerText();
            $this->WriteAttributeString('Abgleichstatus', $text);
            $this->UpdateFormField('Abgleichstatus', 'caption', $text);
            return $text;
        }
        $this->anwenden($stats);
        // Antwortet die API, läuft Frigate — auch wenn frigate/available noch nicht kam.
        $this->SetValue('Verfuegbar', true);
        // Birdseye nur anbieten, wenn Frigate es liefert (sonst 404 „Camera not found", live 29.10.2026)
        $this->WriteAttributeBoolean('Birdseye', $this->apiDurchreichen('/api/birdseye/latest.jpg?height=120&h=120', '', 5)['code'] === 200);
        $this->kamerasBeobachten();
        $this->kachelSenden();
        $text = '✅ ' . date('d.m.Y H:i:s') . ': Werte über Frigates API geholt.';
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
            return '⛔ Keine aktive MQTT-Instanz darüber — der Befehl kann Frigate nicht erreichen.';
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
        $this->LogMessage('Neustart von Frigate angefordert', KL_NOTIFY);
        return '🔄 Neustart angefordert. Frigate ist in der Regel nach 1–2 Minuten wieder da („Frigate erreichbar").';
    }

    /* ==================================================================== Formular */

    public function GetConfigurationForm()
    {
        $fach = [
            $this->uebersichtPanel(),
            [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🖥️  Kachel und IPSView',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Diese Instanz ist zugleich eine Übersichtskachel aller Kameras dieser Frigate-Instanz: Livebild je Kamera, Alarm, Personen, gestörte Verbindung; dazu Birdseye, wenn Frigate es liefert. Ein Tipp vergrößert. Die Bilder braucht die Frigate-Adresse.'],
                    ['type' => 'NumberSpinner', 'name' => 'KachelTakt', 'caption' => 'Livebilder neu laden alle', 'suffix' => 's', 'minimum' => 1, 'maximum' => 60],
                    ['type' => 'Label', 'caption' => 'IPSView oder Browser: als Webseiten-Element die Adresse von „Adresse für IPSView anzeigen" eintragen (<Symcon-IP> ersetzen). Ohne Symcon-Anmeldung erreichbar, darum nur mit Schlüssel; ein neuer Schlüssel macht alte Adressen ungültig.'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => 'Adresse für IPSView anzeigen', 'onClick' => 'echo FRIGS_SeiteAdresse($id);'],
                        ['type' => 'Button', 'caption' => 'Kachel-Schlüssel erneuern', 'onClick' => 'echo FRIGS_KachelSchluesselErneuern($id);'],
                    ]],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🖥️  Frigate-Server',
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'TopicPrefix', 'caption' => 'MQTT-Präfix (Frigate: mqtt.topic_prefix, Standard „frigate")'],
                    ['type' => 'Label', 'name' => 'Empfangsstatus', 'caption' => $this->empfangsstatus()],
                    ...$this->apiFormular('Optional: Mit Adresse stehen die Werte gleich nach dem Übernehmen bereit statt erst mit der nächsten Statistik per MQTT.'),
                    ['type' => 'Label', 'name' => 'Abgleichstatus', 'caption' => $this->abgleichstatus()],
                    ['type' => 'CheckBox', 'name' => 'LeistungAlsVariablen', 'caption' => 'Leistungswerte zusätzlich als Variablen (CPU, Arbeitsspeicher, GPU, Erkennungszeit, Version, Laufzeit, Speicher belegt) — für Archiv und Diagramme'],
                    ['type' => 'Label', 'caption' => 'Für andere Module: FRIGS_GetCameras($id) liefert alle Kameras mit den Variablen für Melder und Schalter (Vertrag ' . self::VERTRAG . '). FRIGS_MeldungLetzteKamera($id, "Text", true) schickt das Bild der Kamera mit dem jüngsten Alarm — eine einzige Alarmaktion für alle Kameras.'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => 'Werte jetzt abfragen', 'onClick' => 'echo FRIGS_ZustandAnfordern($id);'],
                        ['type' => 'Button', 'caption' => '🔄 Frigate neu starten', 'onClick' => 'echo FRIGS_Neustarten($id);',
                            'confirm' => 'Frigate jetzt neu starten? Erkennung und Aufnahme setzen dabei etwa 1–2 Minuten aus.'],
                    ]],
                ],
            ],
        ];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Bereit'],
        ];
        return $this->frigatehubFormular($fach, [], $status);
    }

    protected function frigatehubPrefix(): string
    {
        return 'FRIGS';
    }

    protected function frigatehubZweck(): array
    {
        return [
            'Überwacht den Frigate-Server: erreichbar, Version und ob es eine neuere gibt, CPU, Arbeitsspeicher, Erkennungszeit, GPU-Last und freier Speicher für Aufnahmen.',
            'Meldet gestörte Kameras (keine Bilder, schlechte Verbindung) in einer Variablen — gut als Auslöser für eine Benachrichtigung. Frigate lässt sich von hier neu starten.',
        ];
    }

    protected function frigatehubDoku(): array
    {
        return [
            'Unter demselben MQTT-Server bzw. MQTT-Client anlegen wie die Kameras (der Konfigurator bietet den Server mit an). Eine Instanz je Frigate-Server.',
            'Die Werte kommen aus frigate/stats; Frigate schickt das im Takt von mqtt.stats_interval (Standard 60 s). „Frigate erreichbar" folgt frigate/available und fällt sofort, wenn Frigate die MQTT-Verbindung verliert.',
            'Als Variablen (für Benachrichtigungen und Archiv): Frigate erreichbar, Neueste Version, Speicher Aufnahmen frei, Gestörte Kameras und Kamerazustand. Version, Laufzeit, CPU, Arbeitsspeicher, GPU, Erkennungszeit und belegter Speicher stehen in der Übersicht oben; als Variablen nur mit dem Häkchen „Leistungswerte zusätzlich als Variablen".',
            'Die Übersicht holt die Werte beim Öffnen des Formulars frisch von Frigate (mit Frigate-Adresse), sonst zeigt sie die letzte Statistik per MQTT. Je Kamera: Bilder/s (von erwarteten), Erkennungen/s, Verbindungsqualität, Neuverbindungen und Aussetzer der letzten Stunde.',
            '„Neueste Version" kommt von Frigate selbst (es fragt GitHub ab); steht dort „unbekannt", ist diese Abfrage in Frigate abgeschaltet oder ohne Internet.',
            '„Gestörte Kameras" zählt Kameras, die keine Bilder liefern oder deren Verbindung Frigate als schlecht oder unbrauchbar einstuft; „Kamerazustand" sagt, welche und warum.',
            '„Frigate neu starten" schickt frigate/restart. Frigate beendet sich dann, Docker startet es neu (restart: unless-stopped). Ohne diese Docker-Einstellung bleibt Frigate aus.',
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
            $this->variableAnlegen($w['ident'], $w['name'], $typ, $darstellung, $w['pos']);
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
                $quelle = 'Stand ' . date('H:i:s') . ' Uhr, beim Öffnen frisch von Frigate geholt.';
            }
        }
        if ($stats === null) {
            $gepuffert = json_decode($this->GetBuffer('Statistik'), true);
            $ts = $this->ReadAttributeInteger('LetzteStatistik');
            if (is_array($gepuffert) && $gepuffert !== []) {
                $stats = $gepuffert;
                $quelle = 'Stand der letzten Statistik vom ' . date('d.m.Y H:i:s', $ts) . ' Uhr.';
            }
        }
        if ($stats === null) {
            return ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📊  Übersicht', 'items' => [
                ['type' => 'Label', 'caption' => 'ℹ️ Noch keine Statistik von Frigate. Sie kommt per MQTT standardmäßig jede Minute; mit Frigate-Adresse sofort beim Öffnen.'],
            ]];
        }
        $u = FrigateHubServerWerte::uebersicht($stats);
        $items = [['type' => 'Label', 'caption' => $quelle, 'italic' => true]];
        foreach ($u['zeilen'] as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        if ($u['kameras'] !== []) {
            $items[] = [
                'type' => 'List', 'name' => 'KameraUebersicht', 'caption' => 'Kameras', 'rowCount' => min(8, max(2, count($u['kameras']))),
                'add' => false, 'delete' => false,
                'columns' => [
                    ['caption' => 'Kamera', 'name' => 'kamera', 'width' => 'auto'],
                    ['caption' => 'Bilder/s', 'name' => 'bilder', 'width' => '110px'],
                    ['caption' => 'Erkennungen/s', 'name' => 'erkennungen', 'width' => '120px'],
                    ['caption' => 'Verbindung', 'name' => 'verbindung', 'width' => '120px'],
                    ['caption' => 'Neuverbindungen/h', 'name' => 'neu', 'width' => '150px'],
                    ['caption' => 'Aussetzer/h', 'name' => 'aussetzer', 'width' => '110px'],
                ],
                'values' => $u['kameras'],
            ];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📊  Übersicht', 'items' => $items];
    }

    private function empfangsstatus(): string
    {
        if (!$this->HasActiveParent()) {
            return '⚠️ Keine aktive MQTT-Instanz darüber — die Instanz muss unter Symcons MQTT-Server oder einem MQTT-Client hängen, an den Frigate sendet.';
        }
        $ts = $this->ReadAttributeInteger('LetzteStatistik');
        return $ts === 0
            ? 'ℹ️ Noch keine Statistik von Frigate. Sie kommt standardmäßig jede Minute; stimmen MQTT-Server und Präfix?'
            : '✅ Letzte Statistik am ' . date('d.m.Y H:i:s', $ts) . ' Uhr.';
    }

    private function abgleichstatus(): string
    {
        if ($this->apiUrl() === '') {
            return trim((string)$this->ReadPropertyString('ApiUrl')) === ''
                ? 'ℹ️ Keine Frigate-Adresse: Werte nur per MQTT.'
                : '⚠️ Die Frigate-Adresse ist ungültig — erwartet z. B. http://192.168.1.20:5000.';
        }
        $text = $this->ReadAttributeString('Abgleichstatus');
        return $text !== '' ? $text : 'ℹ️ Noch nicht abgefragt — geschieht kurz nach dem Übernehmen.';
    }
}
