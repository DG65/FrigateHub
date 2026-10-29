<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';
require_once __DIR__ . '/../libs/FrigateHubServerWerte.php';

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

    /** Datenschnittstelle „senden" (Publish an den Broker), auf MQTT Client und Server gleich. */
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    /** Wartezeit nach dem Übernehmen bis zum Abgleich (ms) — ApplyChanges nicht blockieren. */
    private const ABGLEICH_VERZOEGERUNG = 2000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TopicPrefix', 'frigate');
        $this->apiRegistrieren();
        $this->RegisterAttributeInteger('LetzteStatistik', 0);
        $this->RegisterAttributeString('Abgleichstatus', '');
        $this->registerFormAttributes();

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
        $this->SetStatus(IS_ACTIVE);
        $this->SetTimerInterval('Abgleich', $this->apiUrl() !== '' ? self::ABGLEICH_VERZOEGERUNG : 0);
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
            [
                'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🖥️  Frigate-Server',
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'TopicPrefix', 'caption' => 'MQTT-Präfix (Frigate: mqtt.topic_prefix, Standard „frigate")'],
                    ['type' => 'Label', 'name' => 'Empfangsstatus', 'caption' => $this->empfangsstatus()],
                    ...$this->apiFormular('Optional: Mit Adresse stehen die Werte gleich nach dem Übernehmen bereit statt erst mit der nächsten Statistik per MQTT.'),
                    ['type' => 'Label', 'name' => 'Abgleichstatus', 'caption' => $this->abgleichstatus()],
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
            'Variablen für Detektoren und GPUs entstehen, sobald Frigate sie meldet. „Neueste Version" kommt von Frigate selbst (es fragt GitHub ab); steht dort „unbekannt", ist diese Abfrage in Frigate abgeschaltet oder ohne Internet.',
            '„Gestörte Kameras" zählt Kameras, die keine Bilder liefern oder deren Verbindung Frigate als schlecht oder unbrauchbar einstuft; „Kamerazustand" sagt, welche und warum.',
            '„Frigate neu starten" schickt frigate/restart. Frigate beendet sich dann, Docker startet es neu (restart: unless-stopped). Ohne diese Docker-Einstellung bleibt Frigate aus.',
        ];
    }

    /* ==================================================================== intern */

    private function anwenden(array $stats): void
    {
        $this->WriteAttributeInteger('LetzteStatistik', time());
        foreach (FrigateHubServerWerte::werte($stats) as $w) {
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
