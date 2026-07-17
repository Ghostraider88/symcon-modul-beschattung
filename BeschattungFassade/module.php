<?php

declare(strict_types=1);

/**
 * Beschattung Fassade (Gerät).
 *
 * Eine Instanz pro Fassade / Fenstergruppe. Bewertet zyklisch (und bei
 * Sensoränderungen) Sonnenstand, Helligkeit und Temperatur und fährt die
 * konfigurierten Aktoren in Beschattungs- oder Offen-Position. Die zentralen
 * Werte (Zeitfenster, Schwellwerte, Sperrzeit, Wolkenerkennung) bezieht sie von
 * der übergeordneten "Beschattung Steuerung"-Instanz.
 *
 * Robustheit: alle Sensoren werden auf Existenz, Plausibilität und Alter
 * geprüft. Bei Ausfall kritischer Sensoren wird das konfigurierte Fail-Safe-
 * Verhalten angewendet (Standard: Position halten).
 */
class BeschattungFassade extends IPSModuleStrict
{
    private const EVENING_OPEN = 0;
    private const EVENING_HOLD = 1;

    private const FAILSAFE_HOLD = 0;
    private const FAILSAFE_OPEN = 1;
    private const FAILSAFE_SHADE = 2;

    private const LATEST_MODE_FIXED = 0;
    private const LATEST_MODE_SUNSET = 1;

    private const STEUERUNG_GUID = '{6D18227C-1720-4002-BDB0-071DB6B0C384}';

    public function Create(): void
    {
        parent::Create();

        // --- TileVisu-Kachel ---
        $this->SetVisualizationType(1);

        // --- Zentrale Steuerungs-Instanz ---
        $this->RegisterPropertyInteger('CentralID', 0);

        // --- Sonnenstand (Standortmodul) ---
        $this->RegisterPropertyInteger('AzimuthID', 0);
        $this->RegisterPropertyInteger('ElevationID', 0);

        // --- Sensoren (optional; leer = Bedingung wird ignoriert) ---
        $this->RegisterPropertyInteger('BrightnessID', 0);
        $this->RegisterPropertyBoolean('UseCentralBrightnessFallback', false); // bei fehlendem eigenem Sensor auf zentralen Wolkensensor zurückfallen
        $this->RegisterPropertyBoolean('CloudModeOwnSensorOverride', false); // im Alternativmodus zusätzlich per eigenem Sensor beschatten dürfen
        $this->RegisterPropertyInteger('OutdoorTempID', 0);
        $this->RegisterPropertyInteger('IndoorTempID', 0);

        // --- Ausrichtung ---
        $this->RegisterPropertyInteger('FacadeDirection', 180);
        $this->RegisterPropertyInteger('ShadeAngleLeft', 90);
        $this->RegisterPropertyInteger('ShadeAngleRight', 90);

        // --- Geometrie (Meter / Grad) ---
        $this->RegisterPropertyFloat('RoofHeight', 0.0);
        $this->RegisterPropertyFloat('RoofOverhang', 0.0);
        $this->RegisterPropertyFloat('WindowSill', 0.0);
        $this->RegisterPropertyInteger('EndAngle', 0);

        // --- Aktoren (Liste) ---
        $this->RegisterPropertyString('Actuators', '[]');
        $this->RegisterPropertyInteger('ShadePosition', 70); // % (0 = offen, 100 = geschlossen)
        $this->RegisterPropertyInteger('OpenPosition', 0);

        // --- Überschreibung zentraler Werte (optional, je Fassade) ---
        $this->RegisterPropertyBoolean('OverrideEarliest', false);
        $this->RegisterPropertyString('OverrideEarliestTime', '08:00');
        $this->RegisterPropertyBoolean('OverrideLatest', false);
        $this->RegisterPropertyInteger('OverrideLatestMode', self::LATEST_MODE_FIXED);
        $this->RegisterPropertyString('OverrideLatestTime', '20:45');
        $this->RegisterPropertyInteger('OverrideLatestSunsetID', 0);
        $this->RegisterPropertyBoolean('OverrideLockTime', false);
        $this->RegisterPropertyInteger('OverrideLockTimeValue', 900);
        $this->RegisterPropertyBoolean('OverrideBrightness', false);
        $this->RegisterPropertyInteger('OverrideBrightnessOn', 21000);
        $this->RegisterPropertyInteger('OverrideBrightnessOff', 19000);
        $this->RegisterPropertyBoolean('OverrideTemp', false);
        $this->RegisterPropertyFloat('OverrideTempOn', 22.5);
        $this->RegisterPropertyFloat('OverrideTempOff', 21.5);
        $this->RegisterPropertyBoolean('OverrideTempAllAround', false);
        $this->RegisterPropertyFloat('OverrideTempAllAroundValue', 30.0);
        $this->RegisterPropertyBoolean('OverrideIndoor', false);
        $this->RegisterPropertyFloat('OverrideIndoorMin', 22.0);
        $this->RegisterPropertyFloat('OverrideIndoorMax', 26.0);

        // --- Verhalten ---
        $this->RegisterPropertyInteger('EveningBehavior', self::EVENING_OPEN);
        $this->RegisterPropertyInteger('EvaluationInterval', 300); // s
        $this->RegisterPropertyBoolean('TempHystDailyReset', false);
        $this->RegisterPropertyInteger('MeanTempDays', 0);

        // --- Handbetrieb-Erkennung ---
        $this->RegisterPropertyBoolean('ManualDetection', false);
        $this->RegisterPropertyInteger('ManualTolerance', 5);    // %
        $this->RegisterPropertyInteger('ManualPause', 3600);     // s

        // --- Entscheidungs-Bestätigung (Decision-Debounce gegen Wolken-Pingpong) ---
        $this->RegisterPropertyInteger('DecisionConfirmMinutes', 10); // min, 0 = aus

        // --- Fail-Safe / Zuverlässigkeit ---
        $this->RegisterPropertyInteger('FailSafe', self::FAILSAFE_HOLD);
        $this->RegisterPropertyInteger('SensorMaxAge', 3600);    // s, 0 = aus

        // --- Anzeige ---
        $this->RegisterPropertyBoolean('EnableHTML', false); // TileVisu-Kachel deckt das jetzt ab
        $this->RegisterPropertyBoolean('EnableProtocol', true);

        // --- Reserviert: Wind/Regen-Hooks (noch keine aktive Logik) ---
        $this->RegisterPropertyInteger('WindID', 0);
        $this->RegisterPropertyInteger('RainID', 0);

        // --- interne Persistenz ---
        $this->RegisterAttributeInteger('LastMovementTs', 0);
        $this->RegisterAttributeBoolean('BrightHyst', false);
        $this->RegisterAttributeBoolean('TempHyst', false);
        $this->RegisterAttributeString('TempHystResetDate', ''); // Tag des letzten täglichen Hysterese-Resets
        $this->RegisterAttributeInteger('ManualUntil', 0);
        $this->RegisterAttributeInteger('LastCommandedPos', -1);
        $this->RegisterAttributeString('TempMaxHistory', '{}');
        $this->RegisterAttributeString('LastModeMap', '{}');  // je Aktor zuletzt befohlener Zustand (fehlt = unbekannt)
        $this->RegisterAttributeInteger('BlockedLast', -1);   // Dedup für Sperr-Protokoll
        $this->RegisterAttributeBoolean('LastRawDecision', false); // rohe Entscheidung des Vorzyklus
        $this->RegisterAttributeInteger('DecisionStableSince', 0); // seit wann unverändert (0 = ungesetzt)
        $this->RegisterAttributeBoolean('ConfirmedShade', false);  // zuletzt an commandPosition() übergebener Sollzustand
        $this->RegisterAttributeString('LastReason', '');          // Entscheidungsgrund für die TileVisu-Kachel
        $this->RegisterAttributeString('LastDecisionPath', 'special'); // welcher Zweig entschieden hat (für die Kachel)
        $this->RegisterAttributeFloat('LastBrightnessValue', -1.0);   // für Sättigungs-Erkennung
        $this->RegisterAttributeInteger('BrightnessStuckSince', 0);   // seit wann unverändert (0 = ungesetzt)
        $this->RegisterAttributeBoolean('BrightnessSaturated', false); // Diagnose-Flag für die Kachel
        $this->RegisterAttributeString('SunTrack', '[]');           // Azimut/Elevation-Verlauf für die Kompass-Grafik
        $this->RegisterAttributeString('DailyStatsDate', '');       // Tag, für den die Statistik unten zählt
        $this->RegisterAttributeInteger('DailyMoveCount', 0);       // Fahrten heute
        $this->RegisterAttributeInteger('DailyShadedSeconds', 0);   // abgeschlossene Beschattungsdauer heute
        $this->RegisterAttributeInteger('DailyShadeStart', 0);      // Start der laufenden Beschattung (0 = gerade nicht beschattet)

        // --- Timer ---
        $this->RegisterTimer('EvalTimer', 0, 'BSFAS_Evaluate($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy(): void
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->DeleteProfile('BSFAS.Position');
            $this->DeleteProfile('BSFAS.Seconds');
        }
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        // --- Statusvariablen ---
        $pos = 10;
        if ($this->RegisterVariableBoolean('Automation', $this->Translate('Automation'), '~Switch', $pos++)) {
            $this->SetValue('Automation', true);
        }
        $this->MaintainAction('Automation', true);

        $this->RegisterVariableBoolean('Shaded', $this->Translate('Shaded'), '~Switch', $pos++);
        $this->RegisterVariableInteger('TargetPosition', $this->Translate('Target position'), 'BSFAS.Position', $pos++);
        $this->RegisterVariableBoolean('SunInWindow', $this->Translate('Sun on window'), '~Switch', $pos++);
        $this->RegisterVariableBoolean('BrightnessOK', $this->Translate('Brightness sufficient'), '~Switch', $pos++);
        $this->RegisterVariableBoolean('TemperatureOK', $this->Translate('Temperature condition met'), '~Switch', $pos++);
        $this->RegisterVariableInteger('LockRemaining', $this->Translate('Lock time remaining'), 'BSFAS.Seconds', $pos++);
        $this->RegisterVariableInteger('LastMovement', $this->Translate('Last movement'), '~UnixTimestamp', $pos++);

        $this->MaintainVariable('ManualMode', $this->Translate('Manual override active'), VARIABLETYPE_BOOLEAN, '~Switch', $pos++, $this->ReadPropertyBoolean('ManualDetection'));
        $this->MaintainVariable('StatusHTML', $this->Translate('Status display'), VARIABLETYPE_STRING, '~HTMLBox', $pos++, $this->ReadPropertyBoolean('EnableHTML'));
        $this->MaintainVariable('Protocol', $this->Translate('Protocol'), VARIABLETYPE_STRING, '~HTMLBox', $pos++, $this->ReadPropertyBoolean('EnableProtocol'));

        $this->MaintainSensorReferencesAndMessages();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // --- Timer ---
        $interval = max(0, $this->ReadPropertyInteger('EvaluationInterval'));
        $this->SetTimerInterval('EvalTimer', $interval * 1000);

        // --- Status ---
        if ($this->getCentralID() === 0) {
            $this->SetStatus(104); // keine Steuerung ausgewählt
        } elseif (!$this->variableValid($this->ReadPropertyInteger('AzimuthID'))
            || !$this->variableValid($this->ReadPropertyInteger('ElevationID'))) {
            $this->SetStatus(201); // Sonnenstandsquelle fehlt
        } else {
            $this->SetStatus(102);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            // Sensor- oder zentrale Wolkenvariable hat sich geändert -> neu bewerten
            $this->Evaluate();
        }
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'Automation':
                $this->SetValue('Automation', (bool) $value);
                $this->Evaluate();
                break;
            default:
                throw new Exception('Invalid Ident: ' . $ident);
        }
    }

    // ------------------------------------------------------------------
    // Öffentliche Befehle (Timer / Test-Buttons)
    // ------------------------------------------------------------------

    /** Hauptauswertung. Wird vom Timer, bei Sensoränderung und manuell aufgerufen. */
    public function Evaluate(): void
    {
        $this->evaluateInternal();
        $this->pushTile();
    }

    /** Sperrzeit zurücksetzen (Button / externer Aufruf). */
    public function ResetLock(): void
    {
        $this->WriteAttributeInteger('LastMovementTs', 0);
        $this->SetValue('LockRemaining', 0);
        $this->log('Sperrzeit manuell zurückgesetzt.');
        $this->pushTile();
    }

    /** Testfahrt aus dem Aktionsbereich (umgeht Sperrzeit/Bedingungen). */
    public function TestMove(bool $Shade): void
    {
        foreach ($this->validActuators() as $act) {
            $pos = $Shade ? $this->actuatorShadePosition($act) : $this->ReadPropertyInteger('OpenPosition');
            $this->moveActuator($act, $pos);
        }
        $this->log(sprintf('🔧 Testfahrt → %s', $Shade ? 'beschatten' : 'öffnen'));
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
                'shaded'              => $this->Translate('Shaded'),
                'open'                => $this->Translate('Open'),
                'automation'          => $this->Translate('Automation'),
                'target'              => $this->Translate('Target'),
                'brightness'          => $this->Translate('Brightness'),
                'outdoorTemp'         => $this->Translate('Outdoor temp.'),
                'indoorTemp'          => $this->Translate('Indoor temp.'),
                'sunOnWindow'         => $this->Translate('Sun on window'),
                'timeWindow'          => $this->Translate('Time window'),
                'lockTime'            => $this->Translate('Lock time'),
                'waitingChip'         => $this->Translate('waiting'),
                'lastMovement'        => $this->Translate('Last movement'),
                'alternativeMode'     => $this->Translate('Alternative mode'),
                'sunPercentage'       => $this->Translate('Sun percentage'),
                'manualActive'        => $this->Translate('Manual override active'),
                'confirming'          => $this->Translate('Confirming change'),
                'blockedSuffix'       => $this->Translate('{n} locked'),
                'saturatedTitle'      => $this->Translate('Sensor possibly saturated (unchanged for a long time despite high sun elevation)'),
                'movesToday'          => $this->Translate('moves today'),
                'shadedToday'         => $this->Translate('shaded today'),
                'actuatorsLabel'      => $this->Translate('Actuators'),
                'protocolLabel'       => $this->Translate('Protocol'),
                'fallbackSensorTitle' => $this->Translate('No own sensor - using central fallback sensor'),
            ],
        ];
        $data = json_encode($this->getTileData());
        return $html . '<script>var _config = ' . json_encode($config) . '; if (window.handleMessage) { handleMessage(' . $data . '); }</script>';
    }

    private function evaluateInternal(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $central = $this->getCentralData();
        if ($central === null) {
            $this->SetStatus(104);
            $this->setReason('⚠️ Keine Steuerung verbunden');
            $this->setDecisionPath('special');
            $this->log('⚠️ Keine Steuerungs-Instanz verbunden – keine Bewegung.', true);
            return;
        }

        // --- Automatik global + lokal ---
        if (!$central['automationGlobal'] || !$this->GetValue('Automation')) {
            $this->setConditions(false, false, false);
            $this->SetValue('Shaded', false);
            $this->setReason('🚫 Automatik aus');
            $this->setDecisionPath('special');
            $this->log('🚫 Automatik deaktiviert.');
            $this->updateHtml($central, false, 'Automatik aus');
            $this->SetStatus(104);
            return;
        }

        // --- Handbetrieb-Erkennung ---
        if ($this->handleManualOverride()) {
            $this->setReason('✋ Handbetrieb aktiv');
            $this->setDecisionPath('special');
            $this->SetStatus(102);
            return;
        }

        // --- Zeitfenster ---
        $nowSec = ((int) date('H') * 3600) + ((int) date('i') * 60);
        if ($nowSec < $central['earliestSec']) {
            $this->setConditions(false, false, false);
            $this->SetValue('Shaded', false);
            $this->setReason('⏰ Vor Zeitfenster');
            $this->setDecisionPath('special');
            $this->log('⏰ Außerhalb Zeitfenster (zu früh) – Position bleibt.', true);
            $this->updateHtml($central, false, 'vor Zeitfenster');
            $this->SetStatus(102);
            return;
        }
        if ($nowSec >= $central['latestSec']) {
            if ($this->ReadPropertyInteger('EveningBehavior') === self::EVENING_OPEN) {
                $this->commandPosition(false, '⏰ Tagesende → öffnen', $central);
            } else {
                $this->SetValue('Shaded', false);
                $this->setReason('⏰ Tagesende – Position gehalten');
                $this->log('⏰ Tagesende – Position halten.', true);
            }
            $this->setConditions(false, false, false);
            $this->setDecisionPath('special');
            $this->updateHtml($central, false, 'nach Zeitfenster');
            $this->SetStatus(102);
            return;
        }

        // --- Sonnenstand (kritischer Sensor) ---
        $azID = $this->ReadPropertyInteger('AzimuthID');
        $elID = $this->ReadPropertyInteger('ElevationID');
        if (!$this->sensorUsable($azID) || !$this->sensorUsable($elID)) {
            $this->applyFailSafe('Sonnenstandsquelle ungültig/veraltet', $central);
            $this->SetStatus(201);
            return;
        }
        $azimut = (float) GetValue($azID);
        $elevation = (float) GetValue($elID);
        if ($azimut < 0 || $azimut > 360 || $elevation < -90 || $elevation > 90) {
            $this->applyFailSafe('Sonnenstand außerhalb plausibler Werte', $central);
            $this->SetStatus(201);
            return;
        }

        $sunInWindow = $this->isSunOnWindow($azimut, $elevation);
        $this->updateSunTrack($azimut, $elevation);

        $brightID = $this->ReadPropertyInteger('BrightnessID');
        $this->updateBrightnessSaturation($this->variableValid($brightID) ? (float) GetValue($brightID) : null, $elevation);

        // --- Helligkeit ---
        $brightnessOK = $this->evaluateBrightness($central);
        if ($brightnessOK === null) {
            $this->applyFailSafe('Helligkeitssensor veraltet', $central);
            $this->SetStatus(202);
            return;
        }

        // --- Temperatur ---
        $temp = $this->evaluateTemperature($central);
        if ($temp === null) {
            $this->applyFailSafe('Temperatursensor veraltet', $central);
            $this->SetStatus(202);
            return;
        }
        [$temperatureOK, $allAround] = $temp;

        // --- Entscheidung (MyHomeControl-konform) ---
        // Rundumbeschattung hebt laut Vorbild NUR die Sonnenstand-/Richtungsprüfung
        // auf ("es wird auch beschattet, wenn die Sonne nicht direkt ins Fenster
        // scheint") - die Helligkeitsbedingung bleibt davon unberührt und weiterhin
        // Pflicht. Sonst würde abends bei bedecktem Himmel trotz noch hoher
        // Lufttemperatur unnötig geschlossen, obwohl kein nennenswerter
        // Hitzeeintrag mehr stattfindet. Im Alternativmodus übernimmt dafür der
        // über ein Zeitfenster gemittelte Sonnenscheinanteil die Rolle der
        // Helligkeitsbedingung (optional zusätzlich per eigenem Sensor, siehe
        // `cloudModeOwnSensorOverrideActive()`) - einheitlich für Rundumbeschattung
        // UND die normale Sonnenstand-Entscheidung.
        $cloudModeActive = (bool) $central['cloudMode'];
        $ownSensorBright = $cloudModeActive && $this->cloudModeOwnSensorOverrideActive() && $brightnessOK;
        $sunEnough = $central['sunPercentage'] > 50;
        $brightEnough = $cloudModeActive ? ($sunEnough || $ownSensorBright) : $brightnessOK;

        $shade = false;
        $reason = '';
        if ($allAround) {
            $decisionPath = 'allAround';
            if ($brightEnough) {
                $shade = true;
                $reason = '🔥 Rundumbeschattung (Außentemp.)';
            } else {
                $reason = '🔥☁️ Rundumbeschattung wegen geringer Helligkeit ausgesetzt';
            }
        } elseif ($cloudModeActive) {
            $decisionPath = 'cloudMode';
            if ($temperatureOK) {
                $shade = $sunInWindow && $brightEnough;
                $reason = ($ownSensorBright && !$sunEnough)
                    ? sprintf('Φ Alternativmodus (Sonne %.0f%%, eigener Sensor hell)', $central['sunPercentage'])
                    : sprintf('Φ Alternativmodus (Sonne %.0f%%)', $central['sunPercentage']);
            } else {
                $reason = '⚠️ Schwellwerte nicht erfüllt';
            }
        } elseif ($brightEnough && $temperatureOK) {
            $decisionPath = 'normal';
            $shade = $sunInWindow;
            $reason = '☀️ Sonnenstand';
        } else {
            $decisionPath = 'normal';
            $reason = '⚠️ Schwellwerte nicht erfüllt';
        }

        $this->setConditions($sunInWindow, $brightnessOK, $temperatureOK);
        $this->setReason($reason);
        $this->setDecisionPath($decisionPath);
        $shade = $this->debounceDecision($shade);
        $this->commandPosition($shade, $reason, $central);
        $this->updateHtml($central, $shade, $reason, $azimut, $elevation);
        $this->SetStatus(102);
    }

    // ------------------------------------------------------------------
    // Logik-Bausteine
    // ------------------------------------------------------------------

    private function isSunOnWindow(float $azimut, float $elevation): bool
    {
        $direction = $this->ReadPropertyInteger('FacadeDirection');
        $left = $this->ReadPropertyInteger('ShadeAngleLeft');
        $right = $this->ReadPropertyInteger('ShadeAngleRight');

        $azFrom = fmod($direction - $left + 360, 360);
        $azTo = fmod($direction + $right, 360);
        $azimutOK = ($azFrom > $azTo)
            ? ($azimut >= $azFrom || $azimut <= $azTo)
            : ($azimut >= $azFrom && $azimut <= $azTo);

        // Endwinkel = obere Elevations-Grenze ("keine Beschattung über dieser
        // Elevation"), 0 = deaktiviert. Untere Grenze bleibt der Dachvorsprung.
        $endAngle = (float) $this->ReadPropertyInteger('EndAngle');
        $elevOK = ($elevation > $this->criticalElevation()) && ($endAngle <= 0 || $elevation < $endAngle);

        return $azimutOK && $elevOK;
    }

    /**
     * Wendet die je Fassade optional aktivierten Überschreibungen auf die von
     * der Steuerung gelieferten zentralen Werte an. So kann z. B. eine
     * einzelne Fassade abweichend von den übrigen erst mit Sonnenuntergang
     * statt zu fester Uhrzeit öffnen, oder eigene Schwellwerte nutzen.
     */
    private function applyOverrides(array $central): array
    {
        if ($this->ReadPropertyBoolean('OverrideEarliest')) {
            $central['earliestSec'] = $this->timeStringToSeconds($this->ReadPropertyString('OverrideEarliestTime'));
        }
        if ($this->ReadPropertyBoolean('OverrideLatest')) {
            $latestSec = null;
            if ($this->ReadPropertyInteger('OverrideLatestMode') === self::LATEST_MODE_SUNSET) {
                $sunsetID = $this->ReadPropertyInteger('OverrideLatestSunsetID');
                if ($this->variableValid($sunsetID)) {
                    $ts = (int) GetValue($sunsetID);
                    if ($ts > 0) {
                        $latestSec = ((int) date('H', $ts) * 3600) + ((int) date('i', $ts) * 60);
                    }
                }
            } else {
                $latestSec = $this->timeStringToSeconds($this->ReadPropertyString('OverrideLatestTime'));
            }
            if ($latestSec !== null) {
                $central['latestSec'] = $latestSec;
            }
        }
        if ($this->ReadPropertyBoolean('OverrideLockTime')) {
            $central['lockTime'] = $this->ReadPropertyInteger('OverrideLockTimeValue');
        }
        if ($this->ReadPropertyBoolean('OverrideBrightness')) {
            $central['brightnessOn'] = $this->ReadPropertyInteger('OverrideBrightnessOn');
            $central['brightnessOff'] = $this->ReadPropertyInteger('OverrideBrightnessOff');
        }
        if ($this->ReadPropertyBoolean('OverrideTemp')) {
            $central['tempOn'] = $this->ReadPropertyFloat('OverrideTempOn');
            $central['tempOff'] = $this->ReadPropertyFloat('OverrideTempOff');
        }
        if ($this->ReadPropertyBoolean('OverrideTempAllAround')) {
            $central['tempAllAround'] = $this->ReadPropertyFloat('OverrideTempAllAroundValue');
        }
        if ($this->ReadPropertyBoolean('OverrideIndoor')) {
            $central['indoorMin'] = $this->ReadPropertyFloat('OverrideIndoorMin');
            $central['indoorMax'] = $this->ReadPropertyFloat('OverrideIndoorMax');
        }
        return $central;
    }

    /** Wandelt "HH:MM" in Sekunden seit Mitternacht; ungültige Eingabe -> 0. */
    private function timeStringToSeconds(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1) {
            return 0;
        }
        $hour = min(23, max(0, (int) $m[1]));
        $minute = min(59, max(0, (int) $m[2]));
        return ($hour * 3600) + ($minute * 60);
    }

    /** Winkel, ab dem der Dachvorsprung die Sonne nicht mehr auf das Fenster lässt. */
    private function criticalElevation(): float
    {
        $height = $this->ReadPropertyFloat('RoofHeight');
        $overhang = $this->ReadPropertyFloat('RoofOverhang');
        $sill = $this->ReadPropertyFloat('WindowSill');
        $denominator = $height - $sill;
        return ($denominator > 0.0) ? rad2deg(atan($overhang / $denominator)) : 0.0;
    }

    /**
     * Verfolgt den Sonnenazimut/-elevation der letzten 3 Stunden, damit die
     * TileVisu-Kachel die bisherige Sonnenbahn (und eine kurze Prognose) im
     * Kompass einzeichnen kann.
     */
    private function updateSunTrack(float $azimut, float $elevation): void
    {
        $track = json_decode($this->ReadAttributeString('SunTrack'), true);
        if (!is_array($track)) {
            $track = [];
        }
        $now = time();
        $track[] = ['t' => $now, 'az' => $azimut, 'el' => $elevation];
        $limit = $now - (180 * 60); // 3 Stunden
        $track = array_values(array_filter($track, static fn ($e) => isset($e['t']) && $e['t'] >= $limit));
        $this->WriteAttributeString('SunTrack', json_encode($track));
    }

    /**
     * Erkennt einen Helligkeitssensor, der (vermutlich durch Sättigung/Messbereichs-
     * Ende) über einen längeren Zeitraum exakt denselben Wert liefert, obwohl die
     * Sonne gerade hoch genug steht, dass sich der Wert eigentlich ändern müsste.
     * Rein diagnostisch – beeinflusst keine Entscheidung, nur die Kachel-Anzeige.
     */
    private function updateBrightnessSaturation(?float $lux, float $elevation): void
    {
        if ($lux === null) {
            $this->WriteAttributeBoolean('BrightnessSaturated', false);
            return;
        }
        $lastVal = $this->ReadAttributeFloat('LastBrightnessValue');
        $stuckSince = $this->ReadAttributeInteger('BrightnessStuckSince');
        $now = time();
        if ($stuckSince === 0 || abs($lux - $lastVal) > 1.0) {
            $this->WriteAttributeFloat('LastBrightnessValue', $lux);
            $this->WriteAttributeInteger('BrightnessStuckSince', $now);
            $this->WriteAttributeBoolean('BrightnessSaturated', false);
            return;
        }
        $stuckMinutes = ($now - $stuckSince) / 60;
        $this->WriteAttributeBoolean('BrightnessSaturated', $stuckMinutes >= 30 && $elevation > 20);
    }

    /** Zählt Fahrten und kumulierte Beschattungsdauer für den heutigen Tag (Kachel-Fußzeile). */
    private function updateDailyStats(bool $shade, bool $moved): void
    {
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('DailyStatsDate') !== $today) {
            $this->WriteAttributeString('DailyStatsDate', $today);
            $this->WriteAttributeInteger('DailyMoveCount', 0);
            $this->WriteAttributeInteger('DailyShadedSeconds', 0);
            $this->WriteAttributeInteger('DailyShadeStart', $shade ? time() : 0);
        }
        if ($moved) {
            $this->WriteAttributeInteger('DailyMoveCount', $this->ReadAttributeInteger('DailyMoveCount') + 1);
        }
        $shadeStart = $this->ReadAttributeInteger('DailyShadeStart');
        if ($shade && $shadeStart === 0) {
            $this->WriteAttributeInteger('DailyShadeStart', time());
        } elseif (!$shade && $shadeStart > 0) {
            $this->WriteAttributeInteger('DailyShadedSeconds', $this->ReadAttributeInteger('DailyShadedSeconds') + (time() - $shadeStart));
            $this->WriteAttributeInteger('DailyShadeStart', 0);
        }
    }

    /** @return bool|null true/false = Bedingung; null = Sensor vorhanden aber veraltet (Fail-Safe). */
    private function evaluateBrightness(array $central): ?bool
    {
        $id = $this->ReadPropertyInteger('BrightnessID');
        if ($this->variableValid($id)) {
            if (!$this->variableFresh($id)) {
                return null;
            }
            $lux = (float) GetValue($id);
            $state = $this->ReadAttributeBoolean('BrightHyst');
            $state = $this->hysteresis($state, $lux, (float) $central['brightnessOn'], (float) $central['brightnessOff']);
            $this->WriteAttributeBoolean('BrightHyst', $state);
            return $state;
        }

        // Kein eigener Sensor: optional (Property `UseCentralBrightnessFallback`,
        // Standard aus) auf den zentralen Wolken-/Helligkeitssensor als Ersatzwert
        // zurückfallen (eigene Ein-/Aus-Schwellen, da dieser meist eine andere
        // Einheit als die Lux-basierten Beschattungsschwellen hat). Ist der
        // Ersatzsensor selbst nicht konfiguriert/veraltet, wird das wie "kein
        // Sensor" behandelt statt Fail-Safe auszulösen - ein gemeinsam genutzter
        // Sensor soll nicht mehrere Fassaden in Fail-Safe versetzen.
        if ($this->ReadPropertyBoolean('UseCentralBrightnessFallback')) {
            $fallback = $central['fallbackBrightnessValue'] ?? null;
            if ($fallback !== null) {
                $state = $this->ReadAttributeBoolean('BrightHyst');
                $state = $this->hysteresis($state, (float) $fallback, (float) $central['fallbackBrightnessOn'], (float) $central['fallbackBrightnessOff']);
                $this->WriteAttributeBoolean('BrightHyst', $state);
                return $state;
            }
        }

        return true; // kein Sensor verfügbar -> Bedingung ignorieren (MHC-konform)
    }

    /**
     * Setzt die Außentemperatur-Hysterese optional einmal täglich zurück
     * (Property `TempHystDailyReset`, Standard aus). Ohne diesen Reset bleibt
     * die Temperaturbedingung ab dem Überschreiten der Ein-Schwelle so lange
     * "erfüllt", bis der Wert unter die Aus-Schwelle fällt – fällt die
     * Außentemperatur nachts nie so weit (Tropennacht), gilt sie am nächsten
     * Morgen weiterhin als erfüllt, obwohl der aktuelle Wert in der Totzone
     * zwischen Aus- und Ein-Schwelle liegt. Ist der Reset aktiv, startet jeder
     * Tag neu bei "nicht erfüllt", unabhängig vom Vortag.
     */
    private function maybeResetTempHysteresis(): void
    {
        if (!$this->ReadPropertyBoolean('TempHystDailyReset')) {
            return;
        }
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('TempHystResetDate') === $today) {
            return;
        }
        $this->WriteAttributeString('TempHystResetDate', $today);
        $this->WriteAttributeBoolean('TempHyst', false);
    }

    /**
     * @return array{0:bool,1:bool}|null [temperaturBedingung, rundumbeschattung]; null = Sensor veraltet.
     */
    private function evaluateTemperature(array $central): ?array
    {
        $this->maybeResetTempHysteresis();

        $outID = $this->ReadPropertyInteger('OutdoorTempID');
        $inID = $this->ReadPropertyInteger('IndoorTempID');

        $outConfigured = $this->variableValid($outID);
        $inConfigured = $this->variableValid($inID);

        if (!$outConfigured && !$inConfigured) {
            return [true, false]; // keine Temperatursensoren -> Bedingung ignorieren
        }
        if ($outConfigured && !$this->variableFresh($outID)) {
            return null;
        }
        if ($inConfigured && !$this->variableFresh($inID)) {
            return null;
        }

        $allAround = false;
        $outTemp = null;
        if ($outConfigured) {
            $outTemp = $this->effectiveOutdoorTemp((float) GetValue($outID));
            if ($outTemp >= $central['tempAllAround']) {
                $allAround = true;
            }
        }

        if ($inConfigured) {
            $inTemp = (float) GetValue($inID);
            if ($inTemp < $central['indoorMin']) {
                return [false, $allAround];
            }
            if ($inTemp > $central['indoorMax']) {
                return [true, $allAround];
            }
            // dazwischen: entscheidet die Außentemperatur
            if ($outTemp === null) {
                return [true, $allAround];
            }
        }

        if ($outTemp === null) {
            return [true, $allAround];
        }
        $state = $this->ReadAttributeBoolean('TempHyst');
        $state = $this->hysteresis($state, $outTemp, $central['tempOn'], $central['tempOff']);
        $this->WriteAttributeBoolean('TempHyst', $state);
        return [$state, $allAround];
    }

    /**
     * Ob im Alternativmodus zusätzlich der eigene Helligkeitssensor dieser
     * Fassade beschatten darf (Property `CloudModeOwnSensorOverride`). Nur
     * mit einem echten, direkt konfigurierten Sensor sinnvoll: ohne eigenen
     * Sensor wäre `brightnessOK` immer `true` (Bedingung ignoriert) und würde
     * den ganzen Zweck des Alternativmodus für sensorlose Fassaden aushebeln.
     */
    private function cloudModeOwnSensorOverrideActive(): bool
    {
        if (!$this->ReadPropertyBoolean('CloudModeOwnSensorOverride')) {
            return false;
        }
        return $this->variableValid($this->ReadPropertyInteger('BrightnessID'));
    }

    /** Gemittelte Außentemperatur (Tagesmaxima der letzten N Tage), sonst aktueller Wert. */
    private function effectiveOutdoorTemp(float $current): float
    {
        $days = $this->ReadPropertyInteger('MeanTempDays');
        $history = json_decode($this->ReadAttributeString('TempMaxHistory'), true);
        if (!is_array($history)) {
            $history = [];
        }
        $today = date('Y-m-d');
        $history[$today] = isset($history[$today]) ? max((float) $history[$today], $current) : $current;

        if ($days > 0) {
            // nur die letzten N Tage behalten
            krsort($history);
            $history = array_slice($history, 0, $days, true);
        } else {
            $history = [$today => $history[$today]];
        }
        $this->WriteAttributeString('TempMaxHistory', json_encode($history));

        if ($days <= 0 || count($history) === 0) {
            return $current;
        }
        $mean = array_sum($history) / count($history);
        // Aktuelle Temperatur wird höher gewichtet: maßgeblich ist der wärmere Wert.
        return max($current, $mean);
    }

    private function hysteresis(bool $state, float $value, float $on, float $off): bool
    {
        if ($state && $value < $off) {
            return false;
        }
        if (!$state && $value > $on) {
            return true;
        }
        return $state;
    }

    /**
     * Verzögert einen WECHSEL der Entscheidung (beschatten ↔ öffnen), bis die rohe
     * Entscheidung mindestens `DecisionConfirmMinutes` Minuten laufend unverändert
     * war. Kurze Ausreißer (z. B. einzelne durchziehende Wolken) werden so
     * ignoriert. Bei DecisionConfirmMinutes <= 0 wird der Rohwert direkt
     * durchgereicht (altes Verhalten); die Stabilitäts-Attribute werden trotzdem
     * gepflegt, damit sie beim Aktivieren sofort korrekt sind.
     */
    private function debounceDecision(bool $rawShade): bool
    {
        $now = time();
        $lastRaw = $this->ReadAttributeBoolean('LastRawDecision');
        $stableSince = $this->ReadAttributeInteger('DecisionStableSince');

        if ($rawShade !== $lastRaw || $stableSince === 0) {
            $stableSince = $now;
            $this->WriteAttributeBoolean('LastRawDecision', $rawShade);
            $this->WriteAttributeInteger('DecisionStableSince', $stableSince);
        }

        $confirmMinutes = $this->ReadPropertyInteger('DecisionConfirmMinutes');
        if ($confirmMinutes <= 0) {
            return $rawShade;
        }

        $confirmed = $this->ReadAttributeBoolean('ConfirmedShade');
        if ($rawShade === $confirmed) {
            return $rawShade;
        }

        $stableFor = $now - $stableSince;
        $needed = $confirmMinutes * 60;
        if ($stableFor < $needed) {
            $this->log(sprintf(
                '🕒 Entscheidungswechsel in Bestätigung: %s → %s (%ds/%ds stabil)',
                $confirmed ? 'beschattet' : 'offen',
                $rawShade ? 'beschattet' : 'offen',
                $stableFor,
                $needed
            ), true);
            return $confirmed;
        }

        return $rawShade;
    }

    /**
     * Fährt die Aktoren entsprechend Entscheidung unter Beachtung von Sperrzeit
     * und Sperrvariablen (Kinderzimmer).
     *
     * Sperr-Semantik (je Aktor):
     *  – Sperre aktiv     → Aktor bleibt unangetastet. Sein Zustand ist für das
     *                       Modul danach UNBEKANNT (modeMap-Eintrag wird entfernt),
     *                       da er zwischenzeitlich von Hand oder einem Skript bewegt
     *                       worden sein kann.
     *  – Sperre aufgehoben→ Beim nächsten gültigen Durchlauf gilt der Zustand als
     *                       unbekannt, also wird der Aktor auf die aktuell gültige
     *                       Zielposition gefahren. Dieser einmalige Nachzieh-
     *                       Fahrbefehl ist von der globalen Sperrzeit AUSGENOMMEN
     *                       (der Aktor hat ja seit der Sperre gar nicht bewegt) –
     *                       sonst könnte ihn eine gerade laufende Sperrzeit einer
     *                       anderen Aktor-Bewegung unnötig lange ausbremsen. Nachts
     *                       ruft Evaluate() commandPosition() gar nicht auf (vor
     *                       Zeitfenster), daher öffnet nach Aufheben nachts nichts –
     *                       erst die nächste echte Entscheidung im Tagfenster fährt.
     *  – Sperre nie aktiv → normaler Fahrbetrieb mit Sperrzeit-Prüfung.
     */
    private function commandPosition(bool $shade, string $reason, array $central): void
    {
        $this->WriteAttributeBoolean('ConfirmedShade', $shade);

        $this->SetValue('Shaded', $shade);
        $facadeTarget = $shade ? $this->ReadPropertyInteger('ShadePosition') : $this->ReadPropertyInteger('OpenPosition');
        $this->SetValue('TargetPosition', $facadeTarget);

        $lockTime = max(0, (int) $central['lockTime']);
        $now = time();
        $elapsed = $now - $this->ReadAttributeInteger('LastMovementTs');
        $this->SetValue('LockRemaining', max(0, $lockTime - $elapsed));

        $modeMap = json_decode($this->ReadAttributeString('LastModeMap'), true);
        if (!is_array($modeMap)) {
            $modeMap = [];
        }

        $moved = 0;
        $blocked = 0;
        $locked = 0;
        $firstPos = null;
        foreach ($this->validActuators() as $act) {
            $key = (string) $act['ActuatorID'];

            if ($this->actuatorBlocked($act)) {
                // Sperre aktiv: Aktor nicht anfassen und Zustand als unbekannt
                // markieren, damit er nach Aufheben der Sperre sicher nachzieht.
                $blocked++;
                unset($modeMap[$key]);
                continue;
            }

            $last = array_key_exists($key, $modeMap) ? (bool) $modeMap[$key] : null;
            if ($last === $shade) {
                continue; // bereits in Zielstellung (durch uns gefahren)
            }

            // Globale Sperrzeit gegen zu häufiges Fahren – gilt NICHT für den
            // einmaligen Nachzieh-Fahrbefehl direkt nach Aufheben einer Sperre
            // ($last === null, Zustand war unbekannt): der Aktor hat währenddessen
            // gar nicht bewegt, muss also nicht künstlich ausgebremst werden.
            $isCatchUp = ($last === null);
            if (!$isCatchUp && $elapsed < $lockTime) {
                $locked++;
                continue;
            }

            $target = $shade ? $this->actuatorShadePosition($act) : $this->ReadPropertyInteger('OpenPosition');

            // Steht der Aktor (z. B. nach Neustart mit unbekanntem Zustand oder
            // durch Fremdsteuerung) bereits auf der Zielposition, wäre die Fahrt
            // wirkungslos: Zustand übernehmen, aber weder fahren noch als
            // Bewegung zählen (verhindert Phantom-Einträge in Protokoll/Statistik).
            $current = $this->variableValid($act['ActuatorID']) ? (int) GetValue($act['ActuatorID']) : null;
            if ($current !== null && $current === $target) {
                $modeMap[$key] = $shade;
                continue;
            }

            $this->moveActuator($act, $target);
            $modeMap[$key] = $shade;
            if ($firstPos === null) {
                $firstPos = $target;
            }
            $moved++;
        }

        $this->WriteAttributeString('LastModeMap', json_encode($modeMap));
        $this->updateDailyStats($shade, $moved > 0);

        $blockSig = $blocked > 0 ? ($shade ? 1 : 0) : -1;

        if ($moved > 0) {
            $this->WriteAttributeInteger('LastMovementTs', $now);
            if ($firstPos !== null) {
                $this->WriteAttributeInteger('LastCommandedPos', $firstPos);
            }
            $this->SetValue('LastMovement', $now);
            $this->SetValue('LockRemaining', $lockTime);
            $suffix = $blocked > 0 ? sprintf(' · %d gesperrt 🔒', $blocked) : '';
            $this->log(sprintf('✅ %s → %d%%%s', $reason, $facadeTarget, $suffix));
        } elseif ($blocked > 0) {
            // nur bei Zustandswechsel protokollieren (sonst Spam je Auswertung)
            if ($blockSig !== $this->ReadAttributeInteger('BlockedLast')) {
                $this->log(sprintf('🔒 %s – %d Aktor(en) gesperrt (Kinderzimmer).', $reason, $blocked));
            }
        } elseif ($locked > 0) {
            $this->log(sprintf('⏳ Sperrzeit aktiv: %ds übrig (%s)', max(0, $lockTime - $elapsed), $reason), true);
        }

        $this->WriteAttributeInteger('BlockedLast', $blockSig);
    }

    private function applyFailSafe(string $why, array $central): void
    {
        $mode = $this->ReadPropertyInteger('FailSafe');
        $this->setConditions(false, false, false);
        $this->setReason('🛟 Fail-Safe: ' . $why);
        $this->setDecisionPath('special');
        switch ($mode) {
            case self::FAILSAFE_OPEN:
                $this->log(sprintf('🛟 Fail-Safe (%s) → öffnen', $why));
                $this->commandPosition(false, 'Fail-Safe öffnen', $central);
                break;
            case self::FAILSAFE_SHADE:
                $this->log(sprintf('🛟 Fail-Safe (%s) → beschatten', $why));
                $this->commandPosition(true, 'Fail-Safe beschatten', $central);
                break;
            case self::FAILSAFE_HOLD:
            default:
                $this->log(sprintf('🛟 Fail-Safe (%s) → Position halten', $why));
                break;
        }
    }

    /** @return bool true, wenn Handbetrieb aktiv ist und die Auswertung abgebrochen werden soll. */
    private function handleManualOverride(): bool
    {
        if (!$this->ReadPropertyBoolean('ManualDetection')) {
            return false;
        }
        $now = time();
        $lastPos = $this->ReadAttributeInteger('LastCommandedPos');
        $tolerance = $this->ReadPropertyInteger('ManualTolerance');

        $actuators = $this->validActuators();
        if ($lastPos >= 0 && count($actuators) > 0) {
            $firstID = $actuators[0]['ActuatorID'];
            if ($this->variableValid($firstID)) {
                $current = (int) GetValue($firstID);
                if (abs($current - $lastPos) > $tolerance) {
                    $until = $now + max(0, $this->ReadPropertyInteger('ManualPause'));
                    $this->WriteAttributeInteger('ManualUntil', $until);
                    $this->SetValue('ManualMode', true);
                    $this->log(sprintf('✋ Handbetrieb erkannt (Ist %d%% ≠ Soll %d%%) – Automatik pausiert.', $current, $lastPos));
                    return true;
                }
            }
        }

        if ($now < $this->ReadAttributeInteger('ManualUntil')) {
            $this->SetValue('ManualMode', true);
            return true;
        }
        $this->SetValue('ManualMode', false);
        return false;
    }

    // ------------------------------------------------------------------
    // Aktor-Helfer
    // ------------------------------------------------------------------

    /** @return list<array{ActuatorID:int,ShadePosition:int,DisableID:int,DisplayName:string}> */
    private function validActuators(): array
    {
        $list = json_decode($this->ReadPropertyString('Actuators'), true);
        if (!is_array($list)) {
            return [];
        }
        $result = [];
        foreach ($list as $entry) {
            $id = (int) ($entry['ActuatorID'] ?? 0);
            if (!$this->variableValid($id)) {
                continue;
            }
            $result[] = [
                'ActuatorID'    => $id,
                'ShadePosition' => (int) ($entry['ShadePosition'] ?? -1),
                'DisableID'     => (int) ($entry['DisableID'] ?? 0),
                'DisplayName'   => trim((string) ($entry['DisplayName'] ?? '')),
            ];
        }
        return $result;
    }

    private function actuatorShadePosition(array $act): int
    {
        $individual = (int) $act['ShadePosition'];
        return ($individual >= 0) ? $individual : $this->ReadPropertyInteger('ShadePosition');
    }

    /** True = dieser Aktor ist per Sperrvariable blockiert (z. B. Kinderzimmer) und bleibt stehen. */
    private function actuatorBlocked(array $act): bool
    {
        $blockID = (int) $act['DisableID'];
        return $blockID > 0 && $this->variableValid($blockID) && (bool) GetValue($blockID);
    }

    private function blockedActuatorCount(): int
    {
        $count = 0;
        foreach ($this->validActuators() as $act) {
            if ($this->actuatorBlocked($act)) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<array{label:string,position:int|null,blocked:bool}> Ist-Position je Aktor für die Kachel. */
    private function actuatorStatuses(): array
    {
        $result = [];
        foreach ($this->validActuators() as $act) {
            $id = $act['ActuatorID'];
            $result[] = [
                'label'    => $this->actuatorLabel($act),
                'position' => $this->variableValid($id) ? (int) GetValue($id) : null,
                'blocked'  => $this->actuatorBlocked($act),
            ];
        }
        return $result;
    }

    /**
     * Bezeichnung eines Aktors für die Kachel: bevorzugt den vom Nutzer
     * eingetragenen Anzeigenamen, sonst den Namen der übergeordneten
     * Geräte-Instanz (z. B. "Rollladen Büro"), da die Positions-Variable
     * selbst meist generisch/technisch heißt (z. B. "K79.Rolladen"). Fällt auf
     * den Variablennamen zurück, falls kein Parent ermittelbar ist.
     */
    private function actuatorLabel(array $act): string
    {
        if ($act['DisplayName'] !== '') {
            return $act['DisplayName'];
        }
        $id = $act['ActuatorID'];
        $parentID = @IPS_GetParent($id);
        if ($parentID > 0 && @IPS_InstanceExists($parentID)) {
            $name = @IPS_GetName($parentID);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return (string) @IPS_GetName($id);
    }

    private function moveActuator(array $act, int $position): void
    {
        $id = (int) $act['ActuatorID'];
        $position = max(0, min(100, $position));
        @RequestAction($id, $position);
    }

    // ------------------------------------------------------------------
    // Zentrale Daten / Steuerung
    // ------------------------------------------------------------------

    /** ID der ausgewählten Steuerungs-Instanz (0 = keine/ungültig). */
    private function getCentralID(): int
    {
        $id = $this->ReadPropertyInteger('CentralID');
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return 0;
        }
        $instance = @IPS_GetInstance($id);
        if (!is_array($instance) || ($instance['ModuleInfo']['ModuleID'] ?? '') !== self::STEUERUNG_GUID) {
            return 0;
        }
        return $id;
    }

    private function getCentralData(): ?array
    {
        $centralID = $this->getCentralID();
        if ($centralID === 0) {
            return null;
        }
        try {
            $raw = BSTRG_GetCentralData($centralID);
        } catch (\Throwable $e) {
            $this->SendDebug(__FUNCTION__, 'Fehler beim Lesen der Steuerung: ' . $e->getMessage(), 0);
            return null;
        }
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $this->applyOverrides($data) : null;
    }

    /** Sammelt alle für die Kachel relevanten Ist-/Soll-Werte als JSON-fähiges Array. */
    private function getTileData(): array
    {
        $central = $this->getCentralData();

        $azID = $this->ReadPropertyInteger('AzimuthID');
        $elID = $this->ReadPropertyInteger('ElevationID');
        $azimuth = $this->variableValid($azID) ? (float) GetValue($azID) : null;
        $elevation = $this->variableValid($elID) ? (float) GetValue($elID) : null;

        $brightID = $this->ReadPropertyInteger('BrightnessID');
        $brightness = $this->variableValid($brightID) ? (float) GetValue($brightID) : null;
        $brightnessOn = $central['brightnessOn'] ?? null;
        $brightnessOff = $central['brightnessOff'] ?? null;
        $brightnessUnit = 'lx';
        $brightnessIsFallback = false;
        if ($brightness === null && $central !== null && $this->ReadPropertyBoolean('UseCentralBrightnessFallback')
            && ($central['fallbackBrightnessValue'] ?? null) !== null) {
            $brightness = (float) $central['fallbackBrightnessValue'];
            $brightnessOn = $central['fallbackBrightnessOn'] ?? null;
            $brightnessOff = $central['fallbackBrightnessOff'] ?? null;
            $brightnessUnit = (string) ($central['fallbackBrightnessUnit'] ?? 'W/m²');
            $brightnessIsFallback = true;
        }

        $outID = $this->ReadPropertyInteger('OutdoorTempID');
        $outdoorTemp = $this->variableValid($outID) ? (float) GetValue($outID) : null;

        $inID = $this->ReadPropertyInteger('IndoorTempID');
        $indoorTemp = $this->variableValid($inID) ? (float) GetValue($inID) : null;

        $lockTime = $central !== null ? max(0, (int) $central['lockTime']) : 0;
        $elapsed = time() - $this->ReadAttributeInteger('LastMovementTs');
        $lockRemaining = max(0, $lockTime - $elapsed);

        return [
            'name'                 => IPS_GetName($this->InstanceID),
            'shaded'               => (bool) $this->GetValue('Shaded'),
            'targetPosition'       => (int) $this->GetValue('TargetPosition'),
            'reason'               => $this->ReadAttributeString('LastReason'),
            'automation'           => (bool) $this->GetValue('Automation'),
            'brightness'           => $brightness,
            'brightnessOn'         => $brightnessOn,
            'brightnessOff'        => $brightnessOff,
            'brightnessUnit'       => $brightnessUnit,
            'brightnessIsFallback' => $brightnessIsFallback,
            'brightnessOK'         => (bool) $this->GetValue('BrightnessOK'),
            'outdoorTemp'          => $outdoorTemp,
            'tempOn'               => $central['tempOn'] ?? null,
            'tempOff'              => $central['tempOff'] ?? null,
            'temperatureOK'        => (bool) $this->GetValue('TemperatureOK'),
            'indoorTemp'           => $indoorTemp,
            'indoorMin'            => $central['indoorMin'] ?? null,
            'indoorMax'            => $central['indoorMax'] ?? null,
            'azimuth'              => $azimuth,
            'elevation'            => $elevation,
            'facadeDirection'      => $this->ReadPropertyInteger('FacadeDirection'),
            'shadeAngleLeft'       => $this->ReadPropertyInteger('ShadeAngleLeft'),
            'shadeAngleRight'      => $this->ReadPropertyInteger('ShadeAngleRight'),
            'criticalElevation'    => $this->criticalElevation(),
            'sunInWindow'          => (bool) $this->GetValue('SunInWindow'),
            'earliestSec'          => $central['earliestSec'] ?? null,
            'latestSec'            => $central['latestSec'] ?? null,
            'nowSec'               => ((int) date('H') * 3600) + ((int) date('i') * 60),
            'lockTime'             => $lockTime,
            'lockRemaining'        => $lockRemaining,
            'lastMovement'         => (int) $this->GetValue('LastMovement'),
            'central'              => $central !== null,
            'cloudMode'            => (bool) ($central['cloudMode'] ?? false),
            'sunPercentage'        => (float) ($central['sunPercentage'] ?? 0.0),
            'manualMode'           => $this->ReadPropertyBoolean('ManualDetection') && (bool) $this->GetValue('ManualMode'),
            'blockedCount'         => $this->blockedActuatorCount(),
            'rawDecision'          => $this->ReadAttributeBoolean('LastRawDecision'),
            'confirmMinutes'       => $this->ReadPropertyInteger('DecisionConfirmMinutes'),
            'stableSince'          => $this->ReadAttributeInteger('DecisionStableSince'),
            'decisionPath'         => $this->ReadAttributeString('LastDecisionPath'),
            'actuators'            => $this->actuatorStatuses(),
            'brightnessSaturated'  => $this->ReadAttributeBoolean('BrightnessSaturated'),
            'sunTrack'             => json_decode($this->ReadAttributeString('SunTrack'), true) ?: [],
            'dailyMoves'           => $this->ReadAttributeInteger('DailyMoveCount'),
            'dailyShadedSeconds'   => $this->ReadAttributeInteger('DailyShadedSeconds')
                + ($this->ReadAttributeInteger('DailyShadeStart') > 0 ? time() - $this->ReadAttributeInteger('DailyShadeStart') : 0),
            'protocolHtml'        => $this->ReadPropertyBoolean('EnableProtocol') ? (string) $this->GetValue('Protocol') : '',
            'houseLength'         => (float) ($central['houseLength'] ?? 10.0),
            'houseWidth'          => (float) ($central['houseWidth'] ?? 8.0),
            'houseRotation'       => (int) ($central['houseRotation'] ?? 0),
            'roofShape'           => (int) ($central['roofShape'] ?? 0),
            'roofHighSideFlip'    => (bool) ($central['roofHighSideFlip'] ?? false),
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

    // ------------------------------------------------------------------
    // Sensor-Validierung
    // ------------------------------------------------------------------

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

    private function sensorUsable(int $id): bool
    {
        return $this->variableValid($id) && $this->variableFresh($id);
    }

    // ------------------------------------------------------------------
    // Statusvariablen / Anzeige / Protokoll
    // ------------------------------------------------------------------

    private function setConditions(bool $sun, bool $brightness, bool $temperature): void
    {
        $this->SetValue('SunInWindow', $sun);
        $this->SetValue('BrightnessOK', $brightness);
        $this->SetValue('TemperatureOK', $temperature);
    }

    /** Merkt sich den zuletzt gültigen Entscheidungsgrund für die TileVisu-Kachel. */
    private function setReason(string $reason): void
    {
        $this->WriteAttributeString('LastReason', $reason);
    }

    /**
     * Merkt sich, welcher Entscheidungszweig zuletzt gegriffen hat, damit die
     * Kachel Bedingungszeilen ausgrauen kann, die gerade nicht ausschlaggebend
     * sind (z. B. Helligkeit/Sonne bei Rundumbeschattung).
     *
     * @param 'allAround'|'cloudMode'|'normal'|'special' $path
     */
    private function setDecisionPath(string $path): void
    {
        $this->WriteAttributeString('LastDecisionPath', $path);
    }

    private function log(string $text, bool $debugOnly = false): void
    {
        $this->SendDebug('Evaluate', $text, 0);
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

    private function updateHtml(array $central, bool $shade, string $reason, float $azimut = 0.0, float $elevation = 0.0): void
    {
        if (!$this->ReadPropertyBoolean('EnableHTML') || !@IPS_VariableExists(@$this->GetIDForIdent('StatusHTML'))) {
            return;
        }
        $statusIcon = $shade ? '🪟🔒' : '🪟🔓';
        $statusText = $shade ? $this->Translate('Shaded') : $this->Translate('Open');
        $reason = htmlspecialchars($reason, ENT_QUOTES);
        $now = date('H:i');
        $html = <<<HTML
<style>.bsbox{font-family:sans-serif;font-size:13px}.bsstatus{font-size:16px;margin-bottom:6px}.bsrow{margin-top:4px}</style>
<div class="bsbox">
  <div class="bsstatus">{$statusIcon} {$statusText}</div>
  <div class="bsrow">🌞 Azimut: {$this->fmt($azimut)}° · 📐 Elevation: {$this->fmt($elevation)}°</div>
  <div class="bsrow">ℹ️ {$reason}</div>
  <div class="bsrow">⏰ {$now}</div>
</div>
HTML;
        $this->SetValue('StatusHTML', $html);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    // ------------------------------------------------------------------
    // Referenzen / Messages / Profile
    // ------------------------------------------------------------------

    private function MaintainSensorReferencesAndMessages(): void
    {
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        // Sensorvariablen referenzieren und auf Änderungen lauschen
        $sensorProps = ['AzimuthID', 'ElevationID', 'BrightnessID', 'OutdoorTempID', 'IndoorTempID'];
        foreach ($sensorProps as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($this->variableValid($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Sonnenuntergangs-Variable (nur bei aktivierter Tagesende-Überschreibung)
        $sunsetID = $this->ReadPropertyInteger('OverrideLatestSunsetID');
        if ($this->variableValid($sunsetID)) {
            $this->RegisterReference($sunsetID);
        }

        // Sperrvariablen der Aktoren (z. B. Kinderzimmer/Ausschlafen) ebenfalls
        // beobachten, damit ein Aufheben der Sperre sofort neu bewertet wird,
        // statt erst beim nächsten Timer-Tick.
        foreach ($this->validActuators() as $act) {
            $blockID = (int) $act['DisableID'];
            if ($this->variableValid($blockID)) {
                $this->RegisterReference($blockID);
                $this->RegisterMessage($blockID, VM_UPDATE);
            }
        }

        // ausgewählte Steuerung referenzieren und ihre zentrale Wolken-Variable beobachten
        $centralID = $this->getCentralID();
        if ($centralID > 0) {
            $this->RegisterReference($centralID);
            $cloudID = @IPS_GetObjectIDByIdent('CloudMode', $centralID);
            if ($cloudID !== false && $cloudID > 0) {
                $this->RegisterMessage($cloudID, VM_UPDATE);
            }
        }
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('BSFAS.Position')) {
            IPS_CreateVariableProfile('BSFAS.Position', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BSFAS.Position', '', ' %');
            IPS_SetVariableProfileValues('BSFAS.Position', 0, 100, 1);
            IPS_SetVariableProfileIcon('BSFAS.Position', 'Jalousie');
        }
        if (!IPS_VariableProfileExists('BSFAS.Seconds')) {
            IPS_CreateVariableProfile('BSFAS.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('BSFAS.Seconds', '', ' s');
            IPS_SetVariableProfileValues('BSFAS.Seconds', 0, 7200, 30);
            IPS_SetVariableProfileIcon('BSFAS.Seconds', 'Clock');
        }
    }

    private function DeleteProfile(string $name): void
    {
        if (IPS_VariableProfileExists($name)) {
            @IPS_DeleteVariableProfile($name);
        }
    }
}
