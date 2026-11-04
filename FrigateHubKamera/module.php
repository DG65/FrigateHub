<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubText.php';
require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';
require_once __DIR__ . '/../libs/FrigateHubWebhook.php';
require_once __DIR__ . '/../libs/FrigateHubKachel.php';
require_once __DIR__ . '/../libs/FrigateHubOha.php';
require_once __DIR__ . '/../libs/FrigateHubPush.php';
require_once __DIR__ . '/../libs/FrigateHubKameraSensor.php';
require_once __DIR__ . '/../libs/FrigateHubKameraEinstellungen.php';

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
    use FrigateHubWebhook;
    use FrigateHubPush;
    use FrigateHubKameraSensor;
    use FrigateHubKameraEinstellungen;
    use FrigateHubKachel {
        MessageSink as private kachelMessageSink;
    }

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
    private const POS_SENSOR    = 500;

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
    private const SMTP_GUID     = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    /** Töne laut Symcon-Doku, für WebFront und Kachel-Visualisierung gleich. */
    private const PUSH_TOENE = ['alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full',
        'happy', 'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Reine Klassen (libs) übersetzen über diesen Übersetzer, siehe FrigateHubText
        FrigateHubText::setzen(fn (string $text): string => $this->Translate($text));
    }

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
        // KI-Einschätzung (Frigate GenAI): zum Alarm nachreichen, verdächtige Erkennungen melden
        $this->RegisterPropertyBoolean('PushKI', true);
        // Optionale Kopplung an OpenHomeAlarm: Bereich ('' = aus), was bei scharf geschieht, ob unscharf zurückschaltet
        $this->RegisterPropertyString('OhaBereich', '');
        $this->RegisterPropertyInteger('OhaBeiScharf', 1);
        $this->RegisterPropertyBoolean('OhaBeiUnscharf', false);
        $this->RegisterAttributeString('OhaLage', '');
        // Alarme an OpenHomeAlarms Alarmereignis-Schnittstelle melden: Quellen-ID ('' = aus), 1 = Alarme, 2 = auch Erkennungen
        $this->RegisterPropertyString('OhaQuelle', '');
        $this->RegisterPropertyInteger('OhaMelden', 1);
        $this->RegisterAttributeString('OhaGemeldet', '');
        $this->RegisterAttributeString('OhaMeldestatus', '');

        $this->RegisterAttributeString('Zonen', '[]');
        $this->RegisterAttributeInteger('LetzteNachricht', 0);
        $this->RegisterAttributeString('LetzteEventId', '');
        $this->RegisterAttributeString('Schalter', '{}');
        $this->RegisterAttributeString('Abgleichstatus', '');
        $this->RegisterAttributeString('Bildstatus', '');
        $this->RegisterAttributeString('LetzterPush', '{}');
        $this->RegisterAttributeString('Pushstatus', '');
        $this->RegisterAttributeString('LetzterKiPush', '');
        $this->registerFormAttributes();
        $this->formAufbauRegistrieren();

        $this->RegisterTimer('Abgleich', 0, 'FRIG_ZustandAnfordern($_IPS[\'TARGET\']);');
        $this->sensorRegistrieren();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->kachelMessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ohaBeobachten();
        } elseif ($Message === VM_UPDATE) {
            $this->ohaPruefen(true);
        }
    }

    public function Destroy()
    {
        // Beim Löschen der Instanz: WebHook-Eintrag und zwischengespeicherte Clips entfernen
        $this->webhookAufraeumen($this->hookPfad());
        if (IPS_GetKernelRunlevel() === KR_READY && !IPS_InstanceExists($this->InstanceID)) {
            foreach (glob(sys_get_temp_dir() . '/frigatehub_' . $this->InstanceID . '_*.mp4') ?: [] as $datei) {
                @unlink($datei);
            }
        }
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->apiUebernehmen()) {
            return;
        }

        $this->festeVariablen();
        $this->kachelUebernehmen();

        $this->sensorUebernehmen();
        $kamera = $this->kamera();
        if ($kamera === '') {
            $this->SetReceiveDataFilter(FrigateHubTopics::sperrfilter());
            $this->SetTimerInterval('Abgleich', 0);
            $this->SetStatus(104);
            return;
        }

        $this->filterSetzen();
        $this->ohaBeobachten();
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
                    $this->sensorBewegung($wert);
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
                    $this->variableAnlegen($ident, $d['name'], VARIABLETYPE_BOOLEAN, self::anzeigeJaNein(FrigateHubText::t('detected'), FrigateHubText::t('silent')), self::POS_GERAEUSCH);
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

            case 'objektupdate':
                $this->objektUpdateVerarbeiten($payload);
                break;

            case 'zustand':
                $this->sensorZustand($d['modell'], $payload);
                break;

            case 'transkript':
                $this->sensorTranskript($payload);
                break;

            case 'ausloeser':
                $this->sensorAusloeser($payload);
                break;

            case 'zahl':
                $zahl = FrigateHubTopics::zahl($payload);
                if ($zahl !== null) {
                    $this->zahlSetzen($d['schluessel'], $zahl);
                    $this->frischMerken($d['schluessel']);
                }
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
        // Aktionen der Kachel (gesehen, Favorit, Export, PTZ): Fehler erscheinen in der Kachel
        try {
            if ($this->kachelAktion($ident, $Value)) {
                return;
            }
        } catch (Throwable $e) {
            $this->kachelMelden('⛔ ' . $e->getMessage());
            throw $e;
        }
        foreach (FrigateHubTopics::ZAHLEN as $schluessel => $z) {
            if ($ident === $z['ident']) {
                $wert = max($z['min'], min($z['max'], (int)$Value));
                // Wie bei Schaltern: Die Variable folgt erst Frigates Bestätigung (…/state).
                if ($this->senden(FrigateHubTopics::setTopic($this->ReadPropertyString('TopicPrefix'), $this->kamera(), $schluessel), (string)$wert)) {
                    $this->frischMerken($schluessel);
                }
                return;
            }
        }
        $schluessel = $this->schalterSchluessel($ident);
        if ($schluessel === '') {
            throw new Exception(FrigateHubText::t('Unknown action %s', $ident));
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
            return FrigateHubText::t('ℹ️ First enter a camera and apply.');
        }
        if ($this->apiUrl() !== '') {
            $text = $this->abgleichen();
            $this->WriteAttributeString('Abgleichstatus', $text);
            $this->UpdateFormField('Abgleichstatus', 'caption', $text);
            $this->UpdateFormField('Empfangsstatus', 'caption', $this->empfangsstatus());
            $this->UpdateFormField('Videostatus', 'caption', $this->videostatus());
            return $text;
        }
        $ok = $this->senden(FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix')) . '/onConnect', '');
        return $ok
            ? FrigateHubText::t('✅ Request sent to Frigate. Frigate does not resend all states in response — it becomes complete with a Frigate address entered (HTTP sync).')
            : FrigateHubText::t('⛔ Sending failed — is the instance below an active MQTT server or MQTT client? Details in the message log.');
    }

    /**
     * Holt das aktuelle Kamerabild über die API (/api/<kamera>/latest.jpg) ins Medienobjekt
     * „Schnappschuss". Rückgabe: Text für das Formular.
     */
    public function BildHolen(): string
    {
        if ($this->kamera() === '' || $this->apiUrl() === '') {
            return FrigateHubText::t('ℹ️ This needs the camera ID and the Frigate address. Snapshots of detections also arrive via MQTT without an address.');
        }
        $bild = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/latest.jpg?' . FrigateHubTopics::hoehe(self::LIVEBILD_HOEHE) . $this->zeitstempel(), 'image/jpeg');
        if ($bild === null || FrigateHubTopics::bildBytes($bild) === null) {
            $text = FrigateHubText::t('⛔ No image from Frigate (%s).', $bild === null ? $this->apiFehler : FrigateHubText::t('response is not a JPEG'));
        } else {
            $this->bildSetzen($bild, FrigateHubText::t('Live image'));
            $text = '✅ ' . $this->ReadAttributeString('Bildstatus');
        }
        $this->UpdateFormField('Bildstatus', 'caption', $text);
        return $text;
    }

    /** Probemeldung an alle eingetragenen Ziele. Rückgabe: Text fürs Formular. */
    public function PushTesten(): string
    {
        $text = $this->pushSenden('🔔 ' . IPS_GetName($this->InstanceID), FrigateHubText::t('Test message from FrigateHub – this is what an alert looks like.'), true);
        $this->UpdateFormField('Pushstatus', 'caption', $this->pushstatus());
        return $text;
    }

    /**
     * FRIG_MeldungMitSchnappschuss($id, $Text, $Livebild): Meldung an alle eingetragenen Ziele —
     * Push an Kachel-Visualisierung/WebFront (Antippen öffnet diese Kamera), E-Mail mit Bild an
     * SMTP-Instanzen. Gedacht als Aktion für Alarmanlagen und Regeln (z. B. OpenHomeAlarm, Automat).
     * $Livebild: aktuelles Kamerabild statt des letzten Schnappschusses. Leerer Text: „Letztes Objekt".
     */
    public function MeldungMitSchnappschuss(string $Text, bool $Livebild): string
    {
        $text = trim($Text) !== '' ? trim($Text) : (string)@$this->GetValue('LetztesObjekt');
        $status = $this->pushSenden('📷 ' . IPS_GetName($this->InstanceID), $text !== '' ? $text : FrigateHubText::t('Message from FrigateHub'), $Livebild);
        $this->UpdateFormField('Pushstatus', 'caption', $this->pushstatus());
        return $status;
    }

    /* ==================================================================== OpenHomeAlarm (optional) */

    /** Erste OpenHomeAlarm-Instanz oder 0. Kein Muss: ohne OpenHomeAlarm bleibt alles wie gehabt. */
    private function ohaInstanz(): int
    {
        $ids = IPS_GetInstanceListByModuleID(FrigateHubOha::GUID);
        return $ids === [] ? 0 : (int)$ids[0];
    }

    /** Antwort von OpenHomeAlarm gedeutet, oder null, wenn es nicht da ist. */
    private function ohaStand(): ?array
    {
        $id = $this->ohaInstanz();
        if ($id === 0 || !function_exists('OHA_GetControlState')) {
            return null;
        }
        try {
            return FrigateHubOha::deuten(@OHA_GetControlState($id));
        } catch (Throwable $e) {
            return ['ok' => false, 'grund' => $e->getMessage(), 'bereiche' => []];
        }
    }

    /**
     * Auf Änderungen der Variablen von OpenHomeAlarm hören (deren Idents legt FrigateHub bewusst
     * nicht fest) und den aktuellen Stand merken, ohne zu schalten — Übernehmen löst nichts aus.
     */
    private function ohaBeobachten(): void
    {
        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            if ($sender !== 0 && in_array(VM_UPDATE, $nachrichten, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
        }
        $id = $this->ohaInstanz();
        if (trim($this->ReadPropertyString('OhaBereich')) === '' || $id === 0 || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        // Nur die Zustandsvariablen (OpenHomeAlarm-Quellcode: „State" für main, „Partition_<hex>_State"
        // für weitere Bereiche) — die übrigen ~20 Variablen ändern sich ständig und bräuchten keine Nachfrage.
        // Findet sich keine davon (andere Benennung), auf alle hören wie bisher.
        $alle = [];
        $zustand = [];
        foreach (IPS_GetChildrenIDs($id) as $kind) {
            $o = IPS_GetObject($kind);
            if ($o['ObjectType'] === 2) {
                $alle[] = $kind;
                if (preg_match(FrigateHubOha::ZUSTAND_IDENT, (string)($o['ObjectIdent'] ?? ''))) {
                    $zustand[] = $kind;
                }
            }
        }
        foreach ($zustand !== [] ? $zustand : $alle as $kind) {
            $this->RegisterMessage($kind, VM_UPDATE);
        }
        $this->ohaPruefen(false);
    }

    /** Zustand des Bereichs prüfen; beim tatsächlichen Wechsel scharf/unscharf schalten. */
    private function ohaPruefen(bool $handeln): void
    {
        $bereich = trim($this->ReadPropertyString('OhaBereich'));
        $stand = $bereich === '' ? null : $this->ohaStand();
        if ($stand === null || !$stand['ok']) {
            return;
        }
        $lage = FrigateHubOha::lage($stand['bereiche'][$bereich] ?? null);
        if ($lage === '' || $lage === $this->ReadAttributeString('OhaLage')) {
            return;
        }
        $this->WriteAttributeString('OhaLage', $lage);
        if (!$handeln) {
            return;
        }
        $was = $this->ReadPropertyInteger('OhaBeiScharf');
        $idents = array_merge(in_array($was, [1, 3], true) ? ['Schalter_recordings'] : [], in_array($was, [2, 3], true) ? ['Schalter_detect'] : []);
        if ($lage === 'unscharf' && !$this->ReadPropertyBoolean('OhaBeiUnscharf')) {
            return;
        }
        $an = $lage === 'scharf';
        foreach ($idents as $ident) {
            if (@$this->GetIDForIdent($ident) !== false && $this->GetValue($ident) !== $an) {
                $this->RequestAction($ident, $an);
            }
        }
        if ($idents !== []) {
            $schaltet = implode(', ', array_map(fn ($i) => $i === 'Schalter_recordings' ? FrigateHubText::t('Recording') : FrigateHubText::t('Object detection'), $idents));
            $this->LogMessage($an ? FrigateHubText::t('OpenHomeAlarm: area "%s" armed → %s on', $bereich, $schaltet)
                : FrigateHubText::t('OpenHomeAlarm: area "%s" disarmed → %s off', $bereich, $schaltet), KL_MESSAGE);
        }
    }

    /**
     * Bestätigten Alarm an OpenHomeAlarm melden (OHA_TriggerExternalAlarmEvent, Alarmereignis-API 1).
     * OpenHomeAlarm entscheidet selbst, ob daraus ein Alarm wird (nur wenn der Bereich der Quelle in
     * einem freigegebenen Modus scharf ist). Einmal je Review.
     */
    private function ohaMelden(string $reviewId, string $severity): void
    {
        $quelle = trim($this->ReadPropertyString('OhaQuelle'));
        $art = $this->ReadPropertyInteger('OhaMelden');
        if ($quelle === '' || $art === 0 || $reviewId === '' || $this->ReadAttributeString('OhaGemeldet') === $reviewId) {
            return;
        }
        if ($severity !== 'alert' && !($art === 2 && $severity === 'detection')) {
            return;
        }
        $id = $this->ohaInstanz();
        if ($id === 0 || !function_exists('OHA_TriggerExternalAlarmEvent')) {
            return;
        }
        $ereignis = FrigateHubOha::ereignis($quelle, $reviewId, time());
        if ($ereignis === null) {
            $this->WriteAttributeString('OhaMeldestatus', FrigateHubText::t('⛔ %s: source ID invalid.', date('d.m.Y H:i:s')));
            return;
        }
        $this->WriteAttributeString('OhaGemeldet', $reviewId);
        try {
            $text = FrigateHubOha::ergebnisText((string)OHA_TriggerExternalAlarmEvent($id, $ereignis));
        } catch (Throwable $e) {
            $text = FrigateHubText::t('⛔ rejected: %s', $e->getMessage());
        }
        $this->WriteAttributeString('OhaMeldestatus', date('d.m.Y H:i:s') . ' ' . $text);
        $this->SendDebug('OpenHomeAlarm', $ereignis . ' → ' . $text, 0);
    }

    /**
     * FRIG_OhaVerbindungPruefen($id): schickt ein Probeereignis, aber nur wenn der Bereich der Quelle
     * unscharf ist — dann verwirft OpenHomeAlarm es („nicht scharf") und der Weg ist bewiesen, ohne
     * dass ein Alarm entstehen kann.
     */
    public function OhaVerbindungPruefen(): string
    {
        $quelle = trim($this->ReadPropertyString('OhaQuelle'));
        $id = $this->ohaInstanz();
        if ($quelle === '' || $id === 0 || !function_exists('OHA_TriggerExternalAlarmEvent') || !function_exists('OHA_GetExternalAlarmEventApi')) {
            return FrigateHubText::t('ℹ️ This needs OpenHomeAlarm with the alarm event interface and a source ID.');
        }
        $api = json_decode((string)@OHA_GetExternalAlarmEventApi($id), true);
        $bereich = '';
        foreach ((array)($api['Sources'] ?? []) as $q) {
            if (is_array($q) && ($q['SourceID'] ?? '') === $quelle) {
                $bereich = (string)($q['PartitionID'] ?? '');
            }
        }
        $stand = $this->ohaStand();
        if ($bereich === '' || $stand === null || !$stand['ok']) {
            return '⚠️ ' . FrigateHubOha::quelleStatus($api, $quelle)['text'];
        }
        if (FrigateHubOha::lage($stand['bereiche'][$bereich] ?? null) !== 'unscharf') {
            return FrigateHubText::t('⛔ Area "%s" is not disarmed — a test event would trigger a real alarm. Please disarm first.', $bereich);
        }
        $ereignis = FrigateHubOha::ereignis($quelle, 'probe-' . time(), time());
        // Erfolg am Status-Schlüssel erkennen, nicht am (übersetzten) Text
        $verworfen = false;
        try {
            $antwort = (string)OHA_TriggerExternalAlarmEvent($id, (string)$ereignis);
            $verworfen = (string)(json_decode($antwort, true)['Status'] ?? '') === 'partition_not_armed';
            $text = FrigateHubOha::ergebnisText($antwort);
        } catch (Throwable $e) {
            $text = FrigateHubText::t('⛔ rejected: %s', $e->getMessage());
        }
        return $verworfen ? FrigateHubText::t('Test event to OpenHomeAlarm: %s — the path works.', $text) : FrigateHubText::t('Test event to OpenHomeAlarm: %s', $text);
    }

    private function ohaQuellenStatus(): string
    {
        $quelle = trim($this->ReadPropertyString('OhaQuelle'));
        $id = $this->ohaInstanz();
        if ($quelle === '') {
            return FrigateHubText::t('ℹ️ Reporting is off (no source ID).');
        }
        if ($id === 0 || !function_exists('OHA_GetExternalAlarmEventApi')) {
            return FrigateHubText::t('ℹ️ This OpenHomeAlarm does not have the external alarm event interface yet (from 1.423).');
        }
        try {
            $text = FrigateHubOha::quelleStatus(@OHA_GetExternalAlarmEventApi($id), $quelle)['text'];
        } catch (Throwable $e) {
            $text = '⚠️ ' . $e->getMessage();
        }
        $letzte = $this->ReadAttributeString('OhaMeldestatus');
        return $letzte !== '' ? FrigateHubText::t('%s Last report: %s', $text, $letzte) : $text;
    }

    /** Statuszeile und Bereiche fürs Formular. */
    private function ohaFormular(): array
    {
        $id = $this->ohaInstanz();
        $stand = $this->ohaStand();
        $bereich = trim($this->ReadPropertyString('OhaBereich'));
        $optionen = [['caption' => FrigateHubText::t('off (no coupling)'), 'value' => '']];
        if ($id === 0) {
            $status = FrigateHubText::t('ℹ️ OpenHomeAlarm is not installed — then there is nothing to do here. FrigateHub works just the same without it.');
        } elseif ($stand === null || !$stand['ok']) {
            $status = FrigateHubText::t('⚠️ OpenHomeAlarm #%s found, but %s. Coupling is off.', $id, $stand['grund'] ?? FrigateHubText::t('OHA_GetControlState() is missing'));
        } else {
            $teile = [];
            foreach ($stand['bereiche'] as $b => $z) {
                $teile[] = $b . ' (' . FrigateHubOha::zustandText($z) . ')';
                $optionen[] = ['caption' => FrigateHubText::t('Area %s', $b), 'value' => (string)$b];
            }
            $status = FrigateHubText::t('✅ OpenHomeAlarm #%s "%s" found — areas: %s', $id, IPS_GetName($id), implode(', ', $teile) ?: FrigateHubText::t('none (areas)'));
        }
        if ($bereich !== '' && !in_array($bereich, array_column($optionen, 'value'), true)) {
            $optionen[] = ['caption' => FrigateHubText::t('Area %s (currently not reported)', $bereich), 'value' => $bereich];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🛡️  OpenHomeAlarm (optional)'),
            'items' => [
                ['type' => 'Label', 'caption' => FrigateHubText::t('Optional cooperation with the alarm system OpenHomeAlarm (Burki24). You set up sensors in OpenHomeAlarm (e.g. "Alert" or a zone counter of this camera), notifications with image via FRIG_MeldungMitSchnappschuss as alarm action. Here only: what this camera does when an area is armed or disarmed.')],
                ['type' => 'Label', 'name' => 'OhaStatus', 'caption' => $status],
                ['type' => 'Select', 'name' => 'OhaBereich', 'caption' => FrigateHubText::t('Area'), 'options' => $optionen],
                ['type' => 'Select', 'name' => 'OhaBeiScharf', 'caption' => FrigateHubText::t('When armed'), 'options' => [
                    ['caption' => FrigateHubText::t('do nothing'), 'value' => 0],
                    ['caption' => FrigateHubText::t('switch on recording'), 'value' => 1],
                    ['caption' => FrigateHubText::t('switch on object detection'), 'value' => 2],
                    ['caption' => FrigateHubText::t('switch on recording and object detection'), 'value' => 3],
                ]],
                ['type' => 'CheckBox', 'name' => 'OhaBeiUnscharf', 'caption' => FrigateHubText::t('Switch off again when disarming')],
                ['type' => 'Label', 'caption' => FrigateHubText::t('Switching happens only on an actual change (armed only after the exit delay), not when applying. Frigate confirms the new state, the variables follow.')],
                ['type' => 'Label', 'caption' => FrigateHubText::t('Report alerts (external alarm event interface of OpenHomeAlarm): FrigateHub reports every new Frigate alert of this camera as a confirmed event. Whether it becomes an alarm is decided by OpenHomeAlarm — only if the source\'s area is armed in an enabled mode. Enter the same source ID in OpenHomeAlarm.')],
                ['type' => 'ValidationTextBox', 'name' => 'OhaQuelle', 'caption' => FrigateHubText::t('Source ID (empty = do not report), e.g. frigate-haustuer'), 'validate' => '^$|^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'],
                ['type' => 'Select', 'name' => 'OhaMelden', 'caption' => FrigateHubText::t('Report on'), 'options' => [
                    ['caption' => FrigateHubText::t('nothing'), 'value' => 0],
                    ['caption' => FrigateHubText::t('Frigate alert (severity "alert")'), 'value' => 1],
                    ['caption' => FrigateHubText::t('Frigate alert and detection'), 'value' => 2],
                ]],
                ['type' => 'Label', 'name' => 'OhaQuellenStatus', 'caption' => $this->ohaQuellenStatus()],
                ['type' => 'Button', 'caption' => FrigateHubText::t('Check connection (only when disarmed)'), 'onClick' => 'echo FRIG_OhaVerbindungPruefen($id);'],
            ],
        ];
    }

    /** Vergisst die gelernten Zonen (z. B. nach Umbenennen in Frigate). Variablen bleiben. */
    public function ZonenVergessen(): string
    {
        $anzahl = count($this->zonen());
        $this->WriteAttributeString('Zonen', '[]');
        if ($this->kamera() !== '') {
            $this->filterSetzen();
        }
        return FrigateHubText::t('✅ %d zone(s) forgotten. They are learned again with the next event in the zone; existing variables remain (archive).', $anzahl);
    }

    /* ==================================================================== Formular */

    /** Fachpanels mit festem Schlüssel (Reihenfolge = Standard) für „🪟 Formular: Aufbau“. */
    protected function frigatehubFachpanels(): array
    {
        $kamera = $this->kamera();
        return [
            'kamera' => [
                'type' => 'ExpansionPanel', 'expanded' => $kamera === '', 'caption' => FrigateHubText::t('📷  Frigate camera'),
                'items' => [
                    ['type' => 'Label', 'caption' => FrigateHubText::t('The camera ID from the Frigate configuration (section cameras:), not the display name. Case matters, e.g. "haustuer" or "Door-Cam".')],
                    ['type' => 'ValidationTextBox', 'name' => 'Camera', 'caption' => FrigateHubText::t('Camera ID')],
                    ['type' => 'ValidationTextBox', 'name' => 'TopicPrefix', 'caption' => FrigateHubText::t('MQTT prefix (Frigate: mqtt.topic_prefix, default "frigate")')],
                    ['type' => 'Label', 'name' => 'Empfangsstatus', 'caption' => $this->empfangsstatus()],
                    ...$this->apiFormular(FrigateHubText::t('Optional, but recommended: without an address, switches only arrive when Frigate reports them via MQTT, and snapshots only as a crop.')),
                    ['type' => 'Label', 'name' => 'Abgleichstatus', 'caption' => $this->abgleichstatus()],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Query state now'), 'onClick' => 'echo FRIG_ZustandAnfordern($id);'],
                ],
            ],
            'schnappschuss' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🖼️  Snapshot'),
                'items' => [
                    ['type' => 'Label', 'caption' => FrigateHubText::t('The media object "Snapshot" shows the image of the last detection (Frigate: snapshots enabled). With a Frigate address it comes in full size, otherwise as a crop via MQTT (size in Frigate under mqtt: height/crop). With an address the current camera image can also be fetched.')],
                    ['type' => 'Label', 'name' => 'Bildstatus', 'caption' => $this->bildstatus()],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Fetch current camera image'), 'onClick' => 'echo FRIG_BildHolen($id);'],
                ],
            ],
            'sensor' => $this->sensorPanel(),
            'einstellungen' => $this->einstellungenPanel(),
            'anmeldung' => $this->apiAnmeldePanel(),
            'meldungen' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🔔  Push and notifications'),
                'items' => [
                    ['type' => 'Label', 'caption' => FrigateHubText::t('Reports every new alert of this camera to the targets, once per alert. What counts as an alert is set in Frigate (review: alerts: labels / required_zones). Without a target, push stays off.')],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('Push messages (tile visualization, WebFront) cannot include an image; tapping opens this camera. Entered as SMTP instance, the notification arrives as an e-mail with the current camera image (frames around detected objects).')],
                    $this->pushZielListe(),
                    ['type' => 'Select', 'name' => 'PushTon', 'caption' => FrigateHubText::t('Sound'), 'options' => $this->tonOptionen()],
                    ['type' => 'NumberSpinner', 'name' => 'PushPause', 'caption' => FrigateHubText::t('Minimum interval between two notifications'), 'suffix' => 's', 'minimum' => 0, 'maximum' => 3600],
                    ['type' => 'CheckBox', 'name' => 'PushKI', 'caption' => FrigateHubText::t('Follow up the alert with Frigate\'s AI assessment (GenAI) and report suspicious detections')],
                    ['type' => 'Label', 'name' => 'Pushstatus', 'caption' => $this->pushstatus()],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Send test message'), 'onClick' => 'echo FRIG_PushTesten($id);'],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('For alarm systems and rules (e.g. OpenHomeAlarm, DG65-Toolkit Automat, scripts): FRIG_MeldungMitSchnappschuss($id, "Text", true) sends a notification with the current camera image to the same targets — in Symcon actions as "Run instance function". Empty text: "Last object"; false instead of true: last snapshot instead of live image.')],
                ],
            ],
            'kachel' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🖥️  Tile and IPSView'),
                'items' => [
                    ['type' => 'Label', 'caption' => FrigateHubText::t('This instance is also a tile for the tile visualization: live image, alert, counters, the latest events with preview image, snapshot and clip on tap as well as switches for detection, recording and snapshots. Live image, preview images and clips need the Frigate address.')],
                    ['type' => 'NumberSpinner', 'name' => 'KachelLiveTakt', 'caption' => FrigateHubText::t('Reload live image every (0 = only on tap)'), 'suffix' => 's', 'minimum' => 0, 'maximum' => 60],
                    ['type' => 'CheckBox', 'name' => 'KachelRahmen', 'caption' => FrigateHubText::t('Frame detected objects in the live image')],
                    ['type' => 'CheckBox', 'name' => 'Zeitstempel', 'caption' => FrigateHubText::t('Frigate\'s timestamp in the live image (tile, notification image, "Fetch current camera image") — off if the camera already shows the time itself')],
                    ['type' => 'CheckBox', 'name' => 'KachelVideo', 'caption' => FrigateHubText::t('Live video if Frigate\'s go2rtc has a stream for the camera')],
                    ['type' => 'Label', 'name' => 'Videostatus', 'caption' => $this->videostatus()],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('The images go through the WebHook %s with a secret key of this instance. If an image address was passed on, a new key invalidates it.', $this->hookPfad())],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('IPSView or browser: the same interface is available as a standalone page. In IPSView create a web page element (WebView) and enter the address from "Show address for IPSView" (replace <Symcon-IP> with the address of the Symcon server, 3777 is Symcon\'s default port).')],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Show address for IPSView'), 'onClick' => 'echo FRIG_SeiteAdresse($id);'],
                    ['type' => 'CheckBox', 'name' => 'SeiteBedienung', 'caption' => FrigateHubText::t('Switches (detection, recording, snapshots) can be operated on the standalone page')],
                    ['type' => 'Label', 'caption' => FrigateHubText::t('⚠️ The page is reachable without Symcon login: whoever knows the address with key sees the camera. Do not pass on the address; if in doubt, renew the key (then enter the new address in IPSView). Alternatively use the instance\'s variables directly in IPSView, then the IPSView login applies.')],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Renew tile key'), 'onClick' => 'echo FRIG_KachelSchluesselErneuern($id);'],
                ],
            ],
            'openhomealarm' => $this->ohaFormular(),
            'zonen' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('🗺️  Zones'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->zonenstatus()],
                    ['type' => 'Button', 'caption' => FrigateHubText::t('Forget learned zones'), 'onClick' => 'echo FRIG_ZonenVergessen($id);'],
                ],
            ],
            'nachrichten' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('📨  Received messages'),
                'items' => $this->nachrichtenLabels(),
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => FrigateHubText::t('Ready')],
            ['code' => 104, 'icon' => 'inactive', 'caption' => FrigateHubText::t('Please enter the camera ID from Frigate')],
        ];
        $teile = $this->formAufbauTeilen($this->frigatehubFachpanels());
        return $this->frigatehubFormular($teile['elements'], $teile['actions'], $status);
    }

    protected function frigatehubPrefix(): string
    {
        return 'FRIG';
    }

    protected function frigatehubZweck(): array
    {
        return [
            FrigateHubText::t('Connects a camera from Frigate (video surveillance with AI object detection) to Symcon: persons, cars, animals and packages per camera and zone, motion, alert and the latest events as variables.'),
            FrigateHubText::t('This lets you trigger automations, e.g. light for a person in the driveway or a push message for a package. Detection, recording and zones can be switched from within Symcon.'),
        ];
    }

    protected function frigatehubDoku(): array
    {
        return [
            FrigateHubText::t('Requirement: Frigate sends via MQTT to the MQTT server or broker this instance is attached to (Frigate configuration mqtt: host/port/user/password).'),
            FrigateHubText::t('One instance per camera. Counters, zones, sounds and switches are created automatically as soon as Frigate reports them for the first time.'),
            FrigateHubText::t('With a Frigate address entered, the instance queries switches and zones via Frigate\'s API after applying and after every Symcon start. Without an address, Frigate only sends switch states via MQTT when it reconnects or something is switched.'),
            FrigateHubText::t('The media object "Snapshot" is created with the first image: the first snapshot of a detection or, with a Frigate address, the current camera image at the first sync. It is kept in memory (spares SSD/SD card) and saved when Symcon shuts down.'),
            FrigateHubText::t('Push on alert: one notification per new alert to the entered visualizations, title = name of this instance, text = time, objects and zones. If a detection is later upgraded to an alert, the notification comes then.'),
            FrigateHubText::t('Tile: the instance can be placed directly in the tile visualization. The live image only loads while the tile is visible. A tap on an event shows snapshot and clip, a tap on the live image enlarges it.'),
            FrigateHubText::t('Zones come from the sync or from the events of this camera. Counters per zone are created as soon as Frigate counts an object there.'),
            FrigateHubText::t('Switches (detection, recording, masks …) only take on a value once Frigate confirms it. If no confirmation arrives, the old value remains.'),
            FrigateHubText::t('"Alert" and "Detection" follow Frigate\'s review status (review_status): alert = Frigate classifies the event as an alert, detection = alert or detection.'),
            ...$this->sensorDoku(),
            FrigateHubText::t('Configure in Frigate: shows Frigate\'s settings for this camera when opened and writes back only what changed via Frigate\'s API (PUT /api/config/set, like Frigate\'s own interface, effective immediately). Frigate checks the values and reverts invalid ones; the reason appears in the form. Needs the role "admin" (port 5000 always has it). Drawing zones/masks and adding cameras stay in Frigate.'),
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
            $this->LogMessage(FrigateHubText::t('Sync with Frigate failed: %s', $this->apiFehler), KL_WARNING);
            return $text;
        }
        $kameras = (array)($config['cameras'] ?? []);
        if (!is_array($kameras[$kamera] ?? null)) {
            return FrigateHubText::t('⛔ Frigate has no camera "%s". Available: %s.', $kamera, implode(', ', array_keys($kameras)) ?: FrigateHubText::t('none (cameras)'));
        }
        $this->SetValue('Verfuegbar', true);
        $this->WriteAttributeString('Einheit', ($config['ui']['unit_system'] ?? 'metric') === 'imperial' ? ' mph' : ' km/h');

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
        $this->livestreamPruefen($kameras[$kamera]);
        $bewegung = (array)($kameras[$kamera]['motion'] ?? []);
        foreach (['motion_threshold' => 'threshold', 'motion_contour_area' => 'contour_area'] as $schluessel => $feld) {
            if (is_numeric($bewegung[$feld] ?? null) && !isset($frisch[$schluessel])) {
                $this->zahlSetzen($schluessel, (int)$bewegung[$feld]);
            }
        }
        // PTZ nur anbieten, wenn Frigate für die Kamera Fähigkeiten meldet (sonst {}, live 29.10.2026)
        $this->ptzMerken(!empty($kameras[$kamera]['onvif']['host']) ? $this->apiHolen('/api/' . rawurlencode($kamera) . '/ptz/info') : null);

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

        $teile = [FrigateHubText::t('%d switches', count($schalter))
            . ($geschont > 0 ? ' ' . FrigateHubText::t('(of which %d were just switched, MQTT state kept)', $geschont) : ''),
            FrigateHubText::t('%d zone(s)', count($this->zonen()))];
        if (is_numeric($fps)) {
            $teile[] = (float)$fps > 0 ? FrigateHubText::t('camera delivers images') : FrigateHubText::t('camera delivers no images');
        }
        return FrigateHubText::t('✅ Synced on %s: %s.', date('d.m.Y H:i:s'), implode(', ', $teile));
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
        $name = FrigateHubText::t('Snapshot %s', FrigateHubTopics::objektEinzahl($label));
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
        $this->bildSetzen($bild, $this->apiUrl() !== '' ? FrigateHubText::t('%s (crop, full image not reachable)', $name) : FrigateHubText::t('%s (crop)', $name));
    }

    /**
     * Schreibt ein JPEG ins Medienobjekt „Schnappschuss" (legt es beim ersten Bild an).
     * Gecacht: Symcon hält das Bild im Arbeitsspeicher und schreibt es erst beim Beenden —
     * bei mehreren Schnappschüssen je Erkennung schont das SSD/SD-Karte.
     */
    private function bildSetzen(string $jpeg, string $quelle): void
    {
        if ($this->mediumSchreiben('Schnappschuss', FrigateHubText::t('Snapshot'), 9, '', $jpeg) === 0) {
            return;
        }
        $this->WriteAttributeString('Bildstatus', FrigateHubText::t('%s from %s (%s KB)', $quelle, date('d.m.Y H:i:s'), round(strlen($jpeg) / 1024)));
    }

    /** Bild-Medienobjekt anlegen (falls nötig, gecacht) und beschreiben. Rückgabe: Medien-ID, 0 bei Fehler. */
    private function mediumSchreiben(string $ident, string $name, int $position, string $dateiZusatz, string $jpeg): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            $id = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            IPS_SetPosition($id, $position);
            IPS_SetMediaFile($id, 'media/FrigateHub_' . $this->InstanceID . $dateiZusatz . '.jpg', false);
            IPS_SetMediaCached($id, true);
        }
        if (!IPS_SetMediaContent($id, base64_encode($jpeg))) {
            $this->LogMessage(FrigateHubText::t('%s could not be saved (media object %s)', $name, $id), KL_ERROR);
            return 0;
        }
        return $id;
    }

    /**
     * Bild für eine Meldung: mit Frigate-Adresse das aktuelle Kamerabild (mit Rahmen um erkannte
     * Objekte) im eigenen Medienobjekt „Meldungsbild" — so bleibt der Erkennungs-Schnappschuss
     * unangetastet. Sonst (oder wenn das nicht klappt) der letzte Schnappschuss. 0 = kein Bild.
     */
    protected function pushBild(bool $livebild): int
    {
        if ($livebild && $this->kamera() !== '' && $this->apiUrl() !== '') {
            $bild = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/latest.jpg?' . FrigateHubTopics::hoehe(1080) . '&bbox=1' . $this->zeitstempel(), 'image/jpeg');
            if ($bild !== null && FrigateHubTopics::bildBytes($bild) !== null) {
                $id = $this->mediumSchreiben('Meldungsbild', FrigateHubText::t('Notification image'), 10, '_meldung', $bild);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        $id = @IPS_GetObjectIDByIdent('Schnappschuss', $this->InstanceID);
        return $id === false ? 0 : (int)$id;
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
        $t = fn (string $s): string => FrigateHubText::t($s);
        $this->variableAnlegen('Verfuegbar', $t('Frigate reachable'), VARIABLETYPE_BOOLEAN, self::anzeigeJaNein($t('reachable'), $t('not reachable'), true), 1);
        $this->variableAnlegen('Online', $t('Camera online'), VARIABLETYPE_BOOLEAN, self::anzeigeJaNein($t('online'), $t('offline'), true), 2);
        $this->variableAnlegen('Bewegung', $t('Motion'), VARIABLETYPE_BOOLEAN, self::anzeigeJaNein($t('motion (value)'), $t('quiet')), 3);
        $this->variableAnlegen('Alarm', $t('Alert'), VARIABLETYPE_BOOLEAN, self::anzeigeJaNein($t('Alert'), $t('no alert')), 4);
        $this->variableAnlegen('Erkennung', $t('Detection'), VARIABLETYPE_BOOLEAN, self::anzeigeJaNein($t('detected'), $t('nothing detected')), 5);
        $this->variableAnlegen('LetzterAlarm', $t('Last alert'), VARIABLETYPE_STRING, self::anzeigeText(), 6);
        $this->variableAnlegen('LetzteErkennung', $t('Last detection'), VARIABLETYPE_STRING, self::anzeigeText(), 7);
        $this->variableAnlegen('LetztesObjekt', $t('Last object'), VARIABLETYPE_STRING, self::anzeigeText(), 8);
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

    /** Einstellbare Zahl (Bewegungsschwelle …) anlegen und setzen; Bedienung per Schieberegler. */
    private function zahlSetzen(string $schluessel, int $wert): void
    {
        $z = FrigateHubTopics::zahlEintrag($schluessel);
        $this->variableAnlegen($z['ident'], $z['name'], VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN' => $z['min'], 'MAX' => $z['max'], 'STEP_SIZE' => 1, 'DIGITS' => 0], self::POS_SCHALTER + 50, true);
        $this->SetValue($z['ident'], $wert);
    }

    /** PTZ-Befehl an Frigate (frigate/<kamera>/ptz). */
    private function ptzSenden(string $befehl): void
    {
        $topic = FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix')) . '/' . $this->kamera() . '/ptz';
        if (!$this->senden($topic, $befehl)) {
            throw new Exception(FrigateHubText::t('No active MQTT instance — PTZ command not sent.'));
        }
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
        $this->eigeneObjekteMerken([(string)($after['id'] ?? '')]);
        $typ = (string)($e['type'] ?? '');
        if ($typ === 'new' || $typ === 'end') {
            $this->SetValue('LetztesObjekt', FrigateHubTopics::eventText($after));
            $this->WriteAttributeString('LetzteEventId', (string)($after['id'] ?? ''));
        }
        $this->sensorEvent($typ, $after);
    }

    private function reviewVerarbeiten(string $payload): void
    {
        $r = json_decode($payload, true);
        $after = is_array($r) ? ($r['after'] ?? null) : null;
        if (!is_array($after) || (string)($after['camera'] ?? '') !== $this->kamera()) {
            return;
        }
        $this->zonenLernen(FrigateHubTopics::zonenAusReview($after));
        $this->eigeneObjekteMerken(array_map('strval', (array)($after['data']['detections'] ?? [])));
        $this->ereignisMerken($after);
        $typ = (string)($r['type'] ?? '');
        if ($typ === 'new' || $typ === 'update') {
            $text = FrigateHubTopics::reviewText($after);
            if ((string)($after['severity'] ?? '') === 'alert') {
                if ($typ === 'new') {
                    $this->sensorAlarm();
                }
                $this->SetValue('LetzterAlarm', $text);
                $this->alarmMelden((string)($after['id'] ?? ''), $text);
            } else {
                $this->SetValue('LetzteErkennung', $text);
            }
        }
        if ($typ === 'new' || $typ === 'update') {
            $this->ohaMelden((string)($after['id'] ?? ''), (string)($after['severity'] ?? ''));
        }
        // Nach dem Alarm-Push: die KI-Einschätzung kommt meist mit einem späteren update/end
        $ki = FrigateHubTopics::reviewKi($after);
        if ($ki !== null) {
            $this->kiVerarbeiten((string)($after['id'] ?? ''), $ki, FrigateHubTopics::zeit($after['start_time'] ?? null));
        }
    }

    /**
     * Gesicht, Kennzeichen, Klassifizierung, KI-Objektbeschreibung (frigate/tracked_object_update).
     * Das Topic ist kameraübergreifend: Gesicht/Kennzeichen/Klassifizierung tragen die Kamera, die
     * Beschreibung nur die Objekt-ID — die wird gegen die eigenen Objekte der letzten Zeit geprüft.
     */
    private function objektUpdateVerarbeiten(string $payload): void
    {
        $u = json_decode($payload, true);
        if (is_array($u)) {
            $this->sensorObjektUpdate($u);
        }
        $d = is_array($u) ? FrigateHubTopics::objektUpdate($u) : null;
        if ($d === null) {
            return;
        }
        $eigen = $d['kamera'] !== '' ? $d['kamera'] === $this->kamera()
            : in_array($d['id'], (array)json_decode($this->GetBuffer('EigeneObjekte'), true), true);
        if (!$eigen) {
            return;
        }
        $ziel = [
            'gesicht'         => ['ErkanntePerson', FrigateHubText::t('Recognized person'), 9],
            'kennzeichen'     => ['Kennzeichen', FrigateHubText::t('License plate'), 10],
            'klassifizierung' => ['Klassifizierung', FrigateHubText::t('Classification'), 11],
            'beschreibung'    => ['KIBeschreibung', FrigateHubText::t('AI description'), 12],
        ][$d['art']];
        $zeit = $d['zeit'] !== '' ? $d['zeit'] : date('d.m.Y H:i:s');
        $this->variableAnlegen($ziel[0], $ziel[1], VARIABLETYPE_STRING, self::anzeigeText(), $ziel[2]);
        $this->SetValue($ziel[0], FrigateHubTopics::kuerzenBytes($zeit . ' – ' . $d['text'], 1000));
    }

    /** Objekt-IDs dieser Kamera merken (für Beschreibungen ohne Kameraangabe), die letzten 100. */
    private function eigeneObjekteMerken(array $ids): void
    {
        $liste = (array)json_decode($this->GetBuffer('EigeneObjekte'), true);
        foreach ($ids as $id) {
            if (FrigateHubTopics::idGueltig($id) && !in_array($id, $liste, true)) {
                $liste[] = $id;
            }
        }
        $this->SetBuffer('EigeneObjekte', (string)json_encode(array_slice($liste, -100)));
    }

    /**
     * KI-Einschätzung eines Reviews: Variablen setzen; einmal je Review per Push nachreichen, wenn
     * der Alarm schon gemeldet wurde, oder melden, wenn die KI eine Erkennung verdächtig findet.
     */
    private function kiVerarbeiten(string $id, array $ki, string $zeit): void
    {
        $this->variableAnlegen('KIBeschreibung', FrigateHubText::t('AI description'), VARIABLETYPE_STRING, self::anzeigeText(), 12);
        $this->SetValue('KIBeschreibung', FrigateHubTopics::kuerzenBytes(($zeit !== '' ? $zeit : date('d.m.Y H:i:s')) . ' – '
            . ($ki['titel'] !== '' && $ki['titel'] !== $ki['zusammenfassung'] ? $ki['titel'] . ': ' : '') . $ki['zusammenfassung'], 1000));
        if ($ki['stufe'] !== null) {
            $this->variableAnlegen('KIStufe', FrigateHubText::t('AI assessment'), VARIABLETYPE_INTEGER, self::anzeigeStufen(FrigateHubTopics::kiStufen()), 13);
            $this->SetValue('KIStufe', $ki['stufe']);
        }
        if (!$this->ReadPropertyBoolean('PushKI') || $id === '' || $this->ReadAttributeString('LetzterKiPush') === $id || $this->pushZiele() === []) {
            return;
        }
        $gemeldet = (string)(json_decode($this->ReadAttributeString('LetzterPush'), true)['id'] ?? '') === $id;
        if ($gemeldet) {
            $titel = '🤖 ' . IPS_GetName($this->InstanceID);
        } elseif (($ki['stufe'] ?? 0) >= 1) {
            $titel = '⚠️ ' . IPS_GetName($this->InstanceID) . ': ' . FrigateHubTopics::kiStufen()[$ki['stufe']];
        } else {
            return;
        }
        $this->WriteAttributeString('LetzterKiPush', $id);
        $this->pushSenden($titel, $ki['zusammenfassung']);
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
        $this->pushSenden('🚨 ' . IPS_GetName($this->InstanceID), $text, true);
    }

    private function senden(string $topic, string $payload): bool
    {
        if (!$this->HasActiveParent()) {
            $this->sendefehler($topic, FrigateHubText::t('no active MQTT instance above'));
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
            $this->sendefehler($topic, FrigateHubText::t('the MQTT instance rejected the packet'));
            return false;
        }
        return true;
    }

    private function sendefehler(string $topic, string $grund): void
    {
        $this->LogMessage(FrigateHubText::t('Sending to Frigate failed (%s): %s', $topic, $grund), KL_ERROR);
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
            return FrigateHubText::t('ℹ️ No camera entered yet.');
        }
        if (!$this->HasActiveParent()) {
            return FrigateHubText::t('⚠️ No active MQTT instance above — the instance must be attached below Symcon\'s MQTT server or an MQTT client that Frigate sends to.');
        }
        $ts = $this->ReadAttributeInteger('LetzteNachricht');
        if ($ts === 0) {
            return FrigateHubText::t('ℹ️ No message from Frigate for "%s" yet. Are the camera ID and prefix correct, and does Frigate send to this MQTT server? Without a Frigate address, states only arrive on changes.', $this->kamera());
        }
        $anzahl = count((array)json_decode($this->GetBuffer('Topics'), true));
        return FrigateHubText::t('✅ Last message from Frigate on %s (%d different topics since the last restart).', date('d.m.Y H:i:s', $ts), $anzahl);
    }

    private function abgleichstatus(): string
    {
        if ($this->apiUrl() === '') {
            return trim((string)$this->ReadPropertyString('ApiUrl')) === ''
                ? FrigateHubText::t('ℹ️ No HTTP sync (no Frigate address entered).')
                : FrigateHubText::t('⚠️ The Frigate address is invalid — expected e.g. http://192.168.1.20:5000.');
        }
        $text = $this->ReadAttributeString('Abgleichstatus');
        return $text !== '' ? $text : FrigateHubText::t('ℹ️ Not synced yet — happens shortly after applying.');
    }

    private function bildstatus(): string
    {
        $text = $this->ReadAttributeString('Bildstatus');
        return $text !== '' ? FrigateHubText::t('✅ Last: %s.', $text) : FrigateHubText::t('ℹ️ No image received yet.');
    }

    private function zonenstatus(): string
    {
        $z = $this->zonen();
        if ($z === []) {
            return $this->apiUrl() !== '' && $this->ReadAttributeString('Abgleichstatus') !== ''
                ? FrigateHubText::t('ℹ️ According to the sync, this camera has no zones in Frigate.')
                : FrigateHubText::t('ℹ️ No zone learned yet. Zones appear with the sync or as soon as Frigate reports an object in a zone of this camera.');
        }
        return FrigateHubText::t('✅ %d zone(s) learned: %s', count($z), implode(', ', $z));
    }

    private function nachrichtenLabels(): array
    {
        $liste = json_decode($this->GetBuffer('Topics'), true);
        if (!is_array($liste) || $liste === []) {
            return [['type' => 'Label', 'caption' => FrigateHubText::t('ℹ️ Nothing received since the last restart.')]];
        }
        ksort($liste);
        $out = [['type' => 'Label', 'caption' => FrigateHubText::t('%d topics since the last restart (time, topic, content):', count($liste))]];
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

    /** Ganzzahl mit Klartext je Wert — bei Integer wirken nur INTERVALS (SUITE.md Stolperstein 9). */
    private static function anzeigeStufen(array $texte): array
    {
        $farben = [0 => 0x43A047, 1 => 0xFB8C00, 2 => 0xE53935];
        $intervalle = [];
        foreach ($texte as $wert => $text) {
            $intervalle[] = ['IntervalMinValue' => $wert, 'IntervalMaxValue' => $wert, 'ConstantActive' => true, 'ConstantValue' => $text,
                'ConversionFactor' => 1, 'PrefixActive' => false, 'SuffixActive' => false, 'DigitsActive' => false, 'IconActive' => false,
                'IconValue' => '', 'ColorActive' => isset($farben[$wert]), 'ColorValue' => $farben[$wert] ?? -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'INTERVALS_ACTIVE' => true, 'INTERVALS' => (string)json_encode($intervalle)];
    }

    private static function anzeigeText(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }
}
