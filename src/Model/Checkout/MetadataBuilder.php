<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Version;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\ResourceInterface;
use Magento\Quote\Model\Quote;

/**
 * Affirm checkout metadata, with the same values Affirm's own extension sends plus this integration's marker.
 */
class MetadataBuilder
{
    private const AFFIRM_MODULE = 'Astound_Affirm';

    /**
     * @param ProductMetadataInterface $productMetadata
     * @param ResourceInterface $moduleResource
     * @param ModuleListInterface $moduleList
     */
    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ResourceInterface $moduleResource,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    /**
     * Metadata object.
     *
     * @param Quote $quote
     * @param string $mode modal|redirect|inline, already normalised
     * @return array<string, string>
     */
    public function build(Quote $quote, string $mode): array
    {
        $version = $this->productMetadata->getVersion() . ' ' . $this->productMetadata->getEdition();
        $metadata = [
            'platform_type' => $this->productMetadata->getName() . ' 2',
            'platform_version' => trim($version),
            'platform_affirm' => $this->affirmVersion(),
            'platform_integration' => Version::platformIntegration(),
            'mode' => $mode,
        ];
        if ($metadata['platform_affirm'] === '') {
            unset($metadata['platform_affirm']);
        }
        if (!$quote->isVirtual()) {
            // Magento stores "Carrier Title - Method Title", the format Affirm's Luma checkout sends.
            $shippingType = $quote->getShippingAddress()->getData('shipping_description');
            if (is_scalar($shippingType) && trim((string) $shippingType) !== '') {
                $metadata['shipping_type'] = trim((string) $shippingType);
            }
        }

        return $metadata;
    }

    /**
     * Installed Affirm extension version: its schema version like Affirm reports it, else module.xml.
     *
     * @return string
     */
    private function affirmVersion(): string
    {
        try {
            $version = $this->moduleResource->getDbVersion(self::AFFIRM_MODULE);
        } catch (\Throwable) {
            $version = false;
        }
        if (is_string($version) && trim($version) !== '') {
            return trim($version);
        }
        $module = $this->moduleList->getOne(self::AFFIRM_MODULE);
        $setupVersion = is_array($module) ? ($module['setup_version'] ?? '') : '';

        return is_scalar($setupVersion) ? trim((string) $setupVersion) : '';
    }
}
