<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubNotfall.php';
require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * „Person liegt / Sturz" (0.21.0) für FrigateHubKamera. Bewertung in libs/FrigateHubNotfall.php.
 * Standard aus: Kameras in Wohnräumen berühren die Privatsphäre, die Erkennung ist ein Hinweis, kein Notruf.
 *
 * Erwartet aus der Klasse: variableAnlegen(), pushSenden(), pushZiele(), zonen().
 */
trait FrigateHubKameraNotfall
{
    protected function notfallRegistrieren(): void
    {
        $this->RegisterPropertyBoolean('NotfallAn', false);
        $this->RegisterPropertyInteger('NotfallWartezeit', 60);
        $this->RegisterPropertyString('NotfallZonen', '');
        $this->RegisterPropertyBoolean('NotfallGeraeusche', true);
        $this->RegisterPropertyBoolean('NotfallPush', true);
        $this->RegisterAttributeString('NotfallGemeldet', '');
        $this->RegisterTimer('Notfall', 0, 'FRIG_NotfallPruefen($_IPS[\'TARGET\']);');
    }

    protected function notfallUebernehmen(): void
    {
        if ($this->ReadPropertyBoolean('NotfallAn')) {
            $this->notfallVariablen();
        } else {
            $this->SetBuffer('Notfall', '');
        }
        $this->notfallTimer();
    }

    /** FRIG_NotfallPruefen($id): Lage neu bewerten (Timer, auch von Hand). Liefert den Klartext. */
    public function NotfallPruefen(): string
    {
        if (!$this->ReadPropertyBoolean('NotfallAn')) {
            $this->SetTimerInterval('Notfall', 0);
            return FrigateHubText::t('Off');
        }
        $lage = $this->notfallLage();
        $text = $this->notfallText($lage);
        $this->notfallVariablen();
        if ($this->GetValue('Notfall') !== $lage['stufe']) {
            $this->SetValue('Notfall', $lage['stufe']);
        }
        if ($lage['stufe'] !== FrigateHubNotfall::OK) {
            $this->SetValue('NotfallText', $text);
        }
        if ($lage['stufe'] === FrigateHubNotfall::NOTFALL && $this->ReadAttributeString('NotfallGemeldet') !== $lage['id']) {
            $this->WriteAttributeString('NotfallGemeldet', $lage['id']);
            if ($this->ReadPropertyBoolean('NotfallPush') && $this->pushZiele() !== []) {
                // Ohne Mindestabstand: ein Notfall wartet nicht auf die Pause nach einem Einbruchsalarm
                $this->pushSenden('🆘 ' . IPS_GetName($this->InstanceID), $text, true);
            }
        }
        $this->notfallTimer();
        return $text;
    }

    /** FRIG_NotfallQuittieren($id): gesehen, kümmere mich — bis die Person aufsteht, keine weitere Meldung. */
    public function NotfallQuittieren(): string
    {
        $zustand = FrigateHubNotfall::quittieren((array)json_decode($this->GetBuffer('Notfall'), true));
        $this->SetBuffer('Notfall', (string)json_encode($zustand));
        if ($this->ReadPropertyBoolean('NotfallAn')) {
            $this->notfallVariablen();
            $this->SetValue('Notfall', FrigateHubNotfall::OK);
        }
        $this->notfallTimer();
        return FrigateHubText::t('✅ Acknowledged.');
    }

    /** frigate/events einarbeiten. */
    protected function notfallEvent(string $typ, array $after): void
    {
        if (!$this->ReadPropertyBoolean('NotfallAn') || ($after['label'] ?? '') !== 'person') {
            return;
        }
        $zustand = (array)json_decode($this->GetBuffer('Notfall'), true);
        $zustand = FrigateHubNotfall::beobachten($zustand, $typ, $after, (float)time());
        $this->SetBuffer('Notfall', (string)json_encode($zustand));
        $this->NotfallPruefen();
    }

    /** frigate/<kamera>/audio/<typ>: Hilferuf oder Aufprall merken. */
    protected function notfallGeraeusch(string $typ, bool $an): void
    {
        if ($an && $this->ReadPropertyBoolean('NotfallAn') && FrigateHubNotfall::istHilfeGeraeusch($typ)) {
            $this->SetBuffer('NotfallGeraeusch', (string)time());
            if ($this->GetBuffer('Notfall') !== '') {
                $this->NotfallPruefen();
            }
        }
    }

    private function notfallLage(): array
    {
        $zustand = (array)json_decode($this->GetBuffer('Notfall'), true);
        $geraeusch = $this->ReadPropertyBoolean('NotfallGeraeusche') ? (float)$this->GetBuffer('NotfallGeraeusch') : 0.0;
        return FrigateHubNotfall::bewerten($zustand, (float)time(), max(10, $this->ReadPropertyInteger('NotfallWartezeit')), $this->notfallZonen(), $geraeusch);
    }

    private function notfallZonen(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->ReadPropertyString('NotfallZonen'))), fn ($z) => $z !== ''));
    }

    private function notfallTimer(): void
    {
        $zustand = (array)json_decode($this->GetBuffer('Notfall'), true);
        $s = $this->ReadPropertyBoolean('NotfallAn') ? FrigateHubNotfall::naechstePruefung($zustand) : 0;
        $this->SetTimerInterval('Notfall', $s * 1000);
    }

    private function notfallText(array $lage): string
    {
        $min = intdiv($lage['dauer'], 60);
        $dauer = $min > 0 ? FrigateHubText::t('%d min', $min) : FrigateHubText::t('%d s', $lage['dauer']);
        switch ($lage['stufe']) {
            case FrigateHubNotfall::NOTFALL:
                $text = FrigateHubText::t('Person lying motionless for %s — please check!', $dauer);
                break;
            case FrigateHubNotfall::STURZ:
                $text = FrigateHubText::t('Possible fall — person lying (%s)', $dauer);
                break;
            case FrigateHubNotfall::LIEGT:
                $text = FrigateHubText::t('Person lying (%s)', $dauer);
                break;
            default:
                return '';
        }
        return $lage['grund'] !== '' ? $text . ' (' . $lage['grund'] . ')' : $text;
    }

    private function notfallVariablen(): void
    {
        $this->variableAnlegen('Notfall', FrigateHubText::t('Emergency'), VARIABLETYPE_INTEGER, self::anzeigeNotfall(), 8);
        $this->variableAnlegen('NotfallText', FrigateHubText::t('Emergency: last finding'), VARIABLETYPE_STRING, self::anzeigeText(), 9);
    }

    private static function anzeigeNotfall(): array
    {
        $stufen = [
            FrigateHubNotfall::OK      => [FrigateHubText::t('all clear'), 0x43A047],
            FrigateHubNotfall::LIEGT   => [FrigateHubText::t('person lying'), 0xFB8C00],
            FrigateHubNotfall::STURZ   => [FrigateHubText::t('possible fall'), 0xE53935],
            FrigateHubNotfall::NOTFALL => [FrigateHubText::t('emergency'), 0xB71C1C],
        ];
        $intervalle = [];
        foreach ($stufen as $wert => [$text, $farbe]) {
            $intervalle[] = ['IntervalMinValue' => $wert, 'IntervalMaxValue' => $wert, 'ConstantActive' => true, 'ConstantValue' => $text,
                'ConversionFactor' => 1, 'PrefixActive' => false, 'SuffixActive' => false, 'DigitsActive' => false, 'IconActive' => false,
                'IconValue' => '', 'ColorActive' => true, 'ColorValue' => $farbe];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'INTERVALS_ACTIVE' => true, 'INTERVALS' => (string)json_encode($intervalle)];
    }

    /** Formular-Panel. */
    protected function notfallPanel(): array
    {
        $bekannt = $this->zonen();
        $zonen = $bekannt === [] ? FrigateHubText::t('No zones known yet (they are learned from Frigate\'s events).')
            : FrigateHubText::t('Known zones: %s', implode(', ', $bekannt));
        $status = $this->ReadPropertyBoolean('NotfallAn') ? FrigateHubText::t('Current state: %s', $this->notfallText($this->notfallLage()) ?: FrigateHubText::t('all clear')) : '';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🆘  Person lying / fall',
            'items' => [
                ['type' => 'Label', 'caption' => 'Reports when a person lies on the ground and no longer moves — e.g. after a fall on the stairs, from a ladder or in the garden. Detection: the person\'s frame turns flat (wider than tall) and stays still. A fall just before (upright → lying within seconds, or the feet dropping fast, e.g. from a ladder) or a call for help or an impact sound (Frigate audio: yell, crying, groan, thump, bang …) shortens the waiting time to 15 seconds.'],
                ['type' => 'Label', 'caption' => '⚠️ A camera cannot see pulse, breathing or consciousness, and only sees where it looks. This is a hint, not an emergency call system. Cameras in living areas affect privacy — only switch on where everyone concerned agrees.'],
                ['type' => 'CheckBox', 'name' => 'NotfallAn', 'caption' => 'Watch for persons lying on the ground'],
                ['type' => 'NumberSpinner', 'name' => 'NotfallWartezeit', 'caption' => 'Lying motionless for (until emergency)', 'suffix' => 's', 'minimum' => 10, 'maximum' => 3600],
                ['type' => 'ValidationTextBox', 'name' => 'NotfallZonen', 'caption' => 'Only in these Frigate zones (comma-separated, empty = whole image)'],
                ['type' => 'Label', 'caption' => $zonen],
                ['type' => 'Label', 'caption' => 'Tip: limit it to zones where nobody lies down normally (stairs, hallway, driveway, garage) — a sunbather on the lawn or a nap on the sofa looks exactly like a fall to the camera.'],
                ['type' => 'CheckBox', 'name' => 'NotfallGeraeusche', 'caption' => 'Calls for help and impact sounds shorten the waiting time (Frigate audio detection)'],
                ['type' => 'CheckBox', 'name' => 'NotfallPush', 'caption' => 'Send emergency to the push targets (panel “Push and notifications”, without minimum interval)'],
                ['type' => 'Label', 'caption' => 'Variable “Emergency”: 0 all clear, 1 person lying, 2 possible fall, 3 emergency — usable in rules and scripts. FRIG_NotfallQuittieren($id) confirms that someone takes care of it.'],
                ['type' => 'Label', 'caption' => $status],
                ['type' => 'Button', 'caption' => 'Acknowledge', 'onClick' => 'echo FRIG_NotfallQuittieren($id);'],
            ],
        ];
    }
}
