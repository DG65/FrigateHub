<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubText.php';
require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';

/**
 * Findet die Kameras einer Frigate-Instanz über deren HTTP-API (/api/config) und legt je
 * Kamera eine Frigate-Kamera-Instanz an.
 *
 * Hängt selbst unter dem MQTT-Server (oder MQTT-Client), an den Frigate sendet: Symcon schließt
 * die hier angelegten Instanzen an denselben Elternteil an (Muster CometWiFi). Empfangen muss der
 * Konfigurator nichts — der Filter sperrt alles.
 */
class FrigateHubKonfigurator extends IPSModule
{
    use FrigateHubFormular;
    use FrigateHubApi;


    private const KAMERA_GUID = '{6BB54171-2F24-4653-9061-F2BC352A9D3B}';
    private const SERVER_GUID = '{114A19E4-4933-4DA4-97DE-AFD2D4AE8D53}';

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Reine Klassen (libs) übersetzen über diesen Übersetzer, siehe FrigateHubText
        FrigateHubText::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();

        $this->apiRegistrieren();
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->apiUebernehmen()) {
            return;
        }

        $this->SetReceiveDataFilter(FrigateHubTopics::sperrfilter());
        $this->SetStatus($this->apiUrl() === '' ? 104 : IS_ACTIVE);
    }

    public function ReceiveData($JSONString)
    {
        return '';
    }

    /* ==================================================================== Formular */

    public function GetConfigurationForm()
    {
        [$status, $werte] = $this->suchen();
        $fach = [
            [
                'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => FrigateHubText::t('🔎  Cameras in Frigate'),
                'items' => [
                    ...$this->apiFormular(FrigateHubText::t('Address and user are passed on to every camera created; the cameras share the login.')),
                    ['type' => 'Label', 'name' => 'Suchstatus', 'caption' => $status],
                    [
                        'type'     => 'Configurator',
                        'name'     => 'Kameras',
                        'caption'  => FrigateHubText::t('Cameras'),
                        'rowCount' => 10,
                        'add'      => false,
                        'delete'   => false,
                        'sort'     => ['column' => 'name', 'direction' => 'ascending'],
                        'columns'  => [
                            ['caption' => FrigateHubText::t('Name'), 'name' => 'name', 'width' => 'auto'],
                            ['caption' => FrigateHubText::t('Camera ID'), 'name' => 'kamera', 'width' => '180px'],
                            ['caption' => FrigateHubText::t('Detection'), 'name' => 'erkennung', 'width' => '110px'],
                            ['caption' => FrigateHubText::t('Resolution'), 'name' => 'aufloesung', 'width' => '170px'],
                            ['caption' => FrigateHubText::t('Zones'), 'name' => 'zonen', 'width' => '80px'],
                            ['caption' => FrigateHubText::t('State'), 'name' => 'zustand', 'width' => '200px'],
                        ],
                        'values'   => $werte,
                    ],
                ],
            ],
        ];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => FrigateHubText::t('Ready')],
            ['code' => 104, 'icon' => 'inactive', 'caption' => FrigateHubText::t('Please enter the Frigate address')],
        ];
        // Zwei Fachpanels, kein Formular-Aufbau (SUITE.md 3a erst ab drei)
        $fach[] = $this->apiAnmeldePanel();
        return $this->frigatehubFormular($fach, [], $status);
    }

    protected function frigatehubPrefix(): string
    {
        return 'FRIGC';
    }

    protected function frigatehubZweck(): array
    {
        return [
            FrigateHubText::t('Finds all cameras of your Frigate installation and creates a Frigate camera instance per camera with one click — with camera ID, MQTT prefix and Frigate address already filled in. It also offers the Frigate server (version, performance, storage, faulty cameras).'),
        ];
    }

    protected function frigatehubDoku(): array
    {
        return [
            FrigateHubText::t('Create the configurator under the MQTT server or MQTT client that Frigate sends to. The cameras created are then attached to the same MQTT server.'),
            FrigateHubText::t('The list is fetched fresh from Frigate\'s API (/api/config) every time it is opened. The configurator only reads and changes nothing in Frigate.'),
            FrigateHubText::t('With port 8971, log in here once: the cameras created take over user and login.'),
            FrigateHubText::t('A camera counts as existing if a Frigate camera instance with the same camera ID exists under the same MQTT server. Instances whose camera no longer exists in Frigate are listed with a note.'),
        ];
    }

    /* ==================================================================== intern */

    /**
     * Fragt Frigate ab und baut die Zeilen der Liste.
     *
     * @return array{0:string,1:array} Statuszeile und Zeilen
     */
    private function suchen(): array
    {
        $vorhanden = $this->vorhandeneKameras();
        if ($this->apiUrl() === '') {
            $text = trim((string)$this->ReadPropertyString('ApiUrl')) === ''
                ? FrigateHubText::t('ℹ️ Enter the Frigate address and apply; the cameras then appear here.')
                : FrigateHubText::t('⚠️ The Frigate address is invalid — expected e.g. http://192.168.1.20:5000.');
            return [$text, $this->verwaisteZeilen($vorhanden, [], FrigateHubText::t('Frigate not queried'))];
        }
        $config = $this->apiHolen('/api/config');
        if ($config === null) {
            return ['⛔ ' . $this->apiFehlerText(),
                $this->verwaisteZeilen($vorhanden, [], FrigateHubText::t('Frigate not reachable'))];
        }
        $stats = $this->apiHolen('/api/stats');
        $kameras = FrigateHubTopics::kamerasAusConfig($config, $stats);
        $praefix = FrigateHubTopics::praefixAusConfig($config);

        $werte = [];
        foreach ($kameras as $k) {
            $werte[] = [
                'name'       => $k['name'],
                'kamera'     => $k['id'],
                'erkennung'  => $k['erkennung'] ? FrigateHubText::t('on') : FrigateHubText::t('off'),
                'aufloesung' => $k['aufloesung'],
                'zonen'      => (string)$k['zonen'],
                'zustand'    => !$k['aktiv'] ? FrigateHubText::t('disabled in Frigate') : ($k['bilder'] === null ? '' : ($k['bilder'] ? FrigateHubText::t('delivers images') : FrigateHubText::t('⚠️ delivers no images'))),
                'instanceID' => $vorhanden[$k['id']] ?? 0,
                'create'     => [
                    'moduleID'      => self::KAMERA_GUID,
                    'name'          => $k['name'],
                    'configuration' => ['Camera' => $k['id'], 'TopicPrefix' => $praefix, 'ApiUrl' => $this->apiUrl(),
                        'ApiBenutzer' => (string)$this->ReadPropertyString('ApiBenutzer'), 'ZertifikatPruefen' => $this->ReadPropertyBoolean('ZertifikatPruefen')],
                ],
            ];
        }
        $werte = array_merge($werte, $this->verwaisteZeilen($vorhanden, array_column($kameras, 'id'), FrigateHubText::t('no longer in Frigate')));
        $werte[] = $this->serverZeile($praefix);

        $version = is_string($stats['service']['version'] ?? null) ? ' ' . preg_replace('/-.*$/', '', $stats['service']['version']) : '';
        $text = count($kameras) === 1 ? FrigateHubText::t('✅ %d camera found in Frigate%s.', count($kameras), $version) : FrigateHubText::t('✅ %d cameras found in Frigate%s.', count($kameras), $version);
        if (!$this->HasActiveParent()) {
            $text .= ' ' . FrigateHubText::t('⚠️ The configurator is not attached to an active MQTT server — cameras created will then receive nothing.');
        }
        return [$text, $werte];
    }

    /** Zeile für die Frigate-Server-Instanz (eine je MQTT-Elternteil). */
    private function serverZeile(string $praefix): array
    {
        $eltern = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $vorhanden = 0;
        foreach (IPS_GetInstanceListByModuleID(self::SERVER_GUID) as $id) {
            if (IPS_GetInstance($id)['ConnectionID'] === $eltern) {
                $vorhanden = $id;
                break;
            }
        }
        return [
            'name'       => FrigateHubText::t('Frigate server'),
            'kamera'     => '',
            'erkennung'  => '',
            'aufloesung' => '',
            'zonen'      => '',
            'zustand'    => FrigateHubText::t('Version, performance, storage, faulty cameras'),
            'instanceID' => $vorhanden,
            'create'     => [
                'moduleID'      => self::SERVER_GUID,
                'name'          => FrigateHubText::t('Frigate server'),
                'configuration' => ['TopicPrefix' => $praefix, 'ApiUrl' => $this->apiUrl(),
                    'ApiBenutzer' => (string)$this->ReadPropertyString('ApiBenutzer'), 'ZertifikatPruefen' => $this->ReadPropertyBoolean('ZertifikatPruefen')],
            ],
        ];
    }

    /**
     * Kamera-ID → Instanz für alle Frigate-Kameras unter demselben MQTT-Elternteil.
     * Nur über die Property, nie über den Namen (CometWiFi-Lehre); bei zwei Brokern zählt
     * nur der eigene.
     *
     * @return array<string,int>
     */
    private function vorhandeneKameras(): array
    {
        $eltern = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $out = [];
        foreach (IPS_GetInstanceListByModuleID(self::KAMERA_GUID) as $id) {
            if (IPS_GetInstance($id)['ConnectionID'] !== $eltern) {
                continue;
            }
            $kamera = trim((string)IPS_GetProperty($id, 'Camera'));
            if ($kamera !== '' && !isset($out[$kamera])) {
                $out[$kamera] = $id;
            }
        }
        return $out;
    }

    /** Zeilen für vorhandene Instanzen, deren Kamera Frigate (gerade) nicht meldet. */
    private function verwaisteZeilen(array $vorhanden, array $gefunden, string $hinweis): array
    {
        $out = [];
        foreach ($vorhanden as $kamera => $id) {
            if (in_array($kamera, $gefunden, true)) {
                continue;
            }
            $out[] = ['name' => IPS_GetName($id), 'kamera' => (string)$kamera, 'erkennung' => '', 'aufloesung' => '',
                'zonen' => '', 'zustand' => $hinweis, 'instanceID' => $id];
        }
        return $out;
    }
}
