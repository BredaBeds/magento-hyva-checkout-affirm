<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model;

use Composer\InstalledVersions;
use Hyva\CheckoutAffirm\Model\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function testPackageNameMatchesComposerJson(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($composer['name'], Version::PACKAGE_NAME);
    }

    public function testVersionComesFromComposer(): void
    {
        $expected = InstalledVersions::isInstalled(Version::PACKAGE_NAME)
            ? InstalledVersions::getPrettyVersion(Version::PACKAGE_NAME)
            : Version::UNKNOWN;

        $this->assertSame($expected, Version::get());
        $this->assertNotSame('', Version::get());
    }

    public function testPlatformIntegration(): void
    {
        $this->assertSame('hyva-checkout-affirm/' . Version::get(), Version::platformIntegration());
    }
}
