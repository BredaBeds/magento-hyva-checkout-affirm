<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use PHPUnit\Framework\TestCase;

class StaleAttemptPolicyTest extends TestCase
{
    private const NOW = 1791367200; // 2026-10-07 10:00:00 UTC

    public function testDefaultThresholdIsFifteenMinutes(): void
    {
        $policy = new StaleAttemptPolicy();

        $this->assertSame(900, StaleAttemptPolicy::DEFAULT_STALE_AFTER_SECONDS);
        $this->assertSame(900, $policy->staleAfterSeconds());
        $this->assertSame(self::NOW - 900, $policy->cutoff(self::NOW));
        $this->assertSame([State::PLACING, State::AUTHORIZING], StaleAttemptPolicy::states());
    }

    public function testThresholdIsConfigurableButNeverBelowOneMinute(): void
    {
        $this->assertSame(1800, (new StaleAttemptPolicy(1800))->staleAfterSeconds());
        $this->assertSame(60, (new StaleAttemptPolicy(0))->staleAfterSeconds());
        $this->assertSame(60, (new StaleAttemptPolicy(-5))->staleAfterSeconds());
    }

    public function testOnlyPlacementsUnchangedForTheThresholdAreStale(): void
    {
        $policy = new StaleAttemptPolicy();

        foreach (StaleAttemptPolicy::states() as $state) {
            $this->assertTrue($policy->isStale($this->attempt($state, '2026-10-07 09:45:00'), self::NOW));
            $this->assertTrue($policy->isStale($this->attempt($state, '2026-10-07 08:00:00'), self::NOW));
            $this->assertFalse($policy->isStale($this->attempt($state, '2026-10-07 09:45:01'), self::NOW));
            $this->assertFalse($policy->isStale($this->attempt($state, 'not a date'), self::NOW));
        }
        foreach ([State::INITIATED, State::CONFIRMED, State::UNCERTAIN, State::PLACED, State::FAILED] as $state) {
            $this->assertFalse($policy->isStale($this->attempt($state, '2026-10-01 00:00:00'), self::NOW));
        }
    }

    private function attempt(State $state, string $updatedAt): Attempt
    {
        return Attempt::fromRow([
            'attempt_id' => str_repeat('ab', 32),
            'owner_key_hash' => str_repeat('1', 64),
            'quote_id' => '42',
            'store_id' => '1',
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => '120000',
            'snapshot_hash' => str_repeat('2', 64),
            'state' => $state->value,
            'updated_at' => $updatedAt,
            'expires_at' => '2026-10-07 11:00:00',
        ]);
    }
}
