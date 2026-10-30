<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use DateTimeImmutable;
use DateTimeZone;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepository;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use InvalidArgumentException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttemptRepositoryTest extends TestCase
{
    private const TABLE = 'prefix_hyva_affirm_checkout_attempt';

    private const NOW = 1791367200; // 2026-10-07 10:00:00 UTC

    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface $connection;

    /**
     * @var list<array{0: string, 1: mixed}>
     */
    private array $wheres = [];

    /**
     * @var list<mixed>
     */
    private array $selectCalls = [];

    /**
     * @var AttemptRepository
     */
    private AttemptRepository $repository;

    /**
     * @var string
     */
    private string $id;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(function (...$args) use ($select) {
            $this->selectCalls[] = ['from', $args];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[] = [$condition, $value];
            return $select;
        });
        $select->method('order')->willReturnCallback(function ($spec) use ($select) {
            $this->selectCalls[] = ['order', $spec];
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count) use ($select) {
            $this->selectCalls[] = ['limit', $count];
            return $select;
        });
        $this->connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')
            ->with(AttemptRepositoryInterface::TABLE)
            ->willReturn(self::TABLE);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $this->repository = new AttemptRepository($resource, $dateTime);
        $this->id = str_repeat('ab', 32);
    }

    public function testCreateInsertsInitiatedAttempt(): void
    {
        $inserted = null;
        $this->connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (string $table, array $bind) use (&$inserted): int {
                $this->assertSame(self::TABLE, $table);
                $inserted = $bind;
                return 1;
            });

        $attempt = $this->repository->create(
            quoteId: 42,
            storeId: 1,
            customerId: null,
            reservedIncrementId: '000000123',
            currency: 'usd',
            grandTotalCents: 120000,
            snapshotHash: str_repeat('2', 64),
            ownerKeyHash: str_repeat('1', 64)
        );

        $this->assertTrue(Attempt::isValidId($attempt->attemptId));
        $this->assertSame(State::INITIATED, $attempt->state);
        $this->assertSame('USD', $attempt->currency);
        $this->assertSame('affirm_gateway', $attempt->paymentMethod);
        $this->assertSame('2026-10-07 10:00:00', $attempt->createdAt);
        $this->assertSame('2026-10-07 11:00:00', $attempt->expiresAt);
        $this->assertSame($attempt->toArray(), $inserted);
    }

    public function testCreateUsesRandomIds(): void
    {
        $first = $this->createAttempt();
        $second = $this->createAttempt();

        $this->assertNotSame($first->attemptId, $second->attemptId);
    }

    public function testCreateRejectsIncompleteData(): void
    {
        $this->connection->expects($this->never())->method('insert');
        $this->expectException(InvalidArgumentException::class);

        $this->repository->create(42, 1, null, '', 'USD', 100, str_repeat('2', 64), str_repeat('1', 64));
    }

    public function testGetLoadsRow(): void
    {
        $this->connection->method('fetchRow')->willReturn($this->row());

        $attempt = $this->repository->get($this->id);

        $this->assertNotNull($attempt);
        $this->assertSame(State::CONFIRMED, $attempt->state);
        $this->assertSame([['attempt_id = ?', $this->id]], $this->wheres);
    }

    public function testGetReturnsNullForUnknownOrMalformedIds(): void
    {
        $this->connection->expects($this->once())->method('fetchRow')->willReturn(false);

        $this->assertNull($this->repository->get($this->id));
        $this->assertNull($this->repository->get('../../etc'));
    }

    public function testTransitionIsCompareAndSwap(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['token_hash' => 'h', 'state' => 'confirmed'],
                ['attempt_id = ?' => $this->id, 'state IN (?)' => ['initiated']]
            )
            ->willReturn(1);

        $this->assertTrue(
            $this->repository->transition($this->id, [State::INITIATED], State::CONFIRMED, ['token_hash' => 'h'])
        );
    }

    public function testTransitionReportsLostRace(): void
    {
        $this->connection->method('update')->willReturn(0);

        $this->assertFalse($this->repository->transition($this->id, ['confirmed'], 'placing'));
    }

    public function testTransitionAddsConditions(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['state' => 'placing'],
                [
                    'attempt_id = ?' => $this->id,
                    'state IN (?)' => ['confirmed'],
                    'token_hash = ?' => 'h',
                    0 => 'order_id IS NULL',
                ]
            )
            ->willReturn(1);

        $this->assertTrue($this->repository->transition(
            $this->id,
            [State::CONFIRMED],
            State::PLACING,
            [],
            ['token_hash' => 'h', 'order_id' => null]
        ));
    }

    public function testTransitionCanRequireTheRowAsRead(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['state' => 'uncertain'],
                [
                    'attempt_id = ?' => $this->id,
                    'state IN (?)' => ['placing'],
                    'updated_at = ?' => '2026-10-07 09:00:00',
                ]
            )
            ->willReturn(1);

        $this->assertTrue($this->repository->transition(
            $this->id,
            [State::PLACING],
            State::UNCERTAIN,
            [],
            ['updated_at' => '2026-10-07 09:00:00']
        ));
    }

    public function testTransitionFromSeveralStatesFormatsDates(): void
    {
        $expiry = new DateTimeImmutable('2026-10-07 05:00:00', new DateTimeZone('America/Boise'));
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['failure_reason' => 'expired', 'expires_at' => '2026-10-07 11:00:00', 'state' => 'failed'],
                ['attempt_id = ?' => $this->id, 'state IN (?)' => ['initiated', 'confirmed']]
            )
            ->willReturn(1);

        $this->assertTrue($this->repository->transition(
            $this->id,
            [State::INITIATED, State::CONFIRMED, 'initiated'],
            State::FAILED,
            ['failure_reason' => 'expired', 'expires_at' => $expiry]
        ));
    }

    public function testTransitionIgnoresMalformedIds(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertFalse($this->repository->transition('nope', [State::INITIATED], State::FAILED));
    }

    /**
     * @return array<string, array{0: list<State|string>, 1: State|string, 2: array<string, mixed>,
     *     3: array<string, mixed>}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'no source' => [[], State::FAILED, [], []],
            'same state' => [[State::CONFIRMED], State::CONFIRMED, [], []],
            'not allowed' => [[State::PLACED], State::FAILED, [], []],
            'skips confirmation' => [[State::INITIATED], State::PLACING, [], []],
            'unknown state' => [['bogus'], State::FAILED, [], []],
            'immutable column' => [[State::INITIATED], State::FAILED, ['quote_id' => 5], []],
            'state in set' => [[State::INITIATED], State::FAILED, ['state' => 'placed'], []],
            'state condition' => [[State::INITIATED], State::FAILED, [], ['state' => 'placed']],
            'updated_at in set' => [[State::INITIATED], State::FAILED, ['updated_at' => '2026-10-07 09:00:00'], []],
            'created_at condition' => [[State::INITIATED], State::FAILED, [], ['created_at' => '2026-10-07']],
            'array value' => [[State::INITIATED], State::FAILED, ['failure_reason' => ['x']], []],
        ];
    }

    /**
     * @param list<State|string> $from
     * @param State|string $to
     * @param array<string, mixed> $set
     * @param array<string, mixed> $conditions
     */
    #[DataProvider('invalidTransitions')]
    public function testTransitionRejectsInvalidInput(
        array $from,
        State|string $to,
        array $set,
        array $conditions
    ): void {
        $this->connection->expects($this->never())->method('update');
        $this->expectException(InvalidArgumentException::class);

        $this->repository->transition($this->id, $from, $to, $set, $conditions);
    }

    public function testRecordTransactionIdOnlyWhileAuthorizing(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['provider_transaction_id' => 'N330-Z6D4'],
                ['attempt_id = ?' => $this->id, 'state = ?' => 'authorizing']
            )
            ->willReturn(1);

        $this->assertTrue($this->repository->recordTransactionId($this->id, 'N330-Z6D4'));
    }

    public function testRecordTransactionIdRejectsInvalidValues(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertFalse($this->repository->recordTransactionId($this->id, ''));
        $this->assertFalse($this->repository->recordTransactionId($this->id, str_repeat('x', 65)));
        $this->assertFalse($this->repository->recordTransactionId('bad', 'N330-Z6D4'));
    }

    public function testHasUnresolvedForQuote(): void
    {
        $this->connection->method('fetchOne')->willReturnOnConsecutiveCalls($this->id, false);

        $this->assertTrue($this->repository->hasUnresolvedForQuote(42, 'other'));
        $this->assertSame(
            [
                ['quote_id = ?', 42],
                ['state IN (?)', ['placing', 'authorizing', 'uncertain']],
                ['attempt_id <> ?', 'other'],
            ],
            $this->wheres
        );

        $this->wheres = [];
        $this->assertFalse($this->repository->hasUnresolvedForQuote(42));
        $this->assertCount(2, $this->wheres);
    }

    public function testFindReusableRequiresRemainingLifetime(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        $this->assertNull($this->repository->findReusable(42, 'owner', 'snap'));
        $this->assertSame(
            [
                ['quote_id = ?', 42],
                ['owner_key_hash = ?', 'owner'],
                ['snapshot_hash = ?', 'snap'],
                ['state = ?', 'initiated'],
                ['expires_at > ?', '2026-10-07 10:15:00'],
            ],
            $this->wheres
        );
        $this->assertContains(['order', 'created_at DESC'], $this->selectCalls);
    }

    public function testSupersedeOthersForQuoteIsOneCompareAndSwap(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['state' => 'failed', 'failure_reason' => 'superseded'],
                ['quote_id = ?' => 42, 'state IN (?)' => ['initiated', 'confirmed'], 'attempt_id <> ?' => $this->id]
            )
            ->willReturn(2);

        $this->assertSame(2, $this->repository->supersedeOthersForQuote(42, $this->id));
    }

    public function testCountRecentForQuote(): void
    {
        $this->connection->method('fetchOne')->willReturn('3');
        $since = new DateTimeImmutable('2026-10-07 04:00:00', new DateTimeZone('America/Boise'));

        $this->assertSame(3, $this->repository->countRecentForQuote(42, $since));
        $this->assertSame([['quote_id = ?', 42], ['created_at >= ?', '2026-10-07 10:00:00']], $this->wheres);
    }

    public function testListUnresolved(): void
    {
        $row = $this->row();
        $row['state'] = 'uncertain';
        $this->connection->method('fetchAll')->willReturn([$row, $row]);

        $attempts = $this->repository->listUnresolved(10);

        $this->assertCount(2, $attempts);
        $this->assertSame(State::UNCERTAIN, $attempts[0]->state);
        $this->assertSame([['state IN (?)', ['placing', 'authorizing', 'uncertain']]], $this->wheres);
        $this->assertContains(['limit', 10], $this->selectCalls);
    }

    public function testPruneDeletesOnlyFinishedAndExpiredRows(): void
    {
        $calls = [];
        $this->connection->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$calls): int {
                $calls[] = [$table, $where];
                return count($calls) === 1 ? 5 : 2;
            });

        $this->assertSame(7, $this->repository->prune(90));
        $cutoff = '2026-07-09 10:00:00';
        $this->assertSame(
            [
                [self::TABLE, ['state IN (?)' => ['failed', 'resolved'], 'updated_at < ?' => $cutoff]],
                [self::TABLE, ['state IN (?)' => ['initiated', 'confirmed'], 'expires_at < ?' => $cutoff]],
            ],
            $calls
        );
    }

    public function testPruneNeedsAtLeastOneDay(): void
    {
        $this->connection->expects($this->never())->method('delete');
        $this->expectException(InvalidArgumentException::class);

        $this->repository->prune(0);
    }

    /**
     * @return array<string, string|null>
     */
    private function row(): array
    {
        return [
            'attempt_id' => $this->id,
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
            'token_hash' => null,
            'order_increment_id' => null,
            'order_id' => null,
            'failure_reason' => null,
            'provider_transaction_id' => null,
            'created_at' => '2026-10-07 10:00:00',
            'updated_at' => '2026-10-07 10:00:00',
            'expires_at' => '2026-10-07 11:00:00',
        ];
    }

    private function createAttempt(): Attempt
    {
        return $this->repository->create(42, 1, 5, '000000123', 'USD', 100, str_repeat('2', 64), str_repeat('1', 64));
    }
}
