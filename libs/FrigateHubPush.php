<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';
require_once __DIR__ . '/FrigateHubTopics.php';

/**
 * Meldungen an Kachel-Visualisierung, WebFront und E-Mail (SMTP) — gemeinsam für Kamera und
 * Frigate-Server. Erwartet die Properties PushZiele (Liste mit Spalte „Ziel") und PushTon, das
 * Attribut Pushstatus sowie die Konstanten WEBFRONT_GUID, KACHEL_GUID, SMTP_GUID und PUSH_TOENE
 * in der nutzenden Klasse (SUITE.md Stolperstein 22: zwei Oberflächen, zwei Funktionen).
 */
trait FrigateHubPush
{
    /**
     * Bild für eine E-Mail-Meldung: Medienobjekt-ID, 0 = keins vorhanden (Hinweis in der Mail),
     * -1 = diese Instanz verschickt keine Bilder.
     */
    abstract protected function pushBild(bool $livebild): int;

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
     * SMTP-Ziele bekommen eine E-Mail mit Bild (pushBild) — das Bild wird nur geholt, wenn ein
     * SMTP-Ziel eingetragen ist. Antippen einer Push-Meldung öffnet diese Instanz.
     */
    private function pushSenden(string $titel, string $text, bool $livebild = false): string
    {
        $ziele = $this->pushZiele();
        if ($ziele === []) {
            $status = FrigateHubText::t('⚠️ No target entered — please add a visualization under "Targets" and apply.');
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
                // Ziel = diese Instanz: Antippen der Meldung öffnet ihre Kachel.
                $gesendet = @VISU_PostNotificationEx($ziel, $titel, $text, 'Alert', $ton, $this->InstanceID);
                if ($gesendet === false) {
                    // Rückfall: ohne Ziel-Objekt (so live bestätigt 29.10.2026)
                    $gesendet = @VISU_PostNotificationEx($ziel, $titel, $text, 'Alert', $ton, 0);
                }
            } elseif ($modul === self::SMTP_GUID && function_exists('SMTP_SendMailMedia')) {
                $bild = $bild ?? $this->pushBild($livebild);
                $gesendet = $bild > 0 ? @SMTP_SendMailMedia($ziel, $titel, $text, $bild)
                    : @SMTP_SendMail($ziel, $titel, $text . ($bild === 0 ? "\n\n" . FrigateHubText::t('(No image available.)') : ''));
            } elseif ($modul === self::WEBFRONT_GUID && function_exists('WFC_PushNotification')) {
                $gesendet = @WFC_PushNotification($ziel, $titel, $text, $ton, $this->InstanceID);
            } else {
                $fehler[] = FrigateHubText::t('%s (no visualization)', $name);
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
            $status = FrigateHubText::t('✅ %s sent to %s.', $zeit, implode(', ', $ok));
        } else {
            $status = $ok === [] ? FrigateHubText::t('⛔ %s not delivered to %s.', $zeit, implode(', ', $fehler))
                : FrigateHubText::t('⚠️ %s not delivered to %s, sent to %s.', $zeit, implode(', ', $fehler), implode(', ', $ok));
            $this->LogMessage(FrigateHubText::t('Push not delivered to %s', implode(', ', $fehler)), KL_WARNING);
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
        return $this->pushZiele() === [] ? FrigateHubText::t('Push is off (no target entered).') : FrigateHubText::t('No message sent yet.');
    }

    private function tonOptionen(): array
    {
        $optionen = [];
        foreach (self::PUSH_TOENE as $ton) {
            $optionen[] = ['caption' => $ton, 'value' => $ton];
        }
        return $optionen;
    }

    /** Formularliste der Meldeziele (Kachel-Visualisierung, WebFront, E-Mail/SMTP). */
    private function pushZielListe(): array
    {
        return ['type' => 'List', 'name' => 'PushZiele', 'caption' => FrigateHubText::t('Targets (tile visualization, WebFront or email/SMTP)'), 'add' => true, 'delete' => true, 'rowCount' => 3,
            'columns' => [[
                'caption' => FrigateHubText::t('Target'), 'name' => 'Ziel', 'width' => 'auto', 'add' => 0,
                'edit' => ['type' => 'SelectInstance', 'validModules' => [self::KACHEL_GUID, self::WEBFRONT_GUID, self::SMTP_GUID]],
            ]],
        ];
    }
}
