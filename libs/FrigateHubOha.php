<?php

declare(strict_types=1);

/**
 * OpenHomeAlarm (Burki24, Präfix OHA) auswerten — reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Optionale Kopplung zweier eigenständiger Module (mit Burki24 abgestimmt, 29.10.2026): FrigateHub
 * findet OpenHomeAlarm über dessen Modul-GUID und liest nur `OHA_GetControlState()`. Am Quellcode
 * geprüft (OpenHomeAlarm/module.php GetControlState, libs/AlarmControlStateAdapter::identity,
 * AlarmStateMachine::stateName, 29.10.2026): `ApiVersion` 3 (CONTROL_API_VERSION),
 * `Partitions.<Bereich>.State` = {Value, Name}, Name disarmed | exit_delay | armed | entry_delay |
 * alarm | unknown. Mehr wird nicht gelesen. Meldet OpenHomeAlarm eine andere ApiVersion, schaltet
 * FrigateHub die Kopplung sichtbar ab, statt falsch zu lesen (Verbund-Regel „Update-Meldepflicht").
 */
class FrigateHubOha
{
    public const GUID = '{763D545E-C8B3-A3E2-130A-006A10D6181F}';

    /** Control-API von OpenHomeAlarm, gegen die FrigateHub geprüft ist (ApiVersion in GetControlState). */
    public const API = 3;

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
        if (isset($s['ApiVersion']) && (int)$s['ApiVersion'] !== self::API) {
            return ['ok' => false, 'grund' => 'OpenHomeAlarm meldet Control-API ' . (int)$s['ApiVersion'] . ', FrigateHub ist mit ' . self::API . ' geprüft — FrigateHub bitte aktualisieren',
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
