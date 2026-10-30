<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Controller\Checkout;

use Hyva\CheckoutAffirm\Controller\Checkout\Confirm;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationOutcome;
use Hyva\CheckoutAffirm\Model\Checkout\ConfirmationPipelineInterface;
use Hyva\CheckoutAffirm\Model\Checkout\PaymentStep;
use Hyva\CheckoutAffirm\Model\Checkout\PrivateContentInvalidator;
use Hyva\CheckoutAffirm\Model\Checkout\SuccessSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ConfirmTest extends TestCase
{
    private const ATTEMPT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN = 'MARKERTOKEN0001';
    private const NO_STORE = 'no-store, no-cache, must-revalidate, max-age=0';

    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @var bool
     */
    private bool $post = false;

    /**
     * @var ConfirmationOutcome|\Throwable
     */
    private ConfirmationOutcome|\Throwable $outcome;

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $pipelineCalls = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $redirects = [];

    /**
     * @var array<string, mixed>
     */
    private array $page = [];

    /**
     * @var list<string>
     */
    private array $calls = [];

    /**
     * @var list<string>
     */
    private array $errors = [];

    /**
     * @var \Throwable|null thrown by SuccessSession::armForOutcome()
     */
    private ?\Throwable $armFailure = null;

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $logged = [];

    /**
     * @var Template&MockObject
     */
    private Template $statusBlock;

    /**
     * @var Confirm
     */
    private Confirm $controller;

    protected function setUp(): void
    {
        $this->outcome = ConfirmationOutcome::placed(555, '000000123', 42);

        $request = $this->createMock(Http::class);
        $request->method('isPost')->willReturnCallback(fn (): bool => $this->post);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $this->params[$name] ?? null);

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturnCallback(fn (): Redirect => $this->newRedirect());

        $this->statusBlock = $this->getMockBuilder(Template::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toHtml'])
            ->getMock();
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturnCallback(fn (): Page => $this->newPage());

        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            fn (string $route, array $params = []): string => $route . ' ' . json_encode($params)
        );

        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string) $message;

            return $messages;
        });

        $pipeline = $this->createMock(ConfirmationPipelineInterface::class);
        $pipeline->method('confirm')->willReturnCallback(
            function (string $attemptId, string $token, $quote = null): ConfirmationOutcome {
                $this->assertNull($quote);
                $this->pipelineCalls[] = [$attemptId, $token];
                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        );

        $successSession = $this->createMock(SuccessSession::class);
        $successSession->method('armForOutcome')->willReturnCallback(function (ConfirmationOutcome $outcome): bool {
            $this->assertSame($this->outcome, $outcome);
            $this->calls[] = 'armForOutcome';
            if ($this->armFailure !== null) {
                throw $this->armFailure;
            }

            return true;
        });
        $invalidator = $this->createMock(PrivateContentInvalidator::class);
        $invalidator->method('invalidate')->willReturnCallback(function (): void {
            $this->calls[] = 'invalidate';
        });
        $paymentStep = $this->createMock(PaymentStep::class);
        $paymentStep->method('applyTo')->willReturnCallback(function (Redirect $redirect): Redirect {
            $redirect->setPath('checkout', ['_query' => ['step' => 'payment']]);

            return $redirect;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logged[] = [$message, $context];
        });

        $this->controller = new Confirm(
            $request,
            $redirectFactory,
            $pageFactory,
            $url,
            $messages,
            $pipeline,
            $successSession,
            $invalidator,
            $paymentStep,
            $logger
        );
    }

    public function testPostIsAnsweredWithA303ToTheSameUrlAsAGet(): void
    {
        $this->post = true;
        $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

        $result = $this->controller->execute();

        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame([], $this->pipelineCalls);
        $this->assertSame([], $this->calls, 'Nothing touches the session on POST.');
        $this->assertSame([], $this->errors);
        $this->assertSame(303, $this->redirects[0]['code']);
        $this->assertSame(
            'hyva_affirm/checkout/confirm ' . json_encode([
                '_secure' => true,
                '_nosid' => true,
                'attempt' => self::ATTEMPT_ID,
                '_query' => ['checkout_token' => self::TOKEN],
            ]),
            $this->redirects[0]['url']
        );
        $this->assertNoStore($this->redirects[0]);
    }

    public function testPostAcceptsCheckoutIdAndDropsAMalformedAttemptId(): void
    {
        $this->post = true;
        $this->params = ['attempt' => '../x', 'checkout_id' => ' ' . self::TOKEN . ' '];

        $this->controller->execute();

        $this->assertSame(
            'hyva_affirm/checkout/confirm ' . json_encode([
                '_secure' => true,
                '_nosid' => true,
                '_query' => ['checkout_token' => self::TOKEN],
            ]),
            $this->redirects[0]['url']
        );
    }

    public function testPlacedOrderGoesToTheSuccessPage(): void
    {
        foreach ([false, true] as $replay) {
            $this->reset();
            $this->outcome = ConfirmationOutcome::placed(555, '000000123', 42, $replay);
            $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

            $this->controller->execute();

            $this->assertSame([[self::ATTEMPT_ID, self::TOKEN]], $this->pipelineCalls);
            $this->assertSame(['armForOutcome', 'invalidate'], $this->calls);
            $this->assertSame('checkout/onepage/success {"_secure":true}', $this->redirects[0]['url']);
            $this->assertArrayNotHasKey('code', $this->redirects[0]);
            $this->assertNoStore($this->redirects[0]);
            $this->assertSame([], $this->errors);
        }
    }

    public function testPlacedOrderStillGoesToTheSuccessPageWhenItCannotBePrepared(): void
    {
        $this->outcome = ConfirmationOutcome::placed(555, '000000123', 42);
        $this->armFailure = new RuntimeException('order load failed for ' . self::TOKEN);
        $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

        $this->controller->execute();

        $this->assertSame(['armForOutcome', 'invalidate'], $this->calls);
        $this->assertSame('checkout/onepage/success {"_secure":true}', $this->redirects[0]['url']);
        $this->assertNoStore($this->redirects[0]);
        $this->assertSame([], $this->errors);
        $this->assertSame(
            [[
                'Affirm checkout: the success page could not be prepared for a placed order.',
                ['exception_class' => RuntimeException::class],
            ]],
            $this->logged
        );
    }

    public function testFailureGoesToThePaymentStepWithTheMessage(): void
    {
        $this->outcome = ConfirmationOutcome::failed('cart_changed', 'Your cart changed.', 42);
        $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

        $this->controller->execute();

        $this->assertSame(['Your cart changed.'], $this->errors);
        $this->assertToPaymentStep();
        $this->assertSame([], $this->calls);
    }

    public function testMalformedAttemptIdGoesToThePaymentStep(): void
    {
        $this->params = ['attempt' => 'not-an-attempt', 'checkout_token' => self::TOKEN];

        $this->controller->execute();

        $this->assertSame([], $this->pipelineCalls);
        $this->assertCount(1, $this->errors);
        $this->assertToPaymentStep();
    }

    public function testPipelineErrorGoesToThePaymentStep(): void
    {
        $this->outcome = new RuntimeException('database gone ' . self::TOKEN);
        $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

        $this->controller->execute();

        $this->assertCount(1, $this->errors);
        $this->assertStringNotContainsString(self::TOKEN, $this->errors[0]);
        $this->assertToPaymentStep();
    }

    public function testUncertainDropsTheTokenFromTheAddressBar(): void
    {
        $this->outcome = ConfirmationOutcome::uncertain('We are confirming order #000000123.', 42, '000000123');
        $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

        $this->controller->execute();

        $this->assertSame(
            'hyva_affirm/checkout/confirm '
            . json_encode(['_secure' => true, '_nosid' => true, 'attempt' => self::ATTEMPT_ID]),
            $this->redirects[0]['url']
        );
        $this->assertSame([], $this->page);
    }

    public function testInProgressRendersTheRefreshingPage(): void
    {
        $this->outcome = ConfirmationOutcome::inProgress('Being confirmed.', 42);
        $this->params = ['attempt' => self::ATTEMPT_ID];

        $result = $this->controller->execute();

        $this->assertInstanceOf(Page::class, $result);
        $this->assertSame([[self::ATTEMPT_ID, '']], $this->pipelineCalls);
        $this->assertSame('Confirming your payment', $this->page['title']);
        $this->assertSame([], $this->page['unset']);
        $this->assertSame('in_progress', $this->statusBlock->getData('status'));
        $this->assertSame('Being confirmed.', $this->statusBlock->getData('message'));
        $this->assertNoStore($this->page);
        $this->assertSame([], $this->redirects);
    }

    public function testInProgressKeepsTheTokenForTheRefresh(): void
    {
        foreach ([ConfirmationOutcome::REASON_IN_PROGRESS, ConfirmationOutcome::REASON_RETRY] as $reason) {
            $this->outcome = ConfirmationOutcome::inProgress('Being confirmed.', 42, $reason);
            $this->params = ['attempt' => self::ATTEMPT_ID, 'checkout_token' => self::TOKEN];

            $this->assertInstanceOf(Page::class, $this->controller->execute(), $reason);
            $this->assertSame([], $this->page['unset']);
            $this->assertSame([], $this->redirects);
        }
    }

    public function testUncertainRendersThePageWithoutRefresh(): void
    {
        $this->outcome = ConfirmationOutcome::uncertain('We are confirming order #000000123.', 42, '000000123');
        $this->params = ['attempt' => self::ATTEMPT_ID];

        $this->assertInstanceOf(Page::class, $this->controller->execute());
        $this->assertSame([Confirm::REFRESH_BLOCK], $this->page['unset']);
        $this->assertSame('uncertain', $this->statusBlock->getData('status'));
        $this->assertSame('000000123', $this->statusBlock->getData('order_reference'));
    }

    public function testCsrfValidationIsSkippedForTheReturnFromAffirm(): void
    {
        $request = $this->createMock(RequestInterface::class);

        $this->assertNull($this->controller->createCsrfValidationException($request));
        $this->assertTrue($this->controller->validateForCsrf($request));
    }

    /**
     * Assert the last redirect goes to the payment step without the token.
     *
     * @return void
     */
    private function assertToPaymentStep(): void
    {
        $this->assertCount(1, $this->redirects);
        $this->assertSame(['checkout', ['_query' => ['step' => 'payment']]], $this->redirects[0]['path']);
        $this->assertArrayNotHasKey('url', $this->redirects[0]);
        $this->assertNoStore($this->redirects[0]);
    }

    /**
     * Assert the no-cache headers on a recorded result.
     *
     * @param array<string, mixed> $result
     * @return void
     */
    private function assertNoStore(array $result): void
    {
        $this->assertSame(self::NO_STORE, $result['headers']['Cache-Control'] ?? null);
        $this->assertSame('no-cache', $result['headers']['Pragma'] ?? null);
        $this->assertSame('no-referrer', $result['headers']['Referrer-Policy'] ?? null);
    }

    /**
     * Clear what the previous case recorded.
     *
     * @return void
     */
    private function reset(): void
    {
        $this->pipelineCalls = [];
        $this->redirects = [];
        $this->calls = [];
        $this->errors = [];
    }

    /**
     * A redirect result that records what is set on it.
     *
     * @return Redirect
     */
    private function newRedirect(): Redirect
    {
        $index = count($this->redirects);
        $this->redirects[$index] = ['headers' => []];
        $redirect = $this->getMockBuilder(Redirect::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setUrl', 'setPath', 'setHttpResponseCode', 'setHeader'])
            ->getMock();
        $redirect->method('setUrl')->willReturnCallback(function (string $url) use ($index, $redirect) {
            $this->redirects[$index]['url'] = $url;

            return $redirect;
        });
        $redirect->method('setPath')->willReturnCallback(
            function (string $path, array $params) use ($index, $redirect) {
                $this->redirects[$index]['path'] = [$path, $params];

                return $redirect;
            }
        );
        $redirect->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($index, $redirect) {
            $this->redirects[$index]['code'] = $code;

            return $redirect;
        });
        $redirect->method('setHeader')->willReturnCallback(
            function (string $name, string $value, bool $replace) use ($index, $redirect) {
                $this->assertTrue($replace);
                $this->redirects[$index]['headers'][$name] = $value;

                return $redirect;
            }
        );

        return $redirect;
    }

    /**
     * A page result that records its title, removed elements and headers.
     *
     * @return Page
     */
    private function newPage(): Page
    {
        $this->page = ['headers' => [], 'unset' => [], 'title' => null];
        $title = $this->createMock(Title::class);
        $title->method('set')->willReturnCallback(function ($value): void {
            $this->page['title'] = (string) $value;
        });
        $config = $this->createMock(Config::class);
        $config->method('getTitle')->willReturn($title);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturnCallback(
            fn (string $name) => $name === Confirm::STATUS_BLOCK ? $this->statusBlock : false
        );
        $layout->method('unsetElement')->willReturnCallback(function (string $name) use ($layout) {
            $this->page['unset'][] = $name;

            return $layout;
        });
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->method('getLayout')->willReturn($layout);
        $page->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($page) {
            $this->page['headers'][$name] = $value;

            return $page;
        });

        return $page;
    }
}
