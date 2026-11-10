<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubRoboter.php';
require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * „Roboter im Bild" (0.20.0) für FrigateHubKamera: Zuordnung je Kamera, Rahmen merken, Reviews
 * bewerten. Bewertung in libs/FrigateHubRoboter.php.
 *
 * RoboHub wird nur gefunden (Modul-GUID) und über ROBO_GetActivity() gelesen — keine Verknüpfung,
 * kein Nachrichten-Abo: der Zustand wird beim Alarm abgefragt, der Nachlauf kommt aus `since`.
 *
 * Erwartet aus der Klasse: variableAnlegen(), anzeigeText().
 */
trait FrigateHubKameraRoboter
{
    protected function roboterRegistrieren(): void
    {
        // 0 aus (Standard, sicherheitsrelevant), 1 alle Mäher, 2 alle Sauger, 3 alle Roboter, 4 einzelne
        $this->RegisterPropertyInteger('RoboterZuordnung', FrigateHubRoboter::AUS);
        $this->RegisterPropertyString('RoboterAuswahl', '[]');
    }

    /** Ist auf dieser Kamera die Roboter-Berücksichtigung an? */
    private function roboterAn(): bool
    {
        return $this->ReadPropertyInteger('RoboterZuordnung') !== FrigateHubRoboter::AUS;
    }

    /** Gefundene RoboHub-Roboter: [instanceID => Name]. */
    private function roboterGefunden(): array
    {
        $r = [];
        foreach (@IPS_GetInstanceListByModuleID(FrigateHubRoboter::ROBOHUB_GUID) ?: [] as $id) {
            $r[(int)$id] = IPS_GetName((int)$id);
        }
        return $r;
    }

    /** Instanz-IDs aus der Auswahlliste (Häkchen „Berücksichtigen“). */
    private function roboterAuswahl(): array
    {
        $ids = [];
        foreach ((array)json_decode($this->ReadPropertyString('RoboterAuswahl'), true) as $z) {
            if (is_array($z) && !empty($z['Aktiv'])) {
                $ids[] = (int)($z['InstanceID'] ?? 0);
            }
        }
        return $ids;
    }

    /** Namen der zugeordneten Roboter, die gerade unterwegs sind (oder im Nachlauf). */
    private function roboterAktiv(): array
    {
        if (!$this->roboterAn() || !function_exists('ROBO_GetActivity')) {
            return [];
        }
        $zuordnung = $this->ReadPropertyInteger('RoboterZuordnung');
        $auswahl = $this->roboterAuswahl();
        $aktiv = [];
        foreach (array_keys($this->roboterGefunden()) as $id) {
            if ($zuordnung === FrigateHubRoboter::EINZELNE && !in_array($id, $auswahl, true)) {
                continue;
            }
            try {
                $a = FrigateHubRoboter::aktivitaet(@ROBO_GetActivity($id), time());
            } catch (Throwable $e) {
                $this->SendDebug('Roboter', '#' . $id . ': ' . $e->getMessage(), 0);
                continue;
            }
            if ($a['aktiv'] && FrigateHubRoboter::zugeordnet($zuordnung, $auswahl, $id, $a['klasse'])) {
                $aktiv[] = $a['name'] !== '' ? $a['name'] : IPS_GetName($id);
            }
        }
        return $aktiv;
    }

    /** frigate/events: Rahmen merken (nur bei eingeschalteter Zuordnung). */
    protected function roboterEvent(string $typ, array $after): void
    {
        if (!$this->roboterAn()) {
            return;
        }
        $boxen = (array)json_decode($this->GetBuffer('RoboterBoxen'), true);
        $this->SetBuffer('RoboterBoxen', (string)json_encode(FrigateHubRoboter::boxMerken($boxen, $typ, $after, time())));
    }

    /** Könnte gerade ein Roboter im Bild sein? (für review_status ohne Objektangaben) */
    protected function roboterMoeglich(): bool
    {
        if (!$this->roboterAn()) {
            return false;
        }
        $boxen = (array)json_decode($this->GetBuffer('RoboterBoxen'), true);
        return FrigateHubRoboter::maeherRahmen($boxen) !== [] || $this->roboterAktiv() !== [];
    }

    /**
     * Review bewerten: null = melden wie bisher, sonst der Grund der Unterdrückung.
     * Merkt sich das Ergebnis je Review, damit ein späteres update mit neuen Objekten neu entscheidet.
     */
    protected function roboterUnterdruecken(array $review): ?string
    {
        if (!$this->roboterAn()) {
            return null;
        }
        $ids = array_map('strval', (array)($review['data']['detections'] ?? []));
        $boxen = (array)json_decode($this->GetBuffer('RoboterBoxen'), true);
        $nurMaeher = $ids !== [] && array_filter($ids, fn ($id) => ($boxen[$id]['label'] ?? '') !== FrigateHubRoboter::LABEL_MAEHER) === [];
        $aktiv = $nurMaeher ? [] : $this->roboterAktiv();
        $e = FrigateHubRoboter::bewerten($ids, $boxen, $aktiv);
        $this->SendDebug('Roboter', ($e['unterdruecken'] ? 'unterdrückt' : 'gemeldet') . ': ' . $e['grund'], 0);
        return $e['unterdruecken'] ? $e['grund'] : null;
    }

    /**
     * Tageszähler „Alarme“: ohne Roboter wie bisher beim ersten Review (new). Mit Roboter einmal je
     * Review — auch wenn er erst beim update zum Alarm wird, weil ein Mensch dazukam.
     */
    protected function roboterAlarmZaehlen(string $id, string $typ): bool
    {
        if (!$this->roboterAn()) {
            return $typ === 'new';
        }
        if ($id === '' || $this->GetBuffer('RoboterGezaehlt') === $id) {
            return false;
        }
        $this->SetBuffer('RoboterGezaehlt', $id);
        return true;
    }

    /** Unterdrückten Alarm sichtbar machen (Variable entsteht beim ersten Mal). */
    protected function roboterProtokoll(string $text, string $grund): void
    {
        $this->variableAnlegen('RoboterUnterdrueckt', FrigateHubText::t('Not reported (robot)'), VARIABLETYPE_STRING, self::anzeigeText(), 7);
        $this->SetValue('RoboterUnterdrueckt', FrigateHubTopics::kuerzenBytes(FrigateHubText::t('%s – robot active: %s', $text, $grund), 1000));
    }

    /** Formular-Panel. */
    protected function roboterPanel(): array
    {
        $gefunden = $this->roboterGefunden();
        $auswahl = $this->roboterAuswahl();
        $werte = [];
        foreach ($gefunden as $id => $name) {
            $werte[] = ['InstanceID' => $id, 'Name' => $name, 'Aktiv' => in_array($id, $auswahl, true)];
        }
        if ($gefunden === []) {
            $status = FrigateHubText::t('No RoboHub robot found. Without RoboHub, only Frigate+ (object "robot_lawnmower") helps.');
        } elseif (!$this->roboterAn()) {
            $status = FrigateHubText::t('ℹ️ %d robot(s) from RoboHub found. If one of them can appear in this camera\'s image, choose it above.', count($gefunden));
        } else {
            $status = FrigateHubText::t('%d robot(s) from RoboHub found.', count($gefunden));
        }
        if (!function_exists('ROBO_GetActivity') && $gefunden !== []) {
            $status .= ' ' . FrigateHubText::t('⚠️ This RoboHub version does not provide ROBO_GetActivity() yet — please update RoboHub.');
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🤖  Robots in the image',
            'items' => [
                ['type' => 'Label', 'caption' => 'A mowing robot outdoors or a vacuum robot indoors is often taken for an animal or a person by Frigate. If a robot assigned here is out of its station (and one minute after it returns), FrigateHub does not report such false detections as an alert — push, OpenHomeAlarm and the "Alert" variable stay quiet. Recording continues.'],
                ['type' => 'Label', 'caption' => 'Never suppressed: an upright person (taller than wide), a recognized face, vehicles, and anything while the robot state is unknown or outdated. Only flat detections (wider than tall) are suppressed — that is what robot misdetections look like. With Frigate+ (object "robot_lawnmower"), only detections overlapping the mower.'],
                ['type' => 'Select', 'name' => 'RoboterZuordnung', 'caption' => 'Robots in this camera\'s image', 'options' => [
                    ['caption' => 'none (off)', 'value' => FrigateHubRoboter::AUS],
                    ['caption' => 'all mowing robots', 'value' => FrigateHubRoboter::MAEHER],
                    ['caption' => 'all vacuum robots', 'value' => FrigateHubRoboter::SAUGER],
                    ['caption' => 'all robots', 'value' => FrigateHubRoboter::ALLE],
                    ['caption' => 'selected robots', 'value' => FrigateHubRoboter::EINZELNE],
                ]],
                ['type' => 'List', 'name' => 'RoboterAuswahl', 'caption' => 'Selected robots (only for "selected robots")', 'rowCount' => max(1, min(6, count($werte))),
                    'add' => false, 'delete' => false, 'values' => $werte, 'columns' => [
                        ['caption' => 'Consider', 'name' => 'Aktiv', 'width' => '110px', 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Robot', 'name' => 'Name', 'width' => 'auto'],
                        ['caption' => 'ID', 'name' => 'InstanceID', 'width' => '90px'],
                    ]],
                ['type' => 'Label', 'caption' => $status],
            ],
        ];
    }
}
