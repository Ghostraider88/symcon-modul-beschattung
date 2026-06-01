<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

/**
 * Basis-Validierung der Bibliothek und aller enthaltenen Module.
 *
 * Nutzt das SymconStubs-Submodul (siehe tests/README.md) und prüft library.json
 * sowie jede module.json + module.php (u.a. Pflicht-Type-Hints) gemäß den
 * Symcon Best Practices.
 */
class ValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateSteuerung(): void
    {
        $this->validateModule(__DIR__ . '/../BeschattungSteuerung');
    }

    public function testValidateFassade(): void
    {
        $this->validateModule(__DIR__ . '/../BeschattungFassade');
    }
}
