<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubSensor.php';
require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * „Kamera als Sensor" (0.16.0) für FrigateHubKamera: Zustände, „steht seit", Verweilen,
 * Geschwindigkeit, bekannte/unbekannte Gesichter und Kennzeichen, Auslöser, Sprache,
 * Tageszähler und Sabotage. Bewertung in libs/FrigateHubSensor.php.
 *
 * Erwartet aus der Klasse: variableAnlegen(), kamera(), apiUrl(), apiRoh(), pushSenden(),
 * pushZiele(), FrigateHubTopics, Konstante POS_SENSOR.
 */
trait FrigateHubKameraSensor
{
    /** Ab so vielen Minuten ununterbrochener Bewegung meldet die Sabotage-Prüfung „Dauerbewegung". */
    private static int $FRIGATEHUB_DAUERBEWEGUNG = 15;

    protected function sensorRegistrieren(): void
    {
        $this->RegisterPropertyInteger('VerweilSekunden', 30);
        $this->RegisterPropertyString('VerweilKlassen', 'person');
        $this->RegisterPropertyInteger('SabotageMinuten', 5);
        $this->RegisterPropertyBoolean('SabotageMelden', true);
        $this->RegisterPropertyBoolean('Tageszaehler', true);
        $this->RegisterAttributeString('Sabotage', '{}');
        $this->RegisterAttributeString('Statistik', '{}');
        $this->RegisterAttributeString('Einheit', ' km/h');
        // Angelegte „steht seit"-Variablen (zum Zurücksetzen) und Beginn der laufenden Bewegung
        $this->RegisterAttributeString('StehtIdents', '[]');
        $this->RegisterAttributeInteger('BewegungSeit', 0);
        $this->RegisterTimer('Verweilen', 0, 'FRIG_SensorPruefen($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Bildpruefung', 0, 'FRIG_BildPruefen($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Tageswechsel', 0, 'FRIG_Tageswechsel($_IPS[\'TARGET\']);');
    }

    protected function sensorUebernehmen(): void
    {
        $aktiv = $this->kamera() !== '';
        $minuten = max(0, $this->ReadPropertyInteger('SabotageMinuten'));
        $this->SetTimerInterval('Bildpruefung', $aktiv && $minuten > 0 && $this->apiUrl() !== '' ? $minuten * 60000 : 0);
        $this->SetTimerInterval('Tageswechsel', $aktiv && $this->ReadPropertyBoolean('Tageszaehler') ? $this->bisMitternacht() : 0);
        $this->verweilTimer();
    }

    /* ---------------------------------------------------------------- Öffentlich (Timer) */

    /** FRIG_SensorPruefen($id): Verweilen und „steht seit" neu bewerten (Timer, auch von Hand). */
    public function SensorPruefen(): string
    {
        $this->stehenUndVerweilenSetzen();
        return '✅ Verweilen und Stillstand neu bewertet.';
    }

    /** FRIG_Tageswechsel($id): Tageszähler auf 0 (Timer um Mitternacht). */
    public function Tageswechsel(): void
    {
        $alt = (array)json_decode($this->ReadAttributeString('Statistik'), true);
        foreach (array_keys((array)($alt['zaehler'] ?? [])) as $schluessel) {
            $ident = FrigateHubTopics::ident('Heute', (string)$schluessel);
            if (@$this->GetIDForIdent($ident) !== false) {
                $this->SetValue($ident, 0);
            }
        }
        $this->WriteAttributeString('Statistik', (string)json_encode(['tag' => date('Y-m-d'), 'zaehler' => []]));
        $this->SetTimerInterval('Tageswechsel', $this->ReadPropertyBoolean('Tageszaehler') ? $this->bisMitternacht() : 0);
    }

    /**
     * FRIG_BildPruefen($id): Sabotage-Prüfung — kleines Livebild gegen die gelernte Ansicht dieser
     * Tagesstunde, dazu Dauerbewegung. Rückgabe: Text fürs Formular.
     */
    public function BildPruefen(): string
    {
        if ($this->kamera() === '' || $this->apiUrl() === '') {
            return 'ℹ️ Die Bildprüfung braucht Kamera-ID und Frigate-Adresse.';
        }
        if (!function_exists('imagecreatefromstring')) {
            return '⛔ Symcon hat keine Bildfunktionen (PHP-Erweiterung GD) — die Bildprüfung ist nicht möglich.';
        }
        $teile = [];
        $auffaellig = false;
        $bild = $this->apiRoh('/api/' . rawurlencode($this->kamera()) . '/latest.jpg?' . FrigateHubTopics::hoehe(120), 'image/jpeg');
        $k = $bild === null ? null : FrigateHubSensor::kennwerte($bild);
        if ($k !== null) {
            $z = json_decode($this->ReadAttributeString('Sabotage'), true);
            $e = FrigateHubSensor::sabotage(is_array($z) ? $z : [], $k, (int)date('G'));
            $this->WriteAttributeString('Sabotage', (string)json_encode($e['zustand']));
            $auffaellig = $e['auffaellig'];
            if ($auffaellig) {
                $teile[] = $e['text'];
            }
        }
        $seit = $this->ReadAttributeInteger('BewegungSeit');
        if ($seit > 0) {
            if (time() - $seit >= self::$FRIGATEHUB_DAUERBEWEGUNG * 60) {
                $auffaellig = true;
                $teile[] = 'Dauerbewegung seit ' . date('H:i', $seit) . ' — Spinnennetz, Regen auf der Linse oder eine Pflanze im Bild?';
            }
        }
        $vorher = @$this->GetIDForIdent('BildAuffaellig') !== false ? (bool)$this->GetValue('BildAuffaellig') : false;
        $this->variableAnlegen('BildAuffaellig', 'Bild auffällig', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('auffällig', 'unauffällig'), self::POS_SENSOR + 90);
        $this->variableAnlegen('Bildzustand', 'Bildzustand', VARIABLETYPE_STRING, self::anzeigeText(), self::POS_SENSOR + 91);
        $text = $k === null && $teile === [] ? 'Kein Bild von Frigate — Prüfung ausgesetzt.' : ($teile === [] ? 'Bild unauffällig.' : implode(' ', $teile));
        $this->SetValue('BildAuffaellig', $auffaellig);
        $this->SetValue('Bildzustand', $text);
        if ($auffaellig && !$vorher) {
            $this->LogMessage('Bildprüfung: ' . $text, KL_WARNING);
            if ($this->ReadPropertyBoolean('SabotageMelden') && $this->pushZiele() !== []) {
                $this->pushSenden('🔧 ' . IPS_GetName($this->InstanceID), $text, true);
            }
        }
        return ($auffaellig ? '⚠️ ' : '✅ ') . date('d.m.Y H:i:s') . ': ' . $text;
    }

    /** FRIG_BildNeuLernen($id): gelernte Ansichten verwerfen (nach gewolltem Umbau/Neuausrichtung). */
    public function BildNeuLernen(): string
    {
        $this->WriteAttributeString('Sabotage', '{}');
        return '✅ Gelernte Ansichten verworfen. Die Bildprüfung lernt die Kamera jetzt neu (je Tagesstunde ab dem dritten Bild).';
    }

    /* ---------------------------------------------------------------- aus ReceiveData */

    /** frigate/<kamera>/motion: Beginn der Bewegung merken (für „Dauerbewegung"). */
    protected function sensorBewegung(bool $an): void
    {
        if (!$an) {
            $this->WriteAttributeInteger('BewegungSeit', 0);
        } elseif ($this->ReadAttributeInteger('BewegungSeit') === 0) {
            $this->WriteAttributeInteger('BewegungSeit', time());
        }
    }

    /** frigate/<kamera>/classification/<modell>: Zustand als Text (z. B. open/closed). */
    protected function sensorZustand(string $modell, string $payload): void
    {
        $wert = trim($payload);
        if ($modell === '' || $wert === '') {
            return;
        }
        $ident = FrigateHubTopics::ident('Zustand', $modell);
        $this->variableAnlegen($ident, 'Zustand ' . $modell, VARIABLETYPE_STRING, self::anzeigeText(), self::POS_SENSOR);
        $this->SetValue($ident, $wert);
    }

    /** frigate/<kamera>/audio/transcription: gesprochener Text (Frigate-Transkription). */
    protected function sensorTranskript(string $payload): void
    {
        $text = trim($payload);
        if ($text === '') {
            return;
        }
        $this->variableAnlegen('Gesprochen', 'Gesprochener Text', VARIABLETYPE_STRING, self::anzeigeText(), self::POS_SENSOR + 60);
        $this->SetValue('Gesprochen', FrigateHubTopics::kuerzenBytes(date('d.m.Y H:i:s') . ' – ' . $text, 1000));
    }

    /** frigate/triggers (semantische Suche): Zeitpunkt je Auslöser dieser Kamera. */
    protected function sensorAusloeser(string $payload): void
    {
        $t = json_decode($payload, true);
        if (!is_array($t) || (string)($t['camera'] ?? '') !== $this->kamera() || trim((string)($t['name'] ?? '')) === '') {
            return;
        }
        $name = trim((string)$t['name']);
        $ident = FrigateHubTopics::ident('Ausloeser', $name);
        $this->variableAnlegen($ident, 'Auslöser ' . $name, VARIABLETYPE_INTEGER, self::anzeigeZeitpunkt(), self::POS_SENSOR + 70);
        $this->SetValue($ident, time());
    }

    /** frigate/events: Objektliste, „steht seit", Verweilen, Geschwindigkeit, Kennzeichen, Tageszähler. */
    protected function sensorEvent(string $typ, array $after): void
    {
        $jetzt = time();
        $objekte = (array)json_decode($this->GetBuffer('Objekte'), true);
        $id = (string)($after['id'] ?? '');
        $alt = $objekte[$id] ?? null;
        $e = FrigateHubSensor::nachfuehren($objekte, $typ, $after, $jetzt);
        $objekte = $e['objekte'];
        $label = (string)($after['label'] ?? '');

        if ($e['neu'] && $label !== '' && $this->ReadPropertyBoolean('Tageszaehler')) {
            $this->zaehlen($label, 'Heute: ' . FrigateHubTopics::objektName($label));
        }
        $fz = FrigateHubSensor::fahrzeug($after);
        if ($fz !== null && isset($objekte[$id])) {
            $objekte[$id]['kennzeichen'] = $fz['kennzeichen'];
            if ($fz['name'] !== '') {
                $objekte[$id]['fahrzeug'] = $fz['name'];
            }
            $this->fahrzeugSetzen($fz['name'], $fz['kennzeichen']);
        }
        $ende = $e['ende'] ?? ($typ === 'end' ? $alt : null);
        if ($ende !== null) {
            $this->objektEnde($ende, $after);
        }
        $this->SetBuffer('Objekte', (string)json_encode($objekte));
        $this->stehenUndVerweilenSetzen();
    }

    /** Alarm-Review (neu): Tageszähler „Alarme". */
    protected function sensorAlarm(): void
    {
        if ($this->ReadPropertyBoolean('Tageszaehler')) {
            $this->zaehlen('alarme', 'Heute: Alarme');
        }
    }

    /**
     * frigate/tracked_object_update (roh): Gesichts- und Kennzeichenversuche an das Objekt hängen —
     * ob eine Person unbekannt war, steht erst am Ende fest (Frigate versucht mehrmals).
     */
    protected function sensorObjektUpdate(array $u): void
    {
        $art = (string)($u['type'] ?? '');
        if (!in_array($art, ['face', 'lpr'], true) || (string)($u['camera'] ?? '') !== $this->kamera()) {
            return;
        }
        $id = (string)($u['id'] ?? '');
        $name = is_string($u['name'] ?? null) && trim($u['name']) !== '' && $u['name'] !== 'unknown' ? trim($u['name']) : '';
        $objekte = (array)json_decode($this->GetBuffer('Objekte'), true);
        if ($art === 'face') {
            if ($name !== '') {
                $this->variableAnlegen('PersonName', 'Erkannte Person (Name)', VARIABLETYPE_STRING, self::anzeigeText(), self::POS_SENSOR + 40);
                $this->SetValue('PersonName', $name);
            }
            if (isset($objekte[$id])) {
                $objekte[$id]['gesicht'] = $name !== '' ? $name : (string)($objekte[$id]['gesicht'] ?? '');
                $objekte[$id]['gesichtVersuch'] = true;
            }
        } else {
            $kennzeichen = trim((string)($u['plate'] ?? ''));
            $this->fahrzeugSetzen($name, $kennzeichen);
            if (isset($objekte[$id])) {
                $objekte[$id]['kennzeichen'] = $kennzeichen !== '' ? $kennzeichen : (string)($objekte[$id]['kennzeichen'] ?? '');
                if ($name !== '') {
                    $objekte[$id]['fahrzeug'] = $name;
                }
            }
        }
        $this->SetBuffer('Objekte', (string)json_encode($objekte));
    }

    /* ---------------------------------------------------------------- intern */

    /** Objekt verschwunden: Paket entfernt, unbekanntes Gesicht/Kennzeichen, Geschwindigkeit. */
    private function objektEnde(array $o, array $after): void
    {
        $label = (string)($o['label'] ?? '');
        if ($label === 'package' && (int)($o['steht'] ?? 0) > 0) {
            $this->variableAnlegen('PaketEntfernt', 'Paket entfernt', VARIABLETYPE_INTEGER, self::anzeigeZeitpunkt(), self::POS_SENSOR + 30);
            $this->SetValue('PaketEntfernt', time());
        }
        if (!empty($o['gesichtVersuch']) && (string)($o['gesicht'] ?? '') === '') {
            $this->variableAnlegen('UnbekanntesGesicht', 'Unbekanntes Gesicht', VARIABLETYPE_INTEGER, self::anzeigeZeitpunkt(), self::POS_SENSOR + 41);
            $this->SetValue('UnbekanntesGesicht', time());
        }
        if ((string)($o['kennzeichen'] ?? '') !== '' && (string)($o['fahrzeug'] ?? '') === '') {
            $this->variableAnlegen('UnbekanntesKennzeichen', 'Unbekanntes Kennzeichen', VARIABLETYPE_INTEGER, self::anzeigeZeitpunkt(), self::POS_SENSOR + 51);
            $this->SetValue('UnbekanntesKennzeichen', time());
        }
        $tempo = $o['geschwindigkeit'] ?? (is_numeric($after['average_estimated_speed'] ?? null) && (float)$after['average_estimated_speed'] > 0 ? round((float)$after['average_estimated_speed'], 1) : null);
        if ($tempo !== null) {
            $this->variableAnlegen('Geschwindigkeit', 'Letzte Geschwindigkeit', VARIABLETYPE_FLOAT,
                ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $this->ReadAttributeString('Einheit'), 'DIGITS' => 1], self::POS_SENSOR + 55);
            $this->SetValue('Geschwindigkeit', (float)$tempo);
            $this->variableAnlegen('GeschwindigkeitObjekt', 'Letzte Geschwindigkeit: Objekt', VARIABLETYPE_STRING, self::anzeigeText(), self::POS_SENSOR + 56);
            $this->SetValue('GeschwindigkeitObjekt', FrigateHubTopics::objektEinzahl($label) . ', ' . date('d.m.Y H:i:s'));
        }
    }

    private function fahrzeugSetzen(string $name, string $kennzeichen): void
    {
        if ($name === '' && $kennzeichen === '') {
            return;
        }
        $this->variableAnlegen('Fahrzeug', 'Erkanntes Fahrzeug', VARIABLETYPE_STRING, self::anzeigeText(), self::POS_SENSOR + 50);
        $this->SetValue('Fahrzeug', $name !== '' ? $name . ($kennzeichen !== '' ? ' (' . $kennzeichen . ')' : '') : $kennzeichen);
    }

    /** „steht seit" je Klasse und Zone, Verweilen je Zone setzen; Timer auf die nächste Schwelle. */
    private function stehenUndVerweilenSetzen(): void
    {
        $objekte = (array)json_decode($this->GetBuffer('Objekte'), true);
        $s = FrigateHubSensor::stehen($objekte);
        // Bisherige „steht seit" ohne stehendes Objekt zurück auf 0 („-")
        $jetzt = $this->stehenIdents($s);
        $bekannt = (array)json_decode($this->ReadAttributeString('StehtIdents'), true);
        foreach (array_diff($bekannt, $jetzt) as $ident) {
            if (@$this->GetIDForIdent($ident) !== false && $this->GetValue($ident) !== 0) {
                $this->SetValue($ident, 0);
            }
        }
        $alle = array_values(array_unique(array_merge($bekannt, $jetzt)));
        if ($alle !== $bekannt) {
            $this->WriteAttributeString('StehtIdents', (string)json_encode($alle));
        }
        foreach ($s['klasse'] as $label => $seit) {
            $ident = FrigateHubTopics::ident('Steht', $label);
            $this->variableAnlegen($ident, FrigateHubTopics::objektEinzahl($label) . ' steht seit', VARIABLETYPE_INTEGER, self::anzeigeZeitpunkt(), self::POS_SENSOR + 10);
            $this->wertSetzen($ident, $seit);
        }
        foreach ($s['zone'] as $schluessel => $seit) {
            [$zone, $label] = explode('|', $schluessel, 2);
            $ident = FrigateHubTopics::ident('Steht', $zone, $label);
            $this->variableAnlegen($ident, $zone . ': ' . FrigateHubTopics::objektEinzahl($label) . ' steht seit', VARIABLETYPE_INTEGER, self::anzeigeZeitpunkt(), self::POS_SENSOR + 11);
            $this->wertSetzen($ident, $seit);
        }

        $schwelle = $this->ReadPropertyInteger('VerweilSekunden');
        if ($schwelle > 0) {
            $verweilt = FrigateHubSensor::verweilen($objekte, time(), $schwelle, $this->verweilKlassen());
            foreach ($this->zonen() as $zone) {
                $ident = FrigateHubTopics::ident('Verweilt', $zone);
                $wert = $verweilt[$zone] ?? false;
                if ($wert || @$this->GetIDForIdent($ident) !== false) {
                    $this->variableAnlegen($ident, $zone . ': verweilt', VARIABLETYPE_BOOLEAN, self::anzeigeJaNein('verweilt', 'frei'), self::POS_SENSOR + 20);
                    $this->wertSetzen($ident, $wert);
                }
            }
        }
        $this->verweilTimer();
    }

    /** Nur bei Änderung schreiben: jede frigate/events-Nachricht bewertet neu, das soll kein Update-Sturm werden. */
    private function wertSetzen(string $ident, $wert): void
    {
        if ($this->GetValue($ident) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    /** @return string[] Idents der „steht seit"-Variablen, die gerade gelten */
    private function stehenIdents(array $s): array
    {
        $out = [];
        foreach (array_keys($s['klasse']) as $label) {
            $out[] = FrigateHubTopics::ident('Steht', (string)$label);
        }
        foreach (array_keys($s['zone']) as $schluessel) {
            [$zone, $label] = explode('|', (string)$schluessel, 2);
            $out[] = FrigateHubTopics::ident('Steht', $zone, $label);
        }
        return $out;
    }

    /** Timer genau auf den Moment, an dem das nächste Objekt die Verweil-Schwelle erreicht. */
    private function verweilTimer(): void
    {
        $schwelle = $this->ReadPropertyInteger('VerweilSekunden');
        $objekte = (array)json_decode($this->GetBuffer('Objekte'), true);
        $naechste = $schwelle > 0 ? FrigateHubSensor::naechsteVerweilPruefung($objekte, time(), $schwelle, $this->verweilKlassen()) : 0;
        $this->SetTimerInterval('Verweilen', $naechste > 0 ? max(1000, ($naechste - time()) * 1000 + 500) : 0);
    }

    /** @return string[] Klassen fürs Verweilen, leer = alle */
    private function verweilKlassen(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($this->ReadPropertyString('VerweilKlassen')))), 'strlen'));
    }

    private function zaehlen(string $schluessel, string $name): void
    {
        $stat = FrigateHubSensor::zaehlen((array)json_decode($this->ReadAttributeString('Statistik'), true), $schluessel, date('Y-m-d'));
        $this->WriteAttributeString('Statistik', (string)json_encode($stat));
        $ident = FrigateHubTopics::ident('Heute', $schluessel);
        $this->variableAnlegen($ident, $name, VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], self::POS_SENSOR + 80);
        $this->SetValue($ident, $stat['zaehler'][$schluessel]);
    }

    private function bisMitternacht(): int
    {
        return max(1000, (mktime(0, 0, 5, (int)date('n'), (int)date('j') + 1) - time()) * 1000);
    }

    private static function anzeigeZeitpunkt(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME];
    }

    /** Formular-Panel „🧠 Kamera als Sensor". */
    protected function sensorPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🧠  Kamera als Sensor',
            'items' => [
                ['type' => 'Label', 'caption' => 'Macht aus der Kamera Sensoren für Abläufe und Alarmanlagen. Die Variablen entstehen erst, wenn Frigate die Daten liefert — was Frigate nicht erkennt (Kennzeichen, Gesichter, Zustände, Geschwindigkeit, Sprache), muss dort eingerichtet sein.'],
                ['type' => 'Label', 'caption' => '„… steht seit": wann ein Objekt zum Stillstand kam (Frigate „stationary"), je Objektart und je Zone, z. B. „Carport: Auto steht seit". „-" = steht keins. Liegt ein Paket und verschwindet, setzt die Kamera „Paket entfernt".'],
                ['type' => 'NumberSpinner', 'name' => 'VerweilSekunden', 'caption' => 'Verweilen: so lange in einer Zone (0 = aus)', 'suffix' => 's', 'minimum' => 0, 'maximum' => 3600],
                ['type' => 'ValidationTextBox', 'name' => 'VerweilKlassen', 'caption' => 'Verweilen gilt für (Frigate-Objektarten, mit Komma, leer = alle)'],
                ['type' => 'Label', 'caption' => '„<Zone>: verweilt" ist ein besserer Alarm-Auslöser als „Person erkannt": Vorbeigehen löst ihn nicht aus, jemand, der vor der Tür stehen bleibt, schon.'],
                ['type' => 'CheckBox', 'name' => 'Tageszaehler', 'caption' => 'Tageszähler („Heute: Personen", „Heute: Alarme" …), um Mitternacht auf 0'],
                ['type' => 'NumberSpinner', 'name' => 'SabotageMinuten', 'caption' => 'Bildprüfung (abgedeckt, verstellt, Dauerbewegung) alle (0 = aus)', 'suffix' => 'min', 'minimum' => 0, 'maximum' => 60],
                ['type' => 'CheckBox', 'name' => 'SabotageMelden', 'caption' => 'Auffälliges Bild an die Ziele unter „Push und Meldungen" melden'],
                ['type' => 'Label', 'caption' => 'Die Bildprüfung lernt je Tagesstunde, wie das Bild normalerweise aussieht, und meldet erst nach drei auffälligen Prüfungen in Folge — Lichtwechsel und Wetter zählen nicht. Nach einem gewollten Umbau „Ansicht neu lernen".'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Bild jetzt prüfen', 'onClick' => 'echo FRIG_BildPruefen($id);'],
                    ['type' => 'Button', 'caption' => 'Ansicht neu lernen', 'onClick' => 'echo FRIG_BildNeuLernen($id);', 'confirm' => 'Gelernte Ansichten verwerfen und neu lernen?'],
                ]],
            ],
        ];
    }

    /** Zeilen fürs Doku-Panel. */
    protected function sensorDoku(): array
    {
        return [
            'Kamera als Sensor: „Zustand <Modell>" aus Frigates Zustandsklassifizierung (z. B. Tor open/closed; frigate/<kamera>/classification/<modell>, nur bei Änderung). „… steht seit" (Datum/Uhrzeit, „-" = keins) je Objektart und Zone. „<Zone>: verweilt" nach der eingestellten Zeit. „Paket entfernt" (Zeitpunkt).',
            '„Erkannte Person (Name)" und „Erkanntes Fahrzeug" (Name aus Frigates bekannten Kennzeichen, sonst das Kennzeichen); „Unbekanntes Gesicht"/„Unbekanntes Kennzeichen" (Zeitpunkt), wenn Frigate ein Gesicht/Kennzeichen sah, aber niemanden erkannte. „Letzte Geschwindigkeit" aus Zonen mit Geschwindigkeitsmessung. „Auslöser <Name>" aus Frigates semantischen Auslösern. „Gesprochener Text" aus Frigates Transkription.',
            'Tageszähler je Objektart und Alarme. Bildprüfung: „Bild auffällig" + „Bildzustand" (abgedeckt/einfarbig, verstellt, Dauerbewegung ab 15 min). Die Bildprüfung holt alle paar Minuten ein kleines Livebild (120 px) und braucht die Frigate-Adresse.',
        ];
    }
}
