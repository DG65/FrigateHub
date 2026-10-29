<?php

declare(strict_types=1);

/**
 * Zugriff auf Frigates HTTP-API (Port 5000 ohne Anmeldung) — gemeinsam für Kamera und
 * Konfigurator. Erwartet die Property „ApiUrl" am Modul.
 */
trait FrigateHubApi
{
    /** Grund des letzten fehlgeschlagenen API-Aufrufs. */
    private string $apiFehler = '';

    /** Frigate-Adresse ohne Schrägstrich am Ende, leer wenn nicht (gültig) eingetragen. */
    private function apiUrl(): string
    {
        $url = rtrim(trim((string)$this->ReadPropertyString('ApiUrl')), '/');
        return preg_match('#^https?://[^\s/]+$#', $url) ? $url : '';
    }

    /** GET auf Frigates API, JSON-Antwort als Array oder null (Grund in $apiFehler). */
    private function apiHolen(string $pfad): ?array
    {
        $antwort = $this->apiRoh($pfad, 'application/json');
        if ($antwort === null) {
            return null;
        }
        $json = json_decode($antwort, true);
        if (!is_array($json)) {
            $this->apiFehler = 'Antwort ist kein JSON';
            return null;
        }
        return $json;
    }

    /**
     * GET auf Frigates API, Antwort roh oder null (Grund in $apiFehler).
     * curl statt Sys_GetURLContentEx (SUITE.md Stolperstein 11); eigene Methode, damit der
     * Prüfstand sie ersetzen kann.
     */
    protected function apiRoh(string $pfad, string $accept): ?string
    {
        $ch = curl_init($this->apiUrl() . $pfad);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Accept: ' . $accept],
        ]);
        $antwort = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($antwort === false) {
            $this->apiFehler = $fehler !== '' ? $fehler : 'keine Antwort';
            return null;
        }
        if ($code !== 200) {
            $this->apiFehler = 'HTTP ' . $code . ($code === 401 ? ', Anmeldung verlangt — Port 8971 wird noch nicht unterstützt, bitte Port 5000' : '');
            return null;
        }
        return (string)$antwort;
    }
}
