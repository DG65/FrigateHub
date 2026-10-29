<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';
require_once __DIR__ . '/../libs/FrigateHubKachel.php';

/**
 * Eine Frigate-Kamera, angebunden über MQTT (Symcons MQTT-Server oder MQTT-Client darüber).
 *
 * Feste Variablen: Frigate erreichbar, Kamera online, Bewegung, Alarm, Erkennung und drei
 * Ereignistexte. Alles Weitere entsteht beim ersten passenden Topic: Zähler je Objektklasse,
 * Zähler je Zone (Zonen lernt die Instanz aus den Ereignissen), Geräusche und Schalter.
 * Schaltvariablen übernehmen einen Wert erst, wenn Frigate ihn über …/state bestätigt.
 *
 * Optional HTTP-Abgleich über Frigates API (/api/config, /api/stats): Frigate schickt Zustände
 * per MQTT nur beim Verbinden, und Symcons MQTT-Server reicht diese gespeicherten Nachrichten
 * nicht an eine neu angelegte Instanz weiter (live 28.10.2026). Der Abgleich holt sie sofort.
 */
class FrigateHubKamera extends IPSModule
{
    use FrigateHubFormular;
    use FrigateHubApi;
    use FrigateHubKachel;

    /** Datenschnittstelle „senden" (Publish an den Broker), auf MQTT Client und Server gleich. */
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    /** Höchstzahl gemerkter Topics fürs Panel „Empfangene Nachrichten". */
    private const TOPIC_MERKER = 80;

    /** Höhe des Livebilds beim Holen über die API (px); Frigate skaliert, spart Speicher. */
    private const LIVEBILD_HOEHE = 720;

    // Positionen: feste Variablen oben, dann Schalter, Objekte, Zonen, Geräusche.
    private const POS_SCHALTER  = 100;
    private const POS_OBJEKT    = 200;
    private const POS_ZONE      = 300;
    private const POS_GERAEUSCH = 400;

    /**
     * Schonfrist (s): Frigates /api/config hinkt der MQTT-Bestätigung einige Sekunden hinterher
     * (live 28.10.2026: 2,5 s nach „AN" lieferte die API noch „aus"). So lange nach einer
     * MQTT-Bestätigung oder einem eigenen Schaltbefehl überschreibt der Abgleich den Schalter nicht.
     */
    private const SCHONFRIST = 60;

    /** Wartezeit nach dem Übernehmen bis zum Abgleich (ms) — ApplyChanges nicht blockieren. */
    private const ABGLEICH_VERZOEGERUNG = 2000;

    /**
     * Push-Ziele: klassisches WebFront (WFC_PushNotification) und Kachel-Visualisierung
     * (VISU_PostNotificationEx) haben verschiedene Funktionen — beide anbieten (SUITE.md Stolperstein 22).
     */
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const KACHEL_GUID   = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';

    /** Töne laut Symcon-Doku, für WebFront und Kachel-Visualisierung gleich. */
    private const PUSH_TOENE = ['alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full',
        'happy', 'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TopicPrefix', 'frigate');
        $this->RegisterPropertyString('Camera', '');
        $this->apiRegistrieren();
        $this->kachelRegistrieren();
        $this->RegisterPropertyString('PushZiele', '[]');
        $this->RegisterPropertyString('PushTon', 'alarm');
        $this->RegisterPropertyInteger('PushPause', 60);

        $this->RegisterAttributeString('Zonen', '[]');
        $this->RegisterAttributeInteger('LetzteNachricht', 0);
        $this->RegisterAttributeString('LetzteEventId', '');
        $this->RegisterAttributeString('Schalter', '{}');
        $this->RegisterAttributeString('Abgleichstatus', '');
        $this->RegisterAttributeString('Bildstatus', '');
        $this->RegisterAttributeString('LetzterPush', '{}');
        $this->RegisterAttributeString('Pushstatus', '');
        $this->registerFormAttributes();

        $this->RegisterTimer('Abgleich', 0, 'FRIG_ZustandAnfordern($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->apiUebernehmen()) {
            return;
        }

        $this->festeVariablen();
        $this->kachelUebernehmen();

        $kamera = $this->kamera();
        if ($kamera === '') {
            $this->SetReceiveDataFilter(FrigateHubTopics::sperrfilter());
            $this->SetTimerInterval('Abgleich', 0);
            $this->SetStatus(104);
            return;
        }

        $this->filterSetzen();
        $this->SetStatus(IS_ACTIVE);
        // Einmal kurz nach dem Übernehmen (und nach jedem Symcon-Start) abgleichen.
        $this->SetTimerInterval('Abgleich', $this->apiUrl() !== '' ? self::ABGLEICH_VERZOEGERUNG : 0);
    }

    /* ==================================================================== Empfangen */

    public function ReceiveData($JSONString)
    {
        $paket = json_decode((string)$JSONString, true);
        if (!is_array($paket) || !isset($paket['Topic'])) {
            return '';
        }
        $kamera = $this->kamera();
        if ($kamera === '') {
            return '';
        }

        $topic = (string)$paket['Topic'];
        $payload = (string)($paket['Payload'] ?? '');
        $d = FrigateHubTopics::deuten($topic, $this->ReadPropertyString('TopicPrefix'), $kamera, $this->zonen());
        if ($d === null) {
            return '';
        }

        $this->merken($topic, $payload, $d);

        switch ($d['art']) {
            case 'verfuegbar':
                $this->SetValue('Verfuegbar', trim($payload) === 'online');
                break;

            case 'status':
                // Rollen: detect, record, audio. Für „online" zählt die Erkennung.
                if ($d['rolle'] === 'detect') {
                    $this->SetValue('Online', trim($payload) === 'online');
                }
                break;

            case 'bewegung':
                $wert = FrigateHubTopics::anAus($payload);
                if ($wert !== null) {
                    $this->SetValue('Bewegung', $wert);
                }
                break;

            case 'pruefstatus':
                // NONE, DETECTION oder ALERT
                $p = strtoupper(trim($payload));
                $this->SetValue('Alarm', $p === 'ALERT');
                $this->SetValue('Erkennung', $p === 'ALERT' || $p === 'DETECTION');
                break;

            case 'objekt':
                $this->zaehlerSetzen(FrigateHubTopics::ident('Objekt', $d['label']),
                    FrigateHubTopics::objektName($d['label']), self::POS_OBJEKT, $payload);
                if ($d['label'] !== 'all') {
                    // Für die Kachel: Zähler ohne Variablen-Suche
                    $zaehler = (array)json_decode($this->GetBuffer('Zaehler'), true);
                    $zaehler[$d['label']] = (int)FrigateHubTopics::zahl($payload);
                    $this->SetBuffer('Zaehler', (string)json_encode($zaehler));
                }
                break;

            case 'zone':
                $this->zaehlerSetzen(FrigateHubTopics::ident('Zone', $d['zone'], $d['label']),
                    $d['zone'] . ': ' . FrigateHubTopics::objektName($d['label']), self::POS_ZONE, $payload);
                break;

            case 'schalter':
                $wert = FrigateHubTopics::anAus($payload);
                if ($wert === null) {
                    $this->SendDebug('Kein ON/OFF', $d['schluessel'] . ' = ' . $payload, 0);
                    break;
                }
                $this->schalterSetzen($d['schluessel'], $wert);
                $this->frischMerken($d['schluessel']);
                break;

            case 'geraeusch':
                $wert = FrigateHubTopics::anAus($payload);
                if ($wert !== null) {
                    $ident = FrigateHubTopics::ident('Geraeusch', $d['typ']);
                    $this->variableAnlegen($ident, $d['name'], VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('erkannt', 'still'), self::POS_GERAEUSCH);
                    $this->SetValue($ident, $wert);
                }
                break;

            case 'schnappschuss':
                $this->schnappschussVerarbeiten($d['label'], $payload);
                break;

            case 'events':
                $this->eventVerarbeiten($payload);
                break;

            case 'reviews':
                $this->reviewVerarbeiten($payload);
                break;

            default:
                $this->SendDebug('Ignoriert', $topic . ' (' . ($d['grund'] ?? '') . ')', 0);
                break;
        }
        $this->kachelSenden();
        return '';
    }

    /* ==================================================================== Schalten */

    public function RequestAction($Ident, $Value)
    {
        $ident = (string)$Ident;
        $schluessel = $this->schalterSchluessel($ident);
        if ($schluessel === '') {
            throw new Exception('Unbekannte Aktion ' . $ident);
        }
        // Bewusst KEIN SetValue: Die Variable folgt der Bestätigung (…/state) von Frigate.
        // Kommt keine, bleibt der alte Wert stehen — so zeigt Symcon nie einen Zustand, den
        // Frigate nicht hat (SUITE.md „Sichtbare Rückmeldung", WebFront-RequestAction).
        $topic = FrigateHubTopics::setTopic($this->ReadPropertyString('TopicPrefix'), $this->kamera(), $schluessel);
        if ($this->senden($topic, (bool)$Value ? 'ON' : 'OFF')) {
            $this->frischMerken($schluessel);
        }
    }

    /**
     * Holt den aktuellen Zustand der Kamera. Mit Frigate-Adresse über die HTTP-API (Schalter,
     * Zonen, erreichbar/online), sonst per MQTT-Bitte frigate/onConnect — die beantwortet
     * Frigate 0.18 aber nicht mit den Schalterzuständen (live 28.10.2026).
     * Rückgabe: Text für das Formular. Auch vom Timer „Abgleich" aufgerufen.
     */
    public function ZustandAnfordern(): string
    {
        $this->SetTimerInterval('Abgleich', 0);
        if ($this->kamera() === '') {
            return 'ℹ️ Erst eine Kamera eintragen und übernehmen.';
        }
        if ($this->apiUrl() !== '') {
            $text = $this->abgleichen();
            $this->WriteAttributeString('Abgleichstatus', $text);
            $this->UpdateFormField('Abgleichstatus', 'caption', $text);
            $this->UpdateFormField('Empfangsstatus', 'caption', $this->empfangsstatus());
            return $text;
        }
        $ok = $this->senden(FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix')) . '/onConnect', '');
        return $ok
            ? '✅ Anfrage an Frigate gesendet. Frigate schickt darauf nicht alle Zustände neu — vollständig wird es mit eingetragener Frigate-Adresse (HTTP-Abgleich).'
            : '⛔ Senden fehlgeschlagen — hängt die Instanz unter einem aktiven MQTT-Server oder MQTT-Client? Details im Meldungsprotokoll.';
    }

    /**
     * Holt das aktuelle Kamerabild über die API (/api/<kamera>/latest.jpg) ins Medienobjekt
     * „Schnappschuss". Rückgabe: Text für das Formular.
     */
    public function BildHolen(): string
    {
        if ($this->kamera() === '' || $this->apiUrl() === '') {
            return 'ℹ️ Dafür braucht es Kamera-ID und Frigate-Adresse. Schnappschüsse zu Erkennungen kommen auch ohne Adresse per MQTT.';
        }
        $bild = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/latest.jpg?h=' . self::LIVEBILD_HOEHE, 'image/jpeg');
        if ($bild === null || FrigateHubTopics::bildBytes($bild) === null) {
            $text = '⛔ Kein Bild von Frigate (' . ($bild === null ? $this->apiFehler : 'Antwort ist kein JPEG') . ').';
        } else {
            $this->bildSetzen($bild, 'Livebild');
            $text = '✅ ' . $this->ReadAttributeString('Bildstatus');
        }
        $this->UpdateFormField('Bildstatus', 'caption', $text);
        return $text;
    }

    /** Vergisst die gelernten Zonen (z. B. nach Umbenennen in Frigate). Variablen bleiben. */
    /** Probemeldung an alle eingetragenen Ziele. Rückgabe: Text fürs Formular. */
    public function PushTesten(): string
    {
        $text = $this->pushSenden('🔔 ' . IPS_GetName($this->InstanceID), 'Probemeldung von FrigateHub – so sieht ein Alarm aus.');
        $this->UpdateFormField('Pushstatus', 'caption', $this->pushstatus());
        return $text;
    }

    public function ZonenVergessen(): string
    {
        $anzahl = count($this->zonen());
        $this->WriteAttributeString('Zonen', '[]');
        if ($this->kamera() !== '') {
            $this->filterSetzen();
        }
        return '✅ ' . $anzahl . ' Zone(n) vergessen. Sie werden beim nächsten Ereignis in der Zone neu gelernt; vorhandene Variablen bleiben (Archiv).';
    }

    /* ==================================================================== Formular */

    public function GetConfigurationForm()
    {
        $kamera = $this->kamera();
        $fach = [
            [
                'type' => 'ExpansionPanel', 'expanded' => $kamera === '', 'caption' => '📷  Frigate-Kamera',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Die Kamera-ID aus der Frigate-Konfiguration (Abschnitt cameras:), nicht der Anzeigename. Groß-/Kleinschreibung zählt, z. B. „haustuer" oder „Door-Cam".'],
                    ['type' => 'ValidationTextBox', 'name' => 'Camera', 'caption' => 'Kamera-ID'],
                    ['type' => 'ValidationTextBox', 'name' => 'TopicPrefix', 'caption' => 'MQTT-Präfix (Frigate: mqtt.topic_prefix, Standard „frigate")'],
                    ['type' => 'Label', 'name' => 'Empfangsstatus', 'caption' => $this->empfangsstatus()],
                    ...$this->apiFormular('Optional, aber empfohlen: Ohne Adresse kommen Schalter erst, wenn Frigate sie per MQTT meldet, und Schnappschüsse nur als Ausschnitt.'),
                    ['type' => 'Label', 'name' => 'Abgleichstatus', 'caption' => $this->abgleichstatus()],
                    ['type' => 'Button', 'caption' => 'Zustand jetzt abfragen', 'onClick' => 'echo FRIG_ZustandAnfordern($id);'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🖼️  Schnappschuss',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Das Medienobjekt „Schnappschuss" zeigt das Bild der letzten Erkennung (Frigate: snapshots aktiviert). Mit Frigate-Adresse kommt es in voller Größe, sonst als Ausschnitt per MQTT (Größe in Frigate unter mqtt: height/crop). Mit Adresse lässt sich auch das aktuelle Kamerabild holen.'],
                    ['type' => 'Label', 'name' => 'Bildstatus', 'caption' => $this->bildstatus()],
                    ['type' => 'Button', 'caption' => 'Aktuelles Kamerabild holen', 'onClick' => 'echo FRIG_BildHolen($id);'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🔔  Push bei Alarm',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Meldet jeden neuen Alarm dieser Kamera als Push-Nachricht aufs Handy, einmal je Alarm. Was ein Alarm ist, legst du in Frigate fest (review: alerts: labels / required_zones). Ohne Ziel bleibt der Push aus.'],
                    ['type' => 'List', 'name' => 'PushZiele', 'caption' => 'Ziele (WebFront oder Kachel-Visualisierung)', 'add' => true, 'delete' => true, 'rowCount' => 3,
                        'columns' => [[
                            'caption' => 'Visualisierung', 'name' => 'Ziel', 'width' => 'auto', 'add' => 0,
                            'edit' => ['type' => 'SelectInstance', 'validModules' => [self::KACHEL_GUID, self::WEBFRONT_GUID]],
                        ]],
                    ],
                    ['type' => 'Select', 'name' => 'PushTon', 'caption' => 'Ton', 'options' => $this->tonOptionen()],
                    ['type' => 'NumberSpinner', 'name' => 'PushPause', 'caption' => 'Mindestabstand zwischen zwei Meldungen', 'suffix' => 's', 'minimum' => 0, 'maximum' => 3600],
                    ['type' => 'Label', 'name' => 'Pushstatus', 'caption' => $this->pushstatus()],
                    ['type' => 'Button', 'caption' => 'Probemeldung senden', 'onClick' => 'echo FRIG_PushTesten($id);'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🖥️  Kachel',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Diese Instanz ist zugleich eine Kachel für die Kachel-Visualisierung: Livebild, Alarm, Zähler, die letzten Ereignisse mit Vorschaubild, Schnappschuss und Clip per Tipp sowie Schalter für Erkennung, Aufnahme und Schnappschüsse. Livebild, Vorschaubilder und Clips brauchen die Frigate-Adresse.'],
                    ['type' => 'NumberSpinner', 'name' => 'KachelLiveTakt', 'caption' => 'Livebild neu laden alle (0 = nur auf Tipp)', 'suffix' => 's', 'minimum' => 0, 'maximum' => 60],
                    ['type' => 'CheckBox', 'name' => 'KachelRahmen', 'caption' => 'Erkannte Objekte im Livebild einrahmen'],
                    ['type' => 'Label', 'caption' => 'Die Bilder laufen über den WebHook ' . $this->hookPfad() . ' mit einem geheimen Schlüssel dieser Instanz. Wurde eine Bildadresse weitergegeben, macht ein neuer Schlüssel sie ungültig.'],
                    ['type' => 'Button', 'caption' => 'Kachel-Schlüssel erneuern', 'onClick' => 'echo FRIG_KachelSchluesselErneuern($id);'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🗺️  Zonen',
                'items' => [
                    ['type' => 'Label', 'caption' => $this->zonenstatus()],
                    ['type' => 'Button', 'caption' => 'Gelernte Zonen vergessen', 'onClick' => 'echo FRIG_ZonenVergessen($id);'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📨  Empfangene Nachrichten',
                'items' => $this->nachrichtenLabels(),
            ],
        ];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Bereit'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte die Kamera-ID aus Frigate eintragen'],
        ];
        return $this->frigatehubFormular($fach, [], $status);
    }

    protected function frigatehubPrefix(): string
    {
        return 'FRIG';
    }

    protected function frigatehubZweck(): array
    {
        return [
            'Bindet eine Kamera aus Frigate (Videoüberwachung mit KI-Objekterkennung) an Symcon an: Personen, Autos, Tiere und Pakete je Kamera und Zone, Bewegung, Alarm und die letzten Ereignisse als Variablen.',
            'Damit lassen sich Abläufe auslösen, z. B. Licht bei einer Person in der Einfahrt oder eine Push-Nachricht bei einem Paket. Erkennung, Aufnahme und Zonen kannst du aus Symcon heraus schalten.',
        ];
    }

    protected function frigatehubDoku(): array
    {
        return [
            'Voraussetzung: Frigate sendet per MQTT an den MQTT-Server bzw. Broker, unter dem diese Instanz hängt (Frigate-Konfiguration mqtt: host/port/user/password).',
            'Je Kamera eine Instanz. Zähler, Zonen, Geräusche und Schalter entstehen von selbst, sobald Frigate sie zum ersten Mal meldet.',
            'Mit eingetragener Frigate-Adresse fragt die Instanz nach dem Übernehmen und nach jedem Symcon-Start Schalter und Zonen über Frigates API ab. Ohne Adresse schickt Frigate Schalterzustände per MQTT nur, wenn es sich neu verbindet oder etwas umgeschaltet wird.',
            'Das Medienobjekt „Schnappschuss" entsteht mit dem ersten Bild: dem ersten Schnappschuss einer Erkennung oder, mit Frigate-Adresse, dem aktuellen Kamerabild beim ersten Abgleich. Es liegt im Arbeitsspeicher (schont SSD/SD-Karte) und wird beim Beenden von Symcon gesichert.',
            'Push bei Alarm: je neuem Alarm eine Meldung an die eingetragenen Visualisierungen, Titel = Name dieser Instanz, Text = Zeit, Objekte und Zonen. Steigt eine Erkennung später zum Alarm auf, kommt die Meldung dann.',
            'Kachel: Die Instanz lässt sich direkt in die Kachel-Visualisierung legen. Das Livebild lädt nur, solange die Kachel sichtbar ist. Ein Tipp auf ein Ereignis zeigt Schnappschuss und Clip, ein Tipp aufs Livebild vergrößert es.',
            'Zonen kommen aus dem Abgleich oder aus den Ereignissen dieser Kamera. Zähler je Zone entstehen, sobald Frigate dort ein Objekt zählt.',
            'Schalter (Erkennung, Aufnahme, Masken …) übernehmen einen Wert erst, wenn Frigate ihn bestätigt. Bleibt eine Bestätigung aus, steht der alte Wert weiter da.',
            '„Alarm" und „Erkennung" folgen dem Prüfstatus von Frigate (review_status): Alarm = Frigate stuft das Ereignis als Alarm ein, Erkennung = Alarm oder Erkennung.',
        ];
    }

    /* ==================================================================== intern */

    private function kamera(): string
    {
        return trim((string)$this->ReadPropertyString('Camera'));
    }

    /** Schalter, Zonen und Erreichbarkeit über die HTTP-API holen. Rückgabe: Text fürs Formular. */
    private function abgleichen(): string
    {
        $kamera = $this->kamera();
        $config = $this->apiHolen('/api/config');
        if ($config === null) {
            $text = '⛔ ' . date('d.m.Y H:i:s') . ': ' . $this->apiFehlerText();
            $this->LogMessage('Abgleich mit Frigate fehlgeschlagen: ' . $this->apiFehler, KL_WARNING);
            return $text;
        }
        $kameras = (array)($config['cameras'] ?? []);
        if (!is_array($kameras[$kamera] ?? null)) {
            return '⛔ Frigate kennt keine Kamera „' . $kamera . '". Vorhanden: ' . (implode(', ', array_keys($kameras)) ?: 'keine') . '.';
        }
        $this->SetValue('Verfuegbar', true);

        $schalter = FrigateHubTopics::schalterAusConfig($kameras[$kamera]);
        $frisch = $this->frisch();
        $geschont = 0;
        foreach ($schalter as $schluessel => $wert) {
            if (isset($frisch[$schluessel])) {
                $geschont++;
                $this->SendDebug('Abgleich', $schluessel . ': MQTT-Stand ist frischer, API-Wert übergangen', 0);
                continue;
            }
            $this->schalterSetzen($schluessel, $wert);
        }
        $this->zonenLernen(FrigateHubTopics::zonenAusConfig($kameras[$kamera]));

        $stats = $this->apiHolen('/api/stats');
        $fps = $stats['cameras'][$kamera]['camera_fps'] ?? null;
        if (is_numeric($fps)) {
            $this->SetValue('Online', (float)$fps > 0);
        }
        $this->WriteAttributeInteger('LetzteNachricht', time());

        // Ohne Medienobjekt einmal das Livebild holen, damit es gleich verlinkt werden kann.
        // Ein vorhandener Schnappschuss einer Erkennung wird dabei nicht überschrieben.
        if (@IPS_GetObjectIDByIdent('Schnappschuss', $this->InstanceID) === false) {
            $this->BildHolen();
        }
        $this->ereignisseLaden();
        $this->kachelSenden();

        return '✅ Abgleich am ' . date('d.m.Y H:i:s') . ' Uhr: ' . count($schalter) . ' Schalter'
            . ($geschont > 0 ? ' (davon ' . $geschont . ' gerade erst geschaltet, MQTT-Stand behalten)' : '') . ', '
            . count($this->zonen()) . ' Zone(n)' . (is_numeric($fps) ? ', Kamera ' . ((float)$fps > 0 ? 'liefert Bilder' : 'liefert keine Bilder') : '') . '.';
    }

    /** Merkt, dass ein Schalter gerade per MQTT bestätigt oder von hier geschaltet wurde. */
    private function frischMerken(string $schluessel): void
    {
        $map = $this->frisch();
        $map[$schluessel] = time();
        $this->SetBuffer('Frisch', (string)json_encode($map));
    }

    /** @return array<string,int> Schalter innerhalb der Schonfrist */
    private function frisch(): array
    {
        $map = json_decode($this->GetBuffer('Frisch'), true);
        $grenze = time() - self::SCHONFRIST;
        return array_filter(is_array($map) ? $map : [], fn ($t) => (int)$t > $grenze);
    }

    /**
     * Per MQTT schickt Frigate nur einen Ausschnitt (Standard 270 px, zugeschnitten). Mit
     * Frigate-Adresse wird stattdessen derselbe Schnappschuss in voller Größe geholt
     * (/api/<kamera>/<objekt>/snapshot.jpg = bester Schnappschuss des jüngsten Objekts dieser
     * Klasse, live 1280×720). Klappt das nicht, bleibt es beim MQTT-Bild.
     */
    private function schnappschussVerarbeiten(string $label, string $payload): void
    {
        $name = 'Schnappschuss ' . FrigateHubTopics::objektEinzahl($label);
        if ($this->apiUrl() !== '') {
            $voll = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/' . rawurlencode($label) . '/snapshot.jpg', 'image/jpeg');
            if ($voll !== null && FrigateHubTopics::bildBytes($voll) !== null) {
                $this->bildSetzen($voll, $name);
                return;
            }
            $this->SendDebug('Vollbild', 'nicht geholt (' . ($voll === null ? $this->apiFehler : 'kein JPEG') . '), nehme MQTT-Ausschnitt', 0);
        }
        $bild = FrigateHubTopics::bildBytes($payload);
        if ($bild === null) {
            $this->SendDebug('Kein JPEG', $label . ' (' . strlen($payload) . ' Bytes)', 0);
            return;
        }
        $this->bildSetzen($bild, $name . ($this->apiUrl() !== '' ? ' (Ausschnitt, Vollbild nicht erreichbar)' : ' (Ausschnitt)'));
    }

    /**
     * Schreibt ein JPEG ins Medienobjekt „Schnappschuss" (legt es beim ersten Bild an).
     * Gecacht: Symcon hält das Bild im Arbeitsspeicher und schreibt es erst beim Beenden —
     * bei mehreren Schnappschüssen je Erkennung schont das SSD/SD-Karte.
     */
    private function bildSetzen(string $jpeg, string $quelle): void
    {
        $id = @IPS_GetObjectIDByIdent('Schnappschuss', $this->InstanceID);
        if ($id === false) {
            $id = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, 'Schnappschuss');
            IPS_SetName($id, 'Schnappschuss');
            IPS_SetPosition($id, 9);
            IPS_SetMediaFile($id, 'media/FrigateHub_' . $this->InstanceID . '.jpg', false);
            IPS_SetMediaCached($id, true);
        }
        if (!IPS_SetMediaContent($id, base64_encode($jpeg))) {
            $this->LogMessage('Schnappschuss konnte nicht gespeichert werden (Medienobjekt ' . $id . ')', KL_ERROR);
            return;
        }
        $this->WriteAttributeString('Bildstatus', $quelle . ' vom ' . date('d.m.Y H:i:s') . ' Uhr (' . round(strlen($jpeg) / 1024) . ' KB)');
    }

    /** @return string[] */
    private function zonen(): array
    {
        $z = json_decode((string)$this->ReadAttributeString('Zonen'), true);
        return is_array($z) ? array_values(array_map('strval', $z)) : [];
    }

    private function filterSetzen(): void
    {
        $filter = FrigateHubTopics::empfangsfilter($this->kamera(), $this->zonen());
        $this->SetReceiveDataFilter($filter);
        $this->SendDebug('Empfangsfilter', $filter, 0);
    }

    /** Neue Zonen merken und den Filter erweitern, damit ihre Zähler-Topics durchkommen. */
    private function zonenLernen(array $neu): void
    {
        $bekannt = $this->zonen();
        $dazu = array_values(array_diff($neu, $bekannt));
        if ($dazu === []) {
            return;
        }
        $this->WriteAttributeString('Zonen', (string)json_encode(array_values(array_merge($bekannt, $dazu))));
        $this->filterSetzen();
        $this->SendDebug('Zonen gelernt', implode(', ', $dazu), 0);
    }

    private function festeVariablen(): void
    {
        $this->variableAnlegen('Verfuegbar', 'Frigate erreichbar', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('erreichbar', 'nicht erreichbar', true), 1);
        $this->variableAnlegen('Online', 'Kamera online', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('online', 'offline', true), 2);
        $this->variableAnlegen('Bewegung', 'Bewegung', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('Bewegung', 'ruhig'), 3);
        $this->variableAnlegen('Alarm', 'Alarm', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('Alarm', 'kein Alarm'), 4);
        $this->variableAnlegen('Erkennung', 'Erkennung', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('erkannt', 'nichts erkannt'), 5);
        $this->variableAnlegen('LetzterAlarm', 'Letzter Alarm', VARIABLETYPE_STRING, self::anzeigeText(), 6);
        $this->variableAnlegen('LetzteErkennung', 'Letzte Erkennung', VARIABLETYPE_STRING, self::anzeigeText(), 7);
        $this->variableAnlegen('LetztesObjekt', 'Letztes Objekt', VARIABLETYPE_STRING, self::anzeigeText(), 8);
    }

    /**
     * Legt eine Variable an, wenn es sie noch nicht gibt. Bestehende werden nicht angefasst:
     * Position und Name gehören nach dem Anlegen dem Nutzer (SUITE.md 9m), und ein
     * MaintainVariable je Nachricht wäre ein Update-Sturm.
     */
    private function variableAnlegen(string $ident, string $name, int $typ, array $darstellung, int $position, bool $aktion = false): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->MaintainVariable($ident, $name, $typ, $darstellung, $position, true);
        }
        if ($aktion) {
            $this->EnableAction($ident);
        }
    }

    private function zaehlerSetzen(string $ident, string $name, int $position, string $payload): void
    {
        $zahl = FrigateHubTopics::zahl($payload);
        if ($zahl === null) {
            $this->SendDebug('Kein Zähler', $ident . ' = ' . $payload, 0);
            return;
        }
        $this->variableAnlegen($ident, $name, VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], $position);
        $this->SetValue($ident, $zahl);
    }

    private function schalterSetzen(string $schluessel, bool $wert): void
    {
        $ident = FrigateHubTopics::schalterIdent($schluessel);
        $this->variableAnlegen($ident, FrigateHubTopics::schalterName($schluessel), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], self::POS_SCHALTER, true);
        $this->SetValue($ident, $wert);
        $this->schalterMerken($ident, $schluessel);
    }

    /**
     * Ident → Frigate-Schlüssel, damit RequestAction das Set-Topic bauen kann. Im Attribut,
     * nicht im Puffer: nach einem Neustart soll ein Schalter sofort bedienbar sein, auch
     * bevor Frigate wieder ein …/state geschickt hat.
     */
    private function schalterMerken(string $ident, string $schluessel): void
    {
        $map = json_decode($this->ReadAttributeString('Schalter'), true);
        $map = is_array($map) ? $map : [];
        if (($map[$ident] ?? null) !== $schluessel) {
            $map[$ident] = $schluessel;
            $this->WriteAttributeString('Schalter', (string)json_encode($map));
        }
    }

    private function schalterSchluessel(string $ident): string
    {
        $map = json_decode($this->ReadAttributeString('Schalter'), true);
        if (is_array($map) && isset($map[$ident])) {
            return (string)$map[$ident];
        }
        // Attribut leer (z. B. nach Neuinstallation des Moduls): einfache Schlüssel lassen sich
        // aus dem Ident zurückrechnen. Masken und Zonen warten auf ihr …/state oder den Abgleich.
        $rest = strpos($ident, 'Schalter_') === 0 ? substr($ident, strlen('Schalter_')) : '';
        return FrigateHubTopics::istEinfacherSchalter($rest) ? $rest : '';
    }

    private function eventVerarbeiten(string $payload): void
    {
        $e = json_decode($payload, true);
        $after = is_array($e) ? ($e['after'] ?? null) : null;
        if (!is_array($after) || (string)($after['camera'] ?? '') !== $this->kamera()) {
            return;
        }
        $this->zonenLernen(FrigateHubTopics::zonenAusEvent($after));
        $typ = (string)($e['type'] ?? '');
        if ($typ === 'new' || $typ === 'end') {
            $this->SetValue('LetztesObjekt', FrigateHubTopics::eventText($after));
            $this->WriteAttributeString('LetzteEventId', (string)($after['id'] ?? ''));
        }
    }

    private function reviewVerarbeiten(string $payload): void
    {
        $r = json_decode($payload, true);
        $after = is_array($r) ? ($r['after'] ?? null) : null;
        if (!is_array($after) || (string)($after['camera'] ?? '') !== $this->kamera()) {
            return;
        }
        $this->zonenLernen(FrigateHubTopics::zonenAusReview($after));
        $this->ereignisMerken($after);
        $typ = (string)($r['type'] ?? '');
        if ($typ !== 'new' && $typ !== 'update') {
            return;
        }
        $text = FrigateHubTopics::reviewText($after);
        if ((string)($after['severity'] ?? '') === 'alert') {
            $this->SetValue('LetzterAlarm', $text);
            $this->alarmMelden((string)($after['id'] ?? ''), $text);
        } else {
            $this->SetValue('LetzteErkennung', $text);
        }
    }

    /** Einmal je Review-ID, frühestens PushPause Sekunden nach der letzten Meldung. */
    private function alarmMelden(string $id, string $text): void
    {
        if ($this->pushZiele() === []) {
            return;
        }
        $letzter = json_decode($this->ReadAttributeString('LetzterPush'), true);
        $letzter = is_array($letzter) ? $letzter : [];
        if ($id !== '' && $id === (string)($letzter['id'] ?? '')) {
            return;
        }
        if (time() - (int)($letzter['zeit'] ?? 0) < max(0, $this->ReadPropertyInteger('PushPause'))) {
            $this->SendDebug('Push', 'übersprungen (Mindestabstand): ' . $text, 0);
            return;
        }
        $this->WriteAttributeString('LetzterPush', (string)json_encode(['id' => $id, 'zeit' => time()]));
        $this->pushSenden('🚨 ' . IPS_GetName($this->InstanceID), $text);
    }

    /** @return int[] gültige Ziel-Instanzen aus der Liste */
    private function pushZiele(): array
    {
        $ziele = [];
        foreach ((array)json_decode($this->ReadPropertyString('PushZiele'), true) as $zeile) {
            $id = (int)($zeile['Ziel'] ?? 0);
            if ($id > 0 && !in_array($id, $ziele, true)) {
                $ziele[] = $id;
            }
        }
        return $ziele;
    }

    /** An alle Ziele senden; Ergebnis landet im Attribut Pushstatus. Rückgabe: derselbe Text. */
    private function pushSenden(string $titel, string $text): string
    {
        $ziele = $this->pushZiele();
        if ($ziele === []) {
            $status = '⚠️ Kein Ziel eingetragen — bitte unter „Ziele" eine Visualisierung hinzufügen und übernehmen.';
            $this->WriteAttributeString('Pushstatus', $status);
            return $status;
        }
        // Grenzen laut Doku 32/256 Zeichen; ob Symcon Zeichen oder Bytes zählt, ist offen → nach Bytes kürzen.
        $titel = FrigateHubTopics::kuerzenBytes($titel, 32);
        $text = FrigateHubTopics::kuerzenBytes($text, 256);
        $ton = (string)$this->ReadPropertyString('PushTon');
        $ok = [];
        $fehler = [];
        foreach ($ziele as $ziel) {
            $name = IPS_InstanceExists($ziel) ? IPS_GetName($ziel) : ('#' . $ziel);
            $modul = IPS_InstanceExists($ziel) ? (string)(IPS_GetInstance($ziel)['ModuleInfo']['ModuleID'] ?? '') : '';
            if ($modul === self::KACHEL_GUID && function_exists('VISU_PostNotificationEx')) {
                // Ex-Fassung: die einfache VISU_PostNotification lieferte live immer false (Stolperstein 22).
                $gesendet = @VISU_PostNotificationEx($ziel, $titel, $text, 'Alert', $ton, 0);
            } elseif ($modul === self::WEBFRONT_GUID && function_exists('WFC_PushNotification')) {
                $gesendet = @WFC_PushNotification($ziel, $titel, $text, $ton, $this->InstanceID);
            } else {
                $fehler[] = $name . ' (keine Visualisierung)';
                continue;
            }
            if ($gesendet !== false) {
                $ok[] = $name;
            } else {
                $fehler[] = $name;
            }
        }
        $zeit = date('d.m.Y H:i:s');
        if ($fehler === []) {
            $status = '✅ ' . $zeit . ' an ' . implode(', ', $ok) . ' gesendet.';
        } else {
            $status = ($ok === [] ? '⛔ ' : '⚠️ ') . $zeit . ' nicht zugestellt an ' . implode(', ', $fehler)
                . ($ok === [] ? '.' : ', gesendet an ' . implode(', ', $ok) . '.');
            $this->LogMessage('Push nicht zugestellt an ' . implode(', ', $fehler), KL_WARNING);
        }
        $this->WriteAttributeString('Pushstatus', $status);
        return $status;
    }

    private function pushstatus(): string
    {
        $status = $this->ReadAttributeString('Pushstatus');
        if ($status !== '') {
            return $status;
        }
        return $this->pushZiele() === [] ? 'Push ist aus (kein Ziel eingetragen).' : 'Noch keine Meldung gesendet.';
    }

    private function tonOptionen(): array
    {
        $optionen = [];
        foreach (self::PUSH_TOENE as $ton) {
            $optionen[] = ['caption' => $ton, 'value' => $ton];
        }
        return $optionen;
    }

    private function senden(string $topic, string $payload): bool
    {
        if (!$this->HasActiveParent()) {
            $this->sendefehler($topic, 'keine aktive MQTT-Instanz darüber');
            return false;
        }
        // QoS 0 und nie retain: ein gespeicherter Set-Befehl würde Frigate bei jedem
        // Neuverbinden wieder in den alten Zustand zwingen (CometWiFi-Erfahrung).
        $json = (string)json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => $payload,
        ], JSON_UNESCAPED_SLASHES);
        $this->SendDebug('Senden', $json, 0);
        if ($this->SendDataToParent($json) === false) {
            $this->sendefehler($topic, 'die MQTT-Instanz hat das Paket abgelehnt');
            return false;
        }
        return true;
    }

    private function sendefehler(string $topic, string $grund): void
    {
        $this->LogMessage('Senden an Frigate fehlgeschlagen (' . $topic . '): ' . $grund, KL_ERROR);
    }

    /** Merkt Zeitpunkt und die letzten Topics fürs Formular. */
    private function merken(string $topic, string $payload, array $d): void
    {
        $this->WriteAttributeInteger('LetzteNachricht', time());
        $liste = json_decode($this->GetBuffer('Topics'), true);
        $liste = is_array($liste) ? $liste : [];
        unset($liste[$topic]);
        $liste[$topic] = [time(), FrigateHubTopics::kurzInhalt($d['art'], $payload)];
        if (count($liste) > self::TOPIC_MERKER) {
            $liste = array_slice($liste, -self::TOPIC_MERKER, null, true);
        }
        $this->SetBuffer('Topics', (string)json_encode($liste));
    }

    private function empfangsstatus(): string
    {
        if ($this->kamera() === '') {
            return 'ℹ️ Noch keine Kamera eingetragen.';
        }
        if (!$this->HasActiveParent()) {
            return '⚠️ Keine aktive MQTT-Instanz darüber — die Instanz muss unter Symcons MQTT-Server oder einem MQTT-Client hängen, an den Frigate sendet.';
        }
        $ts = $this->ReadAttributeInteger('LetzteNachricht');
        if ($ts === 0) {
            return 'ℹ️ Noch keine Nachricht von Frigate zu „' . $this->kamera() . '". Stimmen Kamera-ID und Präfix, und sendet Frigate an diesen MQTT-Server? Ohne Frigate-Adresse kommen Zustände erst bei Änderungen.';
        }
        $anzahl = count((array)json_decode($this->GetBuffer('Topics'), true));
        return '✅ Zuletzt Nachricht von Frigate am ' . date('d.m.Y H:i:s', $ts) . ' Uhr (' . $anzahl . ' verschiedene Topics seit dem letzten Neustart).';
    }

    private function abgleichstatus(): string
    {
        if ($this->apiUrl() === '') {
            return trim((string)$this->ReadPropertyString('ApiUrl')) === ''
                ? 'ℹ️ Kein HTTP-Abgleich (keine Frigate-Adresse eingetragen).'
                : '⚠️ Die Frigate-Adresse ist ungültig — erwartet z. B. http://192.168.1.20:5000.';
        }
        $text = $this->ReadAttributeString('Abgleichstatus');
        return $text !== '' ? $text : 'ℹ️ Noch nicht abgeglichen — geschieht kurz nach dem Übernehmen.';
    }

    private function bildstatus(): string
    {
        $text = $this->ReadAttributeString('Bildstatus');
        return $text !== '' ? '✅ Zuletzt: ' . $text . '.' : 'ℹ️ Noch kein Bild empfangen.';
    }

    private function zonenstatus(): string
    {
        $z = $this->zonen();
        if ($z === []) {
            return $this->apiUrl() !== '' && $this->ReadAttributeString('Abgleichstatus') !== ''
                ? 'ℹ️ Laut Abgleich hat diese Kamera in Frigate keine Zonen.'
                : 'ℹ️ Noch keine Zone gelernt. Zonen erscheinen mit dem Abgleich oder sobald Frigate ein Objekt in einer Zone dieser Kamera meldet.';
        }
        return '✅ ' . count($z) . ' Zone(n) gelernt: ' . implode(', ', $z);
    }

    private function nachrichtenLabels(): array
    {
        $liste = json_decode($this->GetBuffer('Topics'), true);
        if (!is_array($liste) || $liste === []) {
            return [['type' => 'Label', 'caption' => 'ℹ️ Seit dem letzten Neustart noch nichts empfangen.']];
        }
        ksort($liste);
        $out = [['type' => 'Label', 'caption' => count($liste) . ' Topics seit dem letzten Neustart (Uhrzeit, Topic, Inhalt):']];
        foreach ($liste as $topic => [$ts, $kurz]) {
            $out[] = ['type' => 'Label', 'caption' => date('H:i:s', (int)$ts) . '  ' . $topic . '  =  ' . $kurz];
        }
        return $out;
    }

    /* ==================================================================== Darstellungen */

    /** Wertanzeige für bool mit Klartext; OPTIONS-Schlüssel laut SUITE.md Stolperstein 9. */
    private static function anzeigeJaNein(string $ja, string $nein, bool $nichtGut = false): array
    {
        $rot = 0xE53935;
        $gruen = 0x43A047;
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => (string)json_encode([
                ['Value' => false, 'Caption' => $nein, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => $nichtGut, 'ColorValue' => $nichtGut ? $rot : -1],
                ['Value' => true, 'Caption' => $ja, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => $nichtGut ? $gruen : 0xFB8C00],
            ]),
        ];
    }

    private static function anzeigeText(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }
}
