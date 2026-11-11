<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubLicht.php';
require_once __DIR__ . '/FrigateHubText.php';

/**
 * „Licht bei Erkennung" (0.23.0) für FrigateHubKamera. Logik in libs/FrigateHubLicht.php.
 * Standard aus. Das Kamera-Passwort steht nur im Attribut LichtZugang (nie Property, nie im Log).
 *
 * Erwartet aus der Klasse: variableAnlegen(), kamera(), apiUrl(), apiRoh(), apiHolen(), zonen(), $apiFehler.
 */
trait FrigateHubKameraLicht
{
    /** Symcon-Modul „Location Control" (Variable IsDay). */
    private static $FRIGATEHUB_ORT_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    protected function lichtRegistrieren(): void
    {
        $this->RegisterPropertyInteger('LichtArt', FrigateHubLicht::AUS);
        $this->RegisterPropertyInteger('LichtVariable', 0);
        $this->RegisterPropertyInteger('LichtWert', 100);
        $this->RegisterPropertyString('LichtObjekte', 'person');
        $this->RegisterPropertyString('LichtZonen', '');
        $this->RegisterPropertyInteger('LichtNachlauf', 60);
        $this->RegisterPropertyBoolean('LichtNurDunkel', true);
        $this->RegisterAttributeString('LichtZugang', '');
        $this->RegisterAttributeString('LichtVorher', '');
        $this->RegisterAttributeString('LichtStatus', '');
        $this->RegisterTimer('Licht', 0, 'FRIG_LichtPruefen($_IPS[\'TARGET\']);');
    }

    protected function lichtUebernehmen(): void
    {
        if ($this->lichtArt() === FrigateHubLicht::AUS) {
            $this->SetBuffer('Licht', '');
            // War noch an (z. B. ausgeschaltet während des Nachlaufs): zurück in den Zustand von vorher
            if ($this->ReadAttributeString('LichtVorher') !== '') {
                $this->lichtSchalten(false);
            }
            $this->SetTimerInterval('Licht', 0);
            return;
        }
        $this->variableAnlegen('Licht', FrigateHubText::t('Light on detection'), VARIABLETYPE_BOOLEAN, self::anzeigeJaNein(FrigateHubText::t('on'), FrigateHubText::t('off')), 14, true);
        // Nach einem Neustart von Symcon mitten im Nachlauf: Timer prüft und schaltet zurück
        $this->SetTimerInterval('Licht', $this->ReadAttributeString('LichtVorher') !== '' ? 5000 : 0);
    }

    /** frigate/events einarbeiten. */
    protected function lichtEvent(string $typ, array $after): void
    {
        if ($this->lichtArt() === FrigateHubLicht::AUS) {
            return;
        }
        $zustand = (array)json_decode($this->GetBuffer('Licht'), true);
        $zustand = FrigateHubLicht::beobachten($zustand, $typ, $after, (float)time(), $this->lichtObjekte(), $this->lichtZonen());
        $this->SetBuffer('Licht', (string)json_encode($zustand));
        $this->LichtPruefen();
    }

    /** FRIG_LichtPruefen($id): Soll-Zustand herstellen (Timer). Liefert den Status. */
    public function LichtPruefen(): string
    {
        $zustand = FrigateHubLicht::aufraeumen((array)json_decode($this->GetBuffer('Licht'), true), (float)time());
        $this->SetBuffer('Licht', (string)json_encode($zustand));
        $soll = FrigateHubLicht::soll($zustand, (float)time(), $this->ReadPropertyInteger('LichtNachlauf'));
        $manuell = !empty($zustand['manuell']);
        $an = $this->lichtArt() !== FrigateHubLicht::AUS && ($manuell || ($soll['an'] && $this->lichtDunkel()));
        $ist = $this->ReadAttributeString('LichtVorher') !== '';
        if ($an !== $ist) {
            $this->lichtSchalten($an);
        }
        // Zurückschalten gescheitert (Kamera kurz weg): in 30 s erneut
        $offen = !$an && $this->ReadAttributeString('LichtVorher') !== '';
        $this->SetTimerInterval('Licht', $offen ? 30000 : ($soll['pruefen'] > 0 ? max(5, $soll['pruefen']) * 1000 : 0));
        return $this->lichtStatus();
    }

    /** Variable „Licht" von Hand: an hält, bis man es wieder ausschaltet. */
    protected function lichtAktion(bool $an): void
    {
        $zustand = (array)json_decode($this->GetBuffer('Licht'), true);
        $zustand['manuell'] = $an;
        $this->SetBuffer('Licht', (string)json_encode($zustand));
        $this->LichtPruefen();
    }

    /**
     * FRIG_LichtZugangAusFrigate($id): Benutzer und Passwort der Kamera aus Frigates Konfiguration übernehmen
     * (dieselben, mit denen Frigate den Stream holt). Braucht Frigate-Adminrechte (Port 5000 oder Anmeldung als Admin).
     */
    public function LichtZugangAusFrigate(): string
    {
        $config = $this->apiHolen('/api/config');
        $roh = $this->apiRoh('/api/config/raw', 'application/json');
        if ($config === null || $roh === null) {
            return $this->lichtMelden(FrigateHubText::t('⛔ Frigate configuration not readable (%s).', $this->apiFehler));
        }
        $yaml = json_decode($roh, true);
        $yaml = is_string($yaml) ? $yaml : $roh;
        $eingaenge = array_column((array)($config['cameras'][$this->kamera()]['ffmpeg']['inputs'] ?? []), 'path');
        $z = FrigateHubLicht::zugangAusFrigate($yaml, $eingaenge);
        if ($z === null) {
            return $this->lichtMelden(FrigateHubText::t('⛔ No camera address with user and password found in Frigate\'s configuration for this camera.'));
        }
        $this->WriteAttributeString('LichtZugang', (string)json_encode($z));
        return $this->lichtMelden(FrigateHubText::t('✅ Camera access taken over from Frigate (%s, user %s).', $z['host'], $z['benutzer']) . ' ' . $this->lichtKameraPruefen());
    }

    /** FRIG_LichtZugangSetzen($id, $Adresse, $Benutzer, $Passwort): Zugang zur Kamera von Hand. */
    public function LichtZugangSetzen(string $Adresse, string $Benutzer, string $Passwort): string
    {
        $this->UpdateFormField('LichtPasswort', 'value', '');
        $host = trim(preg_replace('#^https?://#', '', trim($Adresse)), '/');
        if ($host === '' || trim($Benutzer) === '' || $Passwort === '') {
            return $this->lichtMelden(FrigateHubText::t('⚠️ Please enter address, user and password of the camera.'));
        }
        $this->WriteAttributeString('LichtZugang', (string)json_encode(['host' => $host, 'benutzer' => trim($Benutzer), 'passwort' => $Passwort]));
        return $this->lichtMelden(FrigateHubText::t('✅ Camera access saved (%s).', $host) . ' ' . $this->lichtKameraPruefen());
    }

    /** Kann die Kamera ihr Licht über ISAPI schalten? Text für den Status. */
    private function lichtKameraPruefen(): string
    {
        $a = $this->isapi('GET', '/ISAPI/Image/channels/1/supplementLight/capabilities');
        if ($a['code'] !== 200) {
            return FrigateHubText::t('⛔ The camera does not answer the light interface (%s).', $a['code'] === 0 ? $a['fehler'] : 'HTTP ' . $a['code']);
        }
        $modi = FrigateHubLicht::modi($a['body']);
        if (!in_array(FrigateHubLicht::MODUS_AN, $modi, true)) {
            return FrigateHubText::t('⛔ The camera has no white light that can be switched (modes: %s).', $modi === [] ? '-' : implode(', ', $modi));
        }
        return FrigateHubText::t('Camera light can be switched (modes: %s).', implode(', ', $modi));
    }

    /** Licht an/aus; „aus" heißt: zurück in den Zustand von vorher. */
    private function lichtSchalten(bool $an): void
    {
        $art = $this->lichtArt();
        $vorher = json_decode($this->ReadAttributeString('LichtVorher'), true);
        $zeit = date(FrigateHubText::t('Y-m-d H:i:s'));
        if ($an) {
            if ($art === FrigateHubLicht::KAMERA) {
                $a = $this->isapi('GET', '/ISAPI/Image/channels/1/supplementLight');
                $modus = $a['code'] === 200 ? FrigateHubLicht::modus($a['body']) : '';
                if ($modus === '') {
                    $this->lichtMelden(FrigateHubText::t('⛔ %s light not switched on: camera not reachable (%s).', $zeit, $a['code'] === 0 ? $a['fehler'] : 'HTTP ' . $a['code']), true);
                    return;
                }
                // Schon weiß (von Hand gestellt): nichts tun und später nichts zurückstellen
                if ($modus !== FrigateHubLicht::MODUS_AN) {
                    $p = $this->isapi('PUT', '/ISAPI/Image/channels/1/supplementLight', (string)FrigateHubLicht::mitModus($a['body'], FrigateHubLicht::MODUS_AN));
                    if ($p['code'] !== 200) {
                        $this->lichtMelden(FrigateHubText::t('⛔ %s light not switched on: camera refused (%s).', $zeit, 'HTTP ' . $p['code']), true);
                        return;
                    }
                }
                $this->WriteAttributeString('LichtVorher', (string)json_encode(['modus' => $modus === FrigateHubLicht::MODUS_AN ? '' : $modus]));
            } elseif ($art === FrigateHubLicht::VARIABLE) {
                $v = $this->ReadPropertyInteger('LichtVariable');
                if (!IPS_VariableExists($v)) {
                    $this->lichtMelden(FrigateHubText::t('⛔ %s light not switched on: no variable selected.', $zeit), true);
                    return;
                }
                $alt = GetValue($v);
                $neu = $this->lichtWertAn($v);
                if ($alt != $neu) {
                    RequestAction($v, $neu);
                }
                $this->WriteAttributeString('LichtVorher', (string)json_encode(['wert' => $alt != $neu ? $alt : null, 'geschaltet' => $alt != $neu]));
            }
            $this->lichtMelden(FrigateHubText::t('💡 %s light switched on.', $zeit));
            $this->lichtAnzeigen(true);
            return;
        }
        if (!is_array($vorher)) {
            $this->lichtAnzeigen(false);
            return;
        }
        if (array_key_exists('modus', $vorher) && $vorher['modus'] !== '') {
            $a = $this->isapi('GET', '/ISAPI/Image/channels/1/supplementLight');
            $xml = $a['code'] === 200 ? FrigateHubLicht::mitModus($a['body'], (string)$vorher['modus']) : null;
            $p = $xml !== null ? $this->isapi('PUT', '/ISAPI/Image/channels/1/supplementLight', $xml) : $a;
            if ($p['code'] !== 200) {
                // Später erneut: LichtVorher bleibt stehen, der Timer versucht es wieder
                $this->lichtMelden(FrigateHubText::t('⚠️ %s light not switched back yet (%s), trying again.', $zeit, $p['code'] === 0 ? $p['fehler'] : 'HTTP ' . $p['code']), true);
                return;
            }
        } elseif (!empty($vorher['geschaltet'])) {
            $v = $this->ReadPropertyInteger('LichtVariable');
            if (IPS_VariableExists($v)) {
                RequestAction($v, $vorher['wert']);
            }
        }
        $this->WriteAttributeString('LichtVorher', '');
        $this->lichtMelden(FrigateHubText::t('%s light switched back.', $zeit));
        $this->lichtAnzeigen(false);
    }

    /** „An"-Wert der gewählten Variable: Boolean true, Zahl = eingestellter Wert. */
    private function lichtWertAn(int $v)
    {
        $typ = IPS_GetVariable($v)['VariableType'];
        if ($typ === VARIABLETYPE_BOOLEAN) {
            return true;
        }
        return $typ === VARIABLETYPE_FLOAT ? (float)$this->ReadPropertyInteger('LichtWert') : $this->ReadPropertyInteger('LichtWert');
    }

    private function lichtAnzeigen(bool $an): void
    {
        if (@$this->GetIDForIdent('Licht') !== false && $this->GetValue('Licht') !== $an) {
            $this->SetValue('Licht', $an);
        }
    }

    /** Dunkel laut Symcons Standort-Modul (IsDay); ohne Standort-Modul immer „dunkel“. */
    private function lichtDunkel(): bool
    {
        if (!$this->ReadPropertyBoolean('LichtNurDunkel')) {
            return true;
        }
        foreach (IPS_GetInstanceListByModuleID(self::$FRIGATEHUB_ORT_GUID) as $ort) {
            $tag = @IPS_GetObjectIDByIdent('IsDay', $ort);
            if ($tag !== false) {
                return !GetValueBoolean($tag);
            }
        }
        return true;
    }

    private function lichtArt(): int
    {
        $art = $this->ReadPropertyInteger('LichtArt');
        return in_array($art, [FrigateHubLicht::KAMERA, FrigateHubLicht::VARIABLE], true) ? $art : FrigateHubLicht::AUS;
    }

    private function lichtObjekte(): array
    {
        $o = array_values(array_filter(array_map('trim', explode(',', $this->ReadPropertyString('LichtObjekte'))), fn ($x) => $x !== ''));
        return $o === [] ? ['person'] : $o;
    }

    private function lichtZonen(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->ReadPropertyString('LichtZonen'))), fn ($z) => $z !== ''));
    }

    private function lichtMelden(string $text, bool $warnung = false): string
    {
        $this->WriteAttributeString('LichtStatus', $text);
        if ($warnung) {
            $this->LogMessage($text, KL_WARNING);
        }
        $this->UpdateFormField('LichtStatus', 'caption', $text);
        return $text;
    }

    private function lichtStatus(): string
    {
        $s = $this->ReadAttributeString('LichtStatus');
        return $s !== '' ? $s : FrigateHubText::t('Light not switched yet.');
    }

    /**
     * ISAPI-Anfrage an die Kamera (Digest). Prüfstand-Haken.
     *
     * @return array{code: int, body: string, fehler: string}
     */
    protected function isapi(string $methode, string $pfad, ?string $inhalt = null): array
    {
        $z = json_decode($this->ReadAttributeString('LichtZugang'), true);
        if (!is_array($z) || ($z['host'] ?? '') === '') {
            return ['code' => 0, 'body' => '', 'fehler' => FrigateHubText::t('no camera access entered')];
        }
        $ch = curl_init('http://' . $z['host'] . $pfad);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST | CURLAUTH_BASIC, CURLOPT_USERPWD => $z['benutzer'] . ':' . $z['passwort']]);
        if ($methode === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$inhalt);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml']);
        }
        $antwort = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        return ['code' => $antwort === false ? 0 : $code, 'body' => (string)$antwort, 'fehler' => $fehler !== '' ? $fehler : FrigateHubText::t('no response')];
    }

    /** Formular-Panel. */
    protected function lichtPanel(): array
    {
        $z = json_decode($this->ReadAttributeString('LichtZugang'), true);
        $zugang = is_array($z) ? FrigateHubText::t('Camera access: %s, user %s.', $z['host'], $z['benutzer']) : FrigateHubText::t('No camera access saved yet.');
        $bekannt = $this->zonen();
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '💡  Light on detection',
            'items' => [
                ['type' => 'Label', 'caption' => 'Switches a light on as soon as Frigate detects a person (or another chosen object) and back to its previous state after the run-on time. Either the camera\'s own white light (Hikvision/Annke with smart hybrid light or ColorVu, via ISAPI) or any Symcon variable, e.g. the yard light.'],
                ['type' => 'Select', 'name' => 'LichtArt', 'caption' => 'Light', 'options' => [
                    ['caption' => 'off', 'value' => FrigateHubLicht::AUS],
                    ['caption' => 'camera\'s own white light (Hikvision/Annke)', 'value' => FrigateHubLicht::KAMERA],
                    ['caption' => 'Symcon variable (switch or dimmer)', 'value' => FrigateHubLicht::VARIABLE],
                ]],
                ['type' => 'SelectVariable', 'name' => 'LichtVariable', 'caption' => 'Variable (only for “Symcon variable”)'],
                ['type' => 'NumberSpinner', 'name' => 'LichtWert', 'caption' => 'Value for “on” with a dimmer', 'minimum' => 1, 'maximum' => 255],
                ['type' => 'ValidationTextBox', 'name' => 'LichtObjekte', 'caption' => 'Objects (comma-separated, e.g. person, car)'],
                ['type' => 'ValidationTextBox', 'name' => 'LichtZonen', 'caption' => 'Only in these Frigate zones (comma-separated, empty = whole image)'],
                ['type' => 'Label', 'caption' => $bekannt === [] ? FrigateHubText::t('No zones known yet (they are learned from Frigate\'s events).') : FrigateHubText::t('Known zones: %s', implode(', ', $bekannt))],
                ['type' => 'NumberSpinner', 'name' => 'LichtNachlauf', 'caption' => 'Run-on time after the last detection', 'suffix' => 's', 'minimum' => FrigateHubLicht::NACHLAUF_MIN, 'maximum' => FrigateHubLicht::NACHLAUF_MAX],
                ['type' => 'CheckBox', 'name' => 'LichtNurDunkel', 'caption' => 'Only when it is dark (Symcon location: “Is day”)'],
                ['type' => 'Label', 'caption' => 'Camera light: “on” sets the light mode to white light, “off” back to the mode before (e.g. smart/event or infrared). The camera itself only lights up in the dark. Each switch is a setting change in the camera — therefore only when dark and only for the chosen objects.'],
                ['type' => 'Label', 'caption' => $zugang],
                ['type' => 'Button', 'caption' => 'Take over camera access from Frigate', 'onClick' => 'echo FRIG_LichtZugangAusFrigate($id);'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'LichtAdresse', 'caption' => 'Camera address'],
                    ['type' => 'ValidationTextBox', 'name' => 'LichtBenutzer', 'caption' => 'User'],
                    ['type' => 'PasswordTextBox', 'name' => 'LichtPasswort', 'caption' => 'Password'],
                    ['type' => 'Button', 'caption' => 'Save access', 'onClick' => 'echo FRIG_LichtZugangSetzen($id, $LichtAdresse, $LichtBenutzer, $LichtPasswort);'],
                ]],
                ['type' => 'Label', 'caption' => 'The camera password is only stored in the instance (attribute), never shown and never written to the log. Variable “Light on detection” switches the light by hand as well (stays on until switched off).'],
                ['type' => 'Label', 'name' => 'LichtStatus', 'caption' => $this->lichtStatus()],
            ],
        ];
    }
}
