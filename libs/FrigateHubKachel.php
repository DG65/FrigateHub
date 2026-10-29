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

    /** WebHook-Control (Symcon-Kernmodul). */
    private static string $FRIGATEHUB_WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    /** In Create() aufrufen. */
    protected function kachelRegistrieren(): void
    {
        $this->RegisterPropertyInteger('KachelLiveTakt', 2);
        $this->RegisterPropertyBoolean('KachelRahmen', true);
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
        $pfad = $this->hookFrigatePfad($get);
        if ($pfad === null) {
            $this->hookAntwort(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Unbekannt.');
            return;
        }
        $clip = isset($get['clip']);
        $a = $this->apiDurchreichen($pfad, $clip ? (string)($_SERVER['HTTP_RANGE'] ?? '') : '', $clip ? 60 : 8);
        if ($a['code'] !== 200 && $a['code'] !== 206) {
            $this->hookAntwort($a['code'] === 404 ? 404 : 502, ['Content-Type' => 'text/plain; charset=utf-8'],
                'Frigate liefert nichts (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ').');
            return;
        }
        $kopf = ['Content-Type' => $a['kopf']['content-type'] ?? ($clip ? 'video/mp4' : 'image/jpeg'),
            'Cache-Control' => ($get['bild'] ?? '') === 'live' ? 'no-store' : 'private, max-age=86400'];
        if ($clip) {
            $kopf['Accept-Ranges'] = 'bytes';
            if (isset($a['kopf']['content-range'])) {
                $kopf['Content-Range'] = $a['kopf']['content-range'];
            }
        }
        $this->hookAntwort($a['code'], $kopf, $a['body']);
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
        $id = (string)($get['id'] ?? $get['clip'] ?? '');
        $h = max(120, min(1080, (int)($get['h'] ?? 480)));
        if (isset($get['clip'])) {
            return FrigateHubTopics::idGueltig($id) ? '/api/events/' . $id . '/clip.mp4' : null;
        }
        switch ((string)($get['bild'] ?? '')) {
            case 'live':
                $rahmen = isset($get['rahmen']) ? $get['rahmen'] === '1' : $this->ReadPropertyBoolean('KachelRahmen');
                return '/api/' . rawurlencode($kamera) . '/latest.jpg?h=' . $h . ($rahmen ? '&bbox=1' : '');
            case 'review':
                return FrigateHubTopics::idGueltig($id) ? '/clips/review/thumb-' . rawurlencode($kamera) . '-' . $id . '.webp' : null;
            case 'vorschau':
                return FrigateHubTopics::idGueltig($id) ? '/api/events/' . $id . '/thumbnail.jpg' : null;
            case 'ereignis':
                return FrigateHubTopics::idGueltig($id) ? '/api/events/' . $id . '/snapshot.jpg?h=' . $h : null;
        }
        return null;
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
