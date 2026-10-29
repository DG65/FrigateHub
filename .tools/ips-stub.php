<?php

declare(strict_types=1);

/**
 * Minimaler IP-Symcon-Ersatz für die Prüfstände (übernommen aus CometWiFi, 28.10.2026).
 *
 * Bildet so viel nach, dass eine Modulklasse wirklich ausgeführt werden kann: Properties,
 * Attribute, Buffer, Variablen, Timer, Status. Kein Objektbaum, keine Persistenz.
 *
 * Anlass für diesen Aufwand: `php -l` findet nur Syntaxfehler. Die Fehler, die in diesem
 * Modul wehtun, sind Laufzeitfehler im Empfangspfad — falsch dekodierte Werte, ein Filter
 * der nie trifft, ein SetValue auf eine Variable, die es noch nicht gibt.
 */

define('IS_ACTIVE', 102);
define('KL_ERROR', 3);
define('KL_WARNING', 2);
define('KL_NOTIFY', 1);
define('KL_MESSAGE', 0);
define('IS_INACTIVE', 104);
define('VM_UPDATE', 10603);

define('VARIABLETYPE_BOOLEAN', 0);
define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT', 2);
define('VARIABLETYPE_STRING', 3);

define('VARIABLE_PRESENTATION_VALUE_PRESENTATION', 'VALUE_PRESENTATION');
define('VARIABLE_PRESENTATION_SLIDER', 'SLIDER');
define('VARIABLE_PRESENTATION_SWITCH', 'SWITCH');
define('VARIABLE_PRESENTATION_ENUMERATION', 'ENUMERATION');
define('VARIABLE_PRESENTATION_VALUE_INPUT', 'VALUE_INPUT');
define('MEDIATYPE_IMAGE', 1);
define('KR_READY', 10103);
define('IPS_KERNELMESSAGE', 10100);

/** Sammelt alles, was das Modul nach außen tut, damit Prüfungen es nachsehen können. */
class IPSTestState
{
    /** @var int[] Instanzen, für die IPS_ApplyChanges() aufgerufen wurde. */
    public static array $appliedChanges = [];
    public static array $setProperties  = [];
    public static int $runlevel = 10103;

    public static array $instances    = [];
    public static array $sentPackets  = [];
    public static array $debug        = [];
    public static array $logMessages  = [];

    /** Fremde Variablen anderer Instanzen: objectId => ['instance'=>int,'ident'=>string,'value'=>mixed] */
    public static array $objects = [];
    /** Zuletzt an die Visualisierung geschickte Nutzlast. */
    public static ?string $visualization = null;
    public static array $registeredMessages = [];

    /** Verknüpfungen: linkID => ['parent'=>int,'target'=>int,'name'=>string,'pos'=>int] */
    public static array $links = [];

    /** Medienobjekte: mediaID => ['type','parent','ident','name','pos','file','cached','content'(Base64)] */
    public static array $media = [];

    /** Übersetzungstabelle des geprüften Moduls, oder null zum Durchreichen. */
    public static ?array $locale = null;

    /** Lädt die locale.json eines Moduls, damit Translate() wirklich übersetzt. */
    public static function useLocale(string $modulVerzeichnis, string $sprache = 'de'): void
    {
        $datei = $modulVerzeichnis . '/locale.json';
        if (!is_file($datei)) {
            throw new RuntimeException('locale.json nicht gefunden: ' . $datei);
        }
        $json = json_decode((string) file_get_contents($datei), true);
        self::$locale = $json['translations'][$sprache] ?? [];
    }

    public static function reset(): void
    {
        self::$instances         = [];
        self::$appliedChanges    = [];
        self::$setProperties     = [];
        self::$sentPackets       = [];
        self::$debug             = [];
        self::$logMessages       = [];
        self::$objects           = [];
        self::$visualization     = null;
        self::$registeredMessages = [];
        self::$links = [];
        self::$media = [];
        // $locale bleibt bewusst stehen: Sie beschreibt das Modul, nicht den Prüffall.
    }

    /** Legt eine Variable an einer fremden Instanz an und gibt ihre Objekt-ID zurück. */
    public static function addObject(int $instanceId, string $ident, $value, string $name = '', int $updated = 0): int
    {
        $objectId = 10000 + count(self::$objects) + 1;
        self::$objects[$objectId] = ['instance' => $instanceId, 'ident' => $ident, 'value' => $value, 'name' => $name, 'updated' => $updated];
        return $objectId;
    }
}

function IPS_GetInstance(int $id): array
{
    return IPSTestState::$instances[$id] ?? [
        'InstanceID'     => $id,
        'ConnectionID'   => 0,
        'InstanceStatus' => IS_INACTIVE,
        'ModuleInfo'     => ['ModuleID' => '{TEST}']
    ];
}

function IPS_GetModule(string $guid): array
{
    return ['ModuleID' => $guid, 'LibraryID' => '{TESTLIB}'];
}

function IPS_GetLibrary(string $guid): array
{
    // Echte library.json lesen: eine fest eingetragene Version hier lief beim Versionssprung
    // still auseinander und ließ das News-Panel nach „Verstanden" stehen.
    $lib = json_decode((string)file_get_contents(__DIR__ . '/../library.json'), true);
    return ['LibraryID' => $guid, 'Version' => (string)($lib['version'] ?? ''), 'Build' => (int)($lib['build'] ?? 0)];
}

function IPS_LogMessage(string $sender, string $message): void
{
    IPSTestState::$logMessages[] = $sender . ': ' . $message;
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    $ids = [];
    foreach (IPSTestState::$instances as $id => $instance) {
        if (($instance['ModuleInfo']['ModuleID'] ?? '') === $guid) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Liefert false, wenn es den Ident nicht gibt — genau wie das Original. Die Module fragen
 * mit @ davor ab und prüfen auf false; ein Stub, der stattdessen 0 liefert, würde diesen
 * Pfad nie testen.
 */
function IPS_GetObjectIDByIdent(string $ident, int $parentId)
{
    foreach (IPSTestState::$objects as $objectId => $object) {
        if ($object['instance'] === $parentId && $object['ident'] === $ident) {
            return $objectId;
        }
    }
    foreach (IPSTestState::$media as $mediaId => $m) {
        if ($m['parent'] === $parentId && $m['ident'] === $ident) {
            return $mediaId;
        }
    }
    return false;
}

function GetValue(int $objectId)
{
    if (!isset(IPSTestState::$objects[$objectId])) {
        trigger_error('Variable ' . $objectId . ' existiert nicht', E_USER_WARNING);
        return null;
    }
    return IPSTestState::$objects[$objectId]['value'];
}

function SetValue(int $objectId, $value): void
{
    IPSTestState::$objects[$objectId]['value'] = $value;
}

function IPS_GetProperty(int $id, string $name)
{
    return IPSTestState::$instances[$id]['Properties'][$name] ?? null;
}

function IPS_CreateLink(): int
{
    $id = 80000 + count(IPSTestState::$links) + 1;
    IPSTestState::$links[$id] = ['parent' => 0, 'target' => 0, 'name' => '', 'pos' => 0];
    return $id;
}

function IPS_SetParent(int $id, int $parent): void
{
    if (isset(IPSTestState::$links[$id])) {
        IPSTestState::$links[$id]['parent'] = $parent;
    }
    if (isset(IPSTestState::$media[$id])) {
        IPSTestState::$media[$id]['parent'] = $parent;
    }
}

function IPS_CreateMedia(int $type): int
{
    $id = 90000 + count(IPSTestState::$media) + 1;
    IPSTestState::$media[$id] = ['type' => $type, 'parent' => 0, 'ident' => '', 'name' => '', 'pos' => 0,
        'file' => '', 'cached' => false, 'content' => null];
    return $id;
}

function IPS_SetIdent(int $id, string $ident): void
{
    if (isset(IPSTestState::$media[$id])) {
        IPSTestState::$media[$id]['ident'] = $ident;
    }
}

function IPS_SetMediaFile(int $id, string $file, bool $mustExist): void
{
    IPSTestState::$media[$id]['file'] = $file;
}

function IPS_SetMediaCached(int $id, bool $cached): void
{
    IPSTestState::$media[$id]['cached'] = $cached;
}

/** Wie das Original: ohne Dateinamen oder mit ungültigem Base64 schlägt es fehl. */
function IPS_GetMediaContent(int $id): string
{
    return (string)(IPSTestState::$media[$id]['content'] ?? '');
}

/** Symcon läuft (KR_READY), außer ein Prüffall setzt etwas anderes. */
function IPS_GetKernelRunlevel(): int
{
    return IPSTestState::$runlevel;
}

function IPS_SetMediaContent(int $id, string $base64): bool
{
    if (!isset(IPSTestState::$media[$id]) || IPSTestState::$media[$id]['file'] === ''
        || base64_decode($base64, true) === false) {
        return false;
    }
    IPSTestState::$media[$id]['content'] = $base64;
    return true;
}

function IPS_SetLinkTargetID(int $id, int $target): void
{
    IPSTestState::$links[$id]['target'] = $target;
}

function IPS_SetName(int $id, string $name): void
{
    if (isset(IPSTestState::$links[$id])) {
        IPSTestState::$links[$id]['name'] = $name;
    }
    if (isset(IPSTestState::$media[$id])) {
        IPSTestState::$media[$id]['name'] = $name;
    }
}

function IPS_SetPosition(int $id, int $position): void
{
    if (isset(IPSTestState::$links[$id])) {
        IPSTestState::$links[$id]['pos'] = $position;
    }
    if (isset(IPSTestState::$media[$id])) {
        IPSTestState::$media[$id]['pos'] = $position;
    }
}

function IPS_GetLink(int $id): array
{
    return ['LinkID' => $id, 'TargetID' => IPSTestState::$links[$id]['target'] ?? 0];
}

function IPS_DeleteLink(int $id): void
{
    unset(IPSTestState::$links[$id]);
}

function IPS_GetChildrenIDs(int $parent): array
{
    $kinder = [];
    foreach (IPSTestState::$objects as $id => $o) {
        if ($o['instance'] === $parent) {
            $kinder[] = $id;
        }
    }
    foreach (IPSTestState::$links as $id => $link) {
        if ($link['parent'] === $parent) {
            $kinder[] = $id;
        }
    }
    return $kinder;
}

/** Nur so viel Objektbaum, wie die Module wirklich abfragen: den Typ. 6 = Verknüpfung. */
function IPS_GetObject(int $id): array
{
    if (isset(IPSTestState::$links[$id])) {
        return ['ObjectID' => $id, 'ObjectType' => 6, 'ObjectName' => IPSTestState::$links[$id]['name']];
    }
    if (isset(IPSTestState::$instances[$id])) {
        return ['ObjectID' => $id, 'ObjectType' => 1, 'ObjectName' => IPSTestState::$instances[$id]['Name'] ?? ''];
    }
    $o = IPSTestState::$objects[$id] ?? [];
    return ['ObjectID' => $id, 'ObjectType' => 2, 'ObjectName' => $o['name'] ?? '', 'ObjectIdent' => $o['ident'] ?? ''];
}

function IPS_GetVariable(int $id): array
{
    return ['VariableID' => $id, 'VariableUpdated' => IPSTestState::$objects[$id]['updated'] ?? 0, 'VariableChanged' => IPSTestState::$objects[$id]['updated'] ?? 0];
}

/**
 * Nimmt den Aufruf entgegen und protokolliert ihn.
 *
 * **Bewusst eine Attrappe:** Der Stand kennt keine Objektreferenzen der Modulklassen, kann
 * die echte `ApplyChanges()` also nicht ausführen. Wer hier eine Wirkung prüfen will, ruft
 * die Methode direkt auf dem Objekt auf. Vorhanden ist die Funktion trotzdem, weil ein
 * Formularknopf sie aufruft und der Formular-Prüfstand sonst nicht unterscheiden könnte,
 * ob `IPS_ApplyChanges` eine Symcon-Kernfunktion oder ein verunglückter Modul-Wrapper ist.
 */
function IPS_SetProperty(int $id, string $name, $value): bool
{
    IPSTestState::$setProperties[] = [$id, $name, $value];
    return true;
}

function IPS_ApplyChanges(int $id): void
{
    IPSTestState::$appliedChanges[] = $id;
}

function IPS_InstanceExists(int $id): bool
{
    return isset(IPSTestState::$instances[$id]);
}

function IPS_GetName(int $id): string
{
    return IPSTestState::$instances[$id]['Name'] ?? ('Instanz ' . $id);
}

/**
 * Ersatz für IPSModule. Bewusst schlank — nur was die Module wirklich benutzen.
 */
abstract class IPSModule
{
    public int $InstanceID;

    protected array $properties = [];
    protected array $attributes = [];
    protected array $buffers    = [];

    /** ident => ['type'=>int,'caption'=>string,'presentation'=>mixed,'position'=>int,'value'=>mixed] */
    public array $variables = [];
    public array $enabledActions = [];
    public array $timers = [];
    public string $receiveFilter = '';
    public int $status = IS_INACTIVE;
    public array $formFieldUpdates = [];

    public function __construct(int $InstanceID)
    {
        $this->InstanceID = $InstanceID;
    }

    /* ------------------------------------------------------------ Lebenszyklus */

    public function Create()
    {
    }

    public function ApplyChanges()
    {
    }

    public function Destroy()
    {
    }

    /* -------------------------------------------------------------- Properties */

    protected function RegisterPropertyString(string $name, string $value): void
    {
        $this->properties[$name] = $value;
    }

    protected function RegisterPropertyInteger(string $name, int $value): void
    {
        $this->properties[$name] = $value;
    }

    protected function RegisterPropertyFloat(string $name, float $value): void
    {
        $this->properties[$name] = $value;
    }

    protected function RegisterPropertyBoolean(string $name, bool $value): void
    {
        $this->properties[$name] = $value;
    }

    public function ReadPropertyString(string $name): string
    {
        return (string) ($this->properties[$name] ?? '');
    }

    public function ReadPropertyInteger(string $name): int
    {
        return (int) ($this->properties[$name] ?? 0);
    }

    public function ReadPropertyFloat(string $name): float
    {
        return (float) ($this->properties[$name] ?? 0.0);
    }

    public function ReadPropertyBoolean(string $name): bool
    {
        return (bool) ($this->properties[$name] ?? false);
    }

    /** Nur für den Prüfstand: Property setzen wie im Formular. */
    /**
     * Legt eine Variable von außen an — für Prüfstände, die einen Altzustand herstellen
     * müssen (etwa eine verwaiste RAW_-Variable aus einer früheren Modulversion).
     */
    public function TEST_MaintainVariable(string $ident, string $caption, int $type, $presentation, int $position, bool $keep): void
    {
        $this->MaintainVariable($ident, $caption, $type, $presentation, $position, $keep);
    }

    /** Setzt eine Variable von außen — für Prüffälle, die einen Vorzustand brauchen. */
    public function TEST_SetValue(string $ident, $value): void
    {
        $this->SetValue($ident, $value);
    }

    public function TEST_SetProperty(string $name, $value): void
    {
        $this->properties[$name] = $value;
    }

    /* -------------------------------------------------------------- Attribute */

    protected function RegisterAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function RegisterAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function RegisterAttributeBoolean(string $name, bool $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function ReadAttributeString(string $name): string
    {
        return (string) ($this->attributes[$name] ?? '');
    }

    public function ReadAttributeInteger(string $name): int
    {
        return (int) ($this->attributes[$name] ?? 0);
    }

    public function ReadAttributeBoolean(string $name): bool
    {
        return (bool) ($this->attributes[$name] ?? false);
    }

    public function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function WriteAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function WriteAttributeBoolean(string $name, bool $value): void
    {
        $this->attributes[$name] = $value;
    }

    /* ----------------------------------------------------------------- Buffer */

    public function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }

    public function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    /* -------------------------------------------------------------- Variablen */

    protected function MaintainVariable(
        string $ident,
        string $caption,
        int $type,
        $presentation,
        int $position,
        bool $keep
    ): void {
        if (!$keep) {
            unset($this->variables[$ident]);
            return;
        }
        if (isset($this->variables[$ident])) {
            // Idempotenz: vorhandener Wert bleibt erhalten, nur die Beschreibung wird gepflegt.
            $this->variables[$ident]['caption']      = $caption;
            $this->variables[$ident]['type']         = $type;
            $this->variables[$ident]['presentation'] = $presentation;
            $this->variables[$ident]['position']     = $position;
            return;
        }
        $this->variables[$ident] = [
            'caption'      => $caption,
            'type'         => $type,
            'presentation' => $presentation,
            'position'     => $position,
            'value'        => $this->defaultForType($type)
        ];
    }

    private function defaultForType(int $type)
    {
        switch ($type) {
            case VARIABLETYPE_BOOLEAN: return false;
            case VARIABLETYPE_INTEGER: return 0;
            case VARIABLETYPE_FLOAT:   return 0.0;
            default:                   return '';
        }
    }

    protected function EnableAction(string $ident): void
    {
        if (!isset($this->variables[$ident])) {
            throw new Exception("EnableAction auf unbekannte Variable: {$ident}");
        }
        $this->enabledActions[$ident] = true;
    }

    public function SetValue(string $ident, $value): void
    {
        if (!isset($this->variables[$ident])) {
            throw new Exception("SetValue auf nicht angelegte Variable: {$ident}");
        }
        $this->variables[$ident]['value'] = $value;
    }

    public function GetValue(string $ident)
    {
        if (!isset($this->variables[$ident])) {
            throw new Exception("GetValue auf nicht angelegte Variable: {$ident}");
        }
        return $this->variables[$ident]['value'];
    }

    /* ------------------------------------------------------------------ Timer */

    protected function RegisterTimer(string $name, int $interval, string $script): void
    {
        $this->timers[$name] = ['interval' => $interval, 'script' => $script];
    }

    protected function SetTimerInterval(string $name, int $interval): void
    {
        if (!isset($this->timers[$name])) {
            throw new Exception("SetTimerInterval auf unbekannten Timer: {$name}");
        }
        $this->timers[$name]['interval'] = $interval;
    }

    /* ----------------------------------------------------------------- Status */

    protected function SetStatus(int $status): void
    {
        $this->status = $status;
    }

    public function GetStatus(): int
    {
        return $this->status;
    }

    /* -------------------------------------------------------- Kommunikation */

    protected function SetReceiveDataFilter(string $filter): void
    {
        $this->receiveFilter = $filter;
    }

    protected function SendDataToParent(string $data)
    {
        IPSTestState::$sentPackets[] = json_decode($data, true);
        return true;
    }

    protected function SendDebug(string $message, string $data, int $format): void
    {
        IPSTestState::$debug[] = $message . ': ' . $data;
    }

    /* --------------------------------------------------------------- Formular */

    protected function UpdateFormField(string $name, string $field, $value): void
    {
        $this->formFieldUpdates[] = [$name, $field, $value];
    }

    /**
     * Übersetzt über die echte locale.json des jeweiligen Moduls.
     *
     * Der Stub könnte den Quellstring einfach durchreichen — dann prüfte aber kein Test je,
     * ob es den Eintrag überhaupt gibt. Ein fehlender Eintrag fällt sonst erst beim Nutzer
     * auf, der plötzlich englische Variablennamen sieht. IPSTestState::$locale sagt, welche
     * Datei gilt; ohne Angabe wird durchgereicht wie zuvor.
     */
    protected function Translate(string $text): string
    {
        if (IPSTestState::$locale === null) {
            return $text;
        }
        return IPSTestState::$locale[$text] ?? $text;
    }

    /* ------------------------------------------------- Echte SDK-Methodennamen
     *
     * Diese Methoden benutzt unser Code teils gar nicht — sie stehen hier, damit eine
     * gleichnamige Eigenbau-Methode SOFORT auffällt statt erst auf dem Zielsystem.
     *
     * Anlass: Ein selbst geschriebenes `private function hasActiveParent()` kollidierte mit
     * dem geerbten `HasActiveParent()` (PHP vergleicht Methodennamen ohne Rücksicht auf
     * Groß-/Kleinschreibung) und verengte dessen Sichtbarkeit. Die Folge war ein Fatal Error
     * beim Laden, Symcon verwarf beide Module kommentarlos, und in der Konsole ließ sich
     * schlicht keine Instanz anlegen. Sichtbar war die Ursache nur in Symcons eigener
     * Logdatei. Die Sichtbarkeit (protected) ist hier entscheidend — sie ist es, die den
     * Fehler auslöst.
     */

    protected function HasActiveParent(): bool
    {
        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parentId === 0) {
            return false;
        }
        return (IPS_GetInstance($parentId)['InstanceStatus'] ?? 0) === IS_ACTIVE;
    }

    protected function ConnectParent(string $guid): void
    {
    }

    protected function ForceParent(string $guid): void
    {
    }

    protected function RequireParent(string $guid): void
    {
    }

    /** Wie das Original: false, wenn es den Ident nicht gibt (live bestätigt in ESPHomeHub). */
    protected function GetIDForIdent(string $ident)
    {
        return isset($this->variables[$ident]) ? crc32($ident) : false;
    }

    protected function MaintainAction(string $ident, bool $enable): void
    {
        if ($enable) {
            $this->enabledActions[$ident] = true;
        } else {
            unset($this->enabledActions[$ident]);
        }
    }

    protected function DisableAction(string $ident): void
    {
        unset($this->enabledActions[$ident]);
    }

    protected function SendDataToChildren(string $data)
    {
        return true;
    }

    protected function SetForwardDataFilter(string $filter): void
    {
    }

    protected function RegisterOnceTimer(string $name, string $script): void
    {
    }

    protected function RegisterMessage(int $senderId, int $message): void
    {
        IPSTestState::$registeredMessages[$senderId][$message] = true;
    }

    protected function UnregisterMessage(int $senderId, int $message): void
    {
        unset(IPSTestState::$registeredMessages[$senderId][$message]);
        if (empty(IPSTestState::$registeredMessages[$senderId])) {
            unset(IPSTestState::$registeredMessages[$senderId]);
        }
    }

    protected function GetMessageList(): array
    {
        $list = [];
        foreach (IPSTestState::$registeredMessages as $senderId => $messages) {
            $list[$senderId] = array_keys($messages);
        }
        return $list;
    }

    protected function SetVisualizationType(int $type): void
    {
    }

    protected function UpdateVisualizationValue(string $value): void
    {
        IPSTestState::$visualization = $value;
    }

    protected function RegisterReference(int $id): void
    {
    }

    protected function UnregisterReference(int $id): void
    {
    }

    protected function ReloadForm(): void
    {
    }

    protected function LogMessage(string $message, int $type): void
    {
        IPSTestState::$logMessages[] = $message;
    }
}
