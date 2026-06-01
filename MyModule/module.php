<?php

declare(strict_types=1);

/**
 * MyModule
 *
 * - Klassenname MUSS identisch zum "name" in module.json sein (Leerzeichen entfernt).
 * - Basisklasse IPSModuleStrict (ab IP-Symcon 8.1). Type-Hints sind Pflicht.
 * - Exportierte Funktionen sind über den prefix erreichbar, z.B. MYM_HelloWorld($id);
 */
class MyModule extends IPSModuleStrict
{
    // Einmalig bei Erstellung der Instanz.
    public function Create(): void
    {
        // Diese Zeile nicht entfernen.
        parent::Create();

        // --- Properties (Nutzer-Konfiguration; Namen == "name" in form.json) ---
        $this->RegisterPropertyString('Hostname', '');
        $this->RegisterPropertyInteger('Interval', 0); // Sekunden; 0 = Timer aus

        // --- Attribute (interne Persistenz, nicht im Formular sichtbar) ---
        $this->RegisterAttributeString('LastResponse', '');

        // --- Timer (Callback ruft eine eigene Public-Funktion mit der InstanzID auf) ---
        $this->RegisterTimer('UpdateTimer', 0, 'MYM_Update($_IPS[\'TARGET\']);');
    }

    // Bei jedem Speichern der Konfiguration (und beim Start nach KR_READY).
    public function ApplyChanges(): void
    {
        // Diese Zeile nicht entfernen.
        parent::ApplyChanges();

        // --- Statusvariable anlegen (ident wird zum Wiederfinden genutzt, NICHT der Name) ---
        // Rückgabe ist bool (true = neu erstellt) -> ggf. Startwert setzen.
        if ($this->RegisterVariableString('Status', $this->Translate('Status'), '', 10)) {
            $this->SetValue('Status', '');
        }

        // Beispiel: schaltbare Boolean-Variable (löst RequestAction aus)
        if ($this->RegisterVariableBoolean('Switch', $this->Translate('Switch'), '~Switch', 20)) {
            $this->SetValue('Switch', false);
        }
        $this->MaintainAction('Switch', true);

        // --- Timer-Intervall aus Property übernehmen ---
        $interval = $this->ReadPropertyInteger('Interval');
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        // --- Status der Instanz setzen (102 = ok/aktiv; ab 200 = Fehler) ---
        if ($this->ReadPropertyString('Hostname') === '') {
            $this->SetStatus(104); // inaktiv: noch nicht konfiguriert
        } else {
            $this->SetStatus(102); // aktiv
        }
    }

    // Verarbeitet Schaltbefehle aus der Visualisierung / Aktionen.
    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'Switch':
                $this->SetValue('Switch', $value);
                // ... hier echtes Schalten am Gerät umsetzen ...
                break;
            default:
                throw new Exception('Invalid Ident: ' . $ident);
        }
    }

    // Beispiel-Public-Funktion: erreichbar als MYM_Update($id) in PHP/JSON-RPC.
    public function Update(): void
    {
        $host = $this->ReadPropertyString('Hostname');
        $this->SendDebug(__FUNCTION__, 'Updating from ' . $host, 0);

        // ... Daten abrufen/verarbeiten ...
        $result = 'OK @ ' . date('H:i:s');

        $this->WriteAttributeString('LastResponse', $result);
        $this->SetValue('Status', $result);
    }

    // Beispiel-Public-Funktion für den Test-Button im actions-Bereich der form.json.
    public function HelloWorld(): string
    {
        return $this->Translate('Hello World');
    }
}
