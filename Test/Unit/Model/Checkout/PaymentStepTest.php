<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\Checkout\Exception\CheckoutNotFoundException;
use Hyva\Checkout\Model\Config;
use Hyva\CheckoutAffirm\Model\Checkout\PaymentStep;
use Magento\Framework\Controller\Result\Redirect;
use PHPUnit\Framework\TestCase;

class PaymentStepTest extends TestCase
{
    public function testRedirectsToThePaymentStepWhenTheCheckoutHasOne(): void
    {
        $step = $this->paymentStep([
            'steps' => [
                'shipping' => ['name' => 'shipping', 'route' => 'shipping'],
                'review' => ['name' => 'review', 'route' => 'payment'],
            ],
        ]);

        $this->assertTrue($step->hasPaymentStep());
        $this->assertRedirectsTo($step, ['_query' => ['step' => 'payment']]);
    }

    public function testRedirectsToTheCheckoutOtherwise(): void
    {
        $withoutPayment = $this->paymentStep(['steps' => ['payment' => ['name' => 'payment', 'route' => 'pay']]]);
        $malformed = $this->paymentStep(['steps' => 'payment']);
        $luma = $this->paymentStep(new CheckoutNotFoundException('Checkout "magento_luma" could not be found'));

        foreach ([$withoutPayment, $malformed, $luma] as $step) {
            $this->assertFalse($step->hasPaymentStep());
            $this->assertRedirectsTo($step, []);
        }
    }

    /**
     * Assert the redirect path and parameters.
     *
     * @param PaymentStep $step
     * @param array<string, mixed> $params
     * @return void
     */
    private function assertRedirectsTo(PaymentStep $step, array $params): void
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())->method('setPath')->with('checkout', $params)->willReturnSelf();

        $this->assertSame($redirect, $step->applyTo($redirect));
    }

    /**
     * A PaymentStep over a checkout configuration (or the exception reading it throws).
     *
     * @param array<string, mixed>|\Throwable $data
     * @return PaymentStep
     */
    private function paymentStep(array|\Throwable $data): PaymentStep
    {
        $config = $this->createMock(Config::class);
        if ($data instanceof \Throwable) {
            $config->method('getActiveCheckoutData')->willThrowException($data);
        } else {
            $config->method('getActiveCheckoutData')->willReturn($data);
        }

        return new PaymentStep($config);
    }
}
