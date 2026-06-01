<?php
declare(strict_types=1);

// === Ab hier ändern ===
// Aktoren
// auf 60 %
$aktorIDsBeschatten = [ 22105,
                        58464

                    ]; 
// auf Wert 0
$aktorIDsOeffnen   = [  33239,
                        56707
                    ]; 

// Debugging
$debugID = 41641;

// Ausrichtung
$fensterRichtungID = 49659; // Himmelsrichtung
$winkelRechtsID    = 32701; // rechter Winkel in °
$winkelLinksID     = 11455; // linker Winkel in °

// Geometrie
$hoeheDach     = GetValueFloat(32401);
$dachvorsprung = GetValueFloat(28131);
$endwinkel     = (float)GetValueInteger(26559);
$fensterBrett  = GetValueFloat(44983);

// Zustands-Variablen
$letzteBewegungID = 33983;
$protokollID      = 55827;
$autoID           = 31690;
$beschattID       = 11940;
$luxOKID          = 44504;
$tempOKID         = 58080;
$azimutOKID       = 18863;
$elevOKID         = 51691;
$sperrRestID      = 50222;  // ID der Variable “Sperrzeit übrig”
$letzterModusID   = 36050; // Integer- oder Boolean-Variable in Symcon: 1=Beschatten, 0=Offen


// === Ab hier nichts mehr ändern ===
// Sensoren
$elevationID  = 52571;
$azimutID     = 32562;
$helligkeitID = 39578;
$tempAussenID = 29425;

// Alternativmodus
$alternativID  = 54284;
$anteilSonneID = 28703;

// Zeitfenster (in Sekunden ab Mitternacht)
$fruehestensSec = GetValueInteger(26057);  // z.B. 28800 für 08:00
$spaetestensSec = GetValueInteger(50517);  // z.B. 74700 für 20:45

// Prüfen, ob aktuell Sommerzeit gilt (date('I') = 1 im DST)
$dstOffset = date('I') ? 3600 : 0;

$fruehestensSec += $dstOffset;  // automatisch +1 h während der Sommerzeit
$spaetestensSec += $dstOffset;

// Sperrzeit (Sekunden)
$sperrzeitSekunden = GetValueInteger(31081);

// Hysterese- und Schwellenwerte
$kritTempSofort = 30.0;   // sofort Beschattung ab hier
$tempHystEin    = 22.5;   // Ein-Schwelle Temperatur
$tempHystAus    = 21.5;   // Aus-Schwelle Temperatur
$luxHystEin     = 21000;  // Ein-Schwelle Helligkeit
$luxHystAus     = 19000;  // Aus-Schwelle Helligkeit

// === Werte einlesen ===
$jetzt            = time();
$elevation        = GetValueFloat($elevationID);
$azimut           = GetValueFloat($azimutID);
$helligkeit       = GetValueInteger($helligkeitID);
$tempAussen       = GetValueFloat($tempAussenID);
$fensterRichtung  = GetValueInteger($fensterRichtungID);
$winkelLinks      = GetValueInteger($winkelLinksID);
$winkelRechts     = GetValueInteger($winkelRechtsID);
$alternativAktiv  = GetValueBoolean($alternativID);
$anteilSonne      = GetValueFloat($anteilSonneID);
$letzteBewegung   = GetValueInteger($letzteBewegungID);
$automatikAktiv   = GetValueBoolean($autoID);

// === Azimut-Bereich Fenster ===
$azimutVon = fmod($fensterRichtung - $winkelLinks + 360, 360);
$azimutBis = fmod($fensterRichtung + $winkelRechts, 360);

// === Zeitfenster prüfen ===
// jetzt in Sekunden ab Mitternacht
$heuteSec = ((int)date('H') * 3600) + ((int)date('i') * 60);

if ($heuteSec < $fruehestensSec) {
    SetValueBoolean($beschattID, false);
    ProtokollEintrag("⏰ außerhalb Zeitfenster (zu früh)");
    return;
}
if ($heuteSec >= $spaetestensSec) {
    foreach ($aktorIDsOeffnen as $id) {
        RequestAction($id, 0);
    }
    SetValueInteger($letzteBewegungID, $jetzt);
    SetValueBoolean($beschattID, false);
    ProtokollEintrag("⏰ außerhalb Zeitfenster (zu spät) → Jalousie geöffnet");
    return;
}

// === Automatik aktiv? ===
if (!$automatikAktiv) {
    SetValueBoolean($beschattID, false);
    ProtokollEintrag("🚫 Automatik deaktiviert");
    return;
}

// === Azimut prüfen ===
$azimutOK = ($azimutVon > $azimutBis)
    ? ($azimut >= $azimutVon || $azimut <= $azimutBis)
    : ($azimut >= $azimutVon && $azimut <= $azimutBis);
SetValueBoolean($azimutOKID, $azimutOK);

// === Elevation prüfen ===
$kritischerWinkel = rad2deg(atan($dachvorsprung / ($hoeheDach - $fensterBrett)));
$elevOK = ($elevation > $kritischerWinkel && $elevation > $endwinkel);
SetValueBoolean($elevOKID, $elevOK);

// === Hysterese Temperatur ===
$tempOK = GetValueBoolean($tempOKID);
if ($tempOK && $tempAussen < $tempHystAus) {
    $tempOK = false;
} elseif (!$tempOK && $tempAussen > $tempHystEin) {
    $tempOK = true;
}
SetValueBoolean($tempOKID, $tempOK);

// === Hysterese Helligkeit ===
$luxOK = GetValueBoolean($luxOKID);
if ($luxOK && $helligkeit < $luxHystAus) {
    $luxOK = false;
} elseif (!$luxOK && $helligkeit > $luxHystEin) {
    $luxOK = true;
}
SetValueBoolean($luxOKID, $luxOK);

// === Entscheidung Beschatten vs. Öffnen ===
$beschatten = false;
$grund      = "";
if ($tempAussen >= $kritTempSofort) {
    $beschatten = true;
    $grund       = "🔥 Sofort (T ≥ {$kritTempSofort}°C)";
} else {
    if ($luxOK && $tempOK) {
        if ($alternativAktiv) {
            $beschatten = ($anteilSonne > 50);
            $grund       = "Φ Alternativ (Sonne {$anteilSonne}%)";
        } else {
            $beschatten = ($elevOK && $azimutOK);
            $grund       = "☀️ Sonnenstand";
        }
    } else {
        $grund = "⚠️ Schwellwerte nicht erfüllt";
    }
}
SetValueBoolean($beschattID, $beschatten);

// === Bewegung + Sperrzeit ===
$zielwert      = $beschatten ? 60 : 0;           // Beschatten=60, Öffnen=1
$seitLetzter   = $jetzt - $letzteBewegung;
$restSperrzeit = max(0, $sperrzeitSekunden - $seitLetzter);
SetValueInteger(50222, $restSperrzeit);

// $aktiveIDs definieren
$aktiveIDs = $beschatten ? $aktorIDsBeschatten : $aktorIDsOeffnen;

// Fahrbefehl Auslösen
$letzterModus = GetValueBoolean($letzterModusID); // Letzter Modus merken
$bewegungNoetig = ($beschatten !== $letzterModus);

if ($bewegungNoetig && $seitLetzter >= $sperrzeitSekunden) {
    $aktiveIDs = $beschatten ? $aktorIDsBeschatten : $aktorIDsOeffnen;
    foreach ($aktiveIDs as $id) {
        RequestAction($id, $beschatten ? 60 : 0);
    }
    SetValueInteger($letzteBewegungID, $jetzt);
    SetValueBoolean($letzterModusID, $beschatten); // Jetzt den neuen Modus merken!
    ProtokollEintrag("✅ Ziel {$zielwert}% – {$grund}");
} else {
    if ($restSperrzeit > 0 && $bewegungNoetig) {
        ProtokollEintrag("⏳ Sperrzeit aktiv: {$restSperrzeit}s übrig");
    }
    if (GetValueBoolean($debugID)) {
        ProtokollEintrag("[DEBUG] keine Bewegung nötig (Modus unverändert)", true);
    }
}



// === Debug-Protokoll ===
if (GetValueBoolean($debugID)) {
    $tempIst  = round($tempAussen,1);
    $luxIst   = $helligkeit;
    $tempSoll = $tempHystEin;
    $luxSoll  = $luxHystEin;
    $zeitAkt  = date('H:i');
    $frStd = str_pad((string)floor($fruehestensSec/3600), 2, '0', STR_PAD_LEFT);
    $frMin = str_pad((string)floor(($fruehestensSec%3600)/60), 2, '0', STR_PAD_LEFT);
    $spStd = str_pad((string)floor($spaetestensSec/3600), 2, '0', STR_PAD_LEFT);
    $spMin = str_pad((string)floor(($spaetestensSec%3600)/60), 2, '0', STR_PAD_LEFT);

    ProtokollEintrag(
        "📋 Debug: Fensterwinkel " . round($azimutVon) . "°–" . round($azimutBis) . "°, "
      . "akt. Sonne " . round($azimut) . "°; "
      . "Temp: {$tempIst}°C / {$tempSoll}°C; "
      . "Lux: " . number_format($luxIst,0,',','.') . " lx / " . number_format($luxSoll,0,',','.') . " lx; "
      . "Zeitfenster: {$frStd}:{$frMin}–{$spStd}:{$spMin}, jetzt {$zeitAkt} ",
      true
    );
}

// === Protokoll-Funktion ===
function ProtokollEintrag(string $text, bool $debugOnly = false): void
{
    global $protokollID, $debugID;
    if ($debugOnly && !GetValueBoolean($debugID)) {
        return;
    }
    $ts    = date("d.m.Y H:i:s");
    $zeile = "<b>{$ts}</b> – " . formatText($text) . "<br>";
    $alt   = GetValueString($protokollID);
    $lines = explode("<br>", $alt);
    array_unshift($lines, $zeile);
    $lines = array_slice($lines, 0, 60);
    SetValueString($protokollID, implode("<br>", $lines));
}

// === Beschriftung aktualisieren ===
$profilTemp = sprintf("Zustand: Temperatur (%.1f °C / %.1f °C)", $tempAussen, $tempHystEin);
IPS_SetName($tempOKID, $profilTemp);

$profilLux = sprintf("Zustand: Helligkeit (%s Lux / %s Lux)", number_format($helligkeit, 0, ',', '.'), number_format($luxHystEin, 0, ',', '.'));
IPS_SetName($luxOKID, $profilLux);

// === Farbformatierung ===
function formatText(string $text): string
{
    if (str_contains($text, '🔥'))   return "<span style='color:crimson'>{$text}</span>";
    if (str_contains($text, '✅'))   return "<span style='color:darkgreen'>{$text}</span>";
    if (str_contains($text, '⏳'))   return "<span style='color:orange'>{$text}</span>";
    if (str_contains($text, '🚫'))   return "<span style='color:gray'>{$text}</span>";
    return $text;
}

// === HTML-Visualisierung Sonnenstand, Temperatur, Helligkeit & Zeit ===
$monitorBoxID = 36156;

// ganz am Ende, nach allen Berechnungen:
$statusIcon   = $beschatten ? '🪟🔒' : '🪟🔓';
$statusText   = $beschatten ? 'Beschattet' : 'Offen';

// Zeitfenster-Strings
$fromTime   = sprintf('%02d:%02d', floor($fruehestensSec/3600), ($fruehestensSec%3600)/60);
$toTime     = sprintf('%02d:%02d', floor($spaetestensSec/3600), ($spaetestensSec%3600)/60);
$currentTime = date('H:i');

// Berechne Prozente schon vorher
$azPos  = $azimut / 360 * 100;
$azFrom = $azimutVon / 360 * 100;
$azW    = ($azimutBis - $azimutVon + 360) % 360 / 360 * 100;

$tempPercent   = min(100, $tempAussen/40 * 100);
$tempRangeFrom = $tempHystAus/40 * 100;
$tempRangeW    = ($tempHystEin - $tempHystAus)/40 * 100;

$luxPercent    = min(100, $helligkeit/30000 * 100);
$luxRangeFrom  = $luxHystAus/30000 * 100;
$luxRangeW     = ($luxHystEin - $luxHystAus)/30000 * 100;

$timePercent = $heuteSec/(24*3600) * 100;
$timeFrom    = $fruehestensSec/(24*3600) * 100;
$timeRangeW  = ($spaetestensSec-$fruehestensSec)/(24*3600) * 100;

// === Vorher im Script berechnen ===
// runde und bereite alle Werte vor, die Du im HTML brauchst
$azimutRounded       = round($azimut);
$azimutVonRounded    = round($azimutVon);
$azimutBisRounded    = round($azimutBis);
$tempRounded         = round($tempAussen,1);
$tempHystAusRounded  = round($tempHystAus,1);
$tempHystEinRounded  = round($tempHystEin,1);
$luxRounded          = number_format($helligkeit,0,',','.');
$luxHystAusRounded   = number_format($luxHystAus,0,',','.');
$luxHystEinRounded   = number_format($luxHystEin,0,',','.');
$elevPercent       = min(100, $elevation / 90 * 100); // 90° = max vertikaler Winkel
$elevKritPercent   = min(100, $kritischerWinkel / 90 * 100);
$elevEndPercent    = min(100, $endwinkel / 90 * 100);

$elevRounded       = round($elevation, 1);
$elevKritRounded   = round($kritischerWinkel, 1);
$elevEndRounded    = round($endwinkel, 1);


// Dann der HTML‐Block
$html = <<<HTML
<style>
  .box   { font-family: sans-serif; font-size: 13px; }
  .status{ font-size: 16px; margin-bottom: 8px; }
  .label { font-weight: bold; margin-top: 10px; }
  .scale { display: flex; justify-content: space-between; font-size: 11px; color: #666; margin-top: 4px; }
  .bar   { position: relative; width: 100%; height: 12px; background: #eee; margin-top: 4px; }
  .range { position: absolute; top: 0; height: 100%; background: #c8f7c5; }
  .marker{ position: absolute; top: -2px; width: 2px; height: 16px; background: red; }
</style>

<div class="box">
  <!-- Status -->
  <div class="status">{$statusIcon} {$statusText}</div>

  <!-- Sonnenstand -->
  <div class="label">🌞 Azimut: {$azimutRounded}° (Fenster {$azimutVonRounded}°–{$azimutBisRounded}°)</div>
  <div class="scale"><span>N</span><span>W</span><span>S</span><span>O</span><span>N</span></div>
  <div class="bar">
    <div class="range" style="left:{$azFrom}%; width:{$azW}%;"></div>
    <div class="marker" style="left:{$azPos}%;"></div>
  </div>

  <!-- Elevation -->
  <div class="label">📐 Elevation: {$elevRounded}° (Grenzen: {$elevKritRounded}° / {$elevEndRounded}°)</div>
  <div class="scale"><span>0°</span><span>45°</span><span>90°</span></div>
  <div class="bar">
    <div class="range" style="left:{$elevKritPercent}%; width:2px; background:orange;"></div>
    <div class="range" style="left:{$elevEndPercent}%; width:2px; background:crimson;"></div>
    <div class="marker" style="left:{$elevPercent}%;"></div>
  </div>

  <!-- Temperatur -->
  <div class="label">🌡️ Temperatur: {$tempRounded}°C (Hysterese {$tempHystAusRounded}–{$tempHystEinRounded}°C)</div>
  <div class="scale"><span>0 °C</span><span>20 °C</span><span>40 °C</span></div>
  <div class="bar">
    <div class="range" style="left:{$tempRangeFrom}%; width:{$tempRangeW}%;"></div>
    <div class="marker" style="left:{$tempPercent}%;"></div>
  </div>

  <!-- Helligkeit -->
  <div class="label">💡 Helligkeit: {$luxRounded} lx (Hysterese {$luxHystAusRounded}–{$luxHystEinRounded} lx)</div>
  <div class="scale"><span>0 lx</span><span>15000 lx</span><span>30000 lx</span></div>
  <div class="bar">
    <div class="range" style="left:{$luxRangeFrom}%; width:{$luxRangeW}%;"></div>
    <div class="marker" style="left:{$luxPercent}%;"></div>
  </div>

  <!-- Zeit -->
  <div class="label">⏰ Zeit: {$currentTime} (Fenster {$fromTime}–{$toTime})</div>
  <div class="scale"><span>0:00</span><span>6:00</span><span>12:00</span><span>18:00</span><span>24:00</span></div>
  <div class="bar">
    <div class="range" style="left:{$timeFrom}%; width:{$timeRangeW}%;"></div>
    <div class="marker" style="left:{$timePercent}%;"></div>
  </div>
</div>
HTML;

// In die Variable schreiben
if (IPS_VariableExists($monitorBoxID)) {
    SetValueString($monitorBoxID, $html);
}