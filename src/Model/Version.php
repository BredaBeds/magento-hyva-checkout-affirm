<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model;

/**
 * Package version sent to Affirm as metadata.platform_integration ("hyva-checkout-affirm/<VERSION>").
 *
 * Kept in sync with composer.json "version" (a unit test enforces it), so no file is read at runtime.
 */
class Version
{
    public const VERSION = '0.1.0';

    public const INTEGRATION_NAME = 'hyva-checkout-affirm';

    /**
     * Value for Affirm's metadata.platform_integration field.
     *
     * @return string
     */
    public static function platformIntegration(): string
    {
        return self::INTEGRATION_NAME . '/' . self::VERSION;
    }
}
