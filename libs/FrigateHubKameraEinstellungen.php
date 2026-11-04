<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubEinstellungen.php';

/**
 * „⚙️ In Frigate einstellen" (0.17.0) für FrigateHubKamera: liest die Einstellungen der Kamera
 * aus Frigate, zeigt sie im Formular (Bereich actions, Felder ohne Property) und schreibt nur
 * Geändertes über PUT /api/config/set zurück. Logik in libs/FrigateHubEinstellungen.php.
 */
trait FrigateHubKameraEinstellungen
{
    /**
     * FRIG_EinstellungenSpeichern($id, $Werte): geänderte Einstellungen in Frigate schreiben.
     * $Werte: JSON der Formularfelder (siehe einstellungenPanel()). Rückgabe: Text fürs Formular.
     */
    public function EinstellungenSpeichern(string $Werte): string
    {
        $kamera = $this->kamera();
        if ($kamera === '' || $this->apiUrl() === '') {
            return FrigateHubText::t('ℹ️ This needs the camera ID and the Frigate address.');
        }
        $roh = json_decode($Werte, true);
        if (!is_array($roh)) {
            return FrigateHubText::t('⛔ The form values could not be read.');
        }
        // Immer gegen den frischen Stand vergleichen: jemand kann inzwischen in Frigate geändert haben
        $config = $this->apiHolen('/api/config');
        if ($config === null || !is_array($config['cameras'][$kamera] ?? null)) {
            return '⛔ ' . ($config === null ? $this->apiFehlerText() : FrigateHubText::t('Frigate does not know the camera "%s".', $kamera));
        }
        $alt = FrigateHubEinstellungen::lesen($config['cameras'][$kamera], $config);
        $neu = $roh;
        $neu['zonen'] = [];
        foreach ((array)($roh['zonen'] ?? []) as $zeile) {
            if (is_array($zeile) && (string)($zeile['Zone'] ?? '') !== '') {
                $neu['zonen'][(string)$zeile['Zone']] = ['objekte' => (string)($zeile['Objekte'] ?? ''), 'verweilen' => (int)($zeile['Verweilen'] ?? 0)];
            }
        }
        $aenderungen = FrigateHubEinstellungen::aenderungen($alt, $neu);
        if ($aenderungen === []) {
            return FrigateHubText::t('ℹ️ Nothing changed — the values match the current state in Frigate.');
        }
        $ok = [];
        $fehler = [];
        foreach ($aenderungen as $bereich => $daten) {
            $a = $this->apiSchreiben('PUT', '/api/config/set', FrigateHubEinstellungen::anfrage($kamera, $bereich, $daten));
            $antwort = json_decode($a['body'], true);
            $name = FrigateHubEinstellungen::beschreiben([$bereich => true]);
            if ($a['code'] === 200 && !empty($antwort['success'])) {
                $ok[] = $name;
                continue;
            }
            $grund = is_array($antwort) && (string)($antwort['message'] ?? '') !== '' ? (string)$antwort['message']
                : ($a['code'] === 403 ? FrigateHubText::t('no permission — the Frigate user needs the role "admin"') : ($a['code'] === 0 ? FrigateHubText::t('Frigate not reachable') : 'HTTP ' . $a['code']));
            $fehler[] = $name . ': ' . $grund;
        }
        if ($ok !== []) {
            $this->LogMessage(FrigateHubText::t('Frigate settings changed (%s): %s', $kamera, implode(', ', $ok)), KL_NOTIFY);
            // Schalter, Zonen und Zähler sofort nachziehen
            $this->SetTimerInterval('Abgleich', self::ABGLEICH_VERZOEGERUNG);
        }
        if ($fehler !== []) {
            $this->LogMessage(FrigateHubText::t('Frigate settings rejected (%s): %s', $kamera, implode('; ', $fehler)), KL_WARNING);
        }
        $text = ($fehler === [] ? '✅ ' : ($ok === [] ? '⛔ ' : '⚠️ ')) . date('H:i:s') . ': '
            . ($ok === [] ? '' : FrigateHubText::t('Saved in Frigate: %s.', implode(', ', $ok)) . ' ')
            . ($fehler === [] ? '' : FrigateHubText::t('Rejected (Frigate changed nothing): %s.', implode('; ', $fehler)));
        $this->UpdateFormField('EinstellungenStatus', 'caption', $text);
        return $text;
    }

    /**
     * Fachpanel; liest beim Öffnen frisch aus Frigate. Die FE_*-Felder haben keine Property: Symcons Konsole
     * übernimmt beim „Übernehmen“ nur Felder, die Properties sind, und zählt Änderungen an anderen nicht
     * als ungespeichert (console-app.js, Symcon 9.0) — sie dürfen daher in `elements` stehen.
     */
    protected function einstellungenPanel(): array
    {
        $panel = ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => FrigateHubText::t('⚙️  Configure in Frigate'), 'items' => []];
        $hinweis = fn (string $t) => array_merge($panel, ['items' => [['type' => 'Label', 'caption' => $t]]]);
        $kamera = $this->kamera();
        if ($kamera === '' || $this->apiUrl() === '') {
            return $hinweis(FrigateHubText::t('ℹ️ Here you can change Frigate\'s settings for this camera without switching to Frigate\'s interface. This needs the camera ID and the Frigate address.'));
        }
        $config = $this->apiHolen('/api/config');
        if ($config === null || !is_array($config['cameras'][$kamera] ?? null)) {
            return $hinweis(FrigateHubText::t('⛔ Settings not readable: %s', $config === null ? $this->apiFehlerText() : FrigateHubText::t('Frigate does not know the camera "%s".', $kamera)));
        }
        $w = FrigateHubEinstellungen::lesen($config['cameras'][$kamera], $config);
        $liste = fn (array $l) => implode(', ', $l);
        $zonen = [];
        foreach ($w['zonen'] as $name => $z) {
            $zonen[] = ['Zone' => $name, 'Objekte' => $liste($z['objekte']), 'Verweilen' => $z['verweilen']];
        }
        $tage = fn (string $name, string $caption, float $wert) => ['type' => 'NumberSpinner', 'name' => $name, 'caption' => $caption, 'suffix' => FrigateHubText::t('days'), 'digits' => 1, 'minimum' => 0, 'maximum' => 3650, 'value' => $wert];
        $items = [
            ['type' => 'Label', 'caption' => FrigateHubText::t('Changes this camera\'s settings directly in Frigate (its config.yml) — like Frigate\'s own interface, without a restart. Shown is the state when the form was opened. Only what you changed is saved; Frigate checks the values and reverts invalid ones itself. Drawing zones and masks as well as adding cameras stay in Frigate\'s interface.')],
            ['type' => 'Label', 'caption' => FrigateHubText::t('Object types as Frigate spells them, separated by commas, e.g. %s (package only with Frigate+).', implode(', ', array_slice(FrigateHubEinstellungen::OBJEKTE, 0, 9))), 'italic' => true],
            ['type' => 'CheckBox', 'name' => 'FE_Aufnahme', 'caption' => FrigateHubText::t('Recording on (record.enabled)'), 'value' => $w['aufnahme']],
            ['type' => 'RowLayout', 'items' => [
                $tage('FE_Alarme', FrigateHubText::t('Keep alerts'), $w['alarme']),
                $tage('FE_Erkennungen', FrigateHubText::t('Keep detections'), $w['erkennungen']),
            ]],
            ['type' => 'RowLayout', 'items' => [
                $tage('FE_Dauer', FrigateHubText::t('Keep continuous recording (0 = none)'), $w['dauer']),
                $tage('FE_Bewegung', FrigateHubText::t('Keep recordings with motion'), $w['bewegung']),
            ]],
            ['type' => 'ValidationTextBox', 'name' => 'FE_Verfolgen', 'caption' => FrigateHubText::t('Detect and track (objects.track)'), 'value' => $liste($w['verfolgen'])],
            ['type' => 'ValidationTextBox', 'name' => 'FE_AlarmObjekte', 'caption' => FrigateHubText::t('As alert instead of just detection (review.alerts.labels)'), 'value' => $liste($w['alarmObjekte'])],
            ['type' => 'ValidationTextBox', 'name' => 'FE_AlarmZonen', 'caption' => $zonen === [] ? FrigateHubText::t('Alert only in these zones (empty = everywhere; no zones)') : FrigateHubText::t('Alert only in these zones (empty = everywhere; zones: %s)', implode(', ', array_keys($w['zonen']))), 'value' => $liste($w['alarmZonen'])],
            ['type' => 'List', 'name' => 'FE_Zonen', 'caption' => FrigateHubText::t('Zones: which objects count (empty = all), dwell time in seconds (loitering_time, 0 = immediately)'), 'rowCount' => max(1, min(6, count($zonen))),
                'add' => false, 'delete' => false, 'values' => $zonen,
                'columns' => [
                    ['caption' => FrigateHubText::t('Zone'), 'name' => 'Zone', 'width' => '200px', 'add' => ''],
                    ['caption' => FrigateHubText::t('Objects'), 'name' => 'Objekte', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => FrigateHubText::t('Dwell (s)'), 'name' => 'Verweilen', 'width' => '140px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 3600]],
                ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'FE_Schnappschuss', 'caption' => FrigateHubText::t('Save snapshots'), 'value' => $w['schnappschuss']],
                $tage('FE_SchnappTage', FrigateHubText::t('Keep snapshots'), $w['schnappTage']),
            ]],
            ['type' => 'NumberSpinner', 'name' => 'FE_Stillstand', 'caption' => FrigateHubText::t('An object counts as stationary after (detect.stationary.threshold, at %s frames/s)', $w['fps']), 'suffix' => 's', 'minimum' => 1, 'maximum' => 600, 'value' => $w['stillstand']],
        ];
        $schalter = [];
        if ($w['kennzeichen'] !== null) {
            $schalter[] = ['type' => 'CheckBox', 'name' => 'FE_Kennzeichen', 'caption' => FrigateHubText::t('Recognize license plates'), 'value' => $w['kennzeichen']];
        }
        if ($w['gesichter'] !== null) {
            $schalter[] = ['type' => 'CheckBox', 'name' => 'FE_Gesichter', 'caption' => FrigateHubText::t('Recognize faces'), 'value' => $w['gesichter']];
        }
        $schalter[] = ['type' => 'CheckBox', 'name' => 'FE_Audio', 'caption' => $w['audioRolle'] ? FrigateHubText::t('Detect sounds') : FrigateHubText::t('Detect sounds (camera has no stream with role "audio" — add it in Frigate)'),
            'value' => $w['audio'], 'enabled' => $w['audioRolle'] || $w['audio']];
        $items[] = ['type' => 'RowLayout', 'items' => $schalter];
        if ($w['kennzeichen'] === null || $w['gesichter'] === null) {
            $items[] = ['type' => 'Label', 'caption' => FrigateHubText::t('License plate or face recognition is switched off globally in Frigate; it can only be switched on there (needs more computing power and a model).'), 'italic' => true];
        }
        $items[] = ['type' => 'ValidationTextBox', 'name' => 'FE_Hoeren', 'caption' => FrigateHubText::t('Listen for these sounds (audio.listen), e.g. %s', implode(', ', array_slice(FrigateHubEinstellungen::GERAEUSCHE, 0, 8))), 'value' => $liste($w['hoeren'])];
        $werte = '["aufnahme" => $FE_Aufnahme, "alarme" => $FE_Alarme, "erkennungen" => $FE_Erkennungen, "dauer" => $FE_Dauer, "bewegung" => $FE_Bewegung,'
            . ' "verfolgen" => $FE_Verfolgen, "alarmObjekte" => $FE_AlarmObjekte, "alarmZonen" => $FE_AlarmZonen, "schnappschuss" => $FE_Schnappschuss,'
            . ' "schnappTage" => $FE_SchnappTage, "stillstand" => $FE_Stillstand, "audio" => $FE_Audio, "hoeren" => $FE_Hoeren, "zonen" => $z'
            . ($w['kennzeichen'] !== null ? ', "kennzeichen" => $FE_Kennzeichen' : '') . ($w['gesichter'] !== null ? ', "gesichter" => $FE_Gesichter' : '') . ']';
        $items[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'Button', 'caption' => FrigateHubText::t('💾 Save in Frigate'), 'confirm' => FrigateHubText::t('Save the changed settings in Frigate? Frigate writes them to its config.yml and applies them immediately.'),
                'onClick' => '$z = []; foreach ($FE_Zonen as $x) { $z[] = $x; } echo FRIG_EinstellungenSpeichern($id, json_encode(' . $werte . '));'],
            ['type' => 'Label', 'name' => 'EinstellungenStatus', 'caption' => FrigateHubText::t('State from Frigate: %s. To reload, close and reopen the form.', date('H:i:s'))],
        ]];
        return ['items' => $items] + $panel;
    }
}
