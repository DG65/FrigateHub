<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubFilm.php';
require_once __DIR__ . '/FrigateHubText.php';

/**
 * „Filmsequenz zur Meldung" (0.22.0) für FrigateHubKamera: nach der Meldung mit Foto eine zweite E-Mail mit
 * bewegtem Bild (GIF) oder Videoclip (MP4) an die SMTP-Ziele. Logik in libs/FrigateHubFilm.php.
 *
 * Erwartet aus der Klasse: pushZiele(), kamera(), apiUrl(), apiRoh(), $apiFehler, $apiZeitlimit, SMTP_GUID.
 */
trait FrigateHubKameraFilm
{
    protected function filmRegistrieren(): void
    {
        $this->RegisterPropertyInteger('PushFilm', FrigateHubFilm::AUS);
        $this->RegisterPropertyInteger('PushFilmSekunden', 15);
        $this->RegisterAttributeString('Filmstatus', '');
        $this->RegisterTimer('Film', 0, 'FRIG_FilmSenden($_IPS[\'TARGET\']);');
    }

    protected function filmUebernehmen(): void
    {
        if ($this->filmArt() === FrigateHubFilm::AUS) {
            $this->SetBuffer('Film', '');
            $this->SetTimerInterval('Film', 0);
        }
    }

    /**
     * Film zu einer Meldung vormerken. $ereignis = Zeitpunkt, ab dem gefilmt wird (Unix-Zeit).
     * Läuft schon ein Auftrag, bleibt der (der erste Moment ist der wichtigste).
     */
    protected function filmVormerken(float $ereignis, string $titel, string $text): void
    {
        $art = $this->filmArt();
        if ($art === FrigateHubFilm::AUS || $this->filmZiele() === [] || $this->kamera() === '' || $this->apiUrl() === '') {
            return;
        }
        $offen = json_decode($this->GetBuffer('Film'), true);
        if (is_array($offen) && (int)($offen['faellig'] ?? 0) > time() - 600) {
            return;
        }
        $a = FrigateHubFilm::auftrag($art, $ereignis, $this->ReadPropertyInteger('PushFilmSekunden'), (float)time(), $titel, $text);
        $this->SetBuffer('Film', (string)json_encode($a));
        $this->SetTimerInterval('Film', FrigateHubFilm::warten($a, (float)time()) * 1000);
    }

    /** FRIG_FilmSenden($id): fälligen Film holen und mailen (Timer). Liefert den Status. */
    public function FilmSenden(): string
    {
        $this->SetTimerInterval('Film', 0);
        $a = json_decode($this->GetBuffer('Film'), true);
        if (!is_array($a)) {
            return FrigateHubText::t('No film pending.');
        }
        $art = (int)$a['art'];
        $jetzt = (float)time();
        $vorher = $this->apiZeitlimit;
        $this->apiZeitlimit = 30;   // Frigate setzt GIF/Clip erst zusammen
        $daten = $this->apiRoh(FrigateHubFilm::pfad($this->kamera(), $a, $jetzt), $art === FrigateHubFilm::MP4 ? 'video/mp4' : 'image/gif');
        $this->apiZeitlimit = $vorher;
        $grund = $daten === null ? ($this->apiFehler !== '' ? $this->apiFehler : FrigateHubText::t('no response')) : FrigateHubFilm::pruefen($art, $daten);
        if ($grund !== '') {
            $grund = $daten === null ? $grund : FrigateHubText::t($grund);
            $weiter = $grund !== FrigateHubText::t('too large') ? FrigateHubFilm::nochmal($a, $jetzt) : null;
            if ($weiter !== null) {
                $this->SetBuffer('Film', (string)json_encode($weiter));
                $this->SetTimerInterval('Film', FrigateHubFilm::warten($weiter, $jetzt) * 1000);
                $this->SendDebug('Film', 'noch nicht da (' . $grund . '), neuer Versuch', 0);
                return $grund;
            }
            $this->SetBuffer('Film', '');
            $status = FrigateHubText::t('⛔ %s film not sent: %s', date(FrigateHubText::t('Y-m-d H:i:s')), $grund);
            $this->WriteAttributeString('Filmstatus', $status);
            $this->LogMessage($status, KL_WARNING);
            return $status;
        }
        $this->SetBuffer('Film', '');
        $medium = $this->filmMedium($art, (string)$daten);
        if ($medium === 0) {
            $status = FrigateHubText::t('⛔ %s film not sent: %s', date(FrigateHubText::t('Y-m-d H:i:s')), FrigateHubText::t('media object could not be saved'));
            $this->WriteAttributeString('Filmstatus', $status);
            return $status;
        }
        $ok = [];
        $fehler = [];
        $text = $a['text'] . "\n\n" . FrigateHubText::t($art === FrigateHubFilm::MP4 ? 'Video clip of %d s attached.' : 'Moving image of %d s attached.', (int)$a['ende'] - (int)$a['start']);
        foreach ($this->filmZiele() as $ziel) {
            $gesendet = function_exists('SMTP_SendMailMedia') ? @SMTP_SendMailMedia($ziel, '🎞 ' . $a['titel'], $text, $medium) : false;
            if ($gesendet !== false) {
                $ok[] = IPS_GetName($ziel);
            } else {
                $fehler[] = IPS_GetName($ziel);
            }
        }
        $mb = sprintf('%.1f MB', strlen((string)$daten) / 1048576);
        $zeit = date(FrigateHubText::t('Y-m-d H:i:s'));
        $status = $fehler === [] ? FrigateHubText::t('✅ %s film (%s) sent to %s.', $zeit, $mb, implode(', ', $ok))
            : FrigateHubText::t('⛔ %s film (%s) not delivered to %s.', $zeit, $mb, implode(', ', $fehler));
        if ($fehler !== []) {
            $this->LogMessage($status, KL_WARNING);
        }
        $this->WriteAttributeString('Filmstatus', $status);
        return $status;
    }

    private function filmArt(): int
    {
        $art = $this->ReadPropertyInteger('PushFilm');
        return in_array($art, [FrigateHubFilm::GIF, FrigateHubFilm::MP4], true) ? $art : FrigateHubFilm::AUS;
    }

    /** @return int[] SMTP-Ziele aus der Push-Liste */
    private function filmZiele(): array
    {
        return array_values(array_filter($this->pushZiele(), fn (int $z) => IPS_InstanceExists($z)
            && (string)(IPS_GetInstance($z)['ModuleInfo']['ModuleID'] ?? '') === self::SMTP_GUID));
    }

    /** Medienobjekt (Dokument) je Art anlegen und beschreiben. 0 bei Fehler. */
    private function filmMedium(int $art, string $daten): int
    {
        $ident = $art === FrigateHubFilm::MP4 ? 'Meldungsclip' : 'Meldungsgif';
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            $id = IPS_CreateMedia(defined('MEDIATYPE_DOCUMENT') ? MEDIATYPE_DOCUMENT : 5);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $art === FrigateHubFilm::MP4 ? FrigateHubText::t('Notification video clip') : FrigateHubText::t('Notification moving image'));
            IPS_SetPosition($id, 11);
            IPS_SetHidden($id, true);
            IPS_SetMediaFile($id, 'media/FrigateHub_' . $this->InstanceID . '_meldung.' . FrigateHubFilm::endung($art), false);
            IPS_SetMediaCached($id, true);
        }
        if (!IPS_SetMediaContent($id, base64_encode($daten))) {
            $this->LogMessage(FrigateHubText::t('%s could not be saved (media object %s)', IPS_GetName($id), $id), KL_ERROR);
            return 0;
        }
        return (int)$id;
    }

    private function filmstatus(): string
    {
        $s = $this->ReadAttributeString('Filmstatus');
        return $s !== '' ? $s : FrigateHubText::t('No film sent yet.');
    }

    /** Formularteile für das Panel „Push und Meldungen". */
    protected function filmFormular(): array
    {
        return [
            ['type' => 'Select', 'name' => 'PushFilm', 'caption' => 'Film for e-mail targets (second e-mail after the photo)', 'options' => [
                ['caption' => 'off', 'value' => FrigateHubFilm::AUS],
                ['caption' => 'moving image (GIF, small, plays directly in the e-mail)', 'value' => FrigateHubFilm::GIF],
                ['caption' => 'video clip (MP4, full resolution, larger)', 'value' => FrigateHubFilm::MP4],
            ]],
            ['type' => 'NumberSpinner', 'name' => 'PushFilmSekunden', 'caption' => 'Film length from the alert', 'suffix' => 's', 'minimum' => FrigateHubFilm::SEKUNDEN_MIN, 'maximum' => FrigateHubFilm::SEKUNDEN_MAX],
            ['type' => 'Label', 'caption' => 'An e-mail from Symcon carries exactly one attachment. The photo therefore still arrives immediately; the film follows as soon as Frigate can deliver it (GIF a few seconds, clip about half a minute after the film length). Needs the Frigate address; the clip needs recording to be enabled in Frigate. Clips over 15 MB are not sent.'],
            ['type' => 'Label', 'name' => 'Filmstatus', 'caption' => $this->filmstatus()],
        ];
    }
}
