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

    /** In Create() aufrufen. */
    protected function kachelRegistrieren(): void
    {
        $this->RegisterPropertyInteger('KachelLiveTakt', 2);
        $this->RegisterPropertyBoolean('KachelRahmen', true);
        $this->RegisterPropertyBoolean('KachelVideo', true);
        // Eigenständige Seite (IPSView/Browser): Anzeige mit Schlüssel, Schalten nur mit Freigabe
        $this->RegisterPropertyBoolean('SeiteBedienung', false);
        $this->RegisterAttributeString('Livestream', '');
        $this->RegisterAttributeString('HookSchluessel', '');
        $this->RegisterAttributeString('Ereignisse', '[]');
        // PTZ-Fähigkeiten aus /api/<kamera>/ptz/info (beim Abgleich), leer = keine Steuerung
        $this->RegisterAttributeString('Ptz', '');
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
        return '✅ Neuer Schlüssel erzeugt. Offene Kacheln laden ihn beim nächsten Öffnen; in IPSView die neue Adresse eintragen.';
    }

    /**
     * Adresse der eigenständigen Seite für IPSView/Browser (mit Schlüssel). Nur auf Knopfdruck,
     * damit der Schlüssel nicht im Formular steht.
     */
    public function SeiteAdresse(): string
    {
        return 'http://<Symcon-IP>:3777' . $this->hookPfad() . '?seite=1&k=' . $this->ReadAttributeString('HookSchluessel');
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
        if (isset($get['seite'])) {
            $this->hookSeite();
            return;
        }
        if (isset($get['daten'])) {
            $this->hookAntwort(200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
                (string)json_encode($this->kachelDaten(true)));
            return;
        }
        if (isset($get['schalten'])) {
            $this->hookSchalten($get['schalten'], $get['wert'] ?? '');
            return;
        }
        if (isset($get['archiv'])) {
            $this->hookArchiv($get['tag'] ?? '');
            return;
        }
        if (isset($get['zeitraum'])) {
            $this->hookZeitraum($get['zeitraum'], (string)($_SERVER['HTTP_RANGE'] ?? ''));
            return;
        }
        if (isset($get['info'])) {
            $this->hookInfo($get['info']);
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

    /** Schalter, die die eigenständige Seite bedienen darf (dieselben wie in der Kachel). */
    private const SEITE_SCHALTER = ['Schalter_detect', 'Schalter_recordings', 'Schalter_snapshots'];

    /**
     * Aktionen der Kachel ohne eigene Variable (Wert = Text). In der Kachel über Symcons
     * requestAction (Anmeldung der Visualisierung), auf der eigenständigen Seite nur mit Freigabe.
     */
    private const KACHEL_AKTIONEN = ['Kachel_Gesehen', 'Kachel_Favorit', 'Kachel_Export', 'Kachel_PTZ'];

    /** PTZ-Befehle laut Frigate-Doku (frigate/<kamera>/ptz); Voreinstellungen als preset_<name>. */
    private const PTZ_BEFEHLE = ['MOVE_UP', 'MOVE_DOWN', 'MOVE_LEFT', 'MOVE_RIGHT', 'ZOOM_IN', 'ZOOM_OUT', 'STOP'];

    /** Längster Zeitraum für Clip und Export (s). */
    private const ZEITRAUM_MAX = 3600;

    /**
     * Aktion aus der Kachel ausführen. Rückgabe false: kein Kachel-Ident (dann ist es ein Schalter).
     * Fehler als Exception; das Ergebnis erscheint in der Kachel als Meldung.
     */
    protected function kachelAktion(string $ident, $wert): bool
    {
        if (!in_array($ident, self::KACHEL_AKTIONEN, true)) {
            return false;
        }
        $wert = (string)$wert;
        switch ($ident) {
            case 'Kachel_Gesehen':
                if (!FrigateHubTopics::idGueltig($wert)) {
                    throw new Exception('Ungültige Ereignis-ID.');
                }
                $a = $this->apiSchreiben('POST', '/api/reviews/viewed', ['ids' => [$wert]]);
                if ($a['code'] !== 200) {
                    throw new Exception('Frigate nimmt „gesehen" nicht an (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ').');
                }
                $liste = array_map(fn ($e) => is_array($e) && ($e['id'] ?? '') === $wert ? ['gesehen' => true] + $e : $e,
                    (array)json_decode($this->ReadAttributeString('Ereignisse'), true));
                $this->WriteAttributeString('Ereignisse', (string)json_encode(array_values($liste)));
                $this->kachelSenden();
                return true;

            case 'Kachel_Favorit':
                [$id, $an] = array_pad(explode(':', $wert, 2), 2, '');
                if (!FrigateHubTopics::idGueltig($id) || !in_array($an, ['0', '1'], true)) {
                    throw new Exception('Ungültiger Favorit.');
                }
                $a = $this->apiSchreiben($an === '1' ? 'POST' : 'DELETE', '/api/events/' . $id . '/retain', null);
                if ($a['code'] !== 200) {
                    throw new Exception('Frigate nimmt den Favoriten nicht an (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ').');
                }
                $this->kachelMelden($an === '1' ? '⭐ Ereignis wird dauerhaft behalten.' : '☆ Ereignis wird wieder normal aufgeräumt.');
                return true;

            case 'Kachel_Export':
                $z = FrigateHubTopics::zeitraum($wert, self::ZEITRAUM_MAX);
                if ($z === null) {
                    throw new Exception('Ungültiger Zeitraum.');
                }
                $name = IPS_GetName($this->InstanceID) . ' ' . date('d.m.Y H:i', $z[0]);
                $a = $this->apiSchreiben('POST', '/api/export/' . rawurlencode($this->kamera()) . '/start/' . $z[0] . '/end/' . $z[1],
                    ['playback' => 'realtime', 'name' => $name]);
                if ($a['code'] !== 200) {
                    $grund = (string)(json_decode($a['body'], true)['message'] ?? '');
                    throw new Exception('Export nicht angelegt (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ($grund !== '' ? ': ' . $grund : '') . ').');
                }
                $this->kachelMelden('💾 Export „' . $name . '" angelegt — in Frigate unter „Exporte".');
                return true;

            case 'Kachel_PTZ':
                if (!in_array($wert, self::PTZ_BEFEHLE, true) && !preg_match('/^preset_[A-Za-z0-9 _.-]{1,64}\z/', $wert)) {
                    throw new Exception('Unbekannter PTZ-Befehl.');
                }
                $this->ptzSenden($wert);
                return true;
        }
        return false;
    }

    /** Rückmeldung an offene Kacheln (erscheint unten im Bild). */
    private function kachelMelden(string $text): void
    {
        $this->SetBuffer('KachelMeldung', (string)json_encode(['t' => microtime(true), 'text' => $text]));
        $this->kachelSenden();
    }

    /**
     * Archiv eines Tages: alle Reviews der Kamera (neueste zuerst) und die Tage mit Anzahlen für
     * die Auswahl. Filtern (Stufe, Objekt, ungesehen) macht die Kachel selbst.
     */
    private function hookArchiv(string $tag): void
    {
        $json = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        $kamera = $this->kamera();
        if ($this->apiUrl() === '' || $kamera === '') {
            $this->hookAntwort(404, $json, (string)json_encode(['eintraege' => [], 'tage' => []]));
            return;
        }
        $start = FrigateHubTopics::tagBeginn($tag) ?? mktime(0, 0, 0);
        $ende = (int)strtotime('+1 day', $start);
        $reviews = $this->apiHolen('/api/review?cameras=' . rawurlencode($kamera) . '&after=' . $start . '&before=' . $ende . '&limit=500');
        if ($reviews === null) {
            $this->hookAntwort(502, $json, (string)json_encode(['fehler' => $this->apiFehlerText()], JSON_UNESCAPED_UNICODE));
            return;
        }
        $eintraege = [];
        foreach ($reviews as $r) {
            $e = is_array($r) ? FrigateHubTopics::reviewEintrag($r) : null;
            if ($e !== null) {
                $eintraege[] = $this->eintragAnzeige($e);
            }
        }
        usort($eintraege, fn ($a, $b) => $b['start'] <=> $a['start']);
        $zusammenfassung = $this->apiHolen('/api/review/summary?cameras=' . rawurlencode($kamera)) ?? [];
        $this->hookAntwort(200, $json, (string)json_encode([
            'tag'       => date('Y-m-d', $start),
            'eintraege' => $eintraege,
            'tage'      => FrigateHubTopics::archivTage($zusammenfassung),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Zusatzinfo zu einem Ereignis für die Großansicht: dauerhaft behalten (Favorit)? */
    private function hookInfo(string $id): void
    {
        $json = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($this->apiUrl() === '' || !FrigateHubTopics::idGueltig($id)) {
            $this->hookAntwort(404, $json, '{}');
            return;
        }
        $e = $this->apiHolen('/api/events/' . $id);
        $this->hookAntwort($e === null ? 502 : 200, $json, (string)json_encode([
            'favorit'   => (bool)($e['retain_indefinitely'] ?? false),
            'beschreibung' => (string)($e['data']['description'] ?? ''),
            'name'      => is_string($e['sub_label'] ?? null) ? $e['sub_label'] : (string)($e['sub_label'][0] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Aufnahme eines Zeitraums („start-ende", Unix-Sekunden) als Clip, wie hookClip. */
    private function hookZeitraum(string $zeitraum, string $range): void
    {
        $z = FrigateHubTopics::zeitraum($zeitraum, self::ZEITRAUM_MAX);
        if ($this->apiUrl() === '' || $z === null) {
            $this->hookAntwort(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Unbekannt.');
            return;
        }
        $this->clipAusliefern('zeitraum-' . $z[0] . '-' . $z[1],
            '/api/' . rawurlencode($this->kamera()) . '/start/' . $z[0] . '/end/' . $z[1] . '/clip.mp4', $range);
    }

    /**
     * Eigenständige Seite für IPSView (Webseiten-Element) oder jeden Browser:
     *   /hook/frigatehub<ID>?seite=1&k=<Schlüssel>
     * Dieselbe Oberfläche wie die Kachel; statt Symcons Push fragt sie alle 5 s ?daten=1 ab.
     * Kamerabilder sind privat, darum braucht schon die Anzeige den Schlüssel (anders als bei
     * RoboHub). Schalten geht nur zusätzlich mit der Freigabe „SeiteBedienung" und nur per POST.
     */
    private function hookSeite(): void
    {
        $abruf = json_encode($this->hookPfad() . '?k=' . $this->ReadAttributeString('HookSchluessel'));
        $daten = (string)json_encode($this->kachelDaten(true));
        $html = file_get_contents(__DIR__ . '/../FrigateHubKamera/module.html')
            . '<script>'
            . 'var seiteAbruf=' . $abruf . ';'
            // Nur Geändertes weitergeben: Neu zeichnen lädt sonst alle 5 s die Vorschaubilder neu
            . 'var seiteStand=' . json_encode($daten) . ';'
            . 'function seiteLaden(){fetch(seiteAbruf+"&daten=1",{cache:"no-store"}).then(function(r){return r.ok?r.text():null;})'
            . '.then(function(t){if(t&&t!==seiteStand){seiteStand=t;handleMessage(t);}}).catch(function(){});}'
            . 'function requestAction(ident,wert){fetch(seiteAbruf+"&schalten="+encodeURIComponent(ident)+"&wert="+(wert?1:0),{method:"POST"})'
            . '.then(function(r){return r.json();}).then(function(a){if(!a.ok){meldung(a.text);}}).catch(function(){});'
            . 'setTimeout(seiteLaden,1500);setTimeout(seiteLaden,4000);}'
            . 'handleMessage(seiteStand);'
            . 'setInterval(function(){if(!document.hidden){seiteLaden();}},5000);'
            . '</script>';
        $this->hookAntwort(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'SAMEORIGIN'], $html);
    }

    /** Schalter von der eigenständigen Seite: nur mit Freigabe, nur POST, nur die Kachel-Schalter. */
    private function hookSchalten(string $ident, string $wert): void
    {
        $json = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        if (!$this->ReadPropertyBoolean('SeiteBedienung')) {
            $this->hookAntwort(403, $json, (string)json_encode(['ok' => false,
                'text' => 'Bedienen über diese Seite ist im Formular der Instanz nicht freigegeben.'], JSON_UNESCAPED_UNICODE));
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->hookAntwort(405, $json + ['Allow' => 'POST'], (string)json_encode(['ok' => false, 'text' => 'Nur POST.']));
            return;
        }
        $aktion = in_array($ident, self::KACHEL_AKTIONEN, true);
        if (!$aktion && (!in_array($ident, self::SEITE_SCHALTER, true) || !in_array($wert, ['0', '1'], true)
            || @$this->GetIDForIdent($ident) === false)) {
            $this->hookAntwort(400, $json, (string)json_encode(['ok' => false, 'text' => 'Unbekannter Schalter.']));
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->hookAntwort(502, $json, (string)json_encode(['ok' => false,
                'text' => 'Keine aktive MQTT-Instanz über der Kamera, Frigate ist nicht erreichbar.'], JSON_UNESCAPED_UNICODE));
            return;
        }
        try {
            $this->RequestAction($ident, $aktion ? $wert : $wert === '1');
        } catch (Throwable $e) {
            $this->hookAntwort(502, $json, (string)json_encode(['ok' => false, 'text' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->hookAntwort(200, $json, (string)json_encode(['ok' => true]));
    }

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
        $this->clipAusliefern($id, '/api/events/' . $id . '/clip.mp4', $range);
    }

    /** Clip einmal von Frigate holen, 10 min im Temp-Ordner halten, stückweise ausliefern (Range, 206). */
    private function clipAusliefern(string $schluessel, string $pfad, string $range): void
    {
        $this->clipsAufraeumen();
        $datei = $this->clipDatei($schluessel);
        if (!is_file($datei)) {
            $a = $this->apiDurchreichen($pfad, '', 60);
            if ($a['code'] !== 200 || $a['body'] === '') {
                $grund = (string)(json_decode($a['body'], true)['message'] ?? '');
                $this->hookAntwort(in_array($a['code'], [400, 404], true) ? 404 : 502, ['Content-Type' => 'text/plain; charset=utf-8'],
                    'Frigate liefert keinen Clip (' . ($a['code'] > 0 ? 'HTTP ' . $a['code'] : 'nicht erreichbar') . ($grund !== '' ? ': ' . $grund : '') . ').');
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

    private function hookAnmelden(): void
    {
        $this->webhookEintragen($this->hookPfad());
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

    /** $eigen: für die eigenständige Seite (Name statt Symcon-Titel, Bedienung nur mit Freigabe). */
    private function kachelDaten(bool $eigen = false): array
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
            if (is_array($e)) {
                $ereignisse[] = $this->eintragAnzeige($e);
            }
        }
        $ptz = json_decode($this->ReadAttributeString('Ptz'), true);
        $meldung = json_decode($this->GetBuffer('KachelMeldung'), true);
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
            'ptz'           => is_array($ptz) && $ptz !== [] ? $ptz : null,
            'meldung'       => is_array($meldung) ? $meldung : null,
            'warnungen'     => $this->kachelWarnungen(),
            'eigen'         => $eigen,
            'name'          => $eigen ? IPS_GetName($this->InstanceID) : '',
            'bedienung'     => !$eigen || $this->ReadPropertyBoolean('SeiteBedienung'),
        ];
    }

    /** Ein Ereignis für die Anzeige: Zeit (heute nur Uhrzeit) und Dauer. */
    private function eintragAnzeige(array $e): array
    {
        return $e + [
            'zeit'  => date(date('Ymd', (int)$e['start']) === date('Ymd') ? 'H:i' : 'd.m. H:i', (int)$e['start']),
            'dauer' => ($e['ende'] ?? null) !== null ? max(1, (int)$e['ende'] - (int)$e['start']) : null,
        ];
    }

    /**
     * Warnmarken für die Kachel: was gerade in Frigate aus ist und Aufmerksamkeit verdient.
     *
     * @return string[]
     */
    private function kachelWarnungen(): array
    {
        $aus = [];
        foreach (['Schalter_enabled' => 'Kamera aus', 'Schalter_detect' => 'Erkennung aus', 'Schalter_recordings' => 'Aufnahme aus',
            'Schalter_snapshots' => 'Schnappschüsse aus'] as $ident => $text) {
            if ($this->kachelWert($ident) === false) {
                $aus[] = $text;
            }
        }
        return $aus;
    }

    /** PTZ-Fähigkeiten merken (Abgleich): features/presets aus /api/<kamera>/ptz/info, sonst leer. */
    private function ptzMerken(?array $info): void
    {
        $features = array_values(array_filter((array)($info['features'] ?? []), 'is_string'));
        $presets = array_values(array_filter((array)($info['presets'] ?? []), fn ($p) => is_string($p) && preg_match('/^[A-Za-z0-9 _.-]{1,64}\z/', $p)));
        $this->WriteAttributeString('Ptz', $features === [] && $presets === [] ? '' : (string)json_encode(['features' => $features, 'presets' => $presets]));
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
