<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller FrigateHub-Module nach der DG65-Formular-
// Konvention: „Wozu dieses Modul?" → „Neu bis Version" → „Dokumentation &
// Hilfe" → Fachpanels → Rückmeldungs-Hinweis → „Über dieses Modul".
//
// Trait mit Modul-Präfix im Namen: Klassen- und Trait-Namen sind in Symcon
// prozessweit global. Jedes Modul liefert über frigatehubPrefix() seinen Präfix,
// damit die Ack-Knöpfe die richtige globale Funktion aufrufen.
// ===========================================================================

trait FrigateHubFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $FRIGATEHUB_NEWS_VERSIONS = [
        '0.5.1' => [
            '• Steht vor Port 8971 versehentlich http:// statt https://, sagt die Instanz das jetzt klar und nennt die richtige Adresse.',
        ],
        '0.5.0' => [
            '• Neu: Anmeldung an Frigate über Port 8971 (https://…:8971). Benutzer und Passwort eintragen, „Anmelden" — das Passwort wird nicht gespeichert, nur Frigates Zugangsschlüssel, und der wird automatisch verlängert.',
            '• Instanzen mit derselben Frigate-Adresse teilen sich die Anmeldung: einmal im Konfigurator anmelden genügt für alle Kameras.',
        ],
        '0.4.0' => [
            '• Neu: Push bei Alarm. Im Panel „🔔 Push bei Alarm" eine oder mehrere Visualisierungen eintragen (WebFront oder Kachel-Visualisierung) — jeder neue Frigate-Alarm kommt einmal als Meldung aufs Handy, mit Zeit, Objekt und Zone.',
            '• Ton und Mindestabstand zwischen zwei Meldungen sind einstellbar, „Probemeldung senden" prüft die Zustellung.',
        ],
        '0.3.0' => [
            '• Neu: Frigate-Konfigurator. Unter dem MQTT-Server anlegen, Frigate-Adresse eintragen — er listet alle Kameras aus Frigate und legt sie mit einem Klick fertig eingerichtet an.',
        ],
        '0.2.1' => [
            '• Schnappschuss in voller Größe: Mit Frigate-Adresse holt die Instanz das Bild jeder Erkennung jetzt in Kameraauflösung statt des kleinen MQTT-Ausschnitts.',
        ],
        '0.2.0' => [
            '• Neu: Medienobjekt „Schnappschuss" — zeigt das Bild, das Frigate zu jeder Erkennung schickt. Funktioniert per MQTT, auch ohne Frigate-Adresse.',
            '• Mit Frigate-Adresse holt der Knopf „Aktuelles Kamerabild holen" (FRIG_BildHolen) das Livebild. Beim ersten Abgleich entsteht das Medienobjekt gleich mit, damit du es sofort verlinken kannst.',
        ],
        '0.1.1' => [
            '• Neu: Frigate-Adresse eintragen (z. B. http://192.168.1.20:5000) — dann holt die Instanz Schalter, Zonen und den Kamerazustand sofort über Frigates API, statt auf MQTT zu warten.',
            '• Masken heißen jetzt kurz „Bewegungsmaske 1" statt „Bewegungsmaske motion_mask_1". Aus 0.1.0 vorhandene Masken-Variablen bitte löschen, sie werden neu angelegt.',
            '• Manuell in Frigate ausgelöste Ereignisse heißen „manuell ausgelöst", Schnappschüsse erscheinen in „Empfangene Nachrichten" als Bild statt als Zeichensalat.',
        ],
        '0.1.0' => [
            '• Erstes Release: Frigate-Kameras über Symcons MQTT-Server — Objektzähler je Kamera und Zone, Bewegung, Alarm, letzte Ereignisse.',
            '• Erkennung, Aufnahme, Schnappschüsse, Masken und Zonen direkt aus Symcon schalten. Die Variable folgt der Bestätigung von Frigate.',
        ],
    ];

    private static $FRIGATEHUB_REPO_URL     = 'https://github.com/DG65/FrigateHub';
    private static $FRIGATEHUB_ISSUES_URL   = 'https://github.com/DG65/FrigateHub/issues';
    private static $FRIGATEHUB_LICENSE_URL  = 'https://github.com/DG65/FrigateHub/blob/beta/LICENSE';
    private static $FRIGATEHUB_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $FRIGATEHUB_LIBRARY_GUID = '{4EE3C963-19C2-4F9A-A21B-CFFDC371223D}';

    abstract protected function frigatehubPrefix(): string;

    /** Zweck-Text (2–3 Sätze) je Modul. */
    abstract protected function frigatehubZweck(): array;

    /** Inhalt des Doku-Panels je Modul (ohne Versionszeile). */
    abstract protected function frigatehubDoku(): array;

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich INSTALLIERTE Version (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->frigatehubInstallierteVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected static function frigatehubBasisVersion(string $version): string
    {
        return (string)preg_replace('/-.*$/', '', $version);
    }

    protected function frigatehubBibliothek(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$FRIGATEHUB_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function frigatehubInstallierteVersion(): string
    {
        $lib = $this->frigatehubBibliothek();
        if (!empty($lib['Version'])) {
            return self::frigatehubBasisVersion((string)$lib['Version']);
        }
        $keys = array_keys(self::$FRIGATEHUB_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formZweck(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach ($this->frigatehubZweck() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->frigatehubPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  Wozu dieses Modul?', 'items' => $items];
    }

    protected function formNeu(): ?array
    {
        $gesehen = $this->ReadAttributeString('SeenNews');
        if ($gesehen === '') {
            $gesehen = '0';
        }
        $offen = [];
        foreach (self::$FRIGATEHUB_NEWS_VERSIONS as $version => $zeilen) {
            if (version_compare((string)$version, $gesehen, '>')) {
                $offen[(string)$version] = $zeilen;
            }
        }
        if (count($offen) === 0) {
            return null;
        }
        uksort($offen, 'version_compare');
        $items = [];
        foreach ($offen as $version => $zeilen) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => $zeile];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->frigatehubPrefix() . '_AckNews($id);'];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => '🆕  Neu bis Version ' . end($keys), 'items' => $items];
    }

    protected function formDoku(): array
    {
        $lib = $this->frigatehubBibliothek();
        $ver = isset($lib['Version'])
            ? 'ℹ️ FrigateHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ FrigateHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->frigatehubDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Anleitung auf GitHub', 'onClick' => "echo '" . self::$FRIGATEHUB_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Dokumentation & Hilfe', 'items' => $items];
    }

    protected function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Noch kein Forum-Thread: Rückmeldungen vorerst über GitHub.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 FrigateHub ist neu. Läuft deine Kamera? Fehlt etwas? Schreib es gern als Meldung auf GitHub — mit Frigate-Version und den Topics aus dem Panel „Empfangene Nachrichten".'],
                ['type' => 'Button', 'caption' => 'Fehler auf GitHub melden', 'onClick' => "echo '" . self::$FRIGATEHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->frigatehubPrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::$FRIGATEHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::$FRIGATEHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    protected function frigatehubFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            $fachpanels,
            [$this->formRueckmeldung(), $this->formLizenz()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $aktionen, 'status' => $status]);
    }
}
