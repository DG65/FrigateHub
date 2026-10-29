<?php

declare(strict_types=1);

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
                'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🔎  Kameras in Frigate',
                'items' => [
                    ...$this->apiFormular('Adresse und Benutzer werden an jede angelegte Kamera weitergegeben, die Kameras nutzen die Anmeldung mit.'),
                    ['type' => 'Label', 'name' => 'Suchstatus', 'caption' => $status],
                    [
                        'type'     => 'Configurator',
                        'name'     => 'Kameras',
                        'caption'  => 'Kameras',
                        'rowCount' => 10,
                        'add'      => false,
                        'delete'   => false,
                        'sort'     => ['column' => 'name', 'direction' => 'ascending'],
                        'columns'  => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                            ['caption' => 'Kamera-ID', 'name' => 'kamera', 'width' => '180px'],
                            ['caption' => 'Erkennung', 'name' => 'erkennung', 'width' => '110px'],
                            ['caption' => 'Auflösung', 'name' => 'aufloesung', 'width' => '170px'],
                            ['caption' => 'Zonen', 'name' => 'zonen', 'width' => '80px'],
                            ['caption' => 'Zustand', 'name' => 'zustand', 'width' => '200px'],
                        ],
                        'values'   => $werte,
                    ],
                ],
            ],
        ];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Bereit'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte die Frigate-Adresse eintragen'],
        ];
        return $this->frigatehubFormular($fach, [], $status);
    }

    protected function frigatehubPrefix(): string
    {
        return 'FRIGC';
    }

    protected function frigatehubZweck(): array
    {
        return [
            'Findet alle Kameras deiner Frigate-Instanz und legt je Kamera mit einem Klick eine Frigate-Kamera-Instanz an — mit Kamera-ID, MQTT-Präfix und Frigate-Adresse bereits eingetragen. Dazu bietet er den Frigate-Server an (Version, Leistung, Speicher, gestörte Kameras).',
        ];
    }

    protected function frigatehubDoku(): array
    {
        return [
            'Den Konfigurator unter dem MQTT-Server bzw. MQTT-Client anlegen, an den Frigate sendet. Die angelegten Kameras hängen dann am selben MQTT-Server.',
            'Die Liste kommt bei jedem Öffnen frisch aus Frigates API (/api/config). Der Konfigurator liest nur und ändert in Frigate nichts.',
            'Mit Port 8971 hier einmal anmelden: Die angelegten Kameras übernehmen Benutzer und Anmeldung.',
            'Eine Kamera gilt als vorhanden, wenn eine Frigate-Kamera-Instanz mit derselben Kamera-ID unter demselben MQTT-Server existiert. Instanzen, deren Kamera es in Frigate nicht mehr gibt, stehen mit Hinweis in der Liste.',
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
                ? 'ℹ️ Frigate-Adresse eintragen und übernehmen, dann erscheinen hier die Kameras.'
                : '⚠️ Die Frigate-Adresse ist ungültig — erwartet z. B. http://192.168.1.20:5000.';
            return [$text, $this->verwaisteZeilen($vorhanden, [], 'Frigate nicht abgefragt')];
        }
        $config = $this->apiHolen('/api/config');
        if ($config === null) {
            return ['⛔ ' . $this->apiFehlerText(),
                $this->verwaisteZeilen($vorhanden, [], 'Frigate nicht erreichbar')];
        }
        $stats = $this->apiHolen('/api/stats');
        $kameras = FrigateHubTopics::kamerasAusConfig($config, $stats);
        $praefix = FrigateHubTopics::praefixAusConfig($config);

        $werte = [];
        foreach ($kameras as $k) {
            $werte[] = [
                'name'       => $k['name'],
                'kamera'     => $k['id'],
                'erkennung'  => $k['erkennung'] ? 'an' : 'aus',
                'aufloesung' => $k['aufloesung'],
                'zonen'      => (string)$k['zonen'],
                'zustand'    => !$k['aktiv'] ? 'in Frigate deaktiviert' : ($k['bilder'] === null ? '' : ($k['bilder'] ? 'liefert Bilder' : '⚠️ liefert keine Bilder')),
                'instanceID' => $vorhanden[$k['id']] ?? 0,
                'create'     => [
                    'moduleID'      => self::KAMERA_GUID,
                    'name'          => $k['name'],
                    'configuration' => ['Camera' => $k['id'], 'TopicPrefix' => $praefix, 'ApiUrl' => $this->apiUrl(),
                        'ApiBenutzer' => (string)$this->ReadPropertyString('ApiBenutzer'), 'ZertifikatPruefen' => $this->ReadPropertyBoolean('ZertifikatPruefen')],
                ],
            ];
        }
        $werte = array_merge($werte, $this->verwaisteZeilen($vorhanden, array_column($kameras, 'id'), 'nicht mehr in Frigate'));
        $werte[] = $this->serverZeile($praefix);

        $version = is_string($stats['service']['version'] ?? null) ? ' ' . preg_replace('/-.*$/', '', $stats['service']['version']) : '';
        $text = '✅ ' . count($kameras) . ' ' . (count($kameras) === 1 ? 'Kamera' : 'Kameras') . ' in Frigate' . $version . ' gefunden.';
        if (!$this->HasActiveParent()) {
            $text .= ' ⚠️ Der Konfigurator hängt unter keinem aktiven MQTT-Server — angelegte Kameras empfangen dann nichts.';
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
            'name'       => 'Frigate-Server',
            'kamera'     => '',
            'erkennung'  => '',
            'aufloesung' => '',
            'zonen'      => '',
            'zustand'    => 'Version, Leistung, Speicher, gestörte Kameras',
            'instanceID' => $vorhanden,
            'create'     => [
                'moduleID'      => self::SERVER_GUID,
                'name'          => 'Frigate-Server',
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
