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
 * Configuration for the window.hyvaAffirm loader on the checkout: Affirm's documented _affirm_config for the
 * current store (mode, store currency).
 */
class Sdk implements ArgumentInterface
{
    /**
     * The loader template.
     */
    public const LOADER_TEMPLATE = 'Hyva_CheckoutAffirm::js/sdk-loader.phtml';

    /**
     * @var array{public_api_key: string, script: string, locale: string, country_code: string}|null
     */
    private ?array $loaderConfig = null;

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
     * Whether affirm.js can be loaded.
     *
     * Affirm is active, the store currency is one Affirm supports and a public key exists for the mode and currency.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        try {
            $active = (bool) $this->affirmConfig->getConfigData('active');
            $currency = (string) $this->affirmConfig->getCurrency();
        } catch (\Throwable $e) {
            $this->logFailure($e);

            return false;
        }
        $config = $this->getLoaderConfig();

        return $active
            && in_array($currency, AffirmConfig::VALID_CURRENCIES, true)
            && $config['public_api_key'] !== ''
            && $config['script'] !== '';
    }

    /**
     * Affirm's _affirm_config values; empty strings when the configuration cannot be read.
     *
     * @return array{public_api_key: string, script: string, locale: string, country_code: string}
     */
    public function getLoaderConfig(): array
    {
        if ($this->loaderConfig !== null) {
            return $this->loaderConfig;
        }

        try {
            $this->loaderConfig = [
                'public_api_key' => trim((string) $this->affirmConfig->getPublicApiKey()),
                'script' => (string) $this->affirmConfig->getScript(),
                'locale' => (string) $this->affirmConfig->getLocale(),
                'country_code' => (string) $this->affirmConfig->getCountryCode(),
            ];
        } catch (\Throwable $e) {
            $this->logFailure($e);
            $this->loaderConfig = ['public_api_key' => '', 'script' => '', 'locale' => '', 'country_code' => ''];
        }

        return $this->loaderConfig;
    }

    /**
     * Log a configuration read failure without its message.
     *
     * @param \Throwable $exception
     * @return void
     */
    private function logFailure(\Throwable $exception): void
    {
        $this->logger->warning(
            'Hyva_CheckoutAffirm: affirm.js configuration unavailable.',
            ['exception' => get_class($exception)]
        );
    }
}
