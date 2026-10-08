<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class AttemptTest extends TestCase
{
    /**
     * A row as the adapter returns it (strings for every column).
     *
     * @return array<string, string|null>
     */
    public static function row(): array
    {
        return [
            'attempt_id' => str_repeat('ab', 32),
            'owner_key_hash' => str_repeat('1', 64),
            'quote_id' => '42',
            'store_id' => '1',
            'customer_id' => null,
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => '120000',
            'payment_method' => 'affirm_gateway',
            'snapshot_hash' => str_repeat('2', 64),
            'state' => 'confirmed',
            'token_hash' => hash('sha256', 'TOKEN123'),
            'order_increment_id' => null,
            'order_id' => null,
            'failure_reason' => null,
            'provider_transaction_id' => null,
            'created_at' => '2026-10-07 10:00:00',
            'updated_at' => '2026-10-07 10:01:00',
            'expires_at' => '2026-10-07 11:00:00',
        ];
    }

    public function testFromRowCastsColumns(): void
    {
        $attempt = Attempt::fromRow(self::row());

        $this->assertSame(str_repeat('ab', 32), $attempt->attemptId);
        $this->assertSame(42, $attempt->quoteId);
        $this->assertSame(1, $attempt->storeId);
        $this->assertNull($attempt->customerId);
        $this->assertSame('000000123', $attempt->reservedIncrementId);
        $this->assertSame(120000, $attempt->grandTotalCents);
        $this->assertSame(State::CONFIRMED, $attempt->state);
        $this->assertNull($attempt->orderId);
    }

    public function testToArrayRoundTrip(): void
    {
        $row = self::row();
        $row['customer_id'] = '7';
        $row['order_id'] = '99';
        $row['order_increment_id'] = '000000123';

        $array = Attempt::fromRow($row)->toArray();

        $this->assertSame(array_keys($row), array_keys($array));
        $this->assertSame(7, $array['customer_id']);
        $this->assertSame(99, $array['order_id']);
        $this->assertSame('confirmed', $array['state']);
        $this->assertEquals(Attempt::fromRow($row), Attempt::fromRow($array));
    }

    public function testFromRowRejectsMissingColumn(): void
    {
        $row = self::row();
        unset($row['quote_id']);

        $this->expectException(InvalidArgumentException::class);
        Attempt::fromRow($row);
    }

    public function testFromRowRejectsUnknownState(): void
    {
        $row = self::row();
        $row['state'] = 'bogus';

        $this->expectException(UnexpectedValueException::class);
        Attempt::fromRow($row);
    }

    public function testExpiry(): void
    {
        $attempt = Attempt::fromRow(self::row());
        $expiresAt = gmmktime(11, 0, 0, 10, 7, 2026);

        $this->assertSame($expiresAt, $attempt->expiresAtTimestamp());
        $this->assertFalse($attempt->isExpired($expiresAt - 1));
        $this->assertTrue($attempt->isExpired($expiresAt));

        $row = self::row();
        $row['expires_at'] = 'not a date';
        $this->assertTrue(Attempt::fromRow($row)->isExpired(0));
    }

    public function testLastChange(): void
    {
        $this->assertSame(gmmktime(10, 1, 0, 10, 7, 2026), Attempt::fromRow(self::row())->updatedAtTimestamp());

        $row = self::row();
        $row['updated_at'] = 'not a date';
        $this->assertNull(Attempt::fromRow($row)->updatedAtTimestamp());
        unset($row['updated_at']);
        $this->assertNull(Attempt::fromRow($row)->updatedAtTimestamp());
    }

    public function testTokenHash(): void
    {
        $attempt = Attempt::fromRow(self::row());

        $this->assertSame(hash('sha256', 'TOKEN123'), Attempt::hashToken('TOKEN123'));
        $this->assertTrue($attempt->hasToken('TOKEN123'));
        $this->assertFalse($attempt->hasToken('OTHER'));

        $row = self::row();
        $row['token_hash'] = null;
        $this->assertFalse(Attempt::fromRow($row)->hasToken('TOKEN123'));
    }

    public function testIdValidation(): void
    {
        $this->assertTrue(Attempt::isValidId(str_repeat('0f', 32)));
        $this->assertFalse(Attempt::isValidId(str_repeat('0F', 32)));
        $this->assertFalse(Attempt::isValidId(str_repeat('0f', 31)));
        $this->assertFalse(Attempt::isValidId(''));
        $this->assertFalse(Attempt::isValidId(str_repeat('0f', 32) . "\n"));
    }
}
