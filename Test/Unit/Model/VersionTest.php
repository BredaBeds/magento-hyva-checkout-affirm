<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model;

use Hyva\CheckoutAffirm\Model\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function testVersionMatchesComposerJson(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($composer['version'], Version::VERSION);
    }

    public function testPlatformIntegration(): void
    {
        $this->assertSame('hyva-checkout-affirm/' . Version::VERSION, Version::platformIntegration());
    }
}
