# CLAUDE.md – IP-Symcon Modulentwicklung

> Diese Datei wird von Claude Code automatisch gelesen. Sie enthält alle verbindlichen
> Regeln, Strukturvorgaben und Best Practices für den Bau eines Custom-Moduls für die
> IP-Symcon Hausautomatisierungssoftware. **Lies sie vollständig, bevor du Code änderst.**

---

## 1. Was ist ein IP-Symcon Modul

IP-Symcon verbindet Geräte verschiedener Hersteller über ein einheitliches Schema. Der
Datenfluss folgt immer dem Muster:

```
Geräteinstanz (Gerät)  ->  Gateway (Splitter)  ->  I/O
```

Ein Modul ist PHP-Code, der über das **Module Control** per Repository-URL eingebunden
wird. Eine **Bibliothek** (= dieses Repository) kann **mehrere Module** enthalten.

**Modul-Typen** (`type` in module.json):

| type | Bezeichnung   | Zweck |
|------|---------------|-------|
| 0    | Kern          | Interne Kernmodule (i.d.R. nicht selbst gebaut) |
| 1    | I/O           | Unterste Ebene: physische Verbindung (Serial, TCP/UDP …) |
| 2    | Splitter      | Protokoll-/Gateway-Ebene zwischen I/O und Gerät |
| 3    | Gerät         | Häufigster Typ für eigene Module; legt Statusvariablen an |
| 4    | Konfigurator  | Erstellt vorkonfigurierte Geräte-Instanzen für den Nutzer |
| 5    | Discovery     | Findet Geräte automatisch im Netzwerk |

Für die meisten eigenen Projekte (Sensor auslesen, Werte berechnen, Gerät steuern) ist
**type 3 (Gerät)** der richtige Ausgangspunkt.

---

## 2. Pflicht-Verzeichnisstruktur

Die Struktur ist **zwingend**. Nur bei Einhaltung kann das Module Control die Bibliothek
einlesen. Ordner **ohne** `module.json` werden als fehlerhaft markiert – Ausnahme: die
unten genannten reservierten Ordner und Punkt-Ordner.

```
<Repository-Wurzel = Bibliothek>
├── library.json          (PFLICHT, genau eine, in der Wurzel)
├── README.md             (empfohlen: Überblick + Verweis auf alle Module)
├── MyModule/             (ein Modulordner; Name == Klassenname in module.php)
│   ├── module.php        (PFLICHT)
│   ├── module.json       (PFLICHT)
│   ├── form.json         (optional: Konfigurationsseite)
│   ├── locale.json       (optional: Übersetzungen)
│   └── README.md         (empfohlen: pro Modul)
├── libs/                 (optional, ab 4.2: eigene/externe PHP-Libs, HEX/Trait …)
├── docs/                 (optional, ab 4.2: Dokumente)
├── imgs/                 (optional, ab 4.2: Bilder/Medien)
├── tests/                (optional, ab 4.4: PHPUnit-Tests)
├── actions/              (optional, ab 6.0: Aktionsdefinitionen)
└── .github/              (ignoriert vom Module Control; für CI/Workflows)
```

**Wichtige Regeln zur Struktur:**
- Der **Modulordnername muss identisch zum Klassennamen** in `module.php` sein.
  Einziger erlaubter Unterschied: Leerzeichen im `name` werden im Klassennamen entfernt.
- Reservierte Ordner ohne module.json, die NICHT als Modul interpretiert werden:
  `libs/`, `docs/`, `imgs/`, `tests/`, `actions/`.
- Punkt-Ordner (`.github`, `.style` …) werden ignoriert – ideal für Repo-Tooling.
- In lokaler Entwicklung ist nach Modul-Änderungen ein **kurzer Neustart des
  IP-Symcon-Dienstes** nötig. Bei Update via Repository nicht.

---

## 3. library.json (Kernstück der Bibliothek)

Liegt **in der Wurzel**. Nur die dokumentierten Felder sind erlaubt – andere Felder sind
reserviert und dürfen NICHT verwendet werden.

| Feld            | Typ           | Beschreibung |
|-----------------|---------------|--------------|
| `id`            | string        | Eindeutige GUID der Bibliothek (Format unten) |
| `author`        | string        | Entwicklername |
| `name`          | string        | Name der Bibliothek. Erlaubt: A-Z a-z 0-9 Leerzeichen Unterstrich. Nicht leer; Leerzeichen/Unterstrich nicht am Anfang/Ende |
| `url`           | string        | Homepage; muss mit http:// oder https:// beginnen, oder "" |
| `compatibility` | object        | Mindestens benötigte Kernel-Version/Datum (siehe unten) |
| `version`       | string        | Versionsnummer, Empfehlung Format "Zahl.Zahl", z.B. "1.0" |
| `build`         | integer       | Buildnummer |
| `date`          | integer       | Unix-Zeitstempel |

`compatibility`:
- `version` (optional, string): Mindest-Kernel-Version, z.B. "8.1"
- `date` (optional, integer): Datum als Unix-Timestamp

**GUID-Format:** UUID `8-4-4-4-12`, Zeichen 0-9 und A-F, **nur Großbuchstaben**,
**mit** Bindestrichen **und** geschweiften Klammern. Jede GUID im Repo muss eindeutig sein
(Bibliothek + jedes Modul = je eigene GUID).
Beispiel: `{12345678-90AB-CDEF-1234-567890ABCDEF}`
Generator: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/tools/guid-generator

> **TODO beim Klonen:** Neue GUIDs generieren – niemals die Platzhalter-GUIDs aus dem
> Template übernehmen, sonst kollidieren Bibliotheken.

---

## 4. module.json (Identität des Moduls)

| Feld                 | Typ            | Beschreibung |
|----------------------|----------------|--------------|
| `id`                 | string         | Eindeutige Modul-GUID (eigene, NICHT die der Bibliothek) |
| `name`               | string         | Modulname (gleiche Zeichenregeln wie library `name`) |
| `type`               | integer        | Modultyp 0–5 (siehe Tabelle Abschnitt 1) |
| `vendor`             | string         | Hersteller/Menüpunkt unter "Instanz hinzufügen". Leer => "(Sonstige)" |
| `aliases`            | array[string]  | Zusätzliche Geräte-/Suchnamen |
| `url`                | string         | Doku-URL; http(s):// oder "" |
| `parentRequirements` | array[string]  | Datenfluss-GUIDs für kompatible übergeordnete Instanzen |
| `childRequirements`  | array[string]  | Datenfluss-GUIDs für kompatible untergeordnete Instanzen |
| `implemented`        | array[string]  | Unterstützte Datenfluss-GUIDs (müssen in ReceiveData/ForwardData ausgewertet werden) |
| `prefix`             | string         | Funktions-Prefix, nur Zahlen+Buchstaben. Exportierte Funktionen heißen `PREFIX_Funktion($id, …)` |

Für ein einfaches eigenständiges Gerät (type 3, ohne Datenfluss zu Parent/Child) bleiben
`parentRequirements`, `childRequirements`, `implemented` leer (`[]`).

---

## 5. module.php (die Klasse)

### Basisklasse: IPSModuleStrict verwenden
Seit IP-Symcon **8.1** gibt es die verbesserte Basisklasse **`IPSModuleStrict`**.
**Für neue Module immer `IPSModuleStrict` nutzen**, nicht das alte `IPSModule`.

| Merkmal | IPSModule (alt) | IPSModuleStrict (neu, ab 8.1) |
|---------|-----------------|-------------------------------|
| Type Hints | optional | **immer erforderlich** |
| Fehlende Type Hints | nur Warnung | **Fehler** |
| Alte Typen | Integer/Boolean erlaubt | **int/bool benutzen** |
| Rückgabe `RegisterVariable*` | Variablen-ID (int) | **bool** (ob neu erstellt → ggf. Startwert setzen) |
| Schreibzugriff auf Variablen | immer (auch per SetValue extern) | **nur über `$this->SetValue`** (Variablen ReadOnly) |
| Datenfluss-Verbindung | manuell (ConnectParent/RequireParent/ForceParent) | **automatisch** über Kompatibilität + `GetCompatibleParents()` |
| Datenfluss-Kodierung | UTF-8 (problematisch ab PHP 9) | **HEX** (`bin2hex`/`hex2bin`) |

### Namensregeln (hart)
- **Klassenname == `name` aus module.json** (Leerzeichen entfernt).
- Funktionsnamen nur aus `a-z A-Z 0-9`.
- `$InstanceID` darf **nicht** als Parametername verwendet werden.

### Lebenszyklus-Methoden
- `Create()`: einmal bei Erstellung. Hier `RegisterProperty*`, `RegisterAttribute*`,
  `RegisterTimer`, `RegisterVariable*` (wenn unabhängig von Properties). **Immer
  `parent::Create();` als erste Zeile.**
- `ApplyChanges()`: bei jedem Speichern der Konfiguration. Hier Variablen/Timer abhängig
  von Properties anlegen, Verbindungen prüfen, Status setzen. **Immer
  `parent::ApplyChanges();` als erste Zeile.**
- `Destroy()`: beim Löschen (z.B. Profile aufräumen). `parent::Destroy();` nicht vergessen.

### Minimal-Vorlage
```php
<?php

declare(strict_types=1);

class MyModule extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        // Properties, Attribute, Timer hier registrieren
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Variablen/Aktionen abhängig von Properties anlegen, Status setzen
    }

    // Wird als PREFIX_MeineFunktion($id) in PHP und JSON-RPC verfügbar
    public function MeineFunktion(): void
    {
        echo $this->InstanceID;
    }
}
```

---

## 6. form.json (Konfigurationsseite, optional)

Drei optionale Bereiche – jeden nur definieren, wenn er sichtbar sein soll:

- **`elements`**: Felder, die Properties setzen. `name` eines Feldes == Property-Name
  (wird per `IPS_SetProperty` gesetzt, lesbar per `ReadProperty*`).
- **`actions`**: Testumgebung. Verändert KEINE Properties. Nutzbar erst, nachdem
  Änderungen im elements-Bereich übernommen wurden. Ideal für Test-Buttons.
- **`status`**: Statusmeldungen (Code + Icon + Caption), KEINE Formularfelder.

Grundgerüst:
```json
{
    "elements": [],
    "actions": [],
    "status": []
}
```

Häufige Formularfeld-Typen: `ValidationTextBox`, `PasswordTextBox`, `NumberSpinner`,
`CheckBox`, `Select`, `List`, `Button`, `Label`, `ExpansionPanel`, `RowLayout`,
`SelectVariable`, `SelectInstance`, `SelectColor`, `Configurator`, `Tree`, `Image`.
Vollständige Referenz:
https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/konfigurationsformulare/

---

## 7. locale.json (Übersetzungen, optional, ab 4.1)

Übersetzt `caption`/`label` der Konfigurationsseite. Konvention: **Modul auf Englisch
bauen, ins Deutsche übersetzen.** Abstufende Kürzel möglich (`de`, `de_DE`, `de_CH` …).

```json
{
    "translations": {
        "de": {
            "Hello World": "Hallo Welt"
        }
    }
}
```

---

## 8. Verbindliche Best Practices (Symcon-Konvention)

**Generelle Entwicklung**
- Module auf Englisch entwickeln, per locale.json übersetzen.
- Fehler nie still mit `@` unterdrücken, außer der Rückgabewert wird geprüft und ein
  echter Fehler wird dem Nutzer gemeldet.
- Daten, die der Nutzer nicht braucht (Puffer/Temp), gehören nicht in Variablen –
  stattdessen `SetBuffer`/`GetBuffer` (ggf. JSON-kodiert für mehrere Werte).
- library.json/module.json: **nur** dokumentierte Felder.
- Externe Abhängigkeiten **vollständig** in `libs/` mitliefern. Eine Bibliothek muss in
  sich geschlossen und ohne Fremdbibliotheken installierbar/funktionsfähig sein.
- Objekte **niemals über den Namen** finden – immer einen **Ident** setzen und verwenden.
- In `Create()`/`ApplyChanges()` **nicht** darauf vertrauen, dass andere Instanzen schon
  existieren (zufällige Startreihenfolge → "InstanceInterface is not available"). Prüfen,
  ob Kernel-Runlevel `KR_READY` ist, oder via `RegisterMessage` + `MessageSink` auf
  `IPS_KERNELSTARTED` reagieren. Gilt auch nach Modul-Updates. Kein
  `SendDataToParent`/`SendDataToChildren` vor `KR_READY`.
- Zum Schalten möglichst `RequestAction` statt eigener Public-Funktionen verwenden.

**Hoheit des Nutzers wahren**
- Niemals automatisch Variablen im Archiv (Logging) aktivieren – das entscheidet der Nutzer.
- Sichtbarkeit/Bedienbarkeit von Objekten möglichst nicht verändern; falls doch, dokumentieren.
- Name/Position von Variablen nur über `RegisterVariable*` vorgeben; Umsortieren obliegt dem Nutzer.
- Nie automatisch andere Instanzen erstellen (Ausnahme: RequireParent/ConnectParent/ForceParent
  für die Datenflusskette). Sonst nur per Button im actions-Bereich oder via Konfigurator.
- Properties dienen dem Nutzer; ein Modul konfiguriert sich nicht selbst um
  (kein `IPS_SetProperty`/`IPS_SetConfiguration` auf sich selbst).

**Datenfluss & Sandboxing**
- Data-Filter setzen, um Last gering zu halten: `SetReceiveDataFilter`, `SetForwardDataFilter`.
- Eine Instanz darf nur Objekte **direkt unterhalb sich selbst** verändern. Niemals fremde
  Variablen anderer Instanzen per `SetValue` ändern – dafür den Datenfluss nutzen.
- Andere Instanzen nur ansprechen, wenn der Nutzer sie explizit im Formular ausgewählt hat.

**Usability**
- Objekte nur **direkt** unter der Instanz anlegen (keine tiefe Verschachtelung – Problem
  in der mobilen Ansicht).
- Schaltbefehle möglichst über den actions-Bereich des Formulars testbar machen.

**Profile**
- Profile als `PREFIX.NAME` benennen. Instanzgebundene Profile zusätzlich mit `.<InstanzID>`.
  Nicht mehr benötigte Profile (z.B. beim Löschen der Instanz) wieder entfernen.

---

## 9. Häufig genutzte SDK-Funktionen (Cheat Sheet)

**Properties** (Nutzer-Konfiguration, in Create registrieren, in form.json als element):
`RegisterPropertyBoolean/Integer/Float/String(name, default)` →
`ReadPropertyBoolean/Integer/Float/String(name)`

**Attribute** (interne Persistenz, nicht im Formular):
`RegisterAttribute…(name, default)` / `ReadAttribute…(name)` / `WriteAttribute…(name, value)`

**Statusvariablen** (für den Nutzer sichtbar):
`RegisterVariableBoolean/Integer/Float/String(ident, name, profile = '', position = 0)`
→ Wert setzen mit `$this->SetValue(ident, value)`, lesen mit `GetValue(ident)`.
`MaintainVariable(...)` legt an/entfernt abhängig von einer Bedingung.
`MaintainAction(ident, true)` macht eine Variable schaltbar (→ `RequestAction`).

**Timer:** `RegisterTimer(name, interval_ms, "PREFIX_Funktion(\$_IPS['TARGET']);")`,
`SetTimerInterval(name, ms)` (0 = aus), `RegisterOnceTimer(...)`.

**Schalten:** `RequestAction($ident, $value)` überschreiben, um Variablenänderungen zu verarbeiten.

**Debug/Log:** `SendDebug(message, data, format)` (Debug-Fenster), `LogMessage(text, type)`
(System-Meldungen).

**Status:** `SetStatus(code)` (102 = aktiv/ok; 104 = inaktiv; ab 200 = Fehler) mit
passender Statusmeldung in form.json.

**Formular nachladen:** `ReloadForm()`, einzelnes Feld ändern: `UpdateFormField(...)`.

**Referenzen:** `RegisterReference(id)` für referenzierte Objekte (Aufräum-Sicherheit).

Vollständige Funktionsreferenz:
https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/

---

## 10. Tests & Code-Style (CI)

- **Style:** PHP-CS-Fixer mit den Regeln aus https://github.com/symcon/StylePHP.
- **Tests:** Basis auf https://github.com/symcon/SymconStubs. Die Stubs bieten eine
  Basis-Validierung, die library.json und module.json jedes Moduls prüft (sehr empfohlen).
- Beide laufen automatisch via GitHub Actions (siehe `.github/workflows/`).

---

## 11. Workflow für Claude Code (token-effizient)

Bevorzugter Single-Pass-Ablauf für ein neues Modul:
1. Modultyp festlegen (meist type 3 Gerät).
2. **Neue GUIDs generieren** für library.json und jedes Modul (Platzhalter ersetzen).
3. `library.json` ausfüllen (name, author, version, compatibility.version z.B. "8.1").
4. Modulordner umbenennen → identisch zum geplanten Klassennamen.
5. `module.json` ausfüllen (id, name, type, prefix, vendor).
6. `module.php`: Klasse = Ordnername; `Create()`/`ApplyChanges()`; Properties, Variablen,
   Timer; Logik in eigenen Funktionen oder `RequestAction`.
7. `form.json`: elements (Properties), bei Bedarf actions (Test-Buttons), status.
8. `locale.json`: deutsche Übersetzungen ergänzen.
9. README pro Modul: Funktionsumfang, Voraussetzungen, Kompatibilität, Modul-URL,
   Konfigurationsoptionen, exportierte PHP-Befehle.
10. Einbinden in IP-Symcon über Module Control per Repository-URL; Dienst neu starten.

**Prinzip:** Erst die statischen JSON-Dateien vollständig, dann module.php in einem Zug.
Vermeide Halbzustände (z.B. Klassenname ≠ Ordnername), da das Modul sonst als fehlerhaft
markiert wird.

---

## 12. Quellen (offiziell)

- SDK (PHP): https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/
- Struktur: …/sdk-php/struktur/
- Bibliotheken: …/sdk-php/bibliotheken/
- Module + Funktionsreferenz: …/sdk-php/module/
- Konfigurationsformulare: …/sdk-php/konfigurationsformulare/
- Lokalisierungen: …/sdk-php/lokalisierungen/
- Befehlsreferenz: https://www.symcon.de/de/service/dokumentation/befehlsreferenz/
- Best Practices (paresy): https://gist.github.com/paresy/236bfbfcb26e6936eaae919b3cfdfc4f
- Referenz-Repo: https://github.com/symcon/Rechenmodule
- Stubs/Tests: https://github.com/symcon/SymconStubs · Style: https://github.com/symcon/StylePHP
