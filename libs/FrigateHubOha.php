<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';

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
            return ['ok' => false, 'grund' => FrigateHubText::t('unexpected response from OHA_GetControlState()'), 'bereiche' => []];
        }
        if (isset($s['ApiVersion']) && (int)$s['ApiVersion'] !== self::API) {
            return ['ok' => false, 'grund' => FrigateHubText::t('OpenHomeAlarm reports control API %d, FrigateHub is tested against %d — please update FrigateHub', (int)$s['ApiVersion'], self::API),
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
        $text = [
            'accepted'            => '✅ accepted — OpenHomeAlarm triggers the alarm',
            'partition_not_armed' => 'ℹ️ discarded: partition is not armed',
            'mode_not_allowed'    => 'ℹ️ discarded: arming mode is not enabled for this source',
            'duplicate'           => 'ℹ️ discarded: this event has already arrived',
            'already_in_alarm'    => 'ℹ️ partition is already in alarm',
            'source_disabled'     => '⚠️ source is disabled in OpenHomeAlarm',
            'api_disabled'        => '⚠️ external alarm event interface is switched off in OpenHomeAlarm',
        ][$status] ?? null;
        return $text !== null ? FrigateHubText::t($text) : FrigateHubText::t('⚠️ unexpected response: %s', substr($antwort, 0, 120));
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
            return ['ok' => false, 'text' => FrigateHubText::t('⚠️ No response from OHA_GetExternalAlarmEventApi().')];
        }
        if ((int)($a['ApiVersion'] ?? 0) !== self::EREIGNIS_API) {
            return ['ok' => false, 'text' => FrigateHubText::t('⚠️ OpenHomeAlarm reports alarm event interface %d, FrigateHub is built for %d — please update FrigateHub. Reporting is off.', (int)($a['ApiVersion'] ?? 0), self::EREIGNIS_API)];
        }
        if (empty($a['Enabled'])) {
            return ['ok' => false, 'text' => FrigateHubText::t('⚠️ The "External alarm event interface" is switched off in OpenHomeAlarm.')];
        }
        foreach ((array)($a['Sources'] ?? []) as $q) {
            if (is_array($q) && ($q['SourceID'] ?? '') === $quelle) {
                $modi = array_keys(array_filter((array)($q['Modes'] ?? [])));
                $namen = ['home' => 'Home', 'away' => 'Away', 'night' => 'Night'];
                $modusText = implode('/', array_map(fn ($m) => isset($namen[$m]) ? FrigateHubText::t($namen[$m]) : $m, $modi)) ?: FrigateHubText::t('no mode');
                return ['ok' => !empty($q['Enabled']), 'text' => !empty($q['Enabled'])
                    ? FrigateHubText::t('✅ Source "%s" is enabled in OpenHomeAlarm: partition %s, %s.', $quelle, $q['PartitionID'] ?? '?', $modusText)
                    : FrigateHubText::t('⚠️ Source "%s" is registered in OpenHomeAlarm but disabled: partition %s, %s.', $quelle, $q['PartitionID'] ?? '?', $modusText)];
            }
        }
        return ['ok' => false, 'text' => FrigateHubText::t('⚠️ Source "%s" is not yet registered in OpenHomeAlarm — add it there under "External alarm event interface" with exactly this source ID.', $quelle)];
    }

    /** Klartext eines Zustands für die Statuszeile. */
    public static function zustandText(string $zustand): string
    {
        $text = ['disarmed' => 'disarmed', 'exit_delay' => 'exit delay', 'armed' => 'armed',
            'entry_delay' => 'entry delay', 'alarm' => 'alarm'][$zustand] ?? null;
        return $text !== null ? FrigateHubText::t($text) : $zustand;
    }
}
