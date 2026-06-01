<?php
declare(strict_types=1);
function StarteBeschattung(array $cfg)
{
    // --- Werte & IDs auslesen ---
    $jetzt = time();

    // Geometrie
    $hoeheDach     = GetValueFloat($cfg['hoeheDachID']);
    $dachvorsprung = GetValueFloat($cfg['dachvorsprungID']);
    $endwinkel     = (float)GetValueInteger($cfg['endwinkelID']);
    $fensterBrett  = GetValueFloat($cfg['fensterBrettID']);

    // Zustände
    $letzteBewegungID = $cfg['letzteBewegungID'];
    $protokollID      = $cfg['protokollID'];
    $autoID           = $cfg['autoID'];
    $beschattID       = $cfg['beschattID'];
    $luxOKID          = $cfg['luxOKID'];
    $tempOKID         = $cfg['tempOKID'];
    $azimutOKID       = $cfg['azimutOKID'];
    $elevOKID         = $cfg['elevOKID'];
    $sperrRestID      = $cfg['sperrRestID'];
    $letzterModusID   = $cfg['letzterModusID'];
    $debugID          = $cfg['debugID'];
    $monitorBoxID     = $cfg['monitorBoxID'];

    // Sensoren
    $elevation    = GetValueFloat($cfg['elevationID']);
    $azimut       = GetValueFloat($cfg['azimutID']);
    $helligkeit   = GetValueInteger($cfg['helligkeitID']);
    $tempAussen   = GetValueFloat($cfg['tempAussenID']);

    // Alternativmodus
    $alternativAktiv = isset($cfg['alternativID']) ? GetValueBoolean($cfg['alternativID']) : false;
    $anteilSonne     = isset($cfg['anteilSonneID']) ? GetValueFloat($cfg['anteilSonneID']) : 0;

    // Zeitfenster
    $fruehestensSec = GetValueInteger($cfg['fruehestensSecID']);
    $spaetestensSec = GetValueInteger($cfg['spaetestensSecID']);
    $dstOffset = date('I') ? 3600 : 0;
    $fruehestensSec += $dstOffset;
    $spaetestensSec += $dstOffset;

    // Sperrzeit
    $sperrzeitSekunden = GetValueInteger($cfg['sperrzeitID']);

    // Hysterese & Schwellen
    $kritTempSofort = $cfg['kritTempSofort'];
    $tempHystEin    = $cfg['tempHystEin'];
    $tempHystAus    = $cfg['tempHystAus'];
    $luxHystEin     = $cfg['luxHystEin'];
    $luxHystAus     = $cfg['luxHystAus'];

    // Ausrichtung
    $fensterRichtung = GetValueInteger($cfg['fensterRichtungID']);
    $winkelLinks     = GetValueInteger($cfg['winkelLinksID']);
    $winkelRechts    = GetValueInteger($cfg['winkelRechtsID']);

    // Aktorlisten
    $aktorAutomatik         = $cfg['aktorAutomatik'];
    $aktorAutomatikOeffnen  = $cfg['aktorAutomatikOeffnen'];

    // --- Azimut-Bereich Fenster ---
    $azimutVon = fmod($fensterRichtung - $winkelLinks + 360, 360);
    $azimutBis = fmod($fensterRichtung + $winkelRechts, 360);

    // --- Zeitfenster prüfen ---
    $heuteSec = ((int)date('H') * 3600) + ((int)date('i') * 60);
    if ($heuteSec < $fruehestensSec) {
        SetValueBoolean($beschattID, false);
        ProtokollEintrag("⏰ außerhalb Zeitfenster (zu früh)", $protokollID, $debugID);
        return;
    }
    if ($heuteSec >= $spaetestensSec) {
        $aktiveIDs = GetAktiveAktorIDs($aktorAutomatikOeffnen, false, $protokollID, $debugID);
        foreach ($aktiveIDs as $id) RequestAction($id, 0);
        SetValueInteger($letzteBewegungID, $jetzt);
        SetValueBoolean($beschattID, false);
        ProtokollEintrag("⏰ außerhalb Zeitfenster (zu spät) → Jalousie geöffnet", $protokollID, $debugID);
        return;
    }

    // --- Automatik aktiv? ---
    $automatikAktiv = GetValueBoolean($autoID);
    if (!$automatikAktiv) {
        SetValueBoolean($beschattID, false);
        ProtokollEintrag("🚫 Automatik deaktiviert", $protokollID, $debugID);
        return;
    }

    // --- Azimut/Elevation prüfen ---
    $azimutOK = ($azimutVon > $azimutBis)
        ? ($azimut >= $azimutVon || $azimut <= $azimutBis)
        : ($azimut >= $azimutVon && $azimut <= $azimutBis);
    SetValueBoolean($azimutOKID, $azimutOK);

    $kritischerWinkel = rad2deg(atan($dachvorsprung / ($hoeheDach - $fensterBrett)));
    $elevOK = ($elevation > $kritischerWinkel && $elevation > $endwinkel);
    SetValueBoolean($elevOKID, $elevOK);

    // --- Hysterese Temperatur ---
    $tempOK = GetValueBoolean($tempOKID);
    if ($tempOK && $tempAussen < $tempHystAus)      $tempOK = false;
    elseif (!$tempOK && $tempAussen > $tempHystEin) $tempOK = true;
    SetValueBoolean($tempOKID, $tempOK);

    // --- Hysterese Helligkeit ---
    $luxOK = GetValueBoolean($luxOKID);
    if ($luxOK && $helligkeit < $luxHystAus)        $luxOK = false;
    elseif (!$luxOK && $helligkeit > $luxHystEin)   $luxOK = true;
    SetValueBoolean($luxOKID, $luxOK);

    $profilTemp = sprintf("Zust.: Temperatur (%.1f °C / %.1f °C)", $tempAussen, $tempHystEin);
    IPS_SetName($tempOKID, $profilTemp);

    $profilLux = sprintf("Zust.: Helligkeit (%s Lux / %s Lux)", number_format($helligkeit, 0, ',', '.'), number_format($luxHystEin, 0, ',', '.'));
    IPS_SetName($luxOKID, $profilLux);

    $profilAzimut = sprintf("Zust.: horiz. Son. (%.0f° Az. | Fenster %.0f–%.0f°)", $azimut, $azimutVon, $azimutBis);
    IPS_SetName($azimutOKID, $profilAzimut);

    $profilElev = sprintf("Zust.: vert. Son. (%.1f° / %.1f°)", $elevation, $endwinkel);
    IPS_SetName($elevOKID, $profilElev);

    // --- Entscheidung ---
    $beschatten = false;
    $grund      = "";
    if ($tempAussen >= $kritTempSofort) {
        $beschatten = true;
        $grund      = "🔥 Sofort (T ≥ {$kritTempSofort}°C)";
    } else {
        if ($luxOK && $tempOK) {
            if ($alternativAktiv) {
                $beschatten = ($anteilSonne > 50);
                $grund      = "Φ Alternativ (Sonne {$anteilSonne}%)";
            } else {
                $beschatten = ($elevOK && $azimutOK);
                $grund      = "☀️ Sonnenstand";
            }
        } else {
            $grund = "⚠️ Schwellwerte nicht erfüllt";
        }
    }
    SetValueBoolean($beschattID, $beschatten);

    // --- Bewegung + Sperrzeit ---
    $zielwert        = $beschatten ? 75 : 0;
    $letzteBewegung  = GetValueInteger($letzteBewegungID);
    $seitLetzter     = $jetzt - $letzteBewegung;
    $restSperrzeit   = max(0, $sperrzeitSekunden - $seitLetzter);
    SetValueInteger($sperrRestID, $restSperrzeit);

$aktiveIDs = $beschatten
    ? GetAktiveAktorIDs($aktorAutomatik, true, $protokollID, $debugID)
    : GetAktiveAktorIDs($aktorAutomatikOeffnen, false, $protokollID, $debugID);

    $letzterModus   = GetValueBoolean($letzterModusID);
    $bewegungNoetig = ($beschatten !== $letzterModus);

    if ($bewegungNoetig && $seitLetzter >= $sperrzeitSekunden) {
        foreach ($aktiveIDs as $id) {
            RequestAction($id, $zielwert);
        }

        SetValueInteger($letzteBewegungID, $jetzt);
        SetValueBoolean($letzterModusID, $beschatten);
        ProtokollEintrag("✅ Ziel {$zielwert}% – {$grund}", $protokollID, $debugID);

    } else {
        if ($restSperrzeit > 0 && $bewegungNoetig)
            ProtokollEintrag("⏳ Sperrzeit aktiv: {$restSperrzeit}s übrig", $protokollID, $debugID);
        if (GetValueBoolean($debugID))
            ProtokollEintrag("[DEBUG] keine Bewegung nötig (Modus unverändert)", $protokollID, $debugID, true);
    }




// Dann der HTML‐Block
// Status für Anzeige
$statusIcon = $beschatten ? '🪟🔒' : '🪟🔓';
$statusText = $beschatten ? 'Beschattet' : 'Offen';

// Sonnenstand (Azimut)
// Azimut-Prozent-Berechnung
$azimutVonRounded = round($azimutVon);
$azimutBisRounded = round($azimutBis);
$azimutRounded    = round($azimut);

$azFrom = ($azimutVon / 360) * 100;
$azTo   = ($azimutBis / 360) * 100;
$azPos  = ($azimut / 360) * 100;

// $azW muss immer gesetzt sein!
if ($azimutVon > $azimutBis) {
    $azW = ((360 - $azimutVon + $azimutBis) / 360) * 100;
    $azimutRangeHTML = "<div class='range' style='left:{$azFrom}%; width:" . (100-$azFrom) . "%;'></div>";
    $azimutRangeHTML .= "<div class='range' style='left:0%; width:{$azTo}%;'></div>";
} else {
    $azW = (($azimutBis - $azimutVon) / 360) * 100;
    $azimutRangeHTML = "<div class='range' style='left:{$azFrom}%; width:{$azW}%;'></div>";
}




// Elevation
$elevRounded     = round($elevation, 1);
$elevKritRounded = round($kritischerWinkel, 1);
$elevEndRounded  = round($endwinkel, 1);
$elevPercent     = min(100, $elevation / 90 * 100); // 90° = max vertikaler Winkel
$elevKritPercent = min(100, $kritischerWinkel / 90 * 100);
$elevEndPercent  = min(100, $endwinkel / 90 * 100);

// Temperatur
$tempRounded        = round($tempAussen,1);
$tempHystAusRounded = round($tempHystAus,1);
$tempHystEinRounded = round($tempHystEin,1);
$tempPercent        = min(100, $tempAussen/40 * 100);
$tempRangeFrom      = $tempHystAus/40 * 100;
$tempRangeW         = ($tempHystEin - $tempHystAus)/40 * 100;

// Helligkeit
$luxRounded        = number_format($helligkeit,0,',','.');
$luxHystAusRounded = number_format($luxHystAus,0,',','.');
$luxHystEinRounded = number_format($luxHystEin,0,',','.');
$luxPercent        = min(100, $helligkeit/30000 * 100);
$luxRangeFrom      = $luxHystAus/30000 * 100;
$luxRangeW         = ($luxHystEin - $luxHystAus)/30000 * 100;

// Zeit
$currentTime = date('H:i');
$fromTime    = sprintf('%02d:%02d', floor($fruehestensSec/3600), ($fruehestensSec%3600)/60);
$toTime      = sprintf('%02d:%02d', floor($spaetestensSec/3600), ($spaetestensSec%3600)/60);
$timePercent = $heuteSec/(24*3600) * 100;
$timeFrom    = $fruehestensSec/(24*3600) * 100;
$timeRangeW  = ($spaetestensSec-$fruehestensSec)/(24*3600) * 100;


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
  <!-- Grüner Bereich, ggf. gesplittet -->
  {$azimutRangeHTML}
  <div class="marker" style="left:{$azPos}%"></div>
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
}

// Hilfsfunktion für Aktoren
function GetAktiveAktorIDs(array $aktorMap, bool $automatik, $protokollID, $debugID): array
{
    $aktive = [];
    foreach ($aktorMap as $aktorID => $boolVarID) {
        if ($boolVarID === 0) {
            $aktive[] = $aktorID;
        } elseif (IPS_VariableExists($boolVarID) && !GetValueBoolean($boolVarID)) {
            $aktive[] = $aktorID;
        } else {
            ProtokollEintrag("🚫 Automatik deaktiviert für Aktor {$aktorID} (Variable ist TRUE)", $protokollID, $debugID);
        }
    }
    return $aktive;
}

// Protokoll-Funktion
function ProtokollEintrag(string $text, $protokollID, $debugID, bool $debugOnly = false): void
{
    if ($debugOnly && !GetValueBoolean($debugID)) return;
    $ts    = date("d.m.Y H:i:s");
    $zeile = "<b>{$ts}</b> – " . $text . "<br>";
    $alt   = GetValueString($protokollID);
    $lines = explode("<br>", $alt);
    array_unshift($lines, $zeile);
    $lines = array_slice($lines, 0, 60);
    SetValueString($protokollID, implode("<br>", $lines));
}
