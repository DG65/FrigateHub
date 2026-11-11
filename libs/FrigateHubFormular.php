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
    // Bitte um Rückmeldung je Version, steht in „Was ist neu" mit Forums-Knopf (0.20.1). Der Kasten erscheint
    // nach jedem Update wieder — anders als der Rückmeldungs-Hinweis, der nach „Verstanden" für immer weg ist.
    // Gezeigt wird die Bitte der neuesten ungesehenen Version, sonst die allgemeine.
    private static $FRIGATEHUB_NEWS_BITTE = [
        '0.23.0' => '💬 Does your camera have a white light, or did you connect a yard light? Please tell me in the forum whether “Light on detection” switches reliably — and which camera you use.',
        '0.22.0' => '💬 Have you tried the film by e-mail? Please tell me in the forum whether GIF or clip arrives and plays in your mail program — and how long it took.',
        '0.21.0' => '💬 Have you switched on “Person lying / fall”? Please tell me in the forum which camera watches which area and whether it reported correctly — false alarms too.',
        '0.20.0' => '💬 Does one of your cameras see a mowing or vacuum robot? Please tell me in the forum whether the false alerts are gone — and whether every real alert still comes through.',
    ];
    private static $FRIGATEHUB_NEWS_BITTE_ALLGEMEIN = '💬 Is FrigateHub working for you? Questions, ideas and problems are welcome in the forum thread — even a short note helps.';

    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $FRIGATEHUB_NEWS_VERSIONS = [
        '0.23.0' => [
            '• 💡 New: light on detection (panel “Light on detection” of every camera instance) — switches the camera\'s own white light (Hikvision/Annke) or any Symcon variable on when Frigate detects a person, and back after the run-on time. Only in the dark by default; off by default.',
        ],
        '0.22.0' => [
            '• 🎞 New: film after the photo (panel “Push and notifications”) — e-mail targets can additionally receive a short moving image (GIF) or video clip (MP4) of the alert, also for “Person lying / fall”. Off by default.',
        ],
        '0.21.0' => [
            '• 🆘 New: person lying / fall (panel “Person lying / fall” of every camera instance) — reports when a person lies on the ground and no longer moves, e.g. after a fall on the stairs or from a ladder. A fall just before or a call for help shortens the waiting time. A hint, not an emergency call system; off by default.',
        ],
        '0.20.1' => [
            '• 💬 “What\'s new” now links to the forum thread.',
        ],
        '0.20.0' => [
            '• 🤖 New: robots in the image (panel “Robots in the image” of every camera instance) — a mowing or vacuum robot no longer triggers false alerts while it is out of its station. Works with every robot from RoboHub and with Frigate+ (object “robot_lawnmower”). An upright person, a recognized face and vehicles always alert. Off by default.',
        ],
        '0.19.0' => [
            '• 🎞 New: an event in the tile plays the whole period with all objects (previously only the clip of the first object), with the course per object as in Frigate below it — tap an entry to jump to that moment in the video.',
            '• 🔧 Image check without false alarms at dusk: “moved” is only judged in daylight and only by the pattern (brightness, contrast and infrared no longer count), also against the neighbouring hours.',
            '• ⚙️ New in “Configure in Frigate”: what is kept of alerts and detections — everything, only with motion, or while an object is active.',
        ],
        '0.18.0' => [
            '• 🌍 New: FrigateHub speaks English. The module follows the language of your Symcon installation (forms, variables, notifications, tiles); existing variables keep their names.',
        ],
        '0.17.1' => [
            '• 🪟 “Configure in Frigate” and “Sign in to Frigate” now sit with the other settings (via “Form: layout”) instead of at the very bottom; position and expanding work like any other panel.',
        ],
        '0.17.0' => [
            '• ⚙️ New: change the camera\'s Frigate settings directly in Symcon (in the configuration form of every camera instance, panel “Configure in Frigate”): retention, detected objects, what counts as an alert, zones (which objects, loitering time), snapshots, stationary objects, license plates, faces, sounds. Frigate applies the changes immediately.',
        ],
        '0.16.0' => [
            '• 🧠 New: camera as sensor (in the configuration form of every camera instance, panel “Camera as sensor”): states from Frigate\'s classification (gate open/closed …), “car parked since” per zone, “loitering” per zone, package removed, recognized and unknown faces and license plates, speed, trigger, spoken text, daily counters.',
            '• 🔧 New: image check — reports a covered, dirty or misaligned camera and constant motion.',
            '• Frigate server: show and switch Frigate profiles; FRIGS_GetCameras() now also returns loitering, tampering and faults for alarm systems (contract 1.1).',
        ],
        '0.15.1' => [
            '• Tile: date and time as well as the archive button are now shown large at the top right. With live video the clock keeps running (“Live”); with a still image it shows when the image was taken.',
        ],
        '0.15.0' => [
            '• 🎥 New: recording watchdog in the “Frigate server” instance — reports when Frigate stops recording although it is “running”: write errors (e.g. a disconnected USB drive), changed recording storage, alert without recording, stalled continuous recording, Frigate not responding. Needs the Frigate address; enter notification targets in the new panel.',
        ],
        '0.14.0' => [
            '• 🪟 New: choose the form layout yourself — in the panel “🪟 Form: layout”, set each section to expanded, collapsed or hidden, and set the order. Hidden sections keep working.',
        ],
        '0.13.0' => [
            '• New: report Frigate alerts to the OpenHomeAlarm alarm system (from its version 1.423) — panel “🛡️ OpenHomeAlarm (optional)”, enter the source ID. Also there: switch on recording and detection when arming.',
        ],
        '0.11.0' => [
            '• New: notification with camera image. Enter an SMTP instance as a target and every alert arrives as an email with an image; tapping a push notification opens the camera. FRIG_MeldungMitSchnappschuss() for alarm systems and rules.',
        ],
        '0.10.0' => [
            '• New in the tile: 📅 archive by day with filter, recording from a time of day, unseen events, ☆ keep permanently, 💾 export, warnings and PTZ control pad.',
            '• New: faces, license plates and AI description/assessment from Frigate as variables (if set up in Frigate), motion threshold as a slider.',
        ],
        '0.9.0' => [
            '• New: “Frigate server” instance — reachability, version, free storage, faulty cameras, overview in the configuration form and as a tile of all cameras. Create it in the configurator as well.',
        ],
        '0.8.0' => [
            '• New: the tile is also available as a standalone page for IPSView — address in the panel “🖥️ Tile and IPSView”.',
        ],
        '0.7.0' => [
            '• New: live video in the tile. If Frigate\'s go2rtc has a stream for the camera, the tile plays real video with sound (WebRTC, on the home network) instead of the still image. Setup in Frigate: see the documentation panel and README.',
            '• Tapping the live video enlarges it, 🔇/🔊 toggles the sound. On the go or without a stream, the still image remains.',
        ],
        '0.6.0' => [
            '• New: the Frigate camera is now also a tile for the tile visualization — live image with detection boxes, alert frame, counters and the latest events with thumbnails.',
            '• Tapping an event shows snapshot and clip, tapping the live image enlarges it. Detection, recording and snapshots can be switched directly in the tile.',
            '• Images are served through a secured WebHook of the instance, even if the browser cannot reach Frigate itself. Settings in the panel “🖥️ Tile”.',
        ],
        '0.5.1' => [
            '• If port 8971 is accidentally preceded by http:// instead of https://, the instance now says so clearly and gives the correct address.',
        ],
        '0.5.0' => [
            '• New: sign in to Frigate via port 8971 (https://…:8971). Enter user and password, “Sign in” — the password is not stored, only Frigate\'s access token, which is renewed automatically.',
            '• Instances with the same Frigate address share the sign-in: signing in once in the configurator is enough for all cameras.',
        ],
        '0.4.0' => [
            '• New: push on alert. Enter one or more visualizations in the panel “🔔 Push on alert” (WebFront or tile visualization) — every new Frigate alert arrives once as a notification on your phone, with time, object and zone.',
            '• Sound and minimum interval between two notifications are adjustable, “Send test notification” checks delivery.',
        ],
        '0.3.0' => [
            '• New: Frigate configurator. Create it under the MQTT server and enter the Frigate address — it lists all cameras from Frigate and creates them fully set up with one click.',
        ],
        '0.2.1' => [
            '• Full-size snapshot: with a Frigate address, the instance now fetches the image of every detection in camera resolution instead of the small MQTT crop.',
        ],
        '0.2.0' => [
            '• New: media object “Snapshot” — shows the image Frigate sends with every detection. Works via MQTT, even without a Frigate address.',
            '• With a Frigate address, the button “Fetch current camera image” (FRIG_BildHolen) fetches the live image. The media object is created right away during the first synchronization, so you can link it immediately.',
        ],
        '0.1.1' => [
            '• New: enter the Frigate address (e.g. http://192.168.1.20:5000) — the instance then fetches switches, zones and the camera state immediately via Frigate\'s API instead of waiting for MQTT.',
            '• Masks are now simply called “Motion mask 1” instead of “Motion mask motion_mask_1”. Please delete existing mask variables from 0.1.0, they will be created again.',
            '• Events triggered manually in Frigate are called “triggered manually”, snapshots appear in “Received messages” as an image instead of garbled characters.',
        ],
        '0.1.0' => [
            '• First release: Frigate cameras via Symcon\'s MQTT server — object counters per camera and zone, motion, alert, latest events.',
            '• Switch detection, recording, snapshots, masks and zones directly from Symcon. The variable follows Frigate\'s confirmation.',
        ],
    ];

    private static $FRIGATEHUB_REPO_URL     = 'https://github.com/DG65/FrigateHub';
    private static $FRIGATEHUB_ISSUES_URL   = 'https://github.com/DG65/FrigateHub/issues';
    private static $FRIGATEHUB_FORUM_URL    = 'https://community.symcon.de/t/144813';
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
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->frigatehubPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  What is this module for?', 'items' => $items];
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
            $items[] = ['type' => 'Label', 'caption' => FrigateHubText::t('Version %s:', $version)];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => FrigateHubText::wert($zeile)];
            }
        }
        $bitte = self::$FRIGATEHUB_NEWS_BITTE_ALLGEMEIN;
        foreach (array_reverse(array_keys($offen)) as $version) {
            if (isset(self::$FRIGATEHUB_NEWS_BITTE[$version])) {
                $bitte = self::$FRIGATEHUB_NEWS_BITTE[$version];
                break;
            }
        }
        $items[] = ['type' => 'Label', 'caption' => FrigateHubText::wert($bitte)];
        $items[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::$FRIGATEHUB_FORUM_URL . "';", 'link' => true],
            ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->frigatehubPrefix() . '_AckNews($id);'],
        ]];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => FrigateHubText::t('🆕  New up to version %s', end($keys)), 'items' => $items];
    }

    protected function formDoku(): array
    {
        $lib = $this->frigatehubBibliothek();
        $ver = isset($lib['Version'])
            ? FrigateHubText::t('ℹ️ FrigateHub version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ FrigateHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->frigatehubDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Guide on GitHub', 'onClick' => "echo '" . self::$FRIGATEHUB_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Documentation & help', 'items' => $items];
    }

    protected function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 FrigateHub is new. Is your camera working? Is something missing? Feel free to post in the Symcon forum — with Frigate version, number of cameras and an excerpt from the panel “Received messages”.'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::$FRIGATEHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->frigatehubPrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own installation — and a few evenings spent typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::$FRIGATEHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and would like to leave something anyway? I appreciate a small donation — completely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::$FRIGATEHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /* ------------------------------------------------------------ 🪟 Formular: Aufbau (SUITE.md 3a) */

    /** In Create() aufrufen (nur Module mit mehr als zwei Fachpanels). */
    protected function formAufbauRegistrieren(): void
    {
        $this->RegisterPropertyString('FormLayout', '[]');
    }

    /**
     * Reihenfolge und Zustand der Fachpanels aus „FormLayout" (Referenz Batteriewächter formLayout()).
     * Unbekannte oder fehlende Schlüssel fallen auf Standard und Standardposition zurück.
     *
     * @param string[] $schluessel Fachpanel-Schlüssel in Standardreihenfolge
     * @return array<string,array{open:string,pos:int,idx:int}> in anzuwendender Reihenfolge
     */
    protected function formAufbauLesen(array $schluessel): array
    {
        $gespeichert = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $nach = [];
        foreach (is_array($gespeichert) ? $gespeichert : [] as $zeile) {
            if (is_array($zeile) && isset($zeile['Key'])) {
                $nach[(string)$zeile['Key']] = $zeile;
            }
        }
        $out = [];
        foreach (array_values($schluessel) as $i => $k) {
            $z = $nach[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt; kein Haken = Standard
            $open = !empty($z['Hidden']) ? 'hidden' : (!empty($z['Closed']) ? 'closed' : (!empty($z['Opened']) ? 'open' : 'std'));
            $out[$k] = ['open' => $open, 'pos' => isset($z['Pos']) ? max(1, min(99, (int)$z['Pos'])) : $i + 1, 'idx' => $i];
        }
        // Stabile Sortierung: bei gleicher Position bleibt die Standardreihenfolge
        uasort($out, fn ($a, $b) => [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]);
        return $out;
    }

    /**
     * Fachpanels nach „FormLayout" anordnen, auf-/zuklappen bzw. ausblenden (visible:false, nie
     * weglassen — ihre Werte bleiben beim Übernehmen erhalten) und „🪟 Formular: Aufbau" anhängen.
     *
     * @param array<string,array> $fach Schlüssel → Panel, Standardreihenfolge
     */
    protected function formAufbau(array $fach): array
    {
        return $this->formAufbauTeilen($fach)['elements'];
    }

    /**
     * Wie formAufbau(), aber Fachpanels mit '_aktionen' => true (Eingabefelder ohne Property, SUITE.md
     * 3a) landen in `actions`. Das Aufbau-Panel steuert beide; die Position gilt je Bereich.
     *
     * @return array{elements:array,actions:array}
     */
    protected function formAufbauTeilen(array $fach): array
    {
        $aufbau = $this->formAufbauLesen(array_keys($fach));
        $elements = [];
        $actions = [];
        foreach ($aufbau as $k => $a) {
            $panel = $fach[$k];
            $unten = !empty($panel['_aktionen']);
            unset($panel['_aktionen']);
            if ($a['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($a['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($a['open'] === 'hidden') {
                $panel['visible'] = false;
            }
            if ($unten) {
                $actions[] = $panel;
            } else {
                $elements[] = $panel;
            }
        }
        $elements[] = $this->formAufbauPanel($aufbau, $fach);
        return ['elements' => $elements, 'actions' => $actions];
    }

    /** Name eines Fachpanels für die Liste: Caption ohne Klammerzusatz. */
    protected static function formAufbauName(array $panel, string $k): string
    {
        // Listenwerte übersetzt die Konsole nicht (nur feste Beschriftungen): die Caption hier selbst übersetzen
        $name = trim((string)preg_replace('/\s*\(.*$/u', '', FrigateHubText::t((string)($panel['caption'] ?? $k))));
        return !empty($panel['_aktionen']) ? FrigateHubText::t('%s (below with the actions)', $name) : $name;
    }

    private function formAufbauPanel(array $aufbau, array $fach): array
    {
        $zeilen = [];
        $aus = [];
        foreach ($aufbau as $k => $a) {
            $name = self::formAufbauName($fach[$k], $k);
            $zeilen[] = ['Key' => $k, 'Panel' => $name, 'Opened' => $a['open'] === 'open', 'Closed' => $a['open'] === 'closed',
                'Hidden' => $a['open'] === 'hidden', 'Pos' => $a['pos']];
            if ($a['open'] === 'hidden') {
                $aus[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $ausZeile = $aus === [] ? [] : [['type' => 'Label', 'caption' => FrigateHubText::t('🙈 Hidden: %s — untick “Hidden” to show it again. Hidden sections keep working, only the display is missing.', implode(', ', $aus))]];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $aus === [] ? FrigateHubText::t('🪟  Form: layout') : FrigateHubText::t('🪟  Form: layout (%d hidden)', count($aus)),
            'items' => array_merge($ausZeile, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the configuration form opens and in which order they appear. Symcon does not remember expanding and collapsing itself, so this setting applies every time the form opens. It takes effect after “Apply” the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No tick means “default”: as the module intends (e.g. “Frigate camera” expanded as long as no camera is entered). The position is a number; with equal numbers the default order is kept. “Hidden” removes a section from the display without it losing anything: its settings are kept and keep working. If several ticks are set, “Hidden” wins over “Collapsed”, which wins over “Expanded”. The sections at the top (purpose, new, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($zeilen), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $zeilen,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo ' . $this->frigatehubPrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** PREFIX_ResetFormLayout($id): Aufbau im offenen Formular auf Standard (gilt nach „Übernehmen"). */
    public function ResetFormLayout(): string
    {
        if (!method_exists($this, 'frigatehubFachpanels')) {
            return FrigateHubText::t('ℹ️ This module has no selectable form layout.');
        }
        $zeilen = [];
        $i = 0;
        foreach ($this->frigatehubFachpanels() as $k => $panel) {
            $zeilen[] = ['Key' => $k, 'Panel' => self::formAufbauName($panel, (string)$k), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', (string)json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        return FrigateHubText::t('ℹ️ Reset to default. Click “Apply” below to save.');
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    protected function frigatehubFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            $fachpanels,
            [$this->formRueckmeldung(), $this->formLizenz()]
        )));
        $form = ['elements' => $elements, 'actions' => $aktionen, 'status' => $status];
        if (FrigateHubText::$formularHaken !== null) {
            $form = (FrigateHubText::$formularHaken)($form);
        }
        return (string)json_encode($form);
    }
}
