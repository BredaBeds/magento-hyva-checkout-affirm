<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Forces the storefront to reload customer section data (minicart) on the next page.
 *
 * Magento renews the private_content_version cookie only on POST requests (PageCache\Version::process()), and
 * the redirect-mode return from Affirm is a GET. This sets a fresh version with the same cookie metadata as
 * Magento, so Hyvä's and Luma's section loaders see a version change and fetch fresh data.
 */
class PrivateContentInvalidator
{
    /**
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param HttpRequest $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly HttpRequest $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Set a new private content version (never throws: the cart refresh on the success page is the fallback).
     *
     * @return void
     */
    public function invalidate(): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
            $metadata->setDuration(Version::COOKIE_PERIOD)
                ->setPath('/')
                ->setSecure($this->request->isSecure())
                ->setHttpOnly(false);
            $metadata->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(Version::COOKIE_NAME, bin2hex(random_bytes(16)), $metadata);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Affirm checkout: the private content version cookie could not be renewed.',
                ['exception_class' => get_class($exception)]
            );
        }
    }
}
