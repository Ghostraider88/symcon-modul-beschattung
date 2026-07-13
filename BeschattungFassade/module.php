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

        // --- Verhalten ---
        $this->RegisterPropertyInteger('EveningBehavior', self::EVENING_OPEN);
        $this->RegisterPropertyInteger('EvaluationInterval', 300); // s
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
        $this->RegisterPropertyBoolean('EnableHTML', true);
        $this->RegisterPropertyBoolean('EnableProtocol', true);

        // --- Reserviert: Wind/Regen-Hooks (noch keine aktive Logik) ---
        $this->RegisterPropertyInteger('WindID', 0);
        $this->RegisterPropertyInteger('RainID', 0);

        // --- interne Persistenz ---
        $this->RegisterAttributeInteger('LastMovementTs', 0);
        $this->RegisterAttributeBoolean('BrightHyst', false);
        $this->RegisterAttributeBoolean('TempHyst', false);
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
                'shaded'          => $this->Translate('Shaded'),
                'open'            => $this->Translate('Open'),
                'automation'      => $this->Translate('Automation'),
                'target'          => $this->Translate('Target'),
                'brightness'      => $this->Translate('Brightness'),
                'outdoorTemp'     => $this->Translate('Outdoor temp.'),
                'indoorTemp'      => $this->Translate('Indoor temp.'),
                'sunOnWindow'     => $this->Translate('Sun on window'),
                'timeWindow'      => $this->Translate('Time window'),
                'lockTime'        => $this->Translate('Lock time'),
                'waitingChip'     => $this->Translate('waiting'),
                'lastMovement'    => $this->Translate('Last movement'),
                'alternativeMode' => $this->Translate('Alternative mode'),
                'sunPercentage'   => $this->Translate('Sun percentage'),
                'manualActive'    => $this->Translate('Manual override active'),
                'confirming'      => $this->Translate('Confirming change'),
                'blockedSuffix'   => $this->Translate('{n} locked'),
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

        // --- Entscheidung (MHC-konform) ---
        $shade = false;
        $reason = '';
        $decisionPath = 'normal';
        if ($allAround) {
            $shade = true;
            $reason = '🔥 Rundumbeschattung (Außentemp.)';
            $decisionPath = 'allAround';
        } elseif ($central['cloudMode']) {
            // Alternativmodus: die instantane, durch Wolken flackernde Helligkeits-
            // Hysterese ist hier KEIN Gate mehr. Stattdessen ersetzt der über ein
            // Zeitfenster gemittelte Sonnenscheinanteil den Helligkeits-Aspekt.
            $decisionPath = 'cloudMode';
            if ($temperatureOK) {
                $shade = $sunInWindow && ($central['sunPercentage'] > 50);
                $reason = sprintf('Φ Alternativmodus (Sonne %.0f%%)', $central['sunPercentage']);
            } else {
                $reason = '⚠️ Schwellwerte nicht erfüllt';
            }
        } elseif ($brightnessOK && $temperatureOK) {
            $shade = $sunInWindow;
            $reason = '☀️ Sonnenstand';
        } else {
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

        $endAngle = (float) $this->ReadPropertyInteger('EndAngle');
        $elevOK = ($elevation > $this->criticalElevation()) && ($elevation > $endAngle);

        return $azimutOK && $elevOK;
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

    /** @return bool|null true/false = Bedingung; null = Sensor vorhanden aber veraltet (Fail-Safe). */
    private function evaluateBrightness(array $central): ?bool
    {
        $id = $this->ReadPropertyInteger('BrightnessID');
        if (!$this->variableValid($id)) {
            return true; // kein Sensor -> Bedingung ignorieren (MHC-konform)
        }
        if (!$this->variableFresh($id)) {
            return null;
        }
        $lux = (float) GetValue($id);
        $state = $this->ReadAttributeBoolean('BrightHyst');
        $state = $this->hysteresis($state, $lux, (float) $central['brightnessOn'], (float) $central['brightnessOff']);
        $this->WriteAttributeBoolean('BrightHyst', $state);
        return $state;
    }

    /**
     * @return array{0:bool,1:bool}|null [temperaturBedingung, rundumbeschattung]; null = Sensor veraltet.
     */
    private function evaluateTemperature(array $central): ?array
    {
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
            $this->moveActuator($act, $target);
            $modeMap[$key] = $shade;
            if ($firstPos === null) {
                $firstPos = $target;
            }
            $moved++;
        }

        $this->WriteAttributeString('LastModeMap', json_encode($modeMap));

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

    /** @return list<array{ActuatorID:int,ShadePosition:int,DisableID:int}> */
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
        return is_array($data) ? $data : null;
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

        $outID = $this->ReadPropertyInteger('OutdoorTempID');
        $outdoorTemp = $this->variableValid($outID) ? (float) GetValue($outID) : null;

        $inID = $this->ReadPropertyInteger('IndoorTempID');
        $indoorTemp = $this->variableValid($inID) ? (float) GetValue($inID) : null;

        $lockTime = $central !== null ? max(0, (int) $central['lockTime']) : 0;
        $elapsed = time() - $this->ReadAttributeInteger('LastMovementTs');
        $lockRemaining = max(0, $lockTime - $elapsed);

        return [
            'name'              => IPS_GetName($this->InstanceID),
            'shaded'            => (bool) $this->GetValue('Shaded'),
            'targetPosition'    => (int) $this->GetValue('TargetPosition'),
            'reason'            => $this->ReadAttributeString('LastReason'),
            'automation'        => (bool) $this->GetValue('Automation'),
            'brightness'        => $brightness,
            'brightnessOn'      => $central['brightnessOn'] ?? null,
            'brightnessOff'     => $central['brightnessOff'] ?? null,
            'brightnessOK'      => (bool) $this->GetValue('BrightnessOK'),
            'outdoorTemp'       => $outdoorTemp,
            'tempOn'            => $central['tempOn'] ?? null,
            'tempOff'           => $central['tempOff'] ?? null,
            'temperatureOK'     => (bool) $this->GetValue('TemperatureOK'),
            'indoorTemp'        => $indoorTemp,
            'indoorMin'         => $central['indoorMin'] ?? null,
            'indoorMax'         => $central['indoorMax'] ?? null,
            'azimuth'           => $azimuth,
            'elevation'         => $elevation,
            'facadeDirection'   => $this->ReadPropertyInteger('FacadeDirection'),
            'shadeAngleLeft'    => $this->ReadPropertyInteger('ShadeAngleLeft'),
            'shadeAngleRight'   => $this->ReadPropertyInteger('ShadeAngleRight'),
            'criticalElevation' => $this->criticalElevation(),
            'sunInWindow'       => (bool) $this->GetValue('SunInWindow'),
            'earliestSec'       => $central['earliestSec'] ?? null,
            'latestSec'         => $central['latestSec'] ?? null,
            'nowSec'            => ((int) date('H') * 3600) + ((int) date('i') * 60),
            'lockTime'          => $lockTime,
            'lockRemaining'     => $lockRemaining,
            'lastMovement'      => (int) $this->GetValue('LastMovement'),
            'central'           => $central !== null,
            'cloudMode'         => (bool) ($central['cloudMode'] ?? false),
            'sunPercentage'     => (float) ($central['sunPercentage'] ?? 0.0),
            'manualMode'        => $this->ReadPropertyBoolean('ManualDetection') && (bool) $this->GetValue('ManualMode'),
            'blockedCount'      => $this->blockedActuatorCount(),
            'confirmed'         => $this->ReadAttributeBoolean('ConfirmedShade'),
            'confirmMinutes'    => $this->ReadPropertyInteger('DecisionConfirmMinutes'),
            'stableSince'       => $this->ReadAttributeInteger('DecisionStableSince'),
            'decisionPath'      => $this->ReadAttributeString('LastDecisionPath'),
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
