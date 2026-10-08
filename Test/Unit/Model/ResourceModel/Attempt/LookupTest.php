<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\ResourceModel\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Lookup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LookupTest extends TestCase
{
    private const TABLE = 'prefix_hyva_affirm_checkout_attempt';

    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface $connection;

    /**
     * @var list<array{0: string, 1: mixed}>
     */
    private array $calls = [];

    /**
     * @var Lookup
     */
    private Lookup $lookup;

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                // The mock also passes unset optional parameters (null); keep the arguments given.
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];

                return $select;
            });
        }
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->with('hyva_affirm_checkout_attempt')->willReturn(self::TABLE);

        $this->lookup = new Lookup($resource);
    }

    public function testFindsAttemptsInStatesNewestFirst(): void
    {
        $this->connection->method('fetchAll')->willReturn([$this->row('uncertain', 'a'), $this->row('placing', 'b')]);

        $attempts = $this->lookup->findByStates([State::UNCERTAIN, State::PLACING, State::UNCERTAIN], 25);

        $this->assertSame([
            ['from', [self::TABLE, '*']],
            ['order', [['created_at DESC', 'attempt_id ASC']]],
            ['limit', [25]],
            ['where', ['state IN (?)', ['uncertain', 'placing']]],
        ], $this->calls);
        $this->assertCount(2, $attempts);
        $this->assertSame(State::UNCERTAIN, $attempts[0]->state);
        $this->assertSame(str_repeat('b', 64), $attempts[1]->attemptId);
    }

    public function testNoStatesMeansEveryState(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->lookup->findByStates([], 0));
        $this->assertNotContains('where', array_column($this->calls, 0));
        $this->assertContains(['limit', [1]], $this->calls);
    }

    public function testFindsExpiredOpenAttemptsOldestExpiryFirst(): void
    {
        $this->connection->method('fetchAll')->willReturn([$this->row('confirmed', 'c')]);

        $attempts = $this->lookup->findExpiredOpen(1791367200, 100);

        $this->assertSame([
            ['from', [self::TABLE, '*']],
            ['where', ['state IN (?)', ['initiated', 'confirmed']]],
            ['where', ['expires_at < ?', '2026-10-07 10:00:00']],
            ['order', [['expires_at ASC', 'attempt_id ASC']]],
            ['limit', [100]],
        ], $this->calls);
        $this->assertSame(State::CONFIRMED, $attempts[0]->state);
    }

    public function testFindsStalePlacementsOldestChangeFirst(): void
    {
        $this->connection->method('fetchAll')->willReturn([$this->row('authorizing', 'd')]);

        $attempts = $this->lookup->findStale(1791367200, 50);

        $this->assertSame([
            ['from', [self::TABLE, '*']],
            ['where', ['state IN (?)', ['placing', 'authorizing']]],
            ['where', ['updated_at <= ?', '2026-10-07 10:00:00']],
            ['order', [['updated_at ASC', 'attempt_id ASC']]],
            ['limit', [50]],
        ], $this->calls);
        $this->assertSame(State::AUTHORIZING, $attempts[0]->state);
    }

    public function testStaleLookupAlwaysLimits(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->lookup->findStale(1791367200, 0));
        $this->assertContains(['limit', [1]], $this->calls);
    }

    /**
     * @param string $state
     * @param string $idCharacter
     * @return array<string, mixed>
     */
    private function row(string $state, string $idCharacter): array
    {
        return [
            'attempt_id' => str_repeat($idCharacter, 64),
            'owner_key_hash' => str_repeat('0', 64),
            'quote_id' => '42',
            'store_id' => '1',
            'customer_id' => null,
            'reserved_increment_id' => '000000123',
            'currency' => 'USD',
            'grand_total_cents' => '123456',
            'payment_method' => 'affirm_gateway',
            'snapshot_hash' => str_repeat('1', 64),
            'state' => $state,
            'token_hash' => null,
            'order_increment_id' => null,
            'order_id' => null,
            'failure_reason' => null,
            'provider_transaction_id' => null,
            'created_at' => '2026-10-07 09:00:00',
            'updated_at' => '2026-10-07 09:00:00',
            'expires_at' => '2026-10-07 10:00:00',
        ];
    }
}
