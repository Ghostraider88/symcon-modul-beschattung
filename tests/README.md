# Tests

Die CI nutzt zwei Hilfs-Repositories von Symcon als **Git-Submodule**. Nach dem Klonen
dieses Templates einmalig einrichten:

```bash
# Stubs für die Test-/Validierungsumgebung (PHPUnit)
git submodule add https://github.com/symcon/SymconStubs.git tests/stubs

# Style-Regeln (PHP-CS-Fixer-Konfiguration) für den Style-Check
git submodule add https://github.com/symcon/StylePHP.git .style

git submodule update --init --recursive
```

Anschließend in `phpunit.xml` (Repo-Wurzel) die Bootstrap-Datei der Stubs einbinden,
damit `IPSModuleStrict`, `validateLibrary()` etc. zur Verfügung stehen. Orientierung
bieten die offiziellen Repos, z.B. https://github.com/symcon/Rechenmodule.

Lokal ausführen:

```bash
vendor/bin/phpunit
```

Die GitHub-Actions-Workflows (`.github/workflows/tests.yml`, `style.yml`) erledigen das
automatisch bei jedem Push/Pull-Request.
