<?php

declare(strict_types=1);

/**
 * Übersetzung für alle FrigateHub-Teile (Muster RoboHub 0.35). Quelltexte stehen auf Englisch im Code,
 * die deutsche Fassung in locale.json. Die Datei entsteht aus .tools/locale-teile/*.json und liegt
 * in jedem Modulordner (Symcon liest locale.json nur dort): php .tools/locale-bauen.php
 *
 * Reine Klassen (Topics, Wächter, Sensor, Einstellungen, Serverwerte) haben kein $this->Translate();
 * jedes Modul setzt deshalb im Konstruktor seinen Übersetzer. Ohne Übersetzer bleibt der englische Text.
 */
final class FrigateHubText
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /** @var array<string,string>|false|null null = Sprache noch nicht bestimmt, false = nicht Deutsch */
    private static $tabelle = null;
    /** @var array<string,string> */
    private static $gemerkt = [];

    /**
     * Übersetzer für die Module: Symcons Translate() liest bei jedem Aufruf locale.json — bei hunderten Texten
     * je Formular, Kachel oder MQTT-Nachricht summiert sich das (SUITE.md Stolperstein 24, Automat live 05.11.2026:
     * Tick 2,5 s, Formular 5,4 s; FrigateHub live 05.11.: Formular der Kamera über eine Minute). Deshalb wird Symcon
     * nur einmal nach der Sprache gefragt; bei Deutsch liest FrigateHub die Tabelle selbst, sonst merkt es sich
     * jede Antwort von Symcon.
     */
    public static function mitTabelle(callable $translate, string $localeDatei): callable
    {
        return function (string $text) use ($translate, $localeDatei): string {
            if (self::$tabelle === null) {
                $probe = 'Motion';
                $ziel = (string)$translate($probe);
                $de = $ziel === $probe ? [] : (json_decode((string)@file_get_contents($localeDatei), true)['translations']['de'] ?? []);
                // Englisch: Tabelle leer (Quelltext gilt). Deutsch: eigene Tabelle. Andere Sprache/unlesbar: je Text einmal fragen.
                self::$tabelle = $ziel === $probe || ($de[$probe] ?? null) === $ziel ? $de : false;
                self::$gemerkt[$probe] = $ziel;
            }
            if (self::$tabelle !== false) {
                return self::$tabelle[$text] ?? $text;
            }
            return self::$gemerkt[$text] ?? (self::$gemerkt[$text] = (string)$translate($text));
        };
    }

    /** Nur für den Prüfstand: Sprachwahl vergessen. */
    public static function vergessen(): void
    {
        self::$tabelle = null;
        self::$gemerkt = [];
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(fn ($a) => is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a), $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    /** Für Werte aus Tabellen (Konstanten), die erst beim Anzeigen übersetzt werden. */
    public static function wert($wert)
    {
        return is_string($wert) && $wert !== '' ? self::t($wert) : $wert;
    }
}
