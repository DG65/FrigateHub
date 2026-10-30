<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FrigateHubTopics.php';
require_once __DIR__ . '/../libs/FrigateHubFormular.php';
require_once __DIR__ . '/../libs/FrigateHubApi.php';
require_once __DIR__ . '/../libs/FrigateHubWebhook.php';
require_once __DIR__ . '/../libs/FrigateHubKachel.php';
require_once __DIR__ . '/../libs/FrigateHubOha.php';

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

            case 'objektupdate':
                $this->objektUpdateVerarbeiten($payload);
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
            $this->UpdateFormField('Videostatus', 'caption', $this->videostatus());
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
        $bild = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/latest.jpg?' . FrigateHubTopics::hoehe(self::LIVEBILD_HOEHE) . $this->zeitstempel(), 'image/jpeg');
        if ($bild === null || FrigateHubTopics::bildBytes($bild) === null) {
            $text = '⛔ Kein Bild von Frigate (' . ($bild === null ? $this->apiFehler : 'Antwort ist kein JPEG') . ').';
        } else {
            $this->bildSetzen($bild, 'Livebild');
            $text = '✅ ' . $this->ReadAttributeString('Bildstatus');
        }
        $this->UpdateFormField('Bildstatus', 'caption', $text);
        return $text;
    }

    /** Probemeldung an alle eingetragenen Ziele. Rückgabe: Text fürs Formular. */
    public function PushTesten(): string
    {
        $text = $this->pushSenden('🔔 ' . IPS_GetName($this->InstanceID), 'Probemeldung von FrigateHub – so sieht ein Alarm aus.', true);
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
        $status = $this->pushSenden('📷 ' . IPS_GetName($this->InstanceID), $text !== '' ? $text : 'Meldung von FrigateHub', $Livebild);
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
            $this->LogMessage('OpenHomeAlarm: Bereich „' . $bereich . '" ' . $lage . ' → ' . implode(', ', array_map(fn ($i) => $i === 'Schalter_recordings' ? 'Aufnahme' : 'Objekterkennung', $idents))
                . ($an ? ' an' : ' aus'), KL_MESSAGE);
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
            $this->WriteAttributeString('OhaMeldestatus', '⛔ ' . date('d.m.Y H:i:s') . ': Quellen-ID ungültig.');
            return;
        }
        $this->WriteAttributeString('OhaGemeldet', $reviewId);
        try {
            $text = FrigateHubOha::ergebnisText((string)OHA_TriggerExternalAlarmEvent($id, $ereignis));
        } catch (Throwable $e) {
            $text = '⛔ abgelehnt: ' . $e->getMessage();
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
            return 'ℹ️ Dafür braucht es OpenHomeAlarm mit Alarmereignis-Schnittstelle und eine Quellen-ID.';
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
            return '⛔ Bereich „' . $bereich . '" ist nicht unscharf — ein Probeereignis würde einen echten Alarm auslösen. Bitte erst unscharf schalten.';
        }
        $ereignis = FrigateHubOha::ereignis($quelle, 'probe-' . time(), time());
        try {
            $text = FrigateHubOha::ergebnisText((string)OHA_TriggerExternalAlarmEvent($id, (string)$ereignis));
        } catch (Throwable $e) {
            $text = '⛔ abgelehnt: ' . $e->getMessage();
        }
        return 'Probeereignis an OpenHomeAlarm: ' . $text . (strpos($text, 'nicht scharf') !== false ? ' — der Weg funktioniert.' : '');
    }

    private function ohaQuellenStatus(): string
    {
        $quelle = trim($this->ReadPropertyString('OhaQuelle'));
        $id = $this->ohaInstanz();
        if ($quelle === '') {
            return 'ℹ️ Melden ist aus (keine Quellen-ID).';
        }
        if ($id === 0 || !function_exists('OHA_GetExternalAlarmEventApi')) {
            return 'ℹ️ Dieses OpenHomeAlarm hat noch keine Externe Alarmereignis-Schnittstelle (ab 1.423).';
        }
        try {
            $text = FrigateHubOha::quelleStatus(@OHA_GetExternalAlarmEventApi($id), $quelle)['text'];
        } catch (Throwable $e) {
            $text = '⚠️ ' . $e->getMessage();
        }
        $letzte = $this->ReadAttributeString('OhaMeldestatus');
        return $text . ($letzte !== '' ? ' Letzte Meldung: ' . $letzte : '');
    }

    /** Statuszeile und Bereiche fürs Formular. */
    private function ohaFormular(): array
    {
        $id = $this->ohaInstanz();
        $stand = $this->ohaStand();
        $bereich = trim($this->ReadPropertyString('OhaBereich'));
        $optionen = [['caption' => 'aus (keine Kopplung)', 'value' => '']];
        if ($id === 0) {
            $status = 'ℹ️ OpenHomeAlarm ist nicht installiert — dann gibt es hier nichts zu tun. FrigateHub arbeitet ohne es genauso.';
        } elseif ($stand === null || !$stand['ok']) {
            $status = '⚠️ OpenHomeAlarm #' . $id . ' gefunden, aber ' . ($stand['grund'] ?? 'OHA_GetControlState() fehlt') . '. Kopplung ist aus.';
        } else {
            $teile = [];
            foreach ($stand['bereiche'] as $b => $z) {
                $teile[] = $b . ' (' . FrigateHubOha::zustandText($z) . ')';
                $optionen[] = ['caption' => 'Bereich ' . $b, 'value' => (string)$b];
            }
            $status = '✅ OpenHomeAlarm #' . $id . ' „' . IPS_GetName($id) . '" gefunden — Bereiche: ' . (implode(', ', $teile) ?: 'keine');
        }
        if ($bereich !== '' && !in_array($bereich, array_column($optionen, 'value'), true)) {
            $optionen[] = ['caption' => 'Bereich ' . $bereich . ' (derzeit nicht gemeldet)', 'value' => $bereich];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🛡️  OpenHomeAlarm (optional)',
            'items' => [
                ['type' => 'Label', 'caption' => 'Optionale Zusammenarbeit mit der Alarmanlage OpenHomeAlarm (Burki24). Melder richtest du in OpenHomeAlarm ein (z. B. „Alarm" oder einen Zonen-Zähler dieser Kamera), Meldungen mit Bild über FRIG_MeldungMitSchnappschuss als Alarmaktion. Hier nur: was diese Kamera tut, wenn ein Bereich scharf oder unscharf wird.'],
                ['type' => 'Label', 'name' => 'OhaStatus', 'caption' => $status],
                ['type' => 'Select', 'name' => 'OhaBereich', 'caption' => 'Bereich', 'options' => $optionen],
                ['type' => 'Select', 'name' => 'OhaBeiScharf', 'caption' => 'Wenn scharf', 'options' => [
                    ['caption' => 'nichts tun', 'value' => 0],
                    ['caption' => 'Aufnahme einschalten', 'value' => 1],
                    ['caption' => 'Objekterkennung einschalten', 'value' => 2],
                    ['caption' => 'Aufnahme und Objekterkennung einschalten', 'value' => 3],
                ]],
                ['type' => 'CheckBox', 'name' => 'OhaBeiUnscharf', 'caption' => 'Beim Unscharfschalten wieder ausschalten'],
                ['type' => 'Label', 'caption' => 'Geschaltet wird nur beim tatsächlichen Wechsel (scharf erst nach der Ausgangsverzögerung), nicht beim Übernehmen. Frigate bestätigt den neuen Zustand, die Variablen folgen.'],
                ['type' => 'Label', 'caption' => 'Alarme melden (Externe Alarmereignis-Schnittstelle von OpenHomeAlarm): FrigateHub meldet jeden neuen Frigate-Alarm dieser Kamera als bestätigtes Ereignis. Ob daraus ein Alarm wird, entscheidet OpenHomeAlarm — nur wenn der Bereich der Quelle in einem freigegebenen Modus scharf ist. In OpenHomeAlarm dieselbe Quellen-ID eintragen.'],
                ['type' => 'ValidationTextBox', 'name' => 'OhaQuelle', 'caption' => 'Quellen-ID (leer = nicht melden), z. B. frigate-haustuer', 'validate' => '^$|^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'],
                ['type' => 'Select', 'name' => 'OhaMelden', 'caption' => 'Melden bei', 'options' => [
                    ['caption' => 'nichts', 'value' => 0],
                    ['caption' => 'Frigate-Alarm (Einstufung „Alarm")', 'value' => 1],
                    ['caption' => 'Frigate-Alarm und Erkennung', 'value' => 2],
                ]],
                ['type' => 'Label', 'name' => 'OhaQuellenStatus', 'caption' => $this->ohaQuellenStatus()],
                ['type' => 'Button', 'caption' => 'Verbindung prüfen (nur wenn unscharf)', 'onClick' => 'echo FRIG_OhaVerbindungPruefen($id);'],
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
        return '✅ ' . $anzahl . ' Zone(n) vergessen. Sie werden beim nächsten Ereignis in der Zone neu gelernt; vorhandene Variablen bleiben (Archiv).';
    }

    /* ==================================================================== Formular */

    /** Fachpanels mit festem Schlüssel (Reihenfolge = Standard) für „🪟 Formular: Aufbau“. */
    protected function frigatehubFachpanels(): array
    {
        $kamera = $this->kamera();
        return [
            'kamera' => [
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
            'schnappschuss' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🖼️  Schnappschuss',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Das Medienobjekt „Schnappschuss" zeigt das Bild der letzten Erkennung (Frigate: snapshots aktiviert). Mit Frigate-Adresse kommt es in voller Größe, sonst als Ausschnitt per MQTT (Größe in Frigate unter mqtt: height/crop). Mit Adresse lässt sich auch das aktuelle Kamerabild holen.'],
                    ['type' => 'Label', 'name' => 'Bildstatus', 'caption' => $this->bildstatus()],
                    ['type' => 'Button', 'caption' => 'Aktuelles Kamerabild holen', 'onClick' => 'echo FRIG_BildHolen($id);'],
                ],
            ],
            'meldungen' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🔔  Push und Meldungen',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Meldet jeden neuen Alarm dieser Kamera an die Ziele, einmal je Alarm. Was ein Alarm ist, legst du in Frigate fest (review: alerts: labels / required_zones). Ohne Ziel bleibt der Push aus.'],
                    ['type' => 'Label', 'caption' => 'Push-Nachrichten (Kachel-Visualisierung, WebFront) können kein Bild mitschicken; Antippen öffnet diese Kamera. Als SMTP-Instanz eingetragen, kommt die Meldung als E-Mail mit dem aktuellen Kamerabild (Rahmen um erkannte Objekte).'],
                    ['type' => 'List', 'name' => 'PushZiele', 'caption' => 'Ziele (Kachel-Visualisierung, WebFront oder E-Mail/SMTP)', 'add' => true, 'delete' => true, 'rowCount' => 3,
                        'columns' => [[
                            'caption' => 'Ziel', 'name' => 'Ziel', 'width' => 'auto', 'add' => 0,
                            'edit' => ['type' => 'SelectInstance', 'validModules' => [self::KACHEL_GUID, self::WEBFRONT_GUID, self::SMTP_GUID]],
                        ]],
                    ],
                    ['type' => 'Select', 'name' => 'PushTon', 'caption' => 'Ton', 'options' => $this->tonOptionen()],
                    ['type' => 'NumberSpinner', 'name' => 'PushPause', 'caption' => 'Mindestabstand zwischen zwei Meldungen', 'suffix' => 's', 'minimum' => 0, 'maximum' => 3600],
                    ['type' => 'CheckBox', 'name' => 'PushKI', 'caption' => 'KI-Einschätzung von Frigate (GenAI) zum Alarm nachreichen und verdächtige Erkennungen melden'],
                    ['type' => 'Label', 'name' => 'Pushstatus', 'caption' => $this->pushstatus()],
                    ['type' => 'Button', 'caption' => 'Probemeldung senden', 'onClick' => 'echo FRIG_PushTesten($id);'],
                    ['type' => 'Label', 'caption' => 'Für Alarmanlagen und Regeln (z. B. OpenHomeAlarm, DG65-Toolkit Automat, Skripte): FRIG_MeldungMitSchnappschuss($id, "Text", true) schickt eine Meldung mit dem aktuellen Kamerabild an dieselben Ziele — in Symcon-Aktionen als „Instanzfunktion ausführen". Leerer Text: „Letztes Objekt"; false statt true: letzter Schnappschuss statt Livebild.'],
                ],
            ],
            'kachel' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🖥️  Kachel und IPSView',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Diese Instanz ist zugleich eine Kachel für die Kachel-Visualisierung: Livebild, Alarm, Zähler, die letzten Ereignisse mit Vorschaubild, Schnappschuss und Clip per Tipp sowie Schalter für Erkennung, Aufnahme und Schnappschüsse. Livebild, Vorschaubilder und Clips brauchen die Frigate-Adresse.'],
                    ['type' => 'NumberSpinner', 'name' => 'KachelLiveTakt', 'caption' => 'Livebild neu laden alle (0 = nur auf Tipp)', 'suffix' => 's', 'minimum' => 0, 'maximum' => 60],
                    ['type' => 'CheckBox', 'name' => 'KachelRahmen', 'caption' => 'Erkannte Objekte im Livebild einrahmen'],
                    ['type' => 'CheckBox', 'name' => 'Zeitstempel', 'caption' => 'Frigates Zeitstempel ins Livebild (Kachel, Meldungsbild, „Aktuelles Kamerabild holen") — aus, wenn die Kamera selbst schon die Uhrzeit einblendet'],
                    ['type' => 'CheckBox', 'name' => 'KachelVideo', 'caption' => 'Livevideo, wenn Frigates go2rtc einen Stream für die Kamera hat'],
                    ['type' => 'Label', 'name' => 'Videostatus', 'caption' => $this->videostatus()],
                    ['type' => 'Label', 'caption' => 'Die Bilder laufen über den WebHook ' . $this->hookPfad() . ' mit einem geheimen Schlüssel dieser Instanz. Wurde eine Bildadresse weitergegeben, macht ein neuer Schlüssel sie ungültig.'],
                    ['type' => 'Label', 'caption' => 'IPSView oder Browser: Dieselbe Oberfläche gibt es als eigenständige Seite. In IPSView ein Webseiten-Element (WebView) anlegen und die Adresse von „Adresse für IPSView anzeigen" eintragen (<Symcon-IP> durch die Adresse des Symcon-Servers ersetzen, 3777 ist Symcons Standard-Port).'],
                    ['type' => 'Button', 'caption' => 'Adresse für IPSView anzeigen', 'onClick' => 'echo FRIG_SeiteAdresse($id);'],
                    ['type' => 'CheckBox', 'name' => 'SeiteBedienung', 'caption' => 'Schalter (Erkennung, Aufnahme, Schnappschüsse) auf der eigenständigen Seite bedienbar'],
                    ['type' => 'Label', 'caption' => '⚠️ Die Seite ist ohne Symcon-Anmeldung erreichbar: Wer die Adresse mit Schlüssel kennt, sieht die Kamera. Adresse nicht weitergeben; im Zweifel den Schlüssel erneuern (danach in IPSView die neue Adresse eintragen). Alternativ die Variablen der Instanz direkt in IPSView verwenden, dann gilt die IPSView-Anmeldung.'],
                    ['type' => 'Button', 'caption' => 'Kachel-Schlüssel erneuern', 'onClick' => 'echo FRIG_KachelSchluesselErneuern($id);'],
                ],
            ],
            'openhomealarm' => $this->ohaFormular(),
            'zonen' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🗺️  Zonen',
                'items' => [
                    ['type' => 'Label', 'caption' => $this->zonenstatus()],
                    ['type' => 'Button', 'caption' => 'Gelernte Zonen vergessen', 'onClick' => 'echo FRIG_ZonenVergessen($id);'],
                ],
            ],
            'nachrichten' => [
                'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📨  Empfangene Nachrichten',
                'items' => $this->nachrichtenLabels(),
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Bereit'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte die Kamera-ID aus Frigate eintragen'],
        ];
        return $this->frigatehubFormular($this->formAufbau($this->frigatehubFachpanels()), [], $status);
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
        if ($this->mediumSchreiben('Schnappschuss', 'Schnappschuss', 9, '', $jpeg) === 0) {
            return;
        }
        $this->WriteAttributeString('Bildstatus', $quelle . ' vom ' . date('d.m.Y H:i:s') . ' Uhr (' . round(strlen($jpeg) / 1024) . ' KB)');
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
            $this->LogMessage($name . ' konnte nicht gespeichert werden (Medienobjekt ' . $id . ')', KL_ERROR);
            return 0;
        }
        return $id;
    }

    /**
     * Bild für eine Meldung: mit Frigate-Adresse das aktuelle Kamerabild (mit Rahmen um erkannte
     * Objekte) im eigenen Medienobjekt „Meldungsbild" — so bleibt der Erkennungs-Schnappschuss
     * unangetastet. Sonst (oder wenn das nicht klappt) der letzte Schnappschuss. 0 = kein Bild.
     */
    private function meldungsbild(bool $livebild): int
    {
        if ($livebild && $this->kamera() !== '' && $this->apiUrl() !== '') {
            $bild = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/latest.jpg?' . FrigateHubTopics::hoehe(1080) . '&bbox=1' . $this->zeitstempel(), 'image/jpeg');
            if ($bild !== null && FrigateHubTopics::bildBytes($bild) !== null) {
                $id = $this->mediumSchreiben('Meldungsbild', 'Meldungsbild', 10, '_meldung', $bild);
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

    /** Einstellbare Zahl (Bewegungsschwelle …) anlegen und setzen; Bedienung per Schieberegler. */
    private function zahlSetzen(string $schluessel, int $wert): void
    {
        $z = FrigateHubTopics::ZAHLEN[$schluessel];
        $this->variableAnlegen($z['ident'], $z['name'], VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN' => $z['min'], 'MAX' => $z['max'], 'STEP_SIZE' => 1, 'DIGITS' => 0], self::POS_SCHALTER + 50, true);
        $this->SetValue($z['ident'], $wert);
    }

    /** PTZ-Befehl an Frigate (frigate/<kamera>/ptz). */
    private function ptzSenden(string $befehl): void
    {
        $topic = FrigateHubTopics::praefix($this->ReadPropertyString('TopicPrefix')) . '/' . $this->kamera() . '/ptz';
        if (!$this->senden($topic, $befehl)) {
            throw new Exception('Keine aktive MQTT-Instanz — PTZ-Befehl nicht gesendet.');
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
            'gesicht'         => ['ErkanntePerson', 'Erkannte Person', 9],
            'kennzeichen'     => ['Kennzeichen', 'Kennzeichen', 10],
            'klassifizierung' => ['Klassifizierung', 'Klassifizierung', 11],
            'beschreibung'    => ['KIBeschreibung', 'KI-Beschreibung', 12],
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
        $this->variableAnlegen('KIBeschreibung', 'KI-Beschreibung', VARIABLETYPE_STRING, self::anzeigeText(), 12);
        $this->SetValue('KIBeschreibung', FrigateHubTopics::kuerzenBytes(($zeit !== '' ? $zeit : date('d.m.Y H:i:s')) . ' – '
            . ($ki['titel'] !== '' && $ki['titel'] !== $ki['zusammenfassung'] ? $ki['titel'] . ': ' : '') . $ki['zusammenfassung'], 1000));
        if ($ki['stufe'] !== null) {
            $this->variableAnlegen('KIStufe', 'KI-Einschätzung', VARIABLETYPE_INTEGER, self::anzeigeStufen(FrigateHubTopics::KI_STUFEN), 13);
            $this->SetValue('KIStufe', $ki['stufe']);
        }
        if (!$this->ReadPropertyBoolean('PushKI') || $id === '' || $this->ReadAttributeString('LetzterKiPush') === $id || $this->pushZiele() === []) {
            return;
        }
        $gemeldet = (string)(json_decode($this->ReadAttributeString('LetzterPush'), true)['id'] ?? '') === $id;
        if ($gemeldet) {
            $titel = '🤖 ' . IPS_GetName($this->InstanceID);
        } elseif (($ki['stufe'] ?? 0) >= 1) {
            $titel = '⚠️ ' . IPS_GetName($this->InstanceID) . ': ' . FrigateHubTopics::KI_STUFEN[$ki['stufe']];
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

    /**
     * An alle Ziele senden; Ergebnis landet im Attribut Pushstatus. Rückgabe: derselbe Text.
     * SMTP-Ziele bekommen eine E-Mail mit Bild ($livebild: aktuelles Kamerabild, sonst letzter
     * Schnappschuss) — das Bild wird nur geholt, wenn ein SMTP-Ziel eingetragen ist.
     */
    private function pushSenden(string $titel, string $text, bool $livebild = false): string
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
        $bild = null;   // Meldungsbild nur einmal je Meldung holen
        $ok = [];
        $fehler = [];
        foreach ($ziele as $ziel) {
            $name = IPS_InstanceExists($ziel) ? IPS_GetName($ziel) : ('#' . $ziel);
            $modul = IPS_InstanceExists($ziel) ? (string)(IPS_GetInstance($ziel)['ModuleInfo']['ModuleID'] ?? '') : '';
            if ($modul === self::KACHEL_GUID && function_exists('VISU_PostNotificationEx')) {
                // Ex-Fassung: die einfache VISU_PostNotification lieferte live immer false (Stolperstein 22).
                // Ziel = diese Instanz: Antippen der Meldung öffnet die Kamera-Kachel.
                $gesendet = @VISU_PostNotificationEx($ziel, $titel, $text, 'Alert', $ton, $this->InstanceID);
                if ($gesendet === false) {
                    // Rückfall: ohne Ziel-Objekt (so live bestätigt 29.10.2026)
                    $gesendet = @VISU_PostNotificationEx($ziel, $titel, $text, 'Alert', $ton, 0);
                }
            } elseif ($modul === self::SMTP_GUID && function_exists('SMTP_SendMailMedia')) {
                $bild = $bild ?? $this->meldungsbild($livebild);
                $gesendet = $bild > 0 ? @SMTP_SendMailMedia($ziel, $titel, $text, $bild) : @SMTP_SendMail($ziel, $titel, $text . "\n\n(Kein Bild verfügbar.)");
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
