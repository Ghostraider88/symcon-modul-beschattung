# MyModule

[Hier in 1–2 Sätzen beschreiben, was das Modul tut.]

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* [Was kann das Modul? Stichpunkte.]

### 2. Voraussetzungen

* IP-Symcon ab Version 8.1
* [ggf. unterstützte Geräte/Hardware]

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen:
  `https://github.com/DEIN-USER/DEIN-REPO`

### 4. Einrichten der Instanzen in IP-Symcon

* Unter "Instanz hinzufügen" ist 'MyModule' unter dem Hersteller '(Sonstige)' aufgeführt.

__Konfigurationsseite:__

| Name     | Beschreibung                         |
|----------|--------------------------------------|
| Hostname | Adresse des Zielgeräts               |
| Interval | Aktualisierungsintervall in Sekunden |

### 5. Statusvariablen und Profile

Die Statusvariablen werden automatisch angelegt. Das Löschen einzelner kann zu
Fehlfunktionen führen.

| Name   | Typ     | Beschreibung                |
|--------|---------|-----------------------------|
| Status | String  | Letztes Abrufergebnis       |
| Switch | Boolean | Schaltbarer Beispielzustand |

### 6. PHP-Befehlsreferenz

`MYM_Update(int $InstanzID): void;`
Führt eine sofortige Aktualisierung aus.

`MYM_HelloWorld(int $InstanzID): string;`
Gibt einen Beispieltext zurück.
