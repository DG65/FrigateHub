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
