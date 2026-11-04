<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';

/**
 * Aufnahme-Wächter: erkennt aus Frigates API, dass Frigate nicht mehr aufzeichnet — reines PHP,
 * vom Prüfstand direkt aufgerufen. Das Modul sammelt die Beobachtungen, diese Klasse bewertet sie
 * und hält den Zustand (Hysterese, Meldungen) als Array.
 *
 * Belegt an Dietmars Frigate 0.18.2 (Ausfall der Aufnahme-SSD am 31.10.2026, Quellcode geprüft):
 * - Frigate meldet weiter „healthy", schreibt aber 5 s nach dem Abriss alle 5 s
 *   „Error occurred when attempting to maintain recording cache" und „[Errno 5] Input/output error"
 *   (frigate/record/maintainer.py) ins Protokoll. /api/logs/frigate liefert es (nur Rolle admin;
 *   Port 5000 gilt als admin). Gleiche Zeilen hintereinander fasst Frigate zu
 *   „[LOGGING] Last message repeated N times" zusammen (frigate/util/services.py process_logs).
 * - Fehlt der Speicher beim Start, schreibt Frigate ohne Docker-Absicherung still in den Ordner
 *   auf der Systemplatte: Größe/Art von /media/frigate/recordings in /api/stats ändern sich.
 * - Ohne Daueraufnahme (record.continuous.days 0) sind fehlende neue Aufnahmen normal — nur Alarme
 *   und Daueraufnahme-Kameras taugen als Probe.
 */
class FrigateHubWaechter
{
    /** So lange darf die API schweigen (Neustart dauert 1–2 min), bevor es eine Störung ist. */
    public const STILLE = 180;

    /** Ab so vielen neuen Schreibfehler-Zeilen in einer Prüfung gilt der Speicher als gestört. */
    public const FEHLER_AB = 3;

    /** So viele Prüfungen ohne neuen Schreibfehler, bis die Störung als behoben gilt. */
    public const GUT_NACH = 2;

    /** Ein Alarm wird erst so lange nach seinem Ende geprüft (Frigate verschiebt die Teilstücke verzögert). */
    public const ALARM_WARTEN = 120;

    /** Erstes Prüffenster für Alarme nach dem Einschalten. */
    public const ALARM_RUECKBLICK = 600;

    /** „Alarm ohne Film" bleibt so lange stehen, wenn kein späterer Alarm mit Film kommt. */
    public const ALARM_HALTEN = 86400;

    /** Daueraufnahme: neueste Aufnahme älter als das → steht. */
    public const DAUER_ALTER = 300;

    /** Größe des Aufnahmespeichers darf so viel abweichen (Anteil), bevor er als vertauscht gilt. */
    public const ABWEICHUNG = 0.02;

    /** Erinnerung, solange die Störung anhält. */
    public const ERINNERUNG = 86400;

    /** Protokollzeilen, die eine gescheiterte Ablage von Aufnahmen bedeuten (frigate/record/maintainer.py). */
    private const SCHREIBFEHLER = [
        'Error occurred when attempting to maintain recording cache',
        'Unable to store recording segment',
        'Unable to convert ',
    ];

    /** Reihenfolge der Störungen in Texten. */
    private const REIHENFOLGE = ['erreichbar', 'schreibfehler', 'speicher', 'alarm', 'dauer'];

    /** Größe und Art des Aufnahmespeichers aus /api/stats, null = nicht gemeldet. */
    public static function speicher(array $stats): ?array
    {
        $s = $stats['service']['storage']['/media/frigate/recordings'] ?? null;
        if (!is_array($s) || !is_numeric($s['total'] ?? null) || (float)$s['total'] <= 0) {
            return null;
        }
        return ['total' => round((float)$s['total'], 1), 'typ' => (string)($s['mount_type'] ?? '')];
    }

    /** Ungleich, wenn die Art wechselt oder die Größe mehr als ABWEICHUNG abweicht. */
    public static function speicherGewechselt(array $bekannt, array $jetzt): bool
    {
        if ($bekannt['typ'] !== '' && $jetzt['typ'] !== '' && $bekannt['typ'] !== $jetzt['typ']) {
            return true;
        }
        return abs($jetzt['total'] - $bekannt['total']) > $bekannt['total'] * self::ABWEICHUNG;
    }

    public static function speicherText(array $s): string
    {
        $gb = $s['total'] / 1024;
        return number_format($gb, $gb >= 100 ? 0 : 1, ',', '.') . ' GB' . ($s['typ'] !== '' ? ' (' . $s['typ'] . ')' : '');
    }

    /**
     * Neue Schreibfehler im Protokoll seit der Zeile mit Zeitstempel $gesehen.
     *
     * @param string[] $zeilen Antwort von /api/logs/frigate („lines")
     * @param array{ts:string,n:int} $wiederholt zuletzt gezählte Wiederholungszeile
     * @return array{fehler:int,neueste:string,wiederholt:array{ts:string,n:int}}
     */
    public static function protokoll(array $zeilen, string $gesehen, array $wiederholt): array
    {
        $fehler = 0;
        $neueste = $gesehen;
        $vorigeTraf = false;
        foreach ($zeilen as $zeile) {
            $zeile = (string)$zeile;
            $pos = strpos($zeile, '  ');
            if ($pos === false || !preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/', $zeile)) {
                continue;
            }
            $ts = substr($zeile, 0, $pos);
            if (preg_match('/\[LOGGING\] Last message repeated (\d+) times/', $zeile, $m)) {
                // Frigate fasst gleiche Zeilen zusammen; die Zeile trägt den Zeitstempel der ersten
                // und wächst mit — nur den Zuwachs zählen.
                if ($vorigeTraf) {
                    $schon = $wiederholt['ts'] === $ts ? $wiederholt['n'] : 0;
                    if ((int)$m[1] > $schon && ($ts > $gesehen || $wiederholt['ts'] === $ts)) {
                        $fehler += (int)$m[1] - $schon;
                    }
                    $wiederholt = ['ts' => $ts, 'n' => max($schon, (int)$m[1])];
                }
                continue;
            }
            $vorigeTraf = self::istSchreibfehler($zeile);
            if ($ts > $neueste) {
                $neueste = $ts;
            }
            if ($ts > $gesehen && $vorigeTraf) {
                $fehler++;
            }
        }
        // Erste Prüfung: nur den Stand merken — alte Fehler von früher sind keine neue Störung.
        return ['fehler' => $gesehen === '' ? 0 : $fehler, 'neueste' => $neueste, 'wiederholt' => $wiederholt];
    }

    public static function istSchreibfehler(string $zeile): bool
    {
        foreach (self::SCHREIBFEHLER as $muster) {
            if (strpos($zeile, $muster) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Je Kamera aus /api/config: zeichnet auf, mit Daueraufnahme, mit Alarm-Aufnahmen.
     * Ältere Frigate (bis 0.13) kennen statt continuous nur record.retain mit mode „all".
     *
     * @return array<string,array{aufnahme:bool,dauer:bool,alarme:bool}>
     */
    public static function kameras(array $config): array
    {
        $out = [];
        foreach ((array)($config['cameras'] ?? []) as $name => $k) {
            if (!is_array($k)) {
                continue;
            }
            $r = is_array($k['record'] ?? null) ? $k['record'] : [];
            $aufnahme = ($k['enabled'] ?? true) !== false && ($r['enabled'] ?? false) === true;
            $dauerTage = $r['continuous']['days'] ?? ((($r['retain']['mode'] ?? '') === 'all') ? ($r['retain']['days'] ?? 0) : 0);
            $alarmTage = $r['alerts']['retain']['days'] ?? ($r['events']['retain']['default'] ?? 0);
            $out[(string)$name] = [
                'aufnahme' => $aufnahme,
                'dauer'    => $aufnahme && is_numeric($dauerTage) && (float)$dauerTage > 0,
                'alarme'   => $aufnahme && is_numeric($alarmTage) && (float)$alarmTage > 0,
            ];
        }
        return $out;
    }

    /** Zeitfenster [nach, bis] der Alarme, die jetzt geprüft werden (Alarmende darin). */
    public static function alarmFenster(array $z, int $jetzt): array
    {
        $bis = $jetzt - self::ALARM_WARTEN;
        $nach = (int)($z['alarmeBis'] ?? 0);
        if ($nach <= 0 || $nach > $bis) {
            $nach = $bis - self::ALARM_RUECKBLICK;
        }
        return [$nach, $bis];
    }

    /**
     * Abgeschlossene Alarme im Fenster.
     *
     * @return array<int,array{kamera:string,start:float,ende:float}>
     */
    public static function alarme(array $reviews, int $nach, int $bis): array
    {
        $out = [];
        foreach ($reviews as $r) {
            if (!is_array($r) || ($r['severity'] ?? '') !== 'alert' || !is_numeric($r['end_time'] ?? null) || !is_numeric($r['start_time'] ?? null)) {
                continue;
            }
            $ende = (float)$r['end_time'];
            if ($ende > $nach && $ende <= $bis) {
                $out[] = ['kamera' => (string)($r['camera'] ?? ''), 'start' => (float)$r['start_time'], 'ende' => $ende];
            }
        }
        usort($out, fn ($a, $b) => $a['ende'] <=> $b['ende']);
        return $out;
    }

    /** Hat eine Antwort von /api/<kamera>/recordings echten Inhalt? Ältere Frigate ohne segment_size zählen je Zeile. */
    public static function hatFilm(array $aufnahmen): bool
    {
        foreach ($aufnahmen as $a) {
            if (is_array($a) && (!isset($a['segment_size']) || (float)$a['segment_size'] > 0)) {
                return true;
            }
        }
        return false;
    }

    /** Ende der neuesten Aufnahme in einer Antwort von /api/<kamera>/recordings, 0 = keine. */
    public static function neuesteAufnahme(array $aufnahmen): float
    {
        $neueste = 0.0;
        foreach ($aufnahmen as $a) {
            if (is_array($a) && is_numeric($a['end_time'] ?? null)) {
                $neueste = max($neueste, (float)$a['end_time']);
            }
        }
        return $neueste;
    }

    /**
     * Eine Prüfung bewerten.
     *
     * $b (Beobachtung):
     *   erreichbar bool · speicher ?array (speicher()) · protokoll ?array (protokoll()), null = nicht lesbar
     *   alarme array<int,array{kamera,ende,film:bool}> · alarmeBis int · dauer array<string,float> (Kamera → Ende neueste Aufnahme)
     *
     * @return array{zustand:array,probleme:array<string,string>,meldung:?array{titel:string,text:string,art:string}}
     */
    public static function bewerten(array $z, array $b, int $jetzt): array
    {
        $alt = array_keys((array)($z['aktiv'] ?? []));
        $seit = (array)($z['seit'] ?? []);
        $texte = (array)($z['aktiv'] ?? []);

        if (empty($b['erreichbar'])) {
            $start = (int)($z['stilleSeit'] ?? 0) ?: $jetzt;
            $z['stilleSeit'] = $start;
            if ($jetzt - $start >= self::STILLE) {
                $texte['erreichbar'] = FrigateHubText::t('Frigate has not responded since %s.', self::zeit($start, $jetzt));
                $seit['erreichbar'] = $seit['erreichbar'] ?? $start;
            }
            // Ohne Antwort lässt sich der Rest nicht prüfen: Zustand der übrigen bleibt stehen.
        } else {
            unset($z['stilleSeit'], $texte['erreichbar'], $seit['erreichbar']);

            // Schreibfehler im Protokoll
            $p = $b['protokoll'] ?? null;
            if (is_array($p)) {
                $z['protokoll'] = ['neueste' => $p['neueste'], 'wiederholt' => $p['wiederholt']];
                if ($p['fehler'] >= self::FEHLER_AB) {
                    $seit['schreibfehler'] = $seit['schreibfehler'] ?? $jetzt;
                    $z['gut'] = 0;
                    $texte['schreibfehler'] = FrigateHubText::t('Frigate cannot save recordings: write errors since %s.', self::zeit($seit['schreibfehler'], $jetzt));
                } elseif (isset($texte['schreibfehler']) && $p['fehler'] === 0) {
                    $z['gut'] = (int)($z['gut'] ?? 0) + 1;
                    if ($z['gut'] >= self::GUT_NACH) {
                        unset($texte['schreibfehler'], $seit['schreibfehler'], $z['gut']);
                    }
                }
            }

            // Aufnahmespeicher vertauscht
            $sp = $b['speicher'] ?? null;
            if (is_array($sp)) {
                $bekannt = $z['speicher'] ?? null;
                if (!is_array($bekannt)) {
                    $z['speicher'] = $sp;
                    unset($texte['speicher'], $seit['speicher']);
                } elseif (self::speicherGewechselt($bekannt, $sp)) {
                    $seit['speicher'] = $seit['speicher'] ?? $jetzt;
                    $texte['speicher'] = FrigateHubText::t('Recording storage changed: now %s instead of %s.', self::speicherText($sp), self::speicherText($bekannt));
                } else {
                    unset($texte['speicher'], $seit['speicher']);
                }
            }

            // Alarme ohne Film: je Kamera bis zum nächsten Alarm mit Film, höchstens ALARM_HALTEN
            $ohne = (array)($z['alarmOhneFilm'] ?? []);
            foreach ((array)($b['alarme'] ?? []) as $a) {
                if ($a['film']) {
                    unset($ohne[$a['kamera']]);
                } else {
                    $ohne[$a['kamera']] = (int)$a['ende'];
                }
            }
            $ohne = array_filter($ohne, fn ($t) => $jetzt - $t < self::ALARM_HALTEN);
            $z['alarmOhneFilm'] = $ohne;
            if (isset($b['alarmeBis'])) {
                $z['alarmeBis'] = (int)$b['alarmeBis'];
            }
            if ($ohne === []) {
                unset($texte['alarm'], $seit['alarm']);
            } else {
                ksort($ohne);
                $teile = [];
                foreach ($ohne as $kamera => $t) {
                    $teile[] = $kamera . ' ' . self::zeit($t, $jetzt);
                }
                $seit['alarm'] = $seit['alarm'] ?? min($ohne);
                $texte['alarm'] = FrigateHubText::t('Alert without recording: %s.', implode(', ', $teile));
            }

            // Daueraufnahme steht
            $stehen = [];
            foreach ((array)($b['dauer'] ?? []) as $kamera => $ende) {
                if ($jetzt - $ende > self::DAUER_ALTER) {
                    $stehen[] = $ende > 0 ? FrigateHubText::t('%s since %s', (string)$kamera, self::zeit((int)$ende, $jetzt)) : (string)$kamera;
                }
            }
            if ($stehen === []) {
                unset($texte['dauer'], $seit['dauer']);
            } else {
                sort($stehen);
                $seit['dauer'] = $seit['dauer'] ?? $jetzt;
                $texte['dauer'] = FrigateHubText::t('Continuous recording stopped: %s.', implode(', ', $stehen));
            }
        }

        $texte = self::sortieren($texte);
        $z['aktiv'] = $texte;
        $z['seit'] = $seit;

        $meldung = null;
        $neu = array_diff(array_keys($texte), $alt);
        $letzte = (int)($z['letzteMeldung'] ?? 0);
        if ($neu !== []) {
            $meldung = ['titel' => FrigateHubText::t('⛔ Frigate recording disrupted'), 'text' => implode(' ', $texte), 'art' => 'stoerung'];
        } elseif ($texte === [] && $alt !== []) {
            $meldung = ['titel' => FrigateHubText::t('✅ Frigate recording OK'), 'text' => FrigateHubText::t('The fault is cleared, Frigate is recording again.'), 'art' => 'entwarnung'];
        } elseif ($texte !== [] && $jetzt - $letzte >= self::ERINNERUNG) {
            $meldung = ['titel' => FrigateHubText::t('⏰ Frigate recording disrupted'), 'text' => FrigateHubText::t('Still: %s', implode(' ', $texte)), 'art' => 'erinnerung'];
        }
        if ($meldung !== null) {
            $z['letzteMeldung'] = $jetzt;
        }
        return ['zustand' => $z, 'probleme' => $texte, 'meldung' => $meldung];
    }

    /** Klartext für die Variable „Aufnahmezustand". */
    public static function zustandText(array $probleme): string
    {
        return $probleme === [] ? FrigateHubText::t('Frigate is recording, no fault detected.') : implode(' ', $probleme);
    }

    private static function sortieren(array $texte): array
    {
        $out = [];
        foreach (self::REIHENFOLGE as $k) {
            if (isset($texte[$k])) {
                $out[$k] = $texte[$k];
            }
        }
        return $out;
    }

    /** „19:13" am selben Tag, sonst „31.10. 19:13". */
    public static function zeit(int $t, int $jetzt): string
    {
        return date('Y-m-d', $t) === date('Y-m-d', $jetzt) ? date('H:i', $t) : date('d.m. H:i', $t);
    }
}
