<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';

/**
 * Eine Frigate-Kamera, angebunden über MQTT (Symcons MQTT-Server oder MQTT-Client darüber).
 *
 * Feste Variablen: Frigate erreichbar, Kamera online, Bewegung, Alarm, Erkennung und drei
 * Ereignistexte. Alles Weitere entsteht beim ersten passenden Topic: Zähler je Objektklasse,
 * Zähler je Zone (Zonen lernt die Instanz aus den Ereignissen), Geräusche und Schalter.
 * Schaltvariablen übernehmen einen Wert erst, wenn Frigate ihn über …/state bestätigt.
 */
class FrigateHubKamera extends IPSModule
{
    use FrigateHubFormular;

    /** Datenschnittstelle „senden" (Publish an den Broker), auf MQTT Client und Server gleich. */
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    /** Höchstzahl gemerkter Topics fürs Panel „Empfangene Nachrichten". */
    private const TOPIC_MERKER = 80;

    // Positionen: feste Variablen oben, dann Schalter, Objekte, Zonen, Geräusche.
    private const POS_SCHALTER  = 100;
    private const POS_OBJEKT    = 200;
    private const POS_ZONE      = 300;
    private const POS_GERAEUSCH = 400;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TopicPrefix', 'frigate');
        $this->RegisterPropertyString('Camera', '');

        $this->RegisterAttributeString('Zonen', '[]');
        $this->RegisterAttributeInteger('LetzteNachricht', 0);
        $this->RegisterAttributeString('LetzteEventId', '');
        $this->RegisterAttributeString('Schalter', '{}');
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->festeVariablen();

        $kamera = $this->kamera();
        if ($kamera === '') {
            $this->SetReceiveDataFilter(FrigateHubTopics::sperrfilter());
            $this->SetStatus(104);
            return;
        }

        $this->filterSetzen();
        $this->SetStatus(IS_ACTIVE);
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
                break;

            case 'zone':
                $this->zaehlerSetzen(FrigateHubTopics::ident('Zone', $d['zone'], $d['label']),
                    $d['zone'] . ': ' . FrigateHubTopics::objektName($d['label']), self::POS_ZONE, $payload);
                break;

            case 'schalter':
                $this->schalterSetzen($d['schluessel'], $d['name'], $payload);
                break;

            case 'geraeusch':
                $wert = FrigateHubTopics::anAus($payload);
                if ($wert !== null) {
                    $ident = FrigateHubTopics::ident('Geraeusch', $d['typ']);
                    $this->variableAnlegen($ident, $d['name'], VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('erkannt', 'still'), self::POS_GERAEUSCH);
                    $this->SetValue($ident, $wert);
                }
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
        $this->senden($topic, (bool)$Value ? 'ON' : 'OFF');
    }

    /**
     * Bittet Frigate, die Kamerazustände neu zu schicken (Topic frigate/onConnect).
     * Rückgabe: Text für das Formular.
     */
    public function ZustandAnfordern(): string
    {
        if ($this->kamera() === '') {
            return 'ℹ️ Erst eine Kamera eintragen und übernehmen.';
        }
        $ok = $this->senden(FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix')) . '/onConnect', '');
        return $ok
            ? '✅ Anfrage an Frigate gesendet. Gesendet heißt noch nicht beantwortet — Zustände erscheinen, sobald Frigate antwortet (Panel „Empfangene Nachrichten").'
            : '⛔ Senden fehlgeschlagen — hängt die Instanz unter einem aktiven MQTT-Server oder MQTT-Client? Details im Meldungsprotokoll.';
    }

    /** Vergisst die gelernten Zonen (z. B. nach Umbenennen in Frigate). Variablen bleiben. */
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
                    ['type' => 'Button', 'caption' => 'Zustand bei Frigate anfordern', 'onClick' => 'echo FRIG_ZustandAnfordern($id);'],
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
            'Je Kamera eine Instanz. Zähler, Zonen, Geräusche und Schalter entstehen von selbst, sobald Frigate sie zum ersten Mal meldet — eine frisch angelegte Instanz ist deshalb anfangs leer.',
            'Zonen lernt die Instanz aus den Ereignissen dieser Kamera. Bis ein Objekt eine Zone betreten hat, gibt es für sie noch keinen Zähler.',
            'Schalter (Erkennung, Aufnahme, Masken …) übernehmen einen Wert erst, wenn Frigate ihn bestätigt. Bleibt eine Bestätigung aus, steht der alte Wert weiter da.',
            '„Alarm" und „Erkennung" folgen dem Prüfstatus von Frigate (review_status): Alarm = Frigate stuft das Ereignis als Alarm ein, Erkennung = Alarm oder Erkennung.',
        ];
    }

    /* ==================================================================== intern */

    private function kamera(): string
    {
        return trim((string)$this->ReadPropertyString('Camera'));
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

    private function schalterSetzen(string $schluessel, string $name, string $payload): void
    {
        $wert = FrigateHubTopics::anAus($payload);
        if ($wert === null) {
            $this->SendDebug('Kein ON/OFF', $schluessel . ' = ' . $payload, 0);
            return;
        }
        $ident = FrigateHubTopics::ident('Schalter', $schluessel);
        $this->variableAnlegen($ident, $name, VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], self::POS_SCHALTER, true);
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
        // aus dem Ident zurückrechnen. Masken und Zonen tragen einen Hash und warten auf ihr …/state.
        $rest = strpos($ident, 'Schalter_') === 0 ? substr($ident, strlen('Schalter_')) : '';
        return preg_match('/^[a-z_]+$/', $rest) ? $rest : '';
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
        $typ = (string)($r['type'] ?? '');
        if ($typ !== 'new' && $typ !== 'update') {
            return;
        }
        $text = FrigateHubTopics::reviewText($after);
        if ((string)($after['severity'] ?? '') === 'alert') {
            $this->SetValue('LetzterAlarm', $text);
        } else {
            $this->SetValue('LetzteErkennung', $text);
        }
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
        $kurz = in_array($d['art'], ['events', 'reviews'], true) ? '(JSON, ' . strlen($payload) . ' Zeichen)' : mb_substr($payload, 0, 40);
        $liste[$topic] = [time(), $kurz];
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
            return 'ℹ️ Noch keine Nachricht von Frigate zu „' . $this->kamera() . '". Stimmen Kamera-ID und Präfix, und sendet Frigate an diesen MQTT-Server? Frigate schickt Zustände sonst erst bei Änderungen — „Zustand bei Frigate anfordern" hilft.';
        }
        $anzahl = count((array)json_decode($this->GetBuffer('Topics'), true));
        return '✅ Zuletzt Nachricht von Frigate am ' . date('d.m.Y H:i:s', $ts) . ' Uhr (' . $anzahl . ' verschiedene Topics seit dem letzten Neustart).';
    }

    private function zonenstatus(): string
    {
        $z = $this->zonen();
        if ($z === []) {
            return 'ℹ️ Noch keine Zone gelernt. Zonen erscheinen, sobald Frigate ein Objekt in einer Zone dieser Kamera meldet.';
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
