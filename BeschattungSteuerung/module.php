<?php

declare(strict_types=1);

/**
 * Beschattung Steuerung (Splitter / zentrale Instanz).
 *
 * Hält die zentralen, zur Laufzeit über die Visualisierung änderbaren Werte
 * (Zeitfenster, Schwellwerte, Sperrzeit, globale Automatik) als beschreibbare
 * Statusvariablen, damit auch andere Systeme darauf zugreifen können. Berechnet
 * zyklisch die Wolkenerkennung (Alternativmodus + Sonnenscheinanteil) und stellt
 * alle zentralen Daten den untergeordneten Fassaden-Instanzen über
 * BSTRG_GetCentralData($id) als JSON bereit.
 */
class BeschattungSteuerung extends IPSModuleStrict
{
    // Sinnvolle Startwerte für die zentralen (editierbaren) Variablen.
    private const DEFAULT_EARLIEST_HOUR = 8;    // 08:00
    private const DEFAULT_EARLIEST_MIN = 0;
    private const DEFAULT_LATEST_HOUR = 20;   // 20:45
    private const DEFAULT_LATEST_MIN = 45;
    private const DEFAULT_LOCKTIME = 900;  // 15 min
    private const DEFAULT_BRIGHT_ON = 21000;
    private const DEFAULT_BRIGHT_OFF = 19000;
    private const DEFAULT_TEMP_ON = 22.5;
    private const DEFAULT_TEMP_OFF = 21.5;
    private const DEFAULT_TEMP_ALLROUND = 30.0;
    private const DEFAULT_INDOOR_MIN = 22.0;
    private const DEFAULT_INDOOR_MAX = 26.0;

    private const ROOF_GABLE = 0; // Sattel
    private const ROOF_HIP = 1;   // Walm
    private const ROOF_SHED = 2;  // Pult

    public function Create(): void
    {
        parent::Create();

        // --- TileVisu-Kachel ---
        $this->SetVisualizationType(1);

        // --- Wolkenerkennung (Setup-Konfiguration) ---
        $this->RegisterPropertyInteger('CloudBrightnessID', 0); // zentraler Helligkeitssensor (Lux)
        $this->RegisterPropertyInteger('CloudSunnyThreshold', 20000);
        $this->RegisterPropertyInteger('CloudChangeTolerance', 3000);
        $this->RegisterPropertyInteger('CloudWindowMinutes', 60);
        $this->RegisterPropertyInteger('CloudChangeLimitOn', 8);
        $this->RegisterPropertyInteger('CloudChangeLimitOff', 4);
        $this->RegisterPropertyInteger('CloudInterval', 60); // Sekunden
        $this->RegisterPropertyInteger('SensorMaxAge', 3600); // s, Staleness-Grenze
        $this->RegisterPropertyString('SensorUnitLabel', 'lx'); // rein kosmetisch, z.B. "lx" oder "W/m²"

        // --- Tagesende über Sonnenuntergang (optional) ---
        $this->RegisterPropertyBoolean('UseSunsetAsLatest', false);
        $this->RegisterPropertyInteger('SunsetID', 0); // Variable des Standortmoduls (Timestamp)

        // --- Hausform (Draufsicht, für den Kompass in den Fassaden-Kacheln) ---
        $this->RegisterPropertyFloat('HouseLength', 10.0);  // m, entlang der Firstrichtung
        $this->RegisterPropertyFloat('HouseWidth', 8.0);    // m, rechtwinklig zur Firstrichtung
        $this->RegisterPropertyInteger('HouseRotation', 0); // Kompassrichtung der Firstachse, 0-360°
        $this->RegisterPropertyInteger('RoofShape', self::ROOF_GABLE);
        $this->RegisterPropertyBoolean('RoofHighSideFlip', false); // nur Pultdach: hohe Seite auf der anderen Traufe

        // --- Anzeige ---
        $this->RegisterPropertyBoolean('EnableHTML', false); // TileVisu-Kachel deckt das jetzt ab
        $this->RegisterPropertyBoolean('EnableProtocol', true);

        // --- interne Persistenz ---
        $this->RegisterAttributeString('CloudHistory', '[]');

        // --- Timer ---
        $this->RegisterTimer('CloudTimer', 0, 'BSTRG_ComputeCloudDetection($_IPS[\'TARGET\']);');

        // Auf Kernelstart reagieren (Best Practice: nicht auf andere Instanzen vor KR_READY zugreifen)
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy(): void
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->DeleteProfile('BSTRG.Lux');
            $this->DeleteProfile('BSTRG.Percent');
            $this->DeleteProfile('BSTRG.Seconds');
        }
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        // --- Zentrale, editierbare Werte als Statusvariablen (mit Aktion) ---
        $pos = 10;
        if ($this->RegisterVariableInteger('Earliest', $this->Translate('Earliest'), '~UnixTimestampTime', $pos++)) {
            $this->SetValue('Earliest', $this->todaySeconds(self::DEFAULT_EARLIEST_HOUR, self::DEFAULT_EARLIEST_MIN));
        }
        if ($this->RegisterVariableInteger('Latest', $this->Translate('Latest'), '~UnixTimestampTime', $pos++)) {
            $this->SetValue('Latest', $this->todaySeconds(self::DEFAULT_LATEST_HOUR, self::DEFAULT_LATEST_MIN));
        }
        if ($this->RegisterVariableInteger('LockTime', $this->Translate('Lock time'), 'BSTRG.Seconds', $pos++)) {
            $this->SetValue('LockTime', self::DEFAULT_LOCKTIME);
        }
        if ($this->RegisterVariableInteger('BrightnessOn', $this->Translate('Brightness threshold (on)'), 'BSTRG.Lux', $pos++)) {
            $this->SetValue('BrightnessOn', self::DEFAULT_BRIGHT_ON);
        }
        if ($this->RegisterVariableInteger('BrightnessOff', $this->Translate('Brightness threshold (off)'), 'BSTRG.Lux', $pos++)) {
            $this->SetValue('BrightnessOff', self::DEFAULT_BRIGHT_OFF);
        }
        if ($this->RegisterVariableFloat('TempOn', $this->Translate('Outdoor temperature threshold (on)'), '~Temperature', $pos++)) {
            $this->SetValue('TempOn', self::DEFAULT_TEMP_ON);
        }
        if ($this->RegisterVariableFloat('TempOff', $this->Translate('Outdoor temperature threshold (off)'), '~Temperature', $pos++)) {
            $this->SetValue('TempOff', self::DEFAULT_TEMP_OFF);
        }
        if ($this->RegisterVariableFloat('TempAllAround', $this->Translate('All-around shading temperature'), '~Temperature', $pos++)) {
            $this->SetValue('TempAllAround', self::DEFAULT_TEMP_ALLROUND);
        }
        if ($this->RegisterVariableFloat('IndoorTempMin', $this->Translate('Indoor temperature minimum'), '~Temperature', $pos++)) {
            $this->SetValue('IndoorTempMin', self::DEFAULT_INDOOR_MIN);
        }
        if ($this->RegisterVariableFloat('IndoorTempMax', $this->Translate('Indoor temperature maximum'), '~Temperature', $pos++)) {
            $this->SetValue('IndoorTempMax', self::DEFAULT_INDOOR_MAX);
        }
        if ($this->RegisterVariableBoolean('AutomationGlobal', $this->Translate('Automation (global)'), '~Switch', $pos++)) {
            $this->SetValue('AutomationGlobal', true);
        }
        // editierbare Werte schaltbar machen
        foreach (['Earliest', 'Latest', 'LockTime', 'BrightnessOn', 'BrightnessOff', 'TempOn', 'TempOff',
            'TempAllAround', 'IndoorTempMin', 'IndoorTempMax', 'AutomationGlobal'] as $ident) {
            $this->MaintainAction($ident, true);
        }

        // --- Ausgabewerte der Wolkenerkennung (nur lesend) ---
        $this->RegisterVariableBoolean('CloudMode', $this->Translate('Alternative mode active'), '~Switch', $pos++);
        $this->RegisterVariableFloat('SunPercentage', $this->Translate('Sunshine percentage'), 'BSTRG.Percent', $pos++);
        $this->RegisterVariableInteger('BrightnessChanges', $this->Translate('Brightness changes'), '', $pos++);

        $this->MaintainVariable('StatusHTML', $this->Translate('Status display'), VARIABLETYPE_STRING, '~HTMLBox', $pos++, $this->ReadPropertyBoolean('EnableHTML'));
        $this->MaintainVariable('Protocol', $this->Translate('Protocol'), VARIABLETYPE_STRING, '~HTMLBox', $pos++, $this->ReadPropertyBoolean('EnableProtocol'));

        // Referenz auf den (optional gewählten) Sonnenuntergangs-/Helligkeitssensor sauber halten
        $this->MaintainReferences();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // --- Timer für die Wolkenerkennung ---
        $interval = max(0, $this->ReadPropertyInteger('CloudInterval'));
        $this->SetTimerInterval('CloudTimer', $interval * 1000);

        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'Earliest':
            case 'Latest':
            case 'LockTime':
            case 'BrightnessOn':
            case 'BrightnessOff':
                $this->SetValue($ident, (int) $value);
                break;
            case 'TempOn':
            case 'TempOff':
            case 'TempAllAround':
            case 'IndoorTempMin':
            case 'IndoorTempMax':
                $this->SetValue($ident, (float) $value);
                break;
            case 'AutomationGlobal':
                $this->SetValue('AutomationGlobal', (bool) $value);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $ident);
        }
        $this->pushTile();
    }

    /**
     * Liefert alle zentralen Werte als JSON. Wird von den Fassaden-Instanzen
     * über die Datenflusskette (BSTRG_GetCentralData($parentID)) aufgerufen.
     */
    public function GetCentralData(): string
    {
        return json_encode([
            'earliestSec'      => $this->secondsOfDay($this->GetValue('Earliest')),
            'latestSec'        => $this->effectiveLatestSec(),
            'lockTime'         => (int) $this->GetValue('LockTime'),
            'brightnessOn'     => (int) $this->GetValue('BrightnessOn'),
            'brightnessOff'    => (int) $this->GetValue('BrightnessOff'),
            'tempOn'           => (float) $this->GetValue('TempOn'),
            'tempOff'          => (float) $this->GetValue('TempOff'),
            'tempAllAround'    => (float) $this->GetValue('TempAllAround'),
            'indoorMin'        => (float) $this->GetValue('IndoorTempMin'),
            'indoorMax'        => (float) $this->GetValue('IndoorTempMax'),
            'automationGlobal' => (bool) $this->GetValue('AutomationGlobal'),
            'cloudMode'        => (bool) $this->GetValue('CloudMode'),
            'sunPercentage'    => (float) $this->GetValue('SunPercentage'),
            'houseLength'      => $this->ReadPropertyFloat('HouseLength'),
            'houseWidth'       => $this->ReadPropertyFloat('HouseWidth'),
            'houseRotation'    => $this->ReadPropertyInteger('HouseRotation'),
            'roofShape'        => $this->ReadPropertyInteger('RoofShape'),
            'roofHighSideFlip' => $this->ReadPropertyBoolean('RoofHighSideFlip'),
        ]);
    }

    /**
     * Wolkenerkennung: führt eine Helligkeits-Historie und ermittelt, ob die
     * Lichtverhältnisse wechselhaft sind (Alternativmodus) sowie den
     * prozentualen Sonnenscheinanteil.
     */
    public function ComputeCloudDetection(): void
    {
        $sensorID = $this->ReadPropertyInteger('CloudBrightnessID');
        if (!$this->variableValid($sensorID)) {
            $this->SendDebug(__FUNCTION__, 'Helligkeitssensor nicht konfiguriert/ungültig', 0);
            return;
        }
        if (!$this->variableFresh($sensorID)) {
            $this->SendDebug(__FUNCTION__, 'Helligkeitssensor liefert veraltete Werte – übersprungen', 0);
            return;
        }

        $value = (float) GetValue($sensorID);
        $now = time();

        $history = json_decode($this->ReadAttributeString('CloudHistory'), true);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = ['t' => $now, 'v' => $value];

        $windowMin = max(1, $this->ReadPropertyInteger('CloudWindowMinutes'));
        $limit = $now - ($windowMin * 60);
        $history = array_values(array_filter($history, static fn ($e) => isset($e['t']) && $e['t'] >= $limit));

        $sunnyThreshold = $this->ReadPropertyInteger('CloudSunnyThreshold');
        $tolerance = $this->ReadPropertyInteger('CloudChangeTolerance');

        $changes = 0;
        $sunny = 0;
        $last = null;
        foreach ($history as $entry) {
            if ($entry['v'] > $sunnyThreshold) {
                $sunny++;
            }
            if ($last !== null && abs($entry['v'] - $last) > $tolerance) {
                $changes++;
            }
            $last = $entry['v'];
        }
        $total = count($history);
        $percentage = $total > 0 ? round(($sunny / $total) * 100, 1) : 0.0;

        $cloudMode = (bool) $this->GetValue('CloudMode');
        if (!$cloudMode && $changes >= $this->ReadPropertyInteger('CloudChangeLimitOn')) {
            $cloudMode = true;
            $this->LogMessage($this->Translate('Alternative mode activated (fluctuating light).'), KL_MESSAGE);
            $this->log(sprintf(
                '⛅ Alternativmodus aktiviert: %d Helligkeitswechsel in den letzten %d Minuten (Schwelle: ≥ %d) – Sonnenanteil aktuell %s %%',
                $changes,
                $windowMin,
                $this->ReadPropertyInteger('CloudChangeLimitOn'),
                $this->fmtPct($percentage)
            ));
        } elseif ($cloudMode && $changes < $this->ReadPropertyInteger('CloudChangeLimitOff')) {
            $cloudMode = false;
            $this->LogMessage($this->Translate('Alternative mode ended (light stabilised).'), KL_MESSAGE);
            $this->log(sprintf(
                '☀️ Alternativmodus beendet: nur noch %d Helligkeitswechsel in den letzten %d Minuten (Schwelle: < %d) – Licht wieder stabil',
                $changes,
                $windowMin,
                $this->ReadPropertyInteger('CloudChangeLimitOff')
            ));
        }

        $this->WriteAttributeString('CloudHistory', json_encode($history));
        $this->SetValue('CloudMode', $cloudMode);
        $this->SetValue('SunPercentage', $percentage);
        $this->SetValue('BrightnessChanges', $changes);
        $this->updateHtml($changes, $percentage, $cloudMode, $total, $windowMin);
        $this->pushTile();

        $this->SendDebug(__FUNCTION__, sprintf(
            'Wechsel=%d, Sonnenanteil=%.1f%%, Alternativmodus=%s',
            $changes,
            $percentage,
            $cloudMode ? 'an' : 'aus'
        ), 0);
    }

    /** Setzt alle zentralen Werte auf die Standardvorgaben zurück (Button). */
    public function ResetDefaults(): void
    {
        $this->SetValue('Earliest', $this->todaySeconds(self::DEFAULT_EARLIEST_HOUR, self::DEFAULT_EARLIEST_MIN));
        $this->SetValue('Latest', $this->todaySeconds(self::DEFAULT_LATEST_HOUR, self::DEFAULT_LATEST_MIN));
        $this->SetValue('LockTime', self::DEFAULT_LOCKTIME);
        $this->SetValue('BrightnessOn', self::DEFAULT_BRIGHT_ON);
        $this->SetValue('BrightnessOff', self::DEFAULT_BRIGHT_OFF);
        $this->SetValue('TempOn', self::DEFAULT_TEMP_ON);
        $this->SetValue('TempOff', self::DEFAULT_TEMP_OFF);
        $this->SetValue('TempAllAround', self::DEFAULT_TEMP_ALLROUND);
        $this->SetValue('IndoorTempMin', self::DEFAULT_INDOOR_MIN);
        $this->SetValue('IndoorTempMax', self::DEFAULT_INDOOR_MAX);
        $this->EchoMessage($this->Translate('Central values reset to defaults.'));
        $this->pushTile();
    }

    // ------------------------------------------------------------------
    // TileVisu-Kachel
    // ------------------------------------------------------------------

    /** Baut die HTML-Kachel (statisches Markup + Startdaten) für die TileVisu. */
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/module.html');
        $config = [
            'labels' => [
                'automation'           => $this->Translate('Automation (global)'),
                'sunPercentage'        => $this->Translate('Sunshine percentage'),
                'alternativeModeOn'    => $this->Translate('Alternative mode active'),
                'alternativeModeOff'   => $this->Translate('Alternative mode inactive'),
                'changes'              => $this->Translate('Brightness changes'),
                'sensor'               => $this->Translate('Sensor'),
                'sunnyThreshold'       => $this->Translate('Sunny threshold'),
                'timeWindow'           => $this->Translate('Time window'),
                'lockTime'             => $this->Translate('Lock time'),
                'outdoorThresholds'    => $this->Translate('Outdoor thresholds'),
                'allAroundFrom'        => $this->Translate('All-around from'),
                'indoorRange'          => $this->Translate('Indoor range'),
                'brightnessThresholds' => $this->Translate('Brightness thresholds'),
            ],
        ];
        $data = json_encode($this->getTileData());
        return $html . '<script>var _config = ' . json_encode($config) . '; if (window.handleMessage) { handleMessage(' . $data . '); }</script>';
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /** Effektives Tagesende (Sekunden seit Mitternacht) – ggf. aus Sonnenuntergang. */
    private function effectiveLatestSec(): int
    {
        if ($this->ReadPropertyBoolean('UseSunsetAsLatest')) {
            $sunsetID = $this->ReadPropertyInteger('SunsetID');
            if ($this->variableValid($sunsetID)) {
                $ts = (int) GetValue($sunsetID);
                if ($ts > 0) {
                    return ((int) date('H', $ts) * 3600) + ((int) date('i', $ts) * 60);
                }
            }
        }
        return $this->secondsOfDay($this->GetValue('Latest'));
    }

    /** Wandelt einen (Tageszeit-)Timestamp in Sekunden seit lokaler Mitternacht. */
    private function secondsOfDay(mixed $timestamp): int
    {
        $ts = (int) $timestamp;
        return ((int) date('H', $ts) * 3600) + ((int) date('i', $ts) * 60);
    }

    /** Timestamp für heute HH:MM (lokale Zeit). */
    private function todaySeconds(int $hour, int $minute): int
    {
        return mktime($hour, $minute, 0, (int) date('n'), (int) date('j'), (int) date('Y')) ?: 0;
    }

    private function variableValid(int $id): bool
    {
        return $id > 0 && @IPS_VariableExists($id);
    }

    private function variableFresh(int $id): bool
    {
        $maxAge = $this->ReadPropertyInteger('SensorMaxAge');
        if ($maxAge <= 0) {
            return true;
        }
        $var = @IPS_GetVariable($id);
        if (!is_array($var)) {
            return false;
        }
        return (time() - (int) $var['VariableUpdated']) <= $maxAge;
    }

    private function MaintainReferences(): void
    {
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        foreach (['CloudBrightnessID', 'SunsetID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                $this->RegisterReference($id);
            }
        }
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('BSTRG.Lux')) {
            IPS_CreateVariableProfile('BSTRG.Lux', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BSTRG.Lux', '', ' lx');
            IPS_SetVariableProfileValues('BSTRG.Lux', 0, 150000, 1000);
            IPS_SetVariableProfileIcon('BSTRG.Lux', 'Sun');
        }
        if (!IPS_VariableProfileExists('BSTRG.Percent')) {
            IPS_CreateVariableProfile('BSTRG.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('BSTRG.Percent', '', ' %');
            IPS_SetVariableProfileValues('BSTRG.Percent', 0, 100, 1);
            IPS_SetVariableProfileDigits('BSTRG.Percent', 1);
        }
        if (!IPS_VariableProfileExists('BSTRG.Seconds')) {
            IPS_CreateVariableProfile('BSTRG.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BSTRG.Seconds', '', ' s');
            IPS_SetVariableProfileValues('BSTRG.Seconds', 0, 7200, 30);
            IPS_SetVariableProfileIcon('BSTRG.Seconds', 'Clock');
        }
    }

    private function DeleteProfile(string $name): void
    {
        if (IPS_VariableProfileExists($name)) {
            @IPS_DeleteVariableProfile($name);
        }
    }

    private function EchoMessage(string $text): void
    {
        echo $text;
    }

    // ------------------------------------------------------------------
    // Anzeige / Protokoll
    // ------------------------------------------------------------------

    private function log(string $text, bool $debugOnly = false): void
    {
        $this->SendDebug('ComputeCloudDetection', $text, 0);
        if ($debugOnly || !$this->ReadPropertyBoolean('EnableProtocol')) {
            return;
        }
        if (!@IPS_VariableExists(@$this->GetIDForIdent('Protocol'))) {
            return;
        }
        $line = '<b>' . date('d.m.Y H:i:s') . '</b> – ' . $text . '<br>';
        $lines = explode('<br>', (string) $this->GetValue('Protocol'));
        array_unshift($lines, $line);
        $lines = array_slice($lines, 0, 60);
        $this->SetValue('Protocol', implode('<br>', $lines));
    }

    private function updateHtml(int $changes, float $percentage, bool $cloudMode, int $total, int $windowMin): void
    {
        if (!$this->ReadPropertyBoolean('EnableHTML') || !@IPS_VariableExists(@$this->GetIDForIdent('StatusHTML'))) {
            return;
        }
        $limitOn = $this->ReadPropertyInteger('CloudChangeLimitOn');
        $limitOff = $this->ReadPropertyInteger('CloudChangeLimitOff');
        $icon = $cloudMode ? '⛅' : '☀️';
        $modeText = $cloudMode ? $this->Translate('Alternative mode active') : $this->Translate('Alternative mode inactive');
        $now = date('H:i');
        $html = <<<HTML
<style>.bsbox{font-family:sans-serif;font-size:13px}.bsstatus{font-size:16px;margin-bottom:6px}.bsrow{margin-top:4px}</style>
<div class="bsbox">
  <div class="bsstatus">{$icon} {$modeText}</div>
  <div class="bsrow">☀️ Sonnenscheinanteil: {$this->fmtPct($percentage)} % (Alternativmodus-Beschattung ab &gt; 50 %)</div>
  <div class="bsrow">🔀 Helligkeitswechsel: {$changes} von {$total} Messwerten · letzte {$windowMin} Min.</div>
  <div class="bsrow">↳ Ein ab ≥ {$limitOn} Wechseln · Aus unter {$limitOff} Wechseln</div>
  <div class="bsrow">⏰ Letzte Berechnung: {$now}</div>
</div>
HTML;
        $this->SetValue('StatusHTML', $html);
    }

    private function fmtPct(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    /** Sammelt alle für die Kachel relevanten Werte als JSON-fähiges Array. */
    private function getTileData(): array
    {
        $sensorID = $this->ReadPropertyInteger('CloudBrightnessID');
        $sensorValue = $this->variableValid($sensorID) ? (float) GetValue($sensorID) : null;

        $history = json_decode($this->ReadAttributeString('CloudHistory'), true);
        if (!is_array($history)) {
            $history = [];
        }

        return [
            'name'             => IPS_GetName($this->InstanceID),
            'automationGlobal' => (bool) $this->GetValue('AutomationGlobal'),
            'cloudMode'        => (bool) $this->GetValue('CloudMode'),
            'sunPercentage'    => (float) $this->GetValue('SunPercentage'),
            'changes'          => (int) $this->GetValue('BrightnessChanges'),
            'limitOn'          => $this->ReadPropertyInteger('CloudChangeLimitOn'),
            'limitOff'         => $this->ReadPropertyInteger('CloudChangeLimitOff'),
            'windowMinutes'    => $this->ReadPropertyInteger('CloudWindowMinutes'),
            'sensorValue'      => $sensorValue,
            'sunnyThreshold'   => $this->ReadPropertyInteger('CloudSunnyThreshold'),
            'sensorUnit'       => $this->ReadPropertyString('SensorUnitLabel'),
            'history'          => $history,
            'earliestSec'      => $this->secondsOfDay($this->GetValue('Earliest')),
            'latestSec'        => $this->effectiveLatestSec(),
            'lockTime'         => (int) $this->GetValue('LockTime'),
            'brightnessOn'     => (int) $this->GetValue('BrightnessOn'),
            'brightnessOff'    => (int) $this->GetValue('BrightnessOff'),
            'tempOn'           => (float) $this->GetValue('TempOn'),
            'tempOff'          => (float) $this->GetValue('TempOff'),
            'tempAllAround'    => (float) $this->GetValue('TempAllAround'),
            'indoorMin'        => (float) $this->GetValue('IndoorTempMin'),
            'indoorMax'        => (float) $this->GetValue('IndoorTempMax'),
        ];
    }

    /** Pusht die aktuellen Kachel-Daten an eine ggf. geöffnete TileVisu. */
    private function pushTile(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->UpdateVisualizationValue(json_encode($this->getTileData()));
    }
}
