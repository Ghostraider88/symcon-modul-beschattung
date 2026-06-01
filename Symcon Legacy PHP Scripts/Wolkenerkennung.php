<?php

// === IDs ===
$helligkeitID = 39578;   // Aktueller Messwert der Helligkeit in LUX (nach Süden)
$historieID   = 50703;   // JSON-String mit Historie
$wechselID    = 31462;   // Anzahl Helligkeitswechsel
$anteilID     = 28703;   // Sonnenscheinanteil in %
$alternativID = 54284;   // Ob alternativer Modus aktiv ist

// === Konfiguration ===
$schwelleSonne   = 20000; // Ab hier gilt es als "sonnig" (in Lux)
$wechselToleranz = 3000;  // Differenz für "Wechsel" in Lux
$maxDauer        = 60;    // betrachtete Zeitspanne in Minuten
$wechselGrenze   = 8;     // ab dieser Anzahl wird Modus aktiviert

// === Aktuellen Wert lesen ===
$wert = (float)GetValueInteger($helligkeitID);
$zeit = time();

// === Historie laden oder initialisieren ===
$historie = json_decode(GetValueString($historieID), true);
if (!is_array($historie)) $historie = [];

// Neuen Eintrag hinzufügen
$historie[] = ['zeit' => $zeit, 'wert' => $wert];

// Alte Einträge entfernen (älter als 60 Min)
$grenze = $zeit - ($maxDauer * 60);
$historie = array_filter($historie, fn($e) => $e['zeit'] >= $grenze);

// === Analyse ===
$anzahlWechsel = 0;
$anzahlSonnig  = 0;
$letzterWert   = null;

foreach ($historie as $eintrag) {
    if ($eintrag['wert'] > $schwelleSonne) $anzahlSonnig++;
    if (!is_null($letzterWert) && abs($eintrag['wert'] - $letzterWert) > $wechselToleranz) {
        $anzahlWechsel++;
    }
    $letzterWert = $eintrag['wert'];
}

$anzahlGesamt = count($historie);
$anteil = $anzahlGesamt > 0 ? round(($anzahlSonnig / $anzahlGesamt) * 100, 1) : 0;

// === Alternativmodus-Status prüfen & ggf. ändern ===
$alternativAktiv = GetValueBoolean($alternativID);

if (!$alternativAktiv && $anzahlWechsel >= $wechselGrenze) {
    SetValueBoolean($alternativID, true);
    IPS_LogMessage("Wolkenerkennung", "→ Alternativmodus aktiviert (Φ), $anzahlWechsel Wechsel erkannt.");
}
elseif ($alternativAktiv && $anzahlWechsel < ($wechselGrenze / 2)) {
    SetValueBoolean($alternativID, false);
    IPS_LogMessage("Wolkenerkennung", "→ Alternativmodus beendet – Helligkeit stabil.");
}

// === Ergebnisse speichern ===
SetValueString($historieID, json_encode(array_values($historie)));
SetValueInteger($wechselID, $anzahlWechsel);
SetValueFloat($anteilID, $anteil);
