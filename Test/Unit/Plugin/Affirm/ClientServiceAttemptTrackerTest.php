<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Plugin\Affirm;

use Astound\Affirm\Gateway\Http\Client\ClientService;
use Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface;
use Hyva\CheckoutAffirm\Model\Attempt\PlacementContext;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Plugin\Affirm\ClientServiceAttemptTracker;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\TransferInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ClientServiceAttemptTrackerTest extends TestCase
{
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const API = 'https://api.global.sandbox.affirm.com';

    /**
     * @var PlacementContext
     */
    private PlacementContext $context;

    /**
     * @var AttemptRepositoryInterface&MockObject
     */
    private AttemptRepositoryInterface $attempts;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var int
     */
    private int $transactionLevel = 0;

    /**
     * @var list<string>
     */
    private array $calls = [];

    /**
     * @var ClientServiceAttemptTracker
     */
    private ClientServiceAttemptTracker $tracker;

    protected function setUp(): void
    {
        $this->context = new PlacementContext();
        $this->attempts = $this->createMock(AttemptRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->transactionLevel);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $this->tracker = new ClientServiceAttemptTracker($this->context, $this->attempts, $resource, $this->logger);
    }

    public function testDoesNothingOutsideAPlacement(): void
    {
        $this->attempts->expects($this->never())->method('transition');

        $this->assertSame(['status' => 'authorized'], $this->call('POST', '/api/v1/transactions/'));
    }

    public function testIgnoresEveryRequestButTheAuthorization(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->expects($this->never())->method('transition');
        $this->attempts->expects($this->never())->method('recordTransactionId');

        $this->call('GET', '/api/v2/checkout/MARKERTOKEN0001');
        $this->call('GET', '/api/v1/transactions/ABCD-1234');
        $this->call('POST', '/api/v1/transactions/ABCD-1234/capture');
        $this->call('POST', '/api/v1/transactions/ABCD-1234/void');
        $this->call('POST', '/api/v1/transactions/ABCD-1234/refund');
        $this->call('POST', '/api/v1/transactions/ABCD-1234');
        $this->call('POST', '/api/v2/checkout/direct');
        $this->assertCount(7, $this->calls);
    }

    public function testMarksTheAttemptAuthorizingBeforeTheAuthorizationAndRecordsTheTransaction(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->expects($this->once())
            ->method('transition')
            ->with(self::ATTEMPT_ID, [State::PLACING], State::AUTHORIZING)
            ->willReturnCallback(function (): bool {
                $this->calls[] = 'transition';

                return true;
            });
        $this->attempts->expects($this->once())
            ->method('recordTransactionId')
            ->with(self::ATTEMPT_ID, 'ABCD-1234')
            ->willReturn(true);

        $result = $this->call('post', '/api/v1/transactions/', ['id' => 'ABCD-1234', 'status' => 'authorized']);

        $this->assertSame(['transition', 'POST /api/v1/transactions/'], $this->calls);
        $this->assertSame(['id' => 'ABCD-1234', 'status' => 'authorized'], $result);
    }

    public function testMatchesTheAuthorizationWithoutTrailingSlash(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->expects($this->once())->method('transition')->willReturn(true);

        $this->call('POST', '/api/v1/transactions', ['id' => 7, 'status' => 'authorized']);
    }

    public function testRecordsNothingWithoutAnAuthorizedResponse(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->method('transition')->willReturn(true);
        $this->attempts->expects($this->never())->method('recordTransactionId');

        $this->call('POST', '/api/v1/transactions/', ['id' => 'X', 'status_code' => 400, 'type' => 'invalid_request']);
        $this->call('POST', '/api/v1/transactions/', ['id' => 'X', 'status' => 'declined']);
        $this->call('POST', '/api/v1/transactions/', ['status' => 'authorized']);
        $this->call('POST', '/api/v1/transactions/', null);
    }

    public function testRefusesToAuthorizeAnAttemptThatIsNoLongerBeingPlaced(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->method('transition')->willReturn(false);
        $this->logger->expects($this->once())->method('error');

        try {
            $this->call('POST', '/api/v1/transactions/');
            $this->fail('The authorization must not be sent.');
        } catch (ClientException $exception) {
            $this->assertSame([], $this->calls);
        }
    }

    public function testLetsAuthorizationExceptionsThroughAndKeepsTheAttemptAuthorizing(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->expects($this->once())->method('transition')->willReturn(true);
        $this->attempts->expects($this->never())->method('recordTransactionId');

        $this->expectException(ClientException::class);
        $this->call('POST', '/api/v1/transactions/', new ClientException(__('timeout')));
    }

    public function testAFailedTransactionIdWriteDoesNotFailTheAuthorizedPlacement(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->attempts->method('transition')->willReturn(true);
        $this->attempts->method('recordTransactionId')->willThrowException(new RuntimeException('deadlock'));
        $this->logger->expects($this->once())->method('warning');

        $result = $this->call('POST', '/api/v1/transactions/', ['id' => 'ABCD', 'status' => 'authorized']);

        $this->assertSame(['id' => 'ABCD', 'status' => 'authorized'], $result);
    }

    public function testWarnsWhenTheAuthorizationRunsInsideATransaction(): void
    {
        $this->context->begin(self::ATTEMPT_ID, 42);
        $this->transactionLevel = 1;
        $this->attempts->method('transition')->willReturn(true);
        $this->logger->expects($this->once())->method('critical');

        $this->call('POST', '/api/v1/transactions/');
    }

    /**
     * Run the plugin around a fake Affirm request.
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|\Throwable|null $response
     * @return mixed
     */
    private function call(
        string $method,
        string $path,
        array|\Throwable|null $response = ['status' => 'authorized']
    ): mixed {
        $transfer = $this->createMock(TransferInterface::class);
        $transfer->method('getMethod')->willReturn($method);
        $transfer->method('getUri')->willReturn(self::API . $path);
        $proceed = function (TransferInterface $passed) use ($transfer, $method, $path, $response) {
            $this->assertSame($transfer, $passed);
            $this->calls[] = strtoupper($method) . ' ' . $path;
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        };

        return $this->tracker->aroundPlaceRequest($this->createMock(ClientService::class), $proceed, $transfer);
    }
}
