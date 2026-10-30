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

    /** Idents der Zustandsvariablen in OpenHomeAlarm (PartitionStatusVariableIdents, Quellcode 30.10.2026). */
    public const ZUSTAND_IDENT = '/^(State|Partition_[0-9a-f]+_State)\z/';

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

    /** Version der Alarmereignis-Schnittstelle, gegen die FrigateHub gebaut ist (AlarmExternalEvent::API_VERSION, dev 29.10.2026). */
    public const EREIGNIS_API = 1;

    /** Erlaubte Zeichen für SourceID/EventID laut OpenHomeAlarm (AlarmExternalEvent::ID_PATTERN). */
    public const ID_MUSTER = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/';

    /**
     * Ereignis für OHA_TriggerExternalAlarmEvent(): bestätigter Alarm einer Quelle. Die EventID ist
     * die Review-ID von Frigate (OpenHomeAlarm verwirft Wiederholungen derselben ID).
     */
    public static function ereignis(string $quelle, string $reviewId, int $zeit): ?string
    {
        $eventId = 'frigate-' . $reviewId;
        if (!preg_match(self::ID_MUSTER, $quelle) || !preg_match(self::ID_MUSTER, $eventId)) {
            return null;
        }
        return (string)json_encode(['ApiVersion' => self::EREIGNIS_API, 'EventID' => $eventId, 'SourceID' => $quelle,
            'EventType' => 'alarm', 'OccurredAt' => $zeit], JSON_UNESCAPED_SLASHES);
    }

    /** Antwort von OHA_TriggerExternalAlarmEvent() als Klartext. */
    public static function ergebnisText(string $antwort): string
    {
        $a = json_decode($antwort, true);
        $status = is_array($a) ? (string)($a['Status'] ?? '') : '';
        return [
            'accepted'            => '✅ angenommen — OpenHomeAlarm löst Alarm aus',
            'partition_not_armed' => 'ℹ️ verworfen: Bereich ist nicht scharf',
            'mode_not_allowed'    => 'ℹ️ verworfen: Scharfmodus ist für diese Quelle nicht freigegeben',
            'duplicate'           => 'ℹ️ verworfen: dieses Ereignis kam schon an',
            'already_in_alarm'    => 'ℹ️ Bereich ist bereits im Alarm',
            'source_disabled'     => '⚠️ Quelle ist in OpenHomeAlarm abgeschaltet',
            'api_disabled'        => '⚠️ Externe Alarmereignis-Schnittstelle ist in OpenHomeAlarm ausgeschaltet',
        ][$status] ?? ('⚠️ unerwartete Antwort: ' . substr($antwort, 0, 120));
    }

    /**
     * Stand der Schnittstelle für die eigene Quelle aus OHA_GetExternalAlarmEventApi().
     *
     * @return array{ok:bool,text:string}
     */
    public static function quelleStatus($antwort, string $quelle): array
    {
        $a = is_string($antwort) ? json_decode($antwort, true) : $antwort;
        if (!is_array($a)) {
            return ['ok' => false, 'text' => '⚠️ Keine Antwort von OHA_GetExternalAlarmEventApi().'];
        }
        if ((int)($a['ApiVersion'] ?? 0) !== self::EREIGNIS_API) {
            return ['ok' => false, 'text' => '⚠️ OpenHomeAlarm meldet Alarmereignis-Schnittstelle ' . (int)($a['ApiVersion'] ?? 0) . ', FrigateHub ist mit ' . self::EREIGNIS_API . ' gebaut — FrigateHub bitte aktualisieren. Melden ist aus.'];
        }
        if (empty($a['Enabled'])) {
            return ['ok' => false, 'text' => '⚠️ In OpenHomeAlarm ist die „Externe Alarmereignis-Schnittstelle" ausgeschaltet.'];
        }
        foreach ((array)($a['Sources'] ?? []) as $q) {
            if (is_array($q) && ($q['SourceID'] ?? '') === $quelle) {
                $modi = array_keys(array_filter((array)($q['Modes'] ?? [])));
                $namen = ['home' => 'Zuhause', 'away' => 'Abwesend', 'night' => 'Nacht'];
                return ['ok' => !empty($q['Enabled']), 'text' => (!empty($q['Enabled']) ? '✅' : '⚠️') . ' Quelle „' . $quelle . '" ist in OpenHomeAlarm '
                    . (!empty($q['Enabled']) ? 'freigegeben' : 'eingetragen, aber abgeschaltet') . ': Bereich ' . ($q['PartitionID'] ?? '?') . ', '
                    . (implode('/', array_map(fn ($m) => $namen[$m] ?? $m, $modi)) ?: 'kein Modus') . '.'];
            }
        }
        return ['ok' => false, 'text' => '⚠️ Quelle „' . $quelle . '" ist in OpenHomeAlarm noch nicht eingetragen — dort unter „Externe Alarmereignis-Schnittstelle" mit genau dieser Quellen-ID hinzufügen.'];
    }

    /** Klartext eines Zustands für die Statuszeile. */
    public static function zustandText(string $zustand): string
    {
        return ['disarmed' => 'unscharf', 'exit_delay' => 'Ausgangsverzögerung', 'armed' => 'scharf',
            'entry_delay' => 'Eingangsverzögerung', 'alarm' => 'Alarm'][$zustand] ?? $zustand;
    }
}
