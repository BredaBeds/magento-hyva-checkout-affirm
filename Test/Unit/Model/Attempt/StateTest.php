<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\State;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame(
            ['initiated', 'confirmed', 'placing', 'authorizing', 'placed', 'failed', 'uncertain', 'resolved'],
            array_map(static fn (State $state): string => $state->value, State::cases())
        );
    }

    public function testGroups(): void
    {
        $this->assertSame([State::INITIATED, State::CONFIRMED], State::open());
        $this->assertSame([State::PLACING, State::AUTHORIZING, State::UNCERTAIN], State::unresolved());
        $this->assertTrue(State::CONFIRMED->isOpen());
        $this->assertFalse(State::PLACING->isOpen());
        $this->assertTrue(State::UNCERTAIN->isUnresolved());
        $this->assertFalse(State::FAILED->isUnresolved());
        $this->assertTrue(State::PLACED->isFinal());
        $this->assertTrue(State::FAILED->isFinal());
        $this->assertTrue(State::RESOLVED->isFinal());
        $this->assertFalse(State::UNCERTAIN->isFinal());
    }

    public function testStateMachine(): void
    {
        $allowed = [
            'initiated' => ['confirmed', 'failed'],
            'confirmed' => ['placing', 'failed'],
            'placing' => ['authorizing', 'placed', 'confirmed', 'failed', 'uncertain', 'resolved'],
            'authorizing' => ['placed', 'uncertain', 'placing', 'resolved'],
            'uncertain' => ['resolved'],
            'placed' => [],
            'failed' => [],
            'resolved' => [],
        ];
        foreach (State::cases() as $from) {
            foreach (State::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canTransitionTo($to),
                    sprintf('%s -> %s', $from->value, $to->value)
                );
            }
        }
    }
}
