# Beschattung Fassade

Gerätemodul für die automatische Beschattung **einer Fassade / Fenstergruppe**. Es wird
pro Fassade einmal angelegt; im Konfigurationsformular wird oben die zentrale
**Beschattung Steuerung**-Instanz ausgewählt (zentrale Werte & Wolkenerkennung).

## Funktionsumfang

* Sonnenstandsbasierte Beschattung anhand **Azimut/Elevation** (Standortmodul) und der
  Hausgeometrie (Dachvorsprung, Endwinkel, Fensterausrichtung).
* Helligkeits- und Temperaturbedingungen mit **Hysterese**. Ohne weitere Option bleibt
  die Außentemperatur-Bedingung ab Überschreiten der Ein-Schwelle so lange erfüllt, bis
  der Wert unter die Aus-Schwelle fällt – fällt die Temperatur nachts nie so weit
  (Tropennacht), gilt sie auch am nächsten Morgen noch als erfüllt. Optional lässt sich
  diese Hysterese täglich zurücksetzen (siehe „Verhalten & Sicherheit"), damit jeder Tag
  neu bei „nicht erfüllt" beginnt.
* **Helligkeits-Ersatzsensor** (optional, Checkbox „No brightness sensor above: fall
  back to …", Standard aus): Ist an dieser Fassade kein eigener Helligkeitssensor
  ausgewählt, kann sie stattdessen den zentralen Wolken-/Helligkeitssensor der
  „Beschattung Steuerung" mit eigenen Ein-/Aus-Schwellen nutzen (da dieser meist
  eine andere Einheit hat als ein Lux-Sensor). So bleibt die Helligkeits-Bedingung
  wirksam, statt komplett ignoriert zu werden – ist auch der zentrale Sensor nicht
  konfiguriert/veraltet, wird die Bedingung wie ohne Option ignoriert (kein
  Fail-Safe wegen eines gemeinsam genutzten Sensors). Die Kachel kennzeichnet einen
  genutzten Ersatzsensor mit 🔗. Ohne aktivierte Option verhält sich eine Fassade
  ohne eigenen Sensor wie bisher.
* **Rundumbeschattung** ab hoher Außentemperatur (Sonnenstand wird dann ignoriert). Optional
  (Checkbox in „Overrides", Standard aus) wird sie NICHT erzwungen, wenn der zentrale
  Wolken-/Helligkeitssensor gerade unter dessen Sonnig-Schwelle liegt – z. B. an einem
  bedeckten Abend, an dem die Lufttemperatur zwar noch hoch ist, aber keine nennenswerte
  Sonne mehr scheint und geschlossen halten daher keinen zusätzlichen Hitzeschutz mehr
  bringt. Die Entscheidung fällt dann normal über Helligkeit/Temperatur/Sonnenstand; ohne
  verfügbaren zentralen Sensor bleibt die Rundumbeschattung sicherheitshalber aktiv.
* **Innentemperatur-Logik** (min/max) nach MyHomeControl-Vorbild.
* **Alternativmodus** bei Wolken: der über ein Zeitfenster gemittelte
  Sonnenscheinanteil (> 50 %) ersetzt bei aktivem Alternativmodus vollständig die
  (durch Wolken kurzfristig flackernde) Momentan-Helligkeit als Bedingung;
  Sonnenstand bleibt weiterhin Voraussetzung.
* **Sperrzeit** gegen häufiges Fahren; **Handbetrieb-Erkennung** mit Pause.
* **Entscheidungs-Bestätigungszeit** (`DecisionConfirmMinutes`, Default 10 min): Ein
  Wechsel der Entscheidung (beschatten ↔ öffnen) wird erst tatsächlich gefahren,
  wenn die zugrunde liegende Rohentscheidung mindestens so lange laufend
  unverändert war. Kurze Ausreißer (z. B. einzelne durchziehende Wolken) werden so
  ignoriert – das bekannte Pingpong im Sperrzeit-Takt bei wechselhafter Bewölkung
  entfällt. Gilt einheitlich für alle Entscheidungspfade inkl. Rundumbeschattung;
  die Sonderfälle „Automatik aus", Handbetrieb, Tagesende und Fail-Safe greifen
  weiterhin sofort. 0 = deaktiviert (altes Verhalten).
* **Tagesende**: Behänge öffnen oder Position halten.
* Mehrere Aktoren je Fassade mit optionalem **Anzeigenamen** (z. B. „Fenster links",
  „Kinderzimmer" – überschreibt den sonst automatisch aus der übergeordneten
  Geräte-Instanz ermittelten, oft technischen Namen), optionaler **individueller
  Beschattungsposition** und optionaler **Sperrvariable je Aktor**
  („Kinderzimmer"-Funktion): Solange die zugeordnete Variable `True` ist, bleibt
  **dieser** Rollladen unverändert stehen – die übrigen Aktoren/Fassaden fahren
  normal weiter. Wird die Variable wieder `False`, zieht der Aktor automatisch in
  die aktuell gültige Position nach (ohne dabei als Fahrt gezählt zu werden, falls
  er sich ohnehin schon in der Zielposition befindet).
* **Überschreibung zentraler Werte je Fassade** (optional): Einzelne Fassaden können
  von den Werten der zentralen „Beschattung Steuerung"-Instanz abweichen – z. B.
  Frühestens-/Spätestens-Zeit (wahlweise als feste Uhrzeit oder als
  Sonnenuntergang einer beliebigen Standortmodul-Variable), Sperrzeit,
  Helligkeits-/Außentemperatur-Schwellen, Rundumbeschattungs-Temperatur und
  Innentemperatur-Bereich. Nicht überschriebene Werte folgen weiterhin der
  zentralen Instanz.
* **Fail-Safe** bei ungültigen/veralteten Sensoren (Position halten / öffnen / beschatten).
* Reservierte Wind-/Regen-Eingänge (noch ohne aktive Logik).
* **TileVisu-Kachel**: Die Instanz erscheint als grafische Kachel in der TileVisu –
  Zustand, Zielposition und Entscheidungsgrund, Ist-Position je Aktor (inkl. 🔒 bei
  gesperrten), ein Draufsicht-Kompass (Sonnenstand relativ zum Beschattungsfenster,
  inkl. bisheriger Sonnenbahn der letzten 3 Std. und kurzer Prognose der nächsten
  Stunde) mit maßstäblich nach Länge/Breite/Firstrichtung gezeichneter Hausform und
  passendem Dach-Grundriss-Symbol (First bei Sattel, First+Diagonalen bei Walm, betonte
  hohe Traufe mit Gefälle-Linien bei Pult – Werte aus der zentralen Instanz, siehe dort)
  und ein Hausquerschnitt (Sonnenelevation gegen Dachvorsprung/kritischen
  Winkel), darunter Ist-/Soll-Zeilen für Helligkeit, Außen-/Innentemperatur,
  Zeitfenster und Sperrzeit mit ✓/✗ – Zeilen, die gerade nicht ausschlaggebend sind
  (z. B. Helligkeit bei Rundumbeschattung), werden ausgegraut. Fußzeile zeigt u. a.
  Fahrten und kumulierte Beschattungsdauer des heutigen Tages, darunter die
  Ist-Position je Aktor. Das Protokoll ist nicht mehr dauerhaft sichtbar, sondern
  über einen Button als Popup abrufbar, um die Kachel übersichtlich zu halten.
  Einziges Bedienelement ist der Automatik-Schalter. Alle bisherigen
  Statusvariablen bleiben unverändert bestehen.
* **Sensor-Sättigungswarnung**: Bleibt der Helligkeitssensor trotz hohem Sonnenstand
  länger als 30 Minuten exakt unverändert (typisch bei einem Sensor am Ende seines
  Messbereichs), zeigt die Kachel ein ⚠️ an der Helligkeitszeile – rein diagnostisch,
  beeinflusst keine Entscheidung.

## Voraussetzungen

* IP-Symcon ab Version 8.1.
* Eine **Beschattung Steuerung**-Instanz (im Formular unter „Zentrale Instanz" auswählen).
* Azimut- und Elevations-Variablen (Symcon-Standortmodul).
* Aktor-Variable(n) mit Positionswert `0 = offen … 100 = geschlossen`.

## Konfiguration (Auszug)

| Bereich | Inhalt |
|---|---|
| Zentrale Instanz | Auswahl der „Beschattung Steuerung" |
| Sonnenstand & Sensoren | Azimut, Elevation; optional Helligkeit, Außen-/Innentemperatur |
| Ausrichtung | Fassadenrichtung, Beschattungswinkel links/rechts |
| Geometrie | Dachhöhe, Dachvorsprung, Fensterbretthöhe, Endwinkel |
| Aktoren & Positionen | Aktorliste (je Aktor Variable, Anzeigename, individuelle Position, Sperrvariable), Beschattungs-/Offen-Position (Default 70 % / 0 %) |
| Überschreibungen | Je Fassade optional: Frühestens/Spätestens (fest oder Sonnenuntergang), Sperrzeit, Helligkeits-/Außentemperatur-Schwellen, Rundumbeschattungs-Temperatur, Innentemperatur-Bereich, Rundumbeschattung bei geringer Helligkeit nicht erzwingen |
| Verhalten & Sicherheit | Tagesende, Fail-Safe, Intervall, Sensoralter, mittlere Außentemp., Handbetrieb, Entscheidungs-Bestätigungszeit, Anzeige |

## Statusvariablen

`Automatik` *(schaltbar)*, `Beschattet`, `Zielposition`, `Sonne auf Fenster`,
`Helligkeit ausreichend`, `Temperaturbedingung erfüllt`, `Sperrzeit übrig`,
`Letzte Bewegung`, optional `Handbetrieb aktiv`, `Protokoll`. Die alte HTML-
`Statusanzeige` ist standardmäßig aus (die TileVisu-Kachel deckt das inzwischen ab),
lässt sich bei Bedarf im Panel „Verhalten & Sicherheit" wieder aktivieren.

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
