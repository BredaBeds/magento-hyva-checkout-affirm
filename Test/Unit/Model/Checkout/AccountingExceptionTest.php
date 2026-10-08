<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccountingExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function messages(): array
    {
        $generic = 'Affirm is not available for this cart.';
        $incomplete = 'Please complete your contact, address and shipping details before paying with Affirm.';
        $currency = 'Affirm is not available for the currency of this cart.';

        return [
            AccountingException::REASON_ORDER_NOT_RESERVED => [
                AccountingException::REASON_ORDER_NOT_RESERVED,
                $generic,
            ],
            AccountingException::REASON_NOT_CONFIGURED => [AccountingException::REASON_NOT_CONFIGURED, $generic],
            AccountingException::REASON_UNSUPPORTED_CURRENCY => [
                AccountingException::REASON_UNSUPPORTED_CURRENCY,
                $currency,
            ],
            AccountingException::REASON_CURRENCY_MISMATCH => [AccountingException::REASON_CURRENCY_MISMATCH, $currency],
            AccountingException::REASON_MULTISHIPPING => [AccountingException::REASON_MULTISHIPPING, $generic],
            AccountingException::REASON_ZERO_TOTAL => [AccountingException::REASON_ZERO_TOTAL, $generic],
            AccountingException::REASON_UNSUPPORTED_ADJUSTMENT => [
                AccountingException::REASON_UNSUPPORTED_ADJUSTMENT,
                $generic,
            ],
            AccountingException::REASON_UNEXPLAINED_DIFFERENCE => [
                AccountingException::REASON_UNEXPLAINED_DIFFERENCE,
                $generic,
            ],
            AccountingException::REASON_NO_ITEMS => [AccountingException::REASON_NO_ITEMS, $generic],
            AccountingException::REASON_NON_INTEGER_QTY => [AccountingException::REASON_NON_INTEGER_QTY, $generic],
            AccountingException::REASON_ITEMS_MISMATCH => [AccountingException::REASON_ITEMS_MISMATCH, $generic],
            AccountingException::REASON_INCOMPLETE_ADDRESS => [
                AccountingException::REASON_INCOMPLETE_ADDRESS,
                $incomplete,
            ],
            AccountingException::REASON_MISSING_EMAIL => [AccountingException::REASON_MISSING_EMAIL, $incomplete],
            AccountingException::REASON_MISSING_SHIPPING_METHOD => [
                AccountingException::REASON_MISSING_SHIPPING_METHOD,
                $incomplete,
            ],
            AccountingException::REASON_FINANCING_PROGRAM => [AccountingException::REASON_FINANCING_PROGRAM, $generic],
        ];
    }

    #[DataProvider('messages')]
    public function testDefaultMessageIsShopperSafe(string $reason, string $message): void
    {
        $exception = new AccountingException($reason);

        $this->assertInstanceOf(LocalizedException::class, $exception);
        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($reason, $exception->getReason());
        $this->assertNull($exception->getPrevious());
    }

    public function testCustomMessageAndLogContext(): void
    {
        $exception = new AccountingException(
            AccountingException::REASON_UNSUPPORTED_ADJUSTMENT,
            new Phrase('Gift cards cannot be combined with Affirm.'),
            ['codes' => ['giftcardaccount']]
        );

        $this->assertSame('Gift cards cannot be combined with Affirm.', $exception->getMessage());
        $this->assertSame(['codes' => ['giftcardaccount']], $exception->getDiagnostics());
        $this->assertSame(
            ['reason' => 'unsupported_adjustment', 'codes' => ['giftcardaccount']],
            $exception->getLogContext()
        );
    }

    public function testDiagnosticsCannotOverrideTheReason(): void
    {
        $exception = new AccountingException(AccountingException::REASON_ZERO_TOTAL, null, ['reason' => 'other']);

        $this->assertSame('zero_total', $exception->getLogContext()['reason']);
    }
}
