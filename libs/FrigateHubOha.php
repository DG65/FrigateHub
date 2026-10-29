<?php

declare(strict_types=1);

/**
 * OpenHomeAlarm (Burki24, Präfix OHA) auswerten — reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Optionale Kopplung zweier eigenständiger Module (mit Burki24 abgestimmt, 29.10.2026): FrigateHub
 * findet OpenHomeAlarm über dessen Modul-GUID und liest nur `OHA_GetControlState()`. Belegt in
 * dessen README (Kapitel 5): `Partitions.<Bereich>.State.Name` mit disarmed | exit_delay | armed |
 * entry_delay | alarm, `Mode.Name` home | away | night, Standardbereich `main`. Mehr wird nicht
 * gelesen. Liefert OpenHomeAlarm künftig `contractVersion` mit einer anderen Major, schaltet
 * FrigateHub die Kopplung ab, statt falsch zu lesen (Verbund-Regel „Update-Meldepflicht").
 */
class FrigateHubOha
{
    public const GUID = '{763D545E-C8B3-A3E2-130A-006A10D6181F}';

    /** Vertragsversion, die FrigateHub versteht (Major). Fehlt das Feld, gilt die heutige Struktur. */
    public const MAJOR = 1;

    /** Zustände, die als „scharf" gelten. exit_delay noch nicht: erst der tatsächliche Wechsel zählt. */
    private const SCHARF = ['armed', 'entry_delay', 'alarm'];

    /**
     * Antwort von OHA_GetControlState() deuten.
     *
     * @return array{ok:bool,grund:string,bereiche:array<string,string>}
     *   bereiche: Bereichs-ID → Zustandsname (disarmed, armed, …)
     */
    public static function deuten($antwort): array
    {
        $s = is_string($antwort) ? json_decode($antwort, true) : $antwort;
        if (!is_array($s) || !is_array($s['Partitions'] ?? null)) {
            return ['ok' => false, 'grund' => 'unerwartete Antwort von OHA_GetControlState()', 'bereiche' => []];
        }
        if (isset($s['contractVersion']) && (int)explode('.', (string)$s['contractVersion'])[0] !== self::MAJOR) {
            return ['ok' => false, 'grund' => 'OpenHomeAlarm meldet Vertrag ' . $s['contractVersion'] . ', FrigateHub versteht ' . self::MAJOR . '.x — FrigateHub bitte aktualisieren',
                'bereiche' => []];
        }
        $bereiche = [];
        foreach ($s['Partitions'] as $id => $p) {
            $name = is_array($p) ? ($p['State']['Name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $bereiche[(string)$id] = $name;
            }
        }
        return ['ok' => true, 'grund' => '', 'bereiche' => $bereiche];
    }

    /** „scharf", „unscharf" oder „" (Übergang wie Ausgangsverzögerung, unbekannt). */
    public static function lage(?string $zustand): string
    {
        if ($zustand === 'disarmed') {
            return 'unscharf';
        }
        return in_array($zustand, self::SCHARF, true) ? 'scharf' : '';
    }

    /** Klartext eines Zustands für die Statuszeile. */
    public static function zustandText(string $zustand): string
    {
        return ['disarmed' => 'unscharf', 'exit_delay' => 'Ausgangsverzögerung', 'armed' => 'scharf',
            'entry_delay' => 'Eingangsverzögerung', 'alarm' => 'Alarm'][$zustand] ?? $zustand;
    }
}
