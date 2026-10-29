<?php

declare(strict_types=1);

/**
 * Kachel der Frigate-Kamera (HTML-SDK, module.html) und der WebHook, über den sie ihre Bilder holt.
 *
 * Der Browser erreicht Frigate meist nicht selbst: Port 5000 hängt oft nur an 127.0.0.1, Port 8971
 * verlangt Frigates Anmeldung. Der WebHook /hook/frigatehub<ID> reicht deshalb Livebild,
 * Vorschaubilder, Schnappschüsse und Clips von Frigate durch. Kamerabilder sind privat: Jeder
 * Abruf braucht den zufälligen Schlüssel dieser Instanz, den nur die Kachel kennt (WebHooks sind
 * in Symcon sonst ohne Anmeldung erreichbar, auch über Symcon Connect). Durchgereicht werden nur
 * fest vorgegebene Frigate-Pfade mit geprüften IDs.
 *
 * Erwartet am Modul: FrigateHubApi, kamera(), ReadPropertyString('TopicPrefix').
 */
trait FrigateHubKachel
{
    /** Höchstzahl Ereignisse in der Kachelleiste. */
    private static int $FRIGATEHUB_KACHEL_EREIGNISSE = 8;

    /**
     * Größtes Stück eines Clips je WebHook-Antwort. Symcon lieferte live (29.10.2026) 127 KB aus,
     * einen 1,7-MB-Clip aber leer; Frigate selbst kennt keine Teilabrufe. Deshalb holt der Hook den
     * Clip einmal, legt ihn kurz ab und liefert ihn stückweise (206), wie es Browser für Videos tun.
     */
    private static int $FRIGATEHUB_CLIP_STUECK = 524288;

    /** WebHook-Control (Symcon-Kernmodul). */
    private static string $FRIGATEHUB_WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    /** In Create() aufrufen. */
    protected function kachelRegistrieren(): void
    {
        $this->RegisterPropertyInteger('KachelLiveTakt', 2);
        $this->RegisterPropertyBoolean('KachelRahmen', true);
        $this->RegisterPropertyBoolean('KachelVideo', true);
        $this->RegisterAttributeString('Livestream', '');
        $this->RegisterAttributeString('HookSchluessel', '');
        $this->RegisterAttributeString('Ereignisse', '[]');
        $this->SetVisualizationType(1);
    }

    /** In ApplyChanges() aufrufen. */
    protected function kachelUebernehmen(): void
    {
        $this->SetVisualizationType(1);
        if ($this->ReadAttributeString('HookSchluessel') === '') {
            $this->WriteAttributeString('HookSchluessel', bin2hex(random_bytes(16)));
        }
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->hookAnmelden();
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }
        $this->kachelSenden(true);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->hookAnmelden();
        }
    }

    public function GetVisualizationTile()
    {
        return file_get_contents(__DIR__ . '/../FrigateHubKamera/module.html')
            . '<script>handleMessage(' . json_encode($this->kachelDaten()) . ');</script>';
    }

    /** Neuen Schlüssel erzeugen: Alte Kachel-Links (z. B. weitergegebene Bildadressen) gelten dann nicht mehr. */
    public function KachelSchluesselErneuern(): string
    {
        $this->WriteAttributeString('HookSchluessel', bin2hex(random_bytes(16)));
        $this->kachelSenden(true);
        return '✅ Neuer Schlüssel erzeugt. Offene Kacheln laden ihn beim nächsten Öffnen.';
    }

    /** WebHook: Bilder und Clips aus Frigate durchreichen. */
    public function ProcessHookData()
    {
        // Nur Zeichenketten: ?k[]=… o. ä. darf keine Warnung und keinen Umweg erzeugen.
        $get = array_map(fn ($v) => is_string($v) ? $v : '', (array)$_GET);
        $schluessel = $this->ReadAttributeString('HookSchluessel');
        if ($schluessel === '' || !hash_equals($schluessel, $get['k'] ?? '')) {
            $this->hookAntwort(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Kein Zugriff.');
            return;
        }
        if (($get['bild'] ?? '') === 'schnappschuss') {
            $this->hookSchnappschuss();
            return;
        }
        if (isset($get['clip'])) {
            $this->hookClip($get['clip'], (string)($_SERVER['HTTP_RANGE'] ?? ''));
            return;
        }
        if (isset($get['webrtc'])) {
            $this->hookWebrtc();
            return;
        }
        $pfad = $this->hookFrigatePfad($get);
        if ($pfad === null) {
            $this->hookAntwort(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Unbekannt.');
            return;
        }
        $a = $this->apiDurchreichen($pfad, '', 8);
        if ($a['code'] !== 200) {
            $this->hookAntwort($a['code'] === 404 ? 404 : 502, ['Content-Type' => 'text/plain; charset=utf-8'],
                'Frigate liefert nichts (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ').');
            return;
        }
        $this->hookAntwort(200, ['Content-Type' => $a['kopf']['content-type'] ?? 'image/jpeg',
            'Cache-Control' => ($get['bild'] ?? '') === 'live' ? 'no-store' : 'private, max-age=86400'], $a['body']);
    }

    /* ---------------------------------------------------------------- intern */

    /**
     * Frigate-Pfad zu einer WebHook-Anfrage, null wenn nicht erlaubt. Nur feste Muster, IDs geprüft.
     */
    private function hookFrigatePfad(array $get): ?string
    {
        $kamera = $this->kamera();
        if ($this->apiUrl() === '' || $kamera === '') {
            return null;
        }
        $id = (string)($get['id'] ?? '');
        $h = max(120, min(1080, (int)($get['h'] ?? 480)));
        switch ((string)($get['bild'] ?? '')) {
            case 'live':
                $rahmen = isset($get['rahmen']) ? $get['rahmen'] === '1' : $this->ReadPropertyBoolean('KachelRahmen');
                return '/api/' . rawurlencode($kamera) . '/latest.jpg?' . FrigateHubTopics::hoehe($h) . ($rahmen ? '&bbox=1' : '');
            case 'review':
                return FrigateHubTopics::idGueltig($id) ? '/clips/review/thumb-' . rawurlencode($kamera) . '-' . $id . '.webp' : null;
            case 'vorschau':
                return FrigateHubTopics::idGueltig($id) ? '/api/events/' . $id . '/thumbnail.jpg' : null;
            case 'ereignis':
                return FrigateHubTopics::idGueltig($id) ? '/api/events/' . $id . '/snapshot.jpg?' . FrigateHubTopics::hoehe($h) : null;
        }
        return null;
    }

    /** WebRTC: SDP-Angebot des Browsers an go2rtc weiterreichen, Antwort zurückgeben. */
    private function hookWebrtc(): void
    {
        $stream = $this->ReadAttributeString('Livestream');
        if ($this->apiUrl() === '' || $stream === '' || !$this->ReadPropertyBoolean('KachelVideo')) {
            $this->hookAntwort(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Kein Livevideo eingerichtet.');
            return;
        }
        $angebot = $this->hookEingang();
        if (strpos($angebot, 'v=0') !== 0 || strlen($angebot) > 32768) {
            $this->hookAntwort(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Kein gültiges SDP-Angebot.');
            return;
        }
        $a = $this->apiWebrtc($stream, $angebot);
        if (($a['code'] !== 200 && $a['code'] !== 201) || strpos($a['body'], 'v=0') !== 0) {
            $this->hookAntwort(502, ['Content-Type' => 'text/plain; charset=utf-8'],
                'go2rtc antwortet nicht (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ').');
            return;
        }
        $this->hookAntwort(200, ['Content-Type' => 'application/sdp', 'Cache-Control' => 'no-store'], $a['body']);
    }

    /** Inhalt der Anfrage (POST). Eigene Methode für den Prüfstand. */
    protected function hookEingang(): string
    {
        return (string)file_get_contents('php://input');
    }

    /**
     * Prüft, ob Frigates go2rtc einen Stream für diese Kamera hat (Name aus live.streams der
     * Frigate-Konfiguration, sonst die Kamera-ID). Nur dann bietet die Kachel Livevideo an.
     */
    private function livestreamPruefen(array $kameraConfig): void
    {
        $name = $this->kamera();
        foreach ((array)($kameraConfig['live']['streams'] ?? []) as $s) {
            if (is_string($s) && $s !== '') {
                $name = $s;
                break;
            }
        }
        $streams = $this->apiHolen('/api/go2rtc/streams');
        $this->WriteAttributeString('Livestream', is_array($streams) && array_key_exists($name, $streams) ? $name : '');
    }

    private function videostatus(): string
    {
        if ($this->apiUrl() === '') {
            return 'Livevideo braucht die Frigate-Adresse.';
        }
        $stream = $this->ReadAttributeString('Livestream');
        if ($stream === '') {
            return 'ℹ️ Kein Livevideo: Frigates go2rtc hat (beim letzten Abgleich) keinen Stream für diese Kamera. Ohne Stream zeigt die Kachel ein Standbild im eingestellten Takt.';
        }
        return $this->ReadPropertyBoolean('KachelVideo')
            ? '✅ Livevideo über go2rtc-Stream „' . $stream . '" (WebRTC, im Heimnetz). Geht das nicht, zeigt die Kachel das Standbild.'
            : 'Livevideo ist ausgeschaltet, die Kachel zeigt das Standbild.';
    }

    /** Clip eines Ereignisses stückweise ausliefern (Range, 206). */
    private function hookClip(string $id, string $range): void
    {
        if ($this->apiUrl() === '' || !FrigateHubTopics::idGueltig($id)) {
            $this->hookAntwort(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Unbekannt.');
            return;
        }
        $this->clipsAufraeumen();
        $datei = $this->clipDatei($id);
        if (!is_file($datei)) {
            $a = $this->apiDurchreichen('/api/events/' . $id . '/clip.mp4', '', 60);
            if ($a['code'] !== 200 || $a['body'] === '') {
                $this->hookAntwort($a['code'] === 404 ? 404 : 502, ['Content-Type' => 'text/plain; charset=utf-8'],
                    'Frigate liefert keinen Clip (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ').');
                return;
            }
            file_put_contents($datei, $a['body']);
        }
        $groesse = (int)filesize($datei);
        $bereich = FrigateHubTopics::bereich($range, $groesse, self::$FRIGATEHUB_CLIP_STUECK);
        if ($bereich === null) {
            $this->hookAntwort(416, ['Content-Range' => 'bytes */' . $groesse, 'Content-Type' => 'text/plain; charset=utf-8'], '');
            return;
        }
        [$von, $bis] = $bereich;
        $this->hookAntwort(206, [
            'Content-Type'  => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Content-Range' => 'bytes ' . $von . '-' . $bis . '/' . $groesse,
            'Cache-Control' => 'private, max-age=600',
        ], (string)file_get_contents($datei, false, null, $von, $bis - $von + 1));
    }

    /** Ablage eines Clips im Temp-Ordner (je Instanz und Ereignis). */
    private function clipDatei(string $id): string
    {
        return sys_get_temp_dir() . '/frigatehub_' . $this->InstanceID . '_' . md5($id) . '.mp4';
    }

    /** Clips dieser Instanz, die älter als 10 Minuten sind, löschen. */
    private function clipsAufraeumen(): void
    {
        foreach (glob(sys_get_temp_dir() . '/frigatehub_' . $this->InstanceID . '_*.mp4') ?: [] as $alt) {
            if (@filemtime($alt) < time() - 600) {
                @unlink($alt);
            }
        }
    }

    private function hookSchnappschuss(): void
    {
        $id = @IPS_GetObjectIDByIdent('Schnappschuss', $this->InstanceID);
        $inhalt = $id === false ? '' : (string)base64_decode((string)@IPS_GetMediaContent($id));
        if ($inhalt === '') {
            $this->hookAntwort(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Noch kein Schnappschuss.');
            return;
        }
        $this->hookAntwort(200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store'], $inhalt);
    }

    /** Antwort ausgeben. Eigene Methode, damit der Prüfstand sie mitschneiden kann. */
    protected function hookAntwort(int $code, array $kopf, string $inhalt): void
    {
        http_response_code($code);
        foreach ($kopf as $name => $wert) {
            header($name . ': ' . $wert);
        }
        header('Content-Length: ' . strlen($inhalt));
        echo $inhalt;
    }

    private function hookPfad(): string
    {
        return '/hook/frigatehub' . $this->InstanceID;
    }

    /** WebHook beim WebHook-Control eintragen (Standard-Muster, wie NRGDashboardTile). */
    private function hookAnmelden(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::$FRIGATEHUB_WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        foreach ($hooks as $i => $hook) {
            if (($hook['Hook'] ?? '') === $this->hookPfad()) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $this->hookPfad(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /** Neue Daten an offene Kacheln, nur wenn sich etwas geändert hat. */
    private function kachelSenden(bool $immer = false): void
    {
        $json = (string)json_encode($this->kachelDaten());
        $hash = md5($json);
        if (!$immer && $this->GetBuffer('KachelHash') === $hash) {
            return;
        }
        $this->SetBuffer('KachelHash', $hash);
        $this->UpdateVisualizationValue($json);
    }

    private function kachelDaten(): array
    {
        $api = $this->apiUrl() !== '' && $this->kamera() !== '';
        $zaehler = [];
        foreach ((array)json_decode($this->GetBuffer('Zaehler'), true) as $label => $anzahl) {
            if ((int)$anzahl > 0) {
                $zaehler[] = ['name' => (int)$anzahl === 1 ? FrigateHubTopics::objektEinzahl((string)$label) : FrigateHubTopics::objektName((string)$label),
                    'anzahl' => (int)$anzahl];
            }
        }
        $ereignisse = [];
        foreach ((array)json_decode($this->ReadAttributeString('Ereignisse'), true) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $ereignisse[] = $e + [
                'zeit'  => date(date('Ymd', (int)$e['start']) === date('Ymd') ? 'H:i' : 'd.m. H:i', (int)$e['start']),
                'dauer' => $e['ende'] !== null ? max(1, (int)$e['ende'] - (int)$e['start']) : null,
            ];
        }
        $medium = @IPS_GetObjectIDByIdent('Schnappschuss', $this->InstanceID);
        return [
            'hook'          => $this->hookPfad(),
            'k'             => $this->ReadAttributeString('HookSchluessel'),
            'kamera'        => $this->kamera(),
            'api'           => $api,
            'live'          => $api ? max(0, min(60, $this->ReadPropertyInteger('KachelLiveTakt'))) : 0,
            'video'         => $api && $this->ReadPropertyBoolean('KachelVideo') && $this->ReadAttributeString('Livestream') !== '',
            'rahmen'        => $this->ReadPropertyBoolean('KachelRahmen'),
            'verfuegbar'    => $this->kachelWert('Verfuegbar'),
            'online'        => $this->kachelWert('Online'),
            'bewegung'      => $this->kachelWert('Bewegung'),
            'alarm'         => $this->kachelWert('Alarm'),
            'erkennung'     => $this->kachelWert('Erkennung'),
            'zaehler'       => $zaehler,
            'schalter'      => [
                'detect'     => $this->kachelWert('Schalter_detect'),
                'recordings' => $this->kachelWert('Schalter_recordings'),
                'snapshots'  => $this->kachelWert('Schalter_snapshots'),
            ],
            'ereignisse'    => $ereignisse,
            'schnappschuss' => $medium !== false ? $this->ReadAttributeString('Bildstatus') : '',
        ];
    }

    /** Wert einer eigenen Variable oder null, wenn es sie (noch) nicht gibt. */
    private function kachelWert(string $ident)
    {
        return @$this->GetIDForIdent($ident) === false ? null : $this->GetValue($ident);
    }

    /** Review aus MQTT in die Ereignisleiste übernehmen. */
    private function ereignisMerken(array $after): void
    {
        $eintrag = FrigateHubTopics::reviewEintrag($after);
        if ($eintrag === null) {
            return;
        }
        $liste = (array)json_decode($this->ReadAttributeString('Ereignisse'), true);
        $liste = FrigateHubTopics::reviewsMerken($liste, $eintrag, self::$FRIGATEHUB_KACHEL_EREIGNISSE);
        $this->WriteAttributeString('Ereignisse', (string)json_encode($liste));
    }

    /** Leere Ereignisleiste einmal über die API füllen (nach Neuanlage oder Update). */
    private function ereignisseLaden(): void
    {
        if ((array)json_decode($this->ReadAttributeString('Ereignisse'), true) !== []) {
            return;
        }
        $reviews = $this->apiHolen('/api/review?cameras=' . rawurlencode($this->kamera()) . '&limit=' . self::$FRIGATEHUB_KACHEL_EREIGNISSE);
        if (!is_array($reviews)) {
            return;
        }
        $liste = [];
        foreach ($reviews as $r) {
            if (is_array($r) && (string)($r['camera'] ?? '') === $this->kamera()) {
                $eintrag = FrigateHubTopics::reviewEintrag($r);
                if ($eintrag !== null) {
                    $liste = FrigateHubTopics::reviewsMerken($liste, $eintrag, self::$FRIGATEHUB_KACHEL_EREIGNISSE);
                }
            }
        }
        $this->WriteAttributeString('Ereignisse', (string)json_encode($liste));
    }
}
