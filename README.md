# Beschattung – IP-Symcon Bibliothek

[![Check Style](https://github.com/Ghostraider88/symcon-modul-beschattung/actions/workflows/style.yml/badge.svg)](https://github.com/Ghostraider88/symcon-modul-beschattung/actions/workflows/style.yml)
[![Run Tests](https://github.com/Ghostraider88/symcon-modul-beschattung/actions/workflows/tests.yml/badge.svg)](https://github.com/Ghostraider88/symcon-modul-beschattung/actions/workflows/tests.yml)

Sonnenstands-, helligkeits- und temperaturabhängige **Beschattungsautomatik** für
IP-Symcon. Fachlich an die Beschattungslogik von *MyHomeControl* angelehnt und aus einer
gewachsenen Skriptsammlung in ein sauberes, mehrfach instanziierbares Modul überführt
(keine hartcodierten Objekt-IDs, vollständig über Properties konfigurierbar).

## Enthaltene Module

| Modul | Typ | Beschreibung | Doku |
|-------|-----|--------------|------|
| Beschattung Steuerung | Splitter | Zentrale Werte + Wolkenerkennung (einmal anlegen) | [README](BeschattungSteuerung/README.md) |
| Beschattung Fassade | Gerät | Beschattungslogik pro Fassade (beliebig viele) | [README](BeschattungFassade/README.md) |

## Architektur

```
Beschattung Steuerung (1×)
  ├── zentrale Werte (editierbare Variablen)
  ├── Wolkenerkennung → Alternativmodus / Sonnenscheinanteil
  └── Beschattung Fassade (n×)   ← jeweils eine Fassade/Fenstergruppe
         ├── Sonnenstand (Azimut/Elevation aus Standortmodul)
         ├── Helligkeit / Außen- / Innentemperatur (optional)
         └── Aktoren (0 = offen … 100 = geschlossen)
```

Jede Fassade ist eine eigene Geräteinstanz mit der Steuerung als Parent. Die zentrale
Wolkenerkennung und alle zentralen Schwellwerte gelten für alle Fassaden gemeinsam.

## Funktionsmerkmale

* Sonnenstand + Hausgeometrie (Dachvorsprung/Endwinkel/Ausrichtung), Helligkeits- und
  Temperatur-Hysterese, Rundumbeschattung, Innentemperatur-Logik (min/max).
* Wolkenerkennung mit Alternativmodus (Sonnenscheinanteil), Sperrzeit, Zeitfenster,
  optional Sonnenuntergang als Tagesende, Abendverhalten (öffnen / halten).
* Handbetrieb-Erkennung, mittlere Außentemperatur über N Tage.
* Robustes Fail-Safe-Verhalten bei fehlenden/ungültigen/veralteten Sensoren.

## Installation

Über das Module Control (Kerninstanz) die Repository-URL hinzufügen:

```
https://github.com/Ghostraider88/symcon-modul-beschattung
```

Anschließend zuerst eine **Beschattung Steuerung**-Instanz anlegen, dann pro Fassade eine
**Beschattung Fassade**-Instanz (Parent = Steuerung).

## Voraussetzungen

* IP-Symcon ab Version 8.1
* Symcon-Standortmodul (liefert Azimut/Elevation)

## Entwicklung

Struktur- und Codiervorgaben stehen in [`CLAUDE.md`](CLAUDE.md). Tests/Validierung lokal:

```bash
git submodule update --init --recursive
composer install
vendor/bin/phpunit
```

Der Ordner `Symcon Legacy PHP Scripts/` enthält die ursprünglichen Skripte und die
MyHomeControl-Referenz-PDFs (Grundlage der Portierung).

## Lizenz

MIT
