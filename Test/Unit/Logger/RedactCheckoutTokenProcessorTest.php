<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Logger;

use DateTimeImmutable;
use Hyva\CheckoutAffirm\Logger\RedactCheckoutTokenProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactCheckoutTokenProcessorTest extends TestCase
{
    private const MARKER = 'HYVAMARKER0123ab';

    private const URI = 'https://api.global.sandbox.affirm.com/api/v2/checkout/' . self::MARKER;

    /**
     * @var RedactCheckoutTokenProcessor
     */
    private RedactCheckoutTokenProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new RedactCheckoutTokenProcessor();
    }

    public function testLogRecord(): void
    {
        $this->requireMonolog3();
        $object = new \stdClass();
        $record = new LogRecord(
            new DateTimeImmutable(),
            'affirm',
            Level::Debug,
            'GET ' . self::URI . ' failed',
            $this->context($object),
            ['url' => '/hyva_affirm/checkout/confirm/attempt/x?checkout_token=' . self::MARKER . '&a=1']
        );

        $result = ($this->processor)($record);

        $this->assertInstanceOf(LogRecord::class, $result);
        $this->assertSame(
            'GET https://api.global.sandbox.affirm.com/api/v2/checkout/[redacted] failed',
            $result->message
        );
        $this->assertRedactedContext($result->context, $object);
        $this->assertSame(
            ['url' => '/hyva_affirm/checkout/confirm/attempt/x?checkout_token=[redacted]&a=1'],
            $result->extra
        );
        $this->assertSame(Level::Debug, $result->level);
        $this->assertSame('affirm', $result->channel);
        $this->assertStringNotContainsString(self::MARKER, var_export($result->toArray(), true));
    }

    public function testArrayRecord(): void
    {
        $object = new \stdClass();
        $record = [
            'message' => 'Request ' . self::URI,
            'context' => $this->context($object),
            'extra' => ['nested' => ['Checkout_Id' => self::MARKER]],
            'level' => 100,
            'channel' => 'affirm',
        ];

        $result = ($this->processor)($record);

        $this->assertIsArray($result);
        $this->assertSame(
            'Request https://api.global.sandbox.affirm.com/api/v2/checkout/[redacted]',
            $result['message']
        );
        $this->assertRedactedContext($result['context'], $object);
        $this->assertSame(['nested' => ['Checkout_Id' => '[redacted]']], $result['extra']);
        $this->assertSame(100, $result['level']);
        $this->assertStringNotContainsString(self::MARKER, var_export($result, true));
    }

    public function testArrayRecordWithoutContextIsLeftAlone(): void
    {
        $record = ['message' => 'Nothing to see', 'level' => 200];

        $this->assertSame($record, ($this->processor)($record));
    }

    public function testUnrelatedValuesAreUnchanged(): void
    {
        $record = [
            'message' => 'POST https://api.global.affirm.com/api/v1/transactions/ABC/capture',
            'context' => ['checkout' => 'kept', 'path' => '/api/v2/checkout/', 'list' => [1, 2.5, true, null]],
        ];

        $this->assertSame($record, ($this->processor)($record));
    }

    public function testRealMonologLoggerWritesNoToken(): void
    {
        $this->requireMonolog3();
        $handler = new TestHandler();
        $logger = new Logger('AffirmDebugLogger', [$handler], [$this->processor]);

        // The shape Affirm's ClientService::placeRequest() logs in its finally block.
        $logger->debug('Astound\Affirm\Gateway\Http\Client\ClientService::placeRequest', [
            'uri' => self::URI,
            'response' => ['checkout_id' => self::MARKER, 'id' => 'TX1', 'status' => 'authorized'],
        ]);
        $logger->error('Error: ' . self::URI);

        $this->assertCount(2, $handler->getRecords());
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString(self::MARKER, var_export($record->toArray(), true));
        }
        $this->assertSame('TX1', $handler->getRecords()[0]->context['response']['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(object $object): array
    {
        return [
            'uri' => self::URI,
            'response' => [
                'checkout_id' => self::MARKER,
                'id' => 'TX1',
                'events' => [['CHECKOUT_TOKEN' => ['deep' => self::MARKER]], ['note' => 'see ' . self::URI]],
            ],
            'checkout_token' => self::MARKER,
            'object' => $object,
            'count' => 3,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertRedactedContext(array $context, object $object): void
    {
        $this->assertSame(
            [
                'uri' => 'https://api.global.sandbox.affirm.com/api/v2/checkout/[redacted]',
                'response' => [
                    'checkout_id' => '[redacted]',
                    'id' => 'TX1',
                    'events' => [
                        ['CHECKOUT_TOKEN' => '[redacted]'],
                        ['note' => 'see https://api.global.sandbox.affirm.com/api/v2/checkout/[redacted]'],
                    ],
                ],
                'checkout_token' => '[redacted]',
                'object' => $object,
                'count' => 3,
            ],
            $context
        );
    }

    private function requireMonolog3(): void
    {
        if (!class_exists(LogRecord::class)) {
            $this->markTestSkipped('Monolog 3 is not installed.');
        }
    }
}
