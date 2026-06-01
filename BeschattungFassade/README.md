# Beschattung Fassade

Gerätemodul für die automatische Beschattung **einer Fassade / Fenstergruppe**. Es wird
pro Fassade einmal angelegt und mit einer übergeordneten **Beschattung Steuerung**-Instanz
verbunden (zentrale Werte & Wolkenerkennung).

## Funktionsumfang

* Sonnenstandsbasierte Beschattung anhand **Azimut/Elevation** (Standortmodul) und der
  Hausgeometrie (Dachvorsprung, Endwinkel, Fensterausrichtung).
* Helligkeits- und Temperaturbedingungen mit **Hysterese**.
* **Rundumbeschattung** ab hoher Außentemperatur (Sonnenstand wird dann ignoriert).
* **Innentemperatur-Logik** (min/max) nach MyHomeControl-Vorbild.
* **Alternativmodus** bei Wolken (Sonnenscheinanteil > 50 %, Sonnenstand bleibt Bedingung).
* **Sperrzeit** gegen häufiges Fahren; **Handbetrieb-Erkennung** mit Pause.
* **Tagesende**: Behänge öffnen oder Position halten.
* Mehrere Aktoren je Fassade mit optionaler **individueller Beschattungsposition** und
  optionaler Handbetrieb-Sperrvariable.
* **Fail-Safe** bei ungültigen/veralteten Sensoren (Position halten / öffnen / beschatten).
* Reservierte Wind-/Regen-Eingänge (noch ohne aktive Logik).

## Voraussetzungen

* IP-Symcon ab Version 8.1.
* Eine **Beschattung Steuerung**-Instanz als Parent.
* Azimut- und Elevations-Variablen (Symcon-Standortmodul).
* Aktor-Variable(n) mit Positionswert `0 = offen … 100 = geschlossen`.

## Konfiguration (Auszug)

| Bereich | Inhalt |
|---|---|
| Sonnenstand & Sensoren | Azimut, Elevation; optional Helligkeit, Außen-/Innentemperatur |
| Ausrichtung | Fassadenrichtung, Beschattungswinkel links/rechts |
| Geometrie | Dachhöhe, Dachvorsprung, Fensterbretthöhe, Endwinkel |
| Aktoren & Positionen | Aktorliste, Beschattungs-/Offen-Position (Default 70 % / 0 %) |
| Verhalten & Sicherheit | Tagesende, Fail-Safe, Intervall, Sensoralter, mittlere Außentemp., Handbetrieb, Anzeige |

## Statusvariablen

`Automatik` *(schaltbar)*, `Beschattet`, `Zielposition`, `Sonne auf Fenster`,
`Helligkeit ausreichend`, `Temperaturbedingung erfüllt`, `Sperrzeit übrig`,
`Letzte Bewegung`, optional `Handbetrieb aktiv`, `Statusanzeige` (HTML), `Protokoll`.

## Statuscodes

| Code | Bedeutung |
|---|---|
| 102 | Aktiv |
| 104 | Inaktiv / keine Steuerung verbunden |
| 201 | Sonnenstandsquelle ungültig |
| 202 | Kritischer Sensor ungültig oder veraltet |

## PHP-Befehle

```php
BSFAS_Evaluate(int $InstanzID);          // Auswertung jetzt ausführen
BSFAS_TestMove(int $InstanzID, bool $Shade); // Testfahrt (true=beschatten, false=öffnen)
BSFAS_ResetLock(int $InstanzID);         // Sperrzeit zurücksetzen
```
