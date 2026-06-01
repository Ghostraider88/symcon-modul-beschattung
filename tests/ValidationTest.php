<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Basis-Validierung der Bibliothek.
 *
 * Nutzt das SymconStubs-Submodul (siehe tests/README.md) und prüft, ob library.json
 * und alle module.json den Anforderungen entsprechen. Sehr empfohlen laut Symcon
 * Best Practices.
 */
class ValidationTest extends TestCase
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
}
