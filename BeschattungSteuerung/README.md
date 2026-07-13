# Beschattung Steuerung

Zentrale Instanz der Beschattungs-Bibliothek. Sie wird **einmal** angelegt; beliebig viele
**Beschattung Fassade**-Instanzen wählen sie in ihrem Formular als „Zentrale Instanz" aus.

## Funktionsumfang

* **Zentrale Werte** (Zeitfenster, Schwellwerte, Sperrzeit, globale Automatik) als
  beschreibbare Statusvariablen – zur Laufzeit in der Visualisierung änderbar und von
  anderen Systemen les-/schreibbar.
* **Wolkenerkennung**: führt eine Helligkeits-Historie, erkennt wechselhafte
  Lichtverhältnisse (Alternativmodus) und ermittelt den Sonnenscheinanteil.
* **Tagesende per Sonnenuntergang** (optional) statt fester „spätestens"-Zeit.
* Stellt allen Fassaden die zentralen Daten gebündelt bereit (`BSTRG_GetCentralData`).
* **TileVisu-Kachel**: Sonnenscheinanteil als Ring-Gauge (50 %-Marke = Alternativmodus-
  Schwelle), Helligkeitswechsel als Balken mit Ein-/Aus-Schwellenmarken, Alternativmodus-
  Status sowie alle zentralen Werte kompakt darunter. Einziges Bedienelement ist der
  globale Automatik-Schalter; editiert wird weiterhin über die vorhandenen Variablen.

## Voraussetzungen

* IP-Symcon ab Version 8.1.
* Ein Helligkeitssensor (Lux) für die Wolkenerkennung (optional, empfohlen).

## Konfiguration

| Bereich | Beschreibung |
|---|---|
| Wolkenerkennung | Helligkeitssensor, Sensor-Einheit (nur Anzeige, z. B. „lx" oder „W/m²"), Sonnig-Schwelle, Wechseltoleranz, Beobachtungszeitraum, Ein-/Aus-Grenzen, Intervall |
| Tagesende / Sonnenuntergang | Sonnenuntergang als Tagesende aktivieren + Sonnenuntergangs-Variable |
| Zuverlässigkeit | Maximales Sensoralter (Staleness-Erkennung) |

Die zentralen Schwellwerte/Zeiten werden als **Variablen** unterhalb der Instanz angelegt
und mit sinnvollen Standardwerten initialisiert. Anpassung erfolgt über die Visualisierung
oder den Button „Zentrale Werte auf Standard zurücksetzen".

## Statusvariablen

`Frühestens`, `Spätestens`, `Sperrzeit`, `Helligkeitsschwelle (Ein/Aus)`,
`Außentemperatur-Schwelle (Ein/Aus)`, `Rundumbeschattungs-Temperatur`,
`Innentemperatur Min/Max`, `Automatik (global)` *(alle editierbar)* sowie
`Alternativmodus aktiv`, `Sonnenscheinanteil`, `Helligkeitswechsel` *(nur lesend)*.

Optional (Panel „Anzeige"): `Statusanzeige` (HTML-Box, erklärt auf einen Blick,
was die aktuellen Werte bedeuten – Sonnenscheinanteil, Helligkeitswechsel im
Beobachtungsfenster sowie die Ein-/Aus-Schwellwerte des Alternativmodus) und
`Protokoll` (History-Log, das jede Aktivierung/Deaktivierung des
Alternativmodus mit Begründung und den zugrunde liegenden Zahlen festhält).

## PHP-Befehle

```php
// Liefert alle zentralen Werte als JSON (intern von den Fassaden genutzt)
string BSTRG_GetCentralData(int $InstanzID);

// Wolkenerkennung sofort neu berechnen
BSTRG_ComputeCloudDetection(int $InstanzID);

// Zentrale Werte auf Standard zurücksetzen
BSTRG_ResetDefaults(int $InstanzID);
```
