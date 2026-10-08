<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Owner key kept in the checkout session (a session proxy is injected through di.xml).
 */
class OwnerKey implements OwnerKeyInterface
{
    private const KEY_PATTERN = '/^[0-9a-f]{64}\z/';

    /**
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHash(): string
    {
        $key = $this->readKey();
        if ($key === null) {
            $key = bin2hex(random_bytes(32));
            // SessionManager forwards set*() calls to the session storage.
            $this->checkoutSession->setData(self::SESSION_KEY, $key); // @phpstan-ignore method.notFound
        }

        return hash('sha256', $key);
    }

    /**
     * @inheritdoc
     */
    public function matches(string $ownerKeyHash): bool
    {
        $key = $this->readKey();

        return $key !== null && $ownerKeyHash !== '' && hash_equals(hash('sha256', $key), $ownerKeyHash);
    }

    /**
     * The session's raw key, or null when absent or malformed.
     *
     * @return string|null
     */
    private function readKey(): ?string
    {
        $key = $this->checkoutSession->getData(self::SESSION_KEY);

        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1 ? $key : null;
    }
}
