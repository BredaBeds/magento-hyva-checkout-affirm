<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\ViewModel;

use Astound\Affirm\Model\Config as AffirmConfig;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm's payment method settings as the Hyvä Checkout method body shows them (current store).
 *
 * Mirrors Affirm's Luma renderer: "Plain Text Appearance" (control) off shows the logo and the admin's info HTML,
 * on shows the title; the inline "edu" widget replaces the default description when enabled (US only).
 */
class Method implements ArgumentInterface
{
    public const MODE_MODAL = 'modal';
    public const MODE_REDIRECT = 'redirect';

    /**
     * Element affirm.js renders the inline checkout widget into.
     */
    public const INLINE_CONTAINER_ID = 'hyva-affirm-inline-checkout';

    /**
     * Markup kept from the admin's "info" HTML; everything else is escaped.
     */
    public const INFO_ALLOWED_TAGS = ['a', 'b', 'br', 'em', 'i', 'p', 'span', 'strong'];

    /**
     * Affirm's default "icon" setting; the method label already carries this module's logo, so only a
     * merchant's own icon is repeated in the body.
     */
    private const AFFIRM_DEFAULT_ICON = 'https://cdn-assets.affirm.com/images/blue_logo-transparent_bg.png';

    /**
     * @param AffirmConfig $affirmConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AffirmConfig $affirmConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Method title (payment/affirm_gateway/title).
     *
     * @return string
     */
    public function getTitle(): string
    {
        $title = $this->text('title');

        return $title !== '' ? $title : 'Affirm';
    }

    /**
     * Whether Affirm's "Plain Text Appearance" is on (no default in Affirm: off shows the logo and info HTML).
     *
     * @return bool
     */
    public function isPlainText(): bool
    {
        return (bool) $this->value('control');
    }

    /**
     * The admin's "info" HTML (filter it with INFO_ALLOWED_TAGS when printing).
     *
     * @return string
     */
    public function getInfo(): string
    {
        return $this->isPlainText() ? '' : $this->text('info');
    }

    /**
     * The merchant's own icon URL, or '' for Affirm's default icon, plain-text mode or a non-https value.
     *
     * @return string
     */
    public function getIconUrl(): string
    {
        $icon = $this->isPlainText() ? '' : $this->text('icon');
        if ($icon === '' || $icon === self::AFFIRM_DEFAULT_ICON || stripos($icon, 'https://') !== 0) {
            return '';
        }

        return $icon;
    }

    /**
     * Checkout flow: "redirect" or "modal" (Affirm stores "Modal" by default, so the comparison ignores case).
     *
     * @return string self::MODE_MODAL|self::MODE_REDIRECT
     */
    public function getMode(): string
    {
        return strtolower($this->text('checkout_flow_type')) === self::MODE_REDIRECT
            ? self::MODE_REDIRECT
            : self::MODE_MODAL;
    }

    /**
     * Whether the shopper leaves the store for Affirm.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->getMode() === self::MODE_REDIRECT;
    }

    /**
     * Whether Affirm's inline checkout widget is shown (Affirm: "edu" on and a US store currency).
     *
     * @return bool
     */
    public function isEduEnabled(): bool
    {
        try {
            return (bool) $this->affirmConfig->getEdu();
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return false;
        }
    }

    /**
     * Affirm's own description for shoppers when the inline widget is off.
     *
     * @return string
     */
    public function getDefaultEduDesc(): string
    {
        try {
            return (string) $this->affirmConfig->getDefaultEduDesc();
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return '';
        }
    }

    /**
     * What happens when the shopper places the order, per checkout flow.
     *
     * @return string
     */
    public function getModeNotice(): string
    {
        return $this->isRedirect()
            ? (string) __('You will be redirected to Affirm to complete your purchase.')
            : (string) __('An Affirm window will open to complete your purchase.');
    }

    /**
     * Body text of the method.
     *
     * Affirm's own description in redirect mode without the widget (it describes the redirect), otherwise the
     * notice for the configured flow.
     *
     * @return string
     */
    public function getCopy(): string
    {
        if ($this->isRedirect() && !$this->isEduEnabled()) {
            $description = $this->getDefaultEduDesc();
            if ($description !== '') {
                return $description;
            }
        }

        return $this->getModeNotice();
    }

    /**
     * Raw payment/affirm_gateway/<field> value for the current store; null when it cannot be read.
     *
     * @param string $field
     * @return mixed
     */
    private function value(string $field): mixed
    {
        try {
            return $this->affirmConfig->getConfigData($field);
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return null;
        }
    }

    /**
     * Trimmed string setting.
     *
     * @param string $field
     * @return string
     */
    private function text(string $field): string
    {
        $value = $this->value($field);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Log a configuration read failure without its message (it may carry store data).
     *
     * @param \Throwable $exception
     * @return void
     */
    private function logFailure(\Throwable $exception): void
    {
        $this->logger->warning(
            'Hyva_CheckoutAffirm: Affirm payment settings could not be read.',
            ['exception' => get_class($exception)]
        );
    }
}
