<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\VerificationResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConfirmationOutcomeTest extends TestCase
{
    public function testPlaced(): void
    {
        $outcome = ConfirmationOutcome::placed(7, '000000123', 42, true);

        $this->assertTrue($outcome->isPlaced());
        $this->assertFalse($outcome->isFailed());
        $this->assertSame(ConfirmationOutcome::PLACED, $outcome->status);
        $this->assertSame(7, $outcome->orderId);
        $this->assertSame('000000123', $outcome->incrementId);
        $this->assertSame(42, $outcome->quoteId);
        $this->assertTrue($outcome->replay);
    }

    public function testOtherOutcomes(): void
    {
        $inProgress = ConfirmationOutcome::inProgress('Busy', 42, ConfirmationOutcome::REASON_RETRY);
        $this->assertTrue($inProgress->isInProgress());
        $this->assertSame('retry', $inProgress->reason);
        $this->assertSame('Busy', $inProgress->message);
        $this->assertNull($inProgress->orderId);

        $uncertain = ConfirmationOutcome::uncertain('Checking', 42, '000000123');
        $this->assertTrue($uncertain->isUncertain());
        $this->assertSame('000000123', $uncertain->incrementId);
        $this->assertSame(ConfirmationOutcome::REASON_UNCERTAIN, $uncertain->reason);

        $failed = ConfirmationOutcome::failed('cart_changed', 'Your cart changed.');
        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->replay);
        $this->assertNull($failed->quoteId);
    }

    public function testRejectsUnknownStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConfirmationOutcome('done');
    }

    public function testPlacedNeedsOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConfirmationOutcome::placed(0, '000000123', 42);
    }

    public function testPlacedNeedsIncrementId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConfirmationOutcome(ConfirmationOutcome::PLACED, 7);
    }

    public function testVerificationResultFactories(): void
    {
        $success = VerificationResult::success('confirmed', '000000123', 100, 'USD');
        $this->assertTrue($success->ok);
        $this->assertSame('', $success->reason);

        $failure = VerificationResult::failure(VerificationResult::REASON_TOTAL_MISMATCH, 'confirmed', '1', 99, 'USD');
        $this->assertFalse($failure->ok);
        $this->assertSame('total_mismatch', $failure->reason);
        $this->assertSame(99, $failure->total);
    }
}
