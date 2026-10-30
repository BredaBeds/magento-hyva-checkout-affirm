<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model;

use Composer\InstalledVersions;

/**
 * Package version sent to Affirm as metadata.platform_integration ("hyva-checkout-affirm/<version>").
 *
 * Read from Composer's record of the installed package, so a release never needs a code change here. A copy
 * installed without Composer (app/code) reports "unknown".
 */
class Version
{
    public const PACKAGE_NAME = 'bredabeds/magento-hyva-checkout-affirm';

    public const INTEGRATION_NAME = 'hyva-checkout-affirm';

    public const UNKNOWN = 'unknown';

    /**
     * The installed package version, for example "0.1.1".
     *
     * @return string
     */
    public static function get(): string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return self::UNKNOWN;
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? self::UNKNOWN;
    }

    /**
     * Value for Affirm's metadata.platform_integration field.
     *
     * @return string
     */
    public static function platformIntegration(): string
    {
        return self::INTEGRATION_NAME . '/' . self::get();
    }
}
