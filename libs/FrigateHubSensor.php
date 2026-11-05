<?php

declare(strict_types=1);

require_once __DIR__ . '/FrigateHubText.php';

/**
 * Die Kamera als Sensor — reines PHP, vom Prüfstand direkt aufgerufen.
 *
 * Aus frigate/events (Felder laut Frigate-Doku 0.18.2, docs/integrations/mqtt.md) führt die Kamera
 * eine Liste der gerade verfolgten Objekte. Daraus entstehen Werte mit Bedeutung:
 * - „steht seit": Frigate markiert ein Objekt als `stationary`, sobald es sich eine Weile nicht bewegt
 *   (detect.stationary.threshold, Standard 50 Bilder). Frigate schickt dann keine Updates mehr —
 *   der Zeitpunkt ist der Moment, in dem FrigateHub `stationary: true` zum ersten Mal sah.
 * - „verweilt": ein Objekt steht seit mindestens N Sekunden in einer Zone (bewegt oder still).
 *   Frigate meldet dafür keine Zeit; die Kamera prüft per Timer, weil ein stillstehendes Objekt
 *   keine Updates mehr erzeugt.
 * - Tageszähler: neue Objekte je Klasse, Alarme.
 * Sabotage: Kennwerte eines kleinen Graustufenbildes (Helligkeit, Streuung, Raster) gegen die
 * gelernte Ansicht derselben Tagesstunde.
 */
class FrigateHubSensor
{
    /** Objekte, die länger als das nichts mehr gemeldet haben, gelten als verschwunden (Frigate schickte kein end). */
    public const VERGESSEN = 21600;

    /** Höchstzahl verfolgter Objekte im Puffer. */
    public const HOECHSTENS = 60;

    /**
     * Objektliste nach einem frigate/events-Paket nachführen.
     *
     * @param array<string,array> $objekte id → {label, start, zonen: zone → seit, steht: seit|0, zuletzt, geschwindigkeit}
     * @return array{objekte:array,neu:bool,ende:?array} ende = das beendete Objekt (für „Paket entfernt")
     */
    public static function nachfuehren(array $objekte, string $typ, array $after, int $jetzt): array
    {
        $id = (string)($after['id'] ?? '');
        if ($id === '') {
            return ['objekte' => $objekte, 'neu' => false, 'ende' => null];
        }
        $alt = $objekte[$id] ?? null;
        if ($typ === 'end' || is_numeric($after['end_time'] ?? null)) {
            unset($objekte[$id]);
            return ['objekte' => $objekte, 'neu' => false, 'ende' => $alt];
        }
        if (!empty($after['false_positive'])) {
            return ['objekte' => $objekte, 'neu' => false, 'ende' => null];
        }
        $o = $alt ?? ['label' => '', 'start' => $jetzt, 'zonen' => [], 'steht' => 0];
        $o['label'] = (string)($after['label'] ?? $o['label']);
        $o['zuletzt'] = $jetzt;
        $zonen = [];
        foreach ((array)($after['current_zones'] ?? []) as $z) {
            $z = (string)$z;
            if ($z !== '') {
                $zonen[$z] = (int)($o['zonen'][$z] ?? $jetzt);
            }
        }
        $o['zonen'] = $zonen;
        $o['steht'] = !empty($after['stationary']) ? ((int)$o['steht'] ?: $jetzt) : 0;
        if (is_numeric($after['average_estimated_speed'] ?? null) && (float)$after['average_estimated_speed'] > 0) {
            $o['geschwindigkeit'] = round((float)$after['average_estimated_speed'], 1);
        }
        $objekte[$id] = $o;
        // Ausgefallene end-Nachrichten: Altes wegräumen, Liste begrenzen
        $objekte = array_filter($objekte, fn ($x) => $jetzt - (int)($x['zuletzt'] ?? 0) < self::VERGESSEN);
        if (count($objekte) > self::HOECHSTENS) {
            uasort($objekte, fn ($a, $b) => (int)$a['zuletzt'] <=> (int)$b['zuletzt']);
            $objekte = array_slice($objekte, -self::HOECHSTENS, null, true);
        }
        return ['objekte' => $objekte, 'neu' => $alt === null, 'ende' => null];
    }

    /**
     * „steht seit" je Klasse und je Zone+Klasse: das am längsten stehende Objekt zählt.
     *
     * @return array{klasse:array<string,int>,zone:array<string,int>} zone-Schlüssel „<zone>|<label>"
     */
    public static function stehen(array $objekte): array
    {
        $klasse = [];
        $zone = [];
        foreach ($objekte as $o) {
            $seit = (int)($o['steht'] ?? 0);
            $label = (string)($o['label'] ?? '');
            if ($seit <= 0 || $label === '') {
                continue;
            }
            $klasse[$label] = isset($klasse[$label]) ? min($klasse[$label], $seit) : $seit;
            foreach (array_keys((array)($o['zonen'] ?? [])) as $z) {
                $k = $z . '|' . $label;
                $zone[$k] = isset($zone[$k]) ? min($zone[$k], $seit) : $seit;
            }
        }
        return ['klasse' => $klasse, 'zone' => $zone];
    }

    /**
     * Verweilen je Zone: irgendein Objekt der gewählten Klassen ist seit mindestens $schwelle s in der Zone.
     *
     * @param string[] $klassen leer = alle
     * @return array<string,bool> Zone → verweilt (nur Zonen, in denen gerade Objekte sind)
     */
    public static function verweilen(array $objekte, int $jetzt, int $schwelle, array $klassen): array
    {
        $out = [];
        foreach ($objekte as $o) {
            if ($klassen !== [] && !in_array((string)($o['label'] ?? ''), $klassen, true)) {
                continue;
            }
            foreach ((array)($o['zonen'] ?? []) as $z => $seit) {
                $out[$z] = ($out[$z] ?? false) || $jetzt - (int)$seit >= $schwelle;
            }
        }
        return $out;
    }

    /** Frühester Zeitpunkt, zu dem sich ein Verweilen ergeben kann (für den Timer), 0 = keiner offen. */
    public static function naechsteVerweilPruefung(array $objekte, int $jetzt, int $schwelle, array $klassen): int
    {
        $naechste = 0;
        foreach ($objekte as $o) {
            if ($klassen !== [] && !in_array((string)($o['label'] ?? ''), $klassen, true)) {
                continue;
            }
            foreach ((array)($o['zonen'] ?? []) as $seit) {
                $t = (int)$seit + $schwelle;
                if ($t > $jetzt && ($naechste === 0 || $t < $naechste)) {
                    $naechste = $t;
                }
            }
        }
        return $naechste;
    }

    /** Tageszähler: am neuen Tag von vorn. @return array{tag:string,zaehler:array<string,int>} */
    public static function zaehlen(array $statistik, string $schluessel, string $tag): array
    {
        $zaehler = ($statistik['tag'] ?? '') === $tag ? (array)($statistik['zaehler'] ?? []) : [];
        $zaehler[$schluessel] = (int)($zaehler[$schluessel] ?? 0) + 1;
        return ['tag' => $tag, 'zaehler' => $zaehler];
    }

    /** Name/Kennzeichen-Paar aus events: recognized_license_plate und sub_label (bekannter Name). */
    public static function fahrzeug(array $after): ?array
    {
        $kennzeichen = trim((string)($after['recognized_license_plate'] ?? ''));
        if ($kennzeichen === '') {
            return null;
        }
        $sub = $after['sub_label'] ?? null;
        $name = is_array($sub) ? trim((string)($sub[0] ?? '')) : (is_string($sub) ? trim($sub) : '');
        return ['kennzeichen' => $kennzeichen, 'name' => $name];
    }

    /* ---------------------------------------------------------------- Sabotage */

    /** Seitenlänge des Vergleichsrasters (Bild wird auf RASTER×RASTER Graustufen verkleinert). */
    public const RASTER = 8;

    /** Unter dieser Streuung (0–255) gilt ein Bild als einfarbig: abgedeckt, verdreckt oder Kamera kaputt. */
    public const EINFARBIG = 6.0;

    /**
     * Unter dieser Musterähnlichkeit (Korrelation −1…1, Helligkeit und Kontrast herausgerechnet) zur gelernten
     * Tagesansicht gilt die Kamera als verstellt. Gemessen an Dietmars Door-Cam (05.11.2026, 58 Bilder über 8 Tage):
     * Tagbilder verschiedener Tage 0,63–0,99, gespiegelt 0,26, um zwei Rasterfelder verschoben 0,17, Kontrast 40 % 1,0.
     */
    public const AEHNLICH = 0.45;

    /**
     * Ab dieser mittleren Farbigkeit (0–255) ist ein Bild ein Tagbild. Infrarot-/Nachtbilder sind grau: gemessen 0–3,5,
     * Tagbilder 8–16. Nur Tagbilder werden auf „verstellt" geprüft — nachts (Infrarot, Scheinwerfer, Licht an/aus)
     * ändert sich das Muster zu stark (Fehlalarme bei Dämmerung bis 0.18.x).
     */
    public const FARBE_TAG = 5.0;

    /** So viele Prüfungen in Folge, bis eine Auffälligkeit gemeldet wird (Lichtwechsel, Schatten, Regen). */
    public const BESTAETIGEN = 3;

    /** So viele Bilder je Tagesstunde, bis die gelernte Ansicht als Vergleich taugt. */
    public const LERNEN = 3;

    /**
     * Kennwerte aus einem JPEG (GD): mittlere Helligkeit, Streuung, Raster RASTER×RASTER. null = kein Bild/kein GD.
     *
     * @return array{hell:float,streuung:float,raster:float[]}|null
     */
    public static function kennwerte(string $jpeg): ?array
    {
        if (!function_exists('imagecreatefromstring') || $jpeg === '') {
            return null;
        }
        $bild = @imagecreatefromstring($jpeg);
        if ($bild === false) {
            return null;
        }
        $n = self::RASTER;
        $klein = imagecreatetruecolor($n, $n);
        imagecopyresampled($klein, $bild, 0, 0, 0, 0, $n, $n, imagesx($bild), imagesy($bild));
        $werte = [];
        $farbe = 0.0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $rgb = imagecolorat($klein, $x, $y);
                $r = ($rgb >> 16) & 255;
                $g = ($rgb >> 8) & 255;
                $b = $rgb & 255;
                $werte[] = round(0.299 * $r + 0.587 * $g + 0.114 * $b, 1);
                $farbe += (abs($r - $g) + abs($g - $b) + abs($r - $b)) / 3;
            }
        }
        $hell = array_sum($werte) / count($werte);
        $streuung = sqrt(array_sum(array_map(fn ($w) => ($w - $hell) ** 2, $werte)) / count($werte));
        return ['hell' => round($hell, 1), 'streuung' => round($streuung, 1), 'farbe' => round($farbe / count($werte), 1), 'raster' => $werte];
    }

    /**
     * Eine Sabotage-Prüfung bewerten. Die gelernte Ansicht je Tagesstunde ist der gleitende
     * Mittelwert der unauffälligen Bilder dieser Stunde — so passt sie sich an Jahreszeit und
     * Bewuchs an. „einfarbig" wird rund um die Uhr geprüft, „verstellt" nur bei Farbbild (Tag) gegen eigene
     * Tagesansichten (`tagansicht`) — Infrarot und Dämmerung lösten bis 0.18.x Fehlalarme aus.
     *
     * @return array{zustand:array,auffaellig:bool,text:string}
     */
    public static function sabotage(array $z, array $k, int $stunde): array
    {
        $ansicht = (array)($z['ansicht'][$stunde] ?? []);
        $tag = (float)($k['farbe'] ?? 0) >= self::FARBE_TAG;
        $grund = '';
        // Erst vergleichen, wenn die Stunde gelernt ist: nachts ohne Licht ist auch ein freies Bild fast einfarbig
        if ((int)($ansicht['n'] ?? 0) >= self::LERNEN && $k['streuung'] < self::EINFARBIG && (float)($ansicht['streuung'] ?? 0) >= 3 * self::EINFARBIG) {
            $grund = 'einfarbig';
        } elseif ($tag) {
            // „verstellt" nur bei Tageslicht und gegen gelernte Tagesansichten derselben Stunde und der Nachbarstunden
            // (die Dämmerung wandert übers Jahr durch die Stunden); die ähnlichste zählt.
            $beste = null;
            foreach ([$stunde, ($stunde + 23) % 24, ($stunde + 1) % 24] as $h) {
                $v = (array)($z['tagansicht'][$h] ?? []);
                if ((int)($v['n'] ?? 0) >= self::LERNEN && is_array($v['raster'] ?? null)) {
                    $a = self::aehnlichkeit($v['raster'], $k['raster']);
                    $beste = $beste === null ? $a : max($beste, $a);
                }
            }
            if ($beste !== null && $beste < self::AEHNLICH) {
                $grund = 'verstellt';
            }
        }
        $folge = $grund === '' ? 0 : ((($z['grund'] ?? '') === $grund) ? (int)($z['folge'] ?? 0) + 1 : 1);
        if ($grund === '') {
            // Nur unauffällige Bilder lernen: eine verstellte Kamera soll nicht zur neuen Normalität werden
            $z['ansicht'][$stunde] = self::lernen($ansicht, $k);
            if ($tag) {
                $z['tagansicht'][$stunde] = self::lernen((array)($z['tagansicht'][$stunde] ?? []), $k);
            }
        }
        $z['grund'] = $grund;
        $z['folge'] = $folge;
        $auffaellig = $folge >= self::BESTAETIGEN;
        $text = FrigateHubText::t(!$auffaellig ? 'Image looks normal.' : ($grund === 'einfarbig'
            ? 'Image almost uniform — camera covered, dirty or faulty?'
            : 'Image differs strongly from the usual view — camera moved or view blocked?'));
        return ['zustand' => $z, 'auffaellig' => $auffaellig, 'text' => $text];
    }

    /** Gleitender Mittelwert (bis 20 Bilder) einer gelernten Ansicht um ein unauffälliges Bild erweitern. */
    private static function lernen(array $ansicht, array $k): array
    {
        $n = min(20, (int)($ansicht['n'] ?? 0) + 1);
        $raster = is_array($ansicht['raster'] ?? null) && count($ansicht['raster']) === count($k['raster'])
            ? array_map(fn ($a, $b) => round($a + ($b - $a) / $n, 1), $ansicht['raster'], $k['raster'])
            : $k['raster'];
        $streuung = round((float)($ansicht['streuung'] ?? $k['streuung']) + ($k['streuung'] - (float)($ansicht['streuung'] ?? $k['streuung'])) / $n, 1);
        return ['n' => $n, 'raster' => $raster, 'streuung' => $streuung];
    }

    /**
     * Musterähnlichkeit zweier Raster (Korrelation, −1…1): Helligkeit UND Kontrast sind herausgerechnet — Sonne,
     * Wolken, Dämmerung oder Gegenlicht ändern das Muster kaum, eine verdrehte Kamera oder ein Gegenstand vor der
     * Linse schon. Ein Raster ohne Streuung (einfarbig) hat kein Muster: 0.
     */
    public static function aehnlichkeit(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $a = array_slice(array_map('floatval', $a), 0, $n);
        $b = array_slice(array_map('floatval', $b), 0, $n);
        $ma = array_sum($a) / $n;
        $mb = array_sum($b) / $n;
        $sa = sqrt(array_sum(array_map(fn ($v) => ($v - $ma) ** 2, $a)) / $n);
        $sb = sqrt(array_sum(array_map(fn ($v) => ($v - $mb) ** 2, $b)) / $n);
        if ($sa < 0.5 || $sb < 0.5) {
            return 0.0;
        }
        $summe = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $summe += ($a[$i] - $ma) * ($b[$i] - $mb);
        }
        return round($summe / ($n * $sa * $sb), 2);
    }
}
