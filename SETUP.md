# SETUP – Template für ein neues Modul verwenden

Kurze Checkliste, wenn du dieses Template als Basis für ein neues Symcon-Modul nimmst.
Die ausführlichen Regeln stehen in [`CLAUDE.md`](CLAUDE.md).

## 1. Repository anlegen
- [ ] Dieses Template als GitHub-Template-Repo markieren (Settings → "Template repository")
      oder Inhalt in ein neues Repo kopieren.
- [ ] In `README.md` und allen JSON-Dateien `DEIN-USER/DEIN-REPO` ersetzen.

## 2. GUIDs ersetzen (wichtig!)
- [ ] Neue GUID für `library.json` → `id`
- [ ] Neue GUID für jedes Modul in `*/module.json` → `id`
- Generator: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/tools/guid-generator
- Die Platzhalter `{00000000-...-0000000000XX}` dürfen NICHT bleiben.

## 3. Modul benennen
- [ ] Ordner `MyModule/` umbenennen → exakter Klassenname (keine Leerzeichen).
- [ ] In `module.json`: `name` (mit ggf. Leerzeichen), `type`, `prefix`, `vendor` setzen.
- [ ] In `module.php`: Klassenname == Ordnername; alle Vorkommen des Prefix `MYM_` anpassen.

## 4. Konfiguration & Logik
- [ ] `form.json`: Properties als `elements`, Test-Buttons als `actions`, `status`-Codes.
- [ ] `locale.json`: deutsche Übersetzungen.
- [ ] `module.php`: Properties/Variablen/Timer in `Create()`/`ApplyChanges()`, Logik in
      eigenen Funktionen bzw. `RequestAction`.

## 5. Tests & Style (optional, empfohlen)
- [ ] Submodule einrichten (siehe `tests/README.md`):
      `SymconStubs` → `tests/stubs`, `StylePHP` → `.style`.
- [ ] Lokal `vendor/bin/phpunit` ausführen.

## 6. In IP-Symcon einbinden
- [ ] Module Control öffnen → Repository-URL hinzufügen.
- [ ] Lokale Entwicklung: nach Code-Änderung IP-Symcon-Dienst kurz neu starten.

## 7. Dokumentation
- [ ] `MyModule/README.md` ausfüllen (Funktionsumfang, Voraussetzungen, Variablen, PHP-Befehle).
- [ ] Wurzel-`README.md`: Modul in die Tabelle eintragen.
