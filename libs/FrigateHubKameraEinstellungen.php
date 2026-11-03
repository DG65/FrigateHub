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
            return 'ℹ️ Dafür braucht es Kamera-ID und Frigate-Adresse.';
        }
        $roh = json_decode($Werte, true);
        if (!is_array($roh)) {
            return '⛔ Formularwerte nicht lesbar.';
        }
        // Immer gegen den frischen Stand vergleichen: jemand kann inzwischen in Frigate geändert haben
        $config = $this->apiHolen('/api/config');
        if ($config === null || !is_array($config['cameras'][$kamera] ?? null)) {
            return '⛔ ' . ($config === null ? $this->apiFehlerText() : 'Frigate kennt die Kamera „' . $kamera . '" nicht.');
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
            return 'ℹ️ Nichts geändert — die Werte entsprechen dem Stand in Frigate.';
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
                : ($a['code'] === 403 ? 'keine Berechtigung — der Frigate-Benutzer braucht die Rolle „admin"' : ($a['code'] === 0 ? 'Frigate nicht erreichbar' : 'HTTP ' . $a['code']));
            $fehler[] = $name . ': ' . $grund;
        }
        if ($ok !== []) {
            $this->LogMessage('Frigate-Einstellungen geändert (' . $kamera . '): ' . implode(', ', $ok), KL_NOTIFY);
            // Schalter, Zonen und Zähler sofort nachziehen
            $this->SetTimerInterval('Abgleich', self::ABGLEICH_VERZOEGERUNG);
        }
        if ($fehler !== []) {
            $this->LogMessage('Frigate-Einstellungen abgelehnt (' . $kamera . '): ' . implode('; ', $fehler), KL_WARNING);
        }
        $text = ($fehler === [] ? '✅ ' : ($ok === [] ? '⛔ ' : '⚠️ ')) . date('H:i:s') . ': '
            . ($ok === [] ? '' : 'In Frigate gespeichert: ' . implode(', ', $ok) . '. ')
            . ($fehler === [] ? '' : 'Abgelehnt (Frigate hat nichts verändert): ' . implode('; ', $fehler) . '.');
        $this->UpdateFormField('EinstellungenStatus', 'caption', $text);
        return $text;
    }

    /** Panel für den actions-Bereich; liest beim Öffnen frisch aus Frigate. */
    protected function einstellungenPanel(): array
    {
        $panel = ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '⚙️  In Frigate einstellen', '_aktionen' => true, 'items' => []];
        $hinweis = fn (string $t) => array_merge($panel, ['items' => [['type' => 'Label', 'caption' => $t]]]);
        $kamera = $this->kamera();
        if ($kamera === '' || $this->apiUrl() === '') {
            return $hinweis('ℹ️ Hier lassen sich Frigates Einstellungen dieser Kamera ändern, ohne in Frigates Oberfläche zu wechseln. Dafür braucht es Kamera-ID und Frigate-Adresse.');
        }
        $config = $this->apiHolen('/api/config');
        if ($config === null || !is_array($config['cameras'][$kamera] ?? null)) {
            return $hinweis('⛔ Einstellungen nicht lesbar: ' . ($config === null ? $this->apiFehlerText() : 'Frigate kennt die Kamera „' . $kamera . '" nicht.'));
        }
        $w = FrigateHubEinstellungen::lesen($config['cameras'][$kamera], $config);
        $liste = fn (array $l) => implode(', ', $l);
        $zonen = [];
        foreach ($w['zonen'] as $name => $z) {
            $zonen[] = ['Zone' => $name, 'Objekte' => $liste($z['objekte']), 'Verweilen' => $z['verweilen']];
        }
        $tage = fn (string $name, string $caption, float $wert) => ['type' => 'NumberSpinner', 'name' => $name, 'caption' => $caption, 'suffix' => 'Tage', 'digits' => 1, 'minimum' => 0, 'maximum' => 3650, 'value' => $wert];
        $items = [
            ['type' => 'Label', 'caption' => 'Ändert die Einstellungen dieser Kamera direkt in Frigate (dessen config.yml) — wie Frigates eigene Oberfläche, ohne Neustart. Angezeigt wird der Stand beim Öffnen. Gespeichert wird nur, was du geändert hast; Frigate prüft die Werte und nimmt ungültige selbst zurück. Zonen und Masken zeichnen sowie Kameras anlegen bleiben in Frigates Oberfläche.'],
            ['type' => 'Label', 'caption' => 'Objektarten in Frigate-Schreibweise, mit Komma getrennt, z. B. ' . implode(', ', array_slice(FrigateHubEinstellungen::OBJEKTE, 0, 9)) . ' (package nur mit Frigate+).', 'italic' => true],
            ['type' => 'CheckBox', 'name' => 'FE_Aufnahme', 'caption' => 'Aufnahme an (record.enabled)', 'value' => $w['aufnahme']],
            ['type' => 'RowLayout', 'items' => [
                $tage('FE_Alarme', 'Alarme behalten', $w['alarme']),
                $tage('FE_Erkennungen', 'Erkennungen behalten', $w['erkennungen']),
            ]],
            ['type' => 'RowLayout', 'items' => [
                $tage('FE_Dauer', 'Daueraufnahme behalten (0 = keine)', $w['dauer']),
                $tage('FE_Bewegung', 'Aufnahmen mit Bewegung behalten', $w['bewegung']),
            ]],
            ['type' => 'ValidationTextBox', 'name' => 'FE_Verfolgen', 'caption' => 'Erkennen und verfolgen (objects.track)', 'value' => $liste($w['verfolgen'])],
            ['type' => 'ValidationTextBox', 'name' => 'FE_AlarmObjekte', 'caption' => 'Als Alarm statt nur Erkennung (review.alerts.labels)', 'value' => $liste($w['alarmObjekte'])],
            ['type' => 'ValidationTextBox', 'name' => 'FE_AlarmZonen', 'caption' => 'Alarm nur in diesen Zonen (leer = überall; ' . ($zonen === [] ? 'keine Zonen' : 'Zonen: ' . implode(', ', array_keys($w['zonen']))) . ')', 'value' => $liste($w['alarmZonen'])],
            ['type' => 'List', 'name' => 'FE_Zonen', 'caption' => 'Zonen: welche Objekte zählen (leer = alle), Verweildauer in Sekunden (loitering_time, 0 = sofort)', 'rowCount' => max(1, min(6, count($zonen))),
                'add' => false, 'delete' => false, 'values' => $zonen,
                'columns' => [
                    ['caption' => 'Zone', 'name' => 'Zone', 'width' => '200px', 'add' => ''],
                    ['caption' => 'Objekte', 'name' => 'Objekte', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Verweilen (s)', 'name' => 'Verweilen', 'width' => '140px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 3600]],
                ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'FE_Schnappschuss', 'caption' => 'Schnappschüsse speichern', 'value' => $w['schnappschuss']],
                $tage('FE_SchnappTage', 'Schnappschüsse behalten', $w['schnappTage']),
            ]],
            ['type' => 'NumberSpinner', 'name' => 'FE_Stillstand', 'caption' => 'Als stehend gilt ein Objekt nach (detect.stationary.threshold, bei ' . $w['fps'] . ' Bildern/s)', 'suffix' => 's', 'minimum' => 1, 'maximum' => 600, 'value' => $w['stillstand']],
        ];
        $schalter = [];
        if ($w['kennzeichen'] !== null) {
            $schalter[] = ['type' => 'CheckBox', 'name' => 'FE_Kennzeichen', 'caption' => 'Kennzeichen erkennen', 'value' => $w['kennzeichen']];
        }
        if ($w['gesichter'] !== null) {
            $schalter[] = ['type' => 'CheckBox', 'name' => 'FE_Gesichter', 'caption' => 'Gesichter erkennen', 'value' => $w['gesichter']];
        }
        $schalter[] = ['type' => 'CheckBox', 'name' => 'FE_Audio', 'caption' => 'Geräusche erkennen' . ($w['audioRolle'] ? '' : ' (Kamera hat keinen Stream mit Rolle „audio" — in Frigate ergänzen)'),
            'value' => $w['audio'], 'enabled' => $w['audioRolle'] || $w['audio']];
        $items[] = ['type' => 'RowLayout', 'items' => $schalter];
        if ($w['kennzeichen'] === null || $w['gesichter'] === null) {
            $items[] = ['type' => 'Label', 'caption' => 'Kennzeichen- bzw. Gesichtserkennung ist in Frigate global aus; einschalten lässt sie sich nur dort (braucht mehr Rechenleistung und ein Modell).', 'italic' => true];
        }
        $items[] = ['type' => 'ValidationTextBox', 'name' => 'FE_Hoeren', 'caption' => 'Auf diese Geräusche hören (audio.listen), z. B. ' . implode(', ', array_slice(FrigateHubEinstellungen::GERAEUSCHE, 0, 8)), 'value' => $liste($w['hoeren'])];
        $werte = '["aufnahme" => $FE_Aufnahme, "alarme" => $FE_Alarme, "erkennungen" => $FE_Erkennungen, "dauer" => $FE_Dauer, "bewegung" => $FE_Bewegung,'
            . ' "verfolgen" => $FE_Verfolgen, "alarmObjekte" => $FE_AlarmObjekte, "alarmZonen" => $FE_AlarmZonen, "schnappschuss" => $FE_Schnappschuss,'
            . ' "schnappTage" => $FE_SchnappTage, "stillstand" => $FE_Stillstand, "audio" => $FE_Audio, "hoeren" => $FE_Hoeren, "zonen" => $z'
            . ($w['kennzeichen'] !== null ? ', "kennzeichen" => $FE_Kennzeichen' : '') . ($w['gesichter'] !== null ? ', "gesichter" => $FE_Gesichter' : '') . ']';
        $items[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'Button', 'caption' => '💾 In Frigate speichern', 'confirm' => 'Geänderte Einstellungen in Frigate speichern? Frigate schreibt sie in seine config.yml und übernimmt sie sofort.',
                'onClick' => '$z = []; foreach ($FE_Zonen as $x) { $z[] = $x; } echo FRIG_EinstellungenSpeichern($id, json_encode(' . $werte . '));'],
            ['type' => 'Label', 'name' => 'EinstellungenStatus', 'caption' => 'Stand aus Frigate: ' . date('H:i:s') . '. Zum Neuladen das Formular schließen und wieder öffnen.'],
        ]];
        return ['items' => $items] + $panel;
    }
}
