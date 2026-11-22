<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Controller\Checkout;

use Hyva\CheckoutAffirm\Controller\Checkout\Prepare;
use Hyva\CheckoutAffirm\Model\Attempt\Attempt;
use Hyva\CheckoutAffirm\Model\Attempt\State;
use Hyva\CheckoutAffirm\Model\Checkout\Preparation;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PrepareTest extends TestCase
{
    private const ATTEMPT_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @var Preparation&MockObject */
    private Preparation $preparation;

    /** @var Quote&MockObject */
    private Quote $quote;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, string> */
    private array $headers = [];

    private ?int $status = null;

    private bool $formKeyValid = true;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->preparation = $this->createMock(Preparation::class);
        $this->quote = $this->createMock(Quote::class);
    }

    public function testIsAPostActionThatChecksTheFormKeyItself(): void
    {
        $interfaces = class_implements(Prepare::class);
        $this->assertContains(HttpPostActionInterface::class, $interfaces);
        $this->assertNotContains(HttpGetActionInterface::class, $interfaces);
        // Magento's own check skips the form key for X-Requested-With requests, so the action decides.
        $this->assertContains(CsrfAwareActionInterface::class, $interfaces);

        $request = $this->createMock(RequestInterface::class);
        $this->assertTrue($this->controller()->validateForCsrf($request));
        $this->formKeyValid = false;
        $this->assertFalse($this->controller()->validateForCsrf($request));
    }

    public function testARefusedFormKeyIsAnsweredWithAMessageThePageCanShow(): void
    {
        $this->preparation->expects($this->never())->method('prepare');

        $exception = $this->controller()->createCsrfValidationException($this->createMock(RequestInterface::class));

        $this->assertInstanceOf(Json::class, $exception->getReplaceResult());
        $this->assertSame(403, $this->status);
        $this->assertSame(
            ['error' => 'Your session has expired. Please reload the page and place the order again.'],
            $this->data
        );
    }

    public function testAnswersWithTheCheckoutObjectAttemptAndMode(): void
    {
        $checkout = ['order_id' => '000000123', 'total' => 132025];
        $this->preparation->expects($this->once())->method('prepare')->with($this->quote, 'redirect')
            ->willReturn([$checkout, $this->attempt()]);

        $this->controller()->execute();

        $this->assertSame(['checkout' => $checkout, 'attemptId' => self::ATTEMPT_ID, 'mode' => 'redirect'], $this->data);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $this->headers['Cache-Control']);
        $this->assertSame(200, $this->status);
    }

    public function testAnswersWithTheShopperMessageOfARefusal(): void
    {
        $this->preparation->method('prepare')
            ->willThrowException(new LocalizedException(__('Please sign in or create an account to place this order.')));

        $this->controller()->execute();

        $this->assertSame(['error' => 'Please sign in or create an account to place this order.'], $this->data);
        $this->assertSame([], $this->errors);
    }

    public function testUnexpectedFailuresAreLoggedWithoutTheirMessage(): void
    {
        $this->preparation->method('prepare')->willThrowException(new RuntimeException('customer ada@example.com'));

        $this->controller()->execute();

        $this->assertSame(
            ['error' => 'Affirm is not available right now. Please try again or choose another payment method.'],
            $this->data
        );
        $this->assertCount(1, $this->errors);
        $this->assertSame(RuntimeException::class, $this->errors[0][1]['exception']);
        $this->assertStringNotContainsString('ada@example.com', (string) json_encode($this->errors));
    }

    private function controller(): Prepare
    {
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($this->quote);

        $method = $this->createMock(Method::class);
        $method->method('getMode')->willReturn('redirect');

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function (array $data) use ($json): Json {
            $this->data = $data;

            return $json;
        });
        $json->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($json): Json {
            $this->headers[$name] = $value;

            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($json): Json {
            $this->status = $code;

            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->errors[] = [$message, $context];
        });

        $formKeyValidator = $this->createMock(FormKeyValidator::class);
        $formKeyValidator->method('validate')->willReturnCallback(fn (): bool => $this->formKeyValid);

        return new Prepare($session, $this->preparation, $method, $jsonFactory, $logger, $formKeyValidator);
    }

    private function attempt(): Attempt
    {
        return new Attempt(
            self::ATTEMPT_ID,
            str_repeat('a', 64),
            42,
            1,
            null,
            '000000123',
            'USD',
            132025,
            'affirm_gateway',
            str_repeat('b', 64),
            State::INITIATED,
            null,
            null,
            null,
            null,
            null,
            '2026-10-08 10:00:00',
            '2026-10-08 10:00:00',
            '2026-10-08 11:00:00'
        );
    }
}
