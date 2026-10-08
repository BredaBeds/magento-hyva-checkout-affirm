<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Attempt;

use Magento\Customer\Model\Session as CustomerSession;

/**
 * Who may confirm, cancel or replay an attempt: the checkout session that started it (owner key), or the
 * logged-in customer it was started for.
 */
class Ownership
{
    /**
     * @param OwnerKeyInterface $ownerKey
     * @param CustomerSession $customerSession
     */
    public function __construct(
        private readonly OwnerKeyInterface $ownerKey,
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * Whether the current visitor owns the attempt.
     *
     * @param Attempt $attempt
     * @return bool
     */
    public function isOwner(Attempt $attempt): bool
    {
        if ($this->ownerKey->matches($attempt->ownerKeyHash)) {
            return true;
        }
        if ($attempt->customerId === null || $attempt->customerId <= 0) {
            return false;
        }

        return $this->customerSession->isLoggedIn()
            && (int) $this->customerSession->getCustomerId() === $attempt->customerId;
    }
}
