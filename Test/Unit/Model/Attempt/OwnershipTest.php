<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Hyva\CheckoutAffirm\Model\Attempt\Ownership;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\TestCase;

class OwnershipTest extends TestCase
{
    public function testTheSessionThatStartedTheAttemptOwnsIt(): void
    {
        $this->assertTrue($this->ownership(true, false, null)->isOwner($this->attempt(null)));
        $this->assertTrue($this->ownership(true, false, null)->isOwner($this->attempt(7)));
    }

    public function testTheLoggedInCustomerOwnsTheirAttempt(): void
    {
        $this->assertTrue($this->ownership(false, true, 7)->isOwner($this->attempt(7)));
    }

    public function testEveryoneElseIsRefused(): void
    {
        $this->assertFalse($this->ownership(false, true, 8)->isOwner($this->attempt(7)), 'other customer');
        $this->assertFalse($this->ownership(false, false, 7)->isOwner($this->attempt(7)), 'logged out');
        $this->assertFalse($this->ownership(false, true, 7)->isOwner($this->attempt(null)), 'guest attempt');
    }

    /**
     * Ownership with a session state.
     *
     * @param bool $ownerKeyMatches
     * @param bool $loggedIn
     * @param int|null $customerId
     * @return Ownership
     */
    private function ownership(bool $ownerKeyMatches, bool $loggedIn, ?int $customerId): Ownership
    {
        $ownerKey = $this->createMock(OwnerKeyInterface::class);
        $ownerKey->method('matches')->with(hash('sha256', 'owner'))->willReturn($ownerKeyMatches);
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])
            ->getMock();
        $session->method('isLoggedIn')->willReturn($loggedIn);
        $session->method('getCustomerId')->willReturn($customerId === null ? null : (string) $customerId);

        return new Ownership($ownerKey, $session);
    }

    /**
     * An attempt for a guest (null) or a customer.
     *
     * @param int|null $customerId
     * @return Attempt
     */
    private function attempt(?int $customerId): Attempt
    {
        return Attempt::fromRow([
            'attempt_id' => str_repeat('a', 64),
            'owner_key_hash' => hash('sha256', 'owner'),
            'quote_id' => 42,
            'store_id' => 1,
            'customer_id' => $customerId,
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => 100000,
            'snapshot_hash' => hash('sha256', 'cart'),
            'state' => 'initiated',
            'expires_at' => '2030-01-01 00:00:00',
        ]);
    }
}
