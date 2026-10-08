<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Checkout;

use Hyva\CheckoutAffirm\Model\Checkout\AccountingException;
use Hyva\CheckoutAffirm\Model\Checkout\ContactBuilder;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactBuilderTest extends TestCase
{
    /**
     * Keys of Affirm's current address schema as Read Checkout echoes them.
     */
    private const ADDRESS_KEYS = ['line1', 'line2', 'city', 'state', 'zipcode', 'country'];

    public function testPhysicalCartSendsBothContactsInAffirmsSchema(): void
    {
        $contacts = (new ContactBuilder())->build($this->quote($this->cart()));

        $this->assertSame(
            [
                'shipping' => [
                    'name' => ['first' => 'Ada', 'last' => 'Lovelace'],
                    'address' => [
                        'line1' => '1 Main St',
                        'line2' => 'Suite 2, Back door',
                        'city' => 'Boise',
                        'state' => 'ID',
                        'zipcode' => '83702',
                        'country' => 'USA',
                    ],
                    'phone_number' => '2085550100',
                    'email' => 'ada@example.com',
                ],
                'billing' => [
                    'name' => ['first' => 'Charles', 'last' => 'Babbage'],
                    'address' => [
                        'line1' => '9 Engine Rd',
                        'line2' => '',
                        'city' => 'Toronto',
                        'state' => 'ON',
                        'zipcode' => 'M5V 2T6',
                        'country' => 'CAN',
                    ],
                    'email' => 'ada@example.com',
                ],
            ],
            $contacts
        );
        $this->assertSame(self::ADDRESS_KEYS, array_keys($contacts['shipping']['address']));
    }

    public function testVirtualCartSendsTheBillingContactForBoth(): void
    {
        $cart = $this->cart();
        $cart['virtual'] = true;
        $cart['shipping'] = [];

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame($contacts['billing'], $contacts['shipping']);
        $this->assertSame('Charles', $contacts['shipping']['name']['first']);
    }

    public function testCustomerEmailComesFromTheQuote(): void
    {
        $cart = $this->cart();
        $cart['quote']['customer_email'] = ' customer@example.com ';

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame('customer@example.com', $contacts['shipping']['email']);
        $this->assertSame('customer@example.com', $contacts['billing']['email']);
    }

    public function testGuestEmailFallsBackToTheAddresses(): void
    {
        $cart = $this->cart();
        $cart['quote']['customer_email'] = null;
        $cart['billing']['email'] = '';
        $cart['shipping']['email'] = 'guest@example.com';

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame('guest@example.com', $contacts['billing']['email']);
    }

    public function testMissingEmailIsDeclined(): void
    {
        $cart = $this->cart();
        $cart['quote']['customer_email'] = '';
        $cart['billing']['email'] = ' ';
        $cart['shipping']['email'] = null;

        $exception = $this->decline($cart, AccountingException::REASON_MISSING_EMAIL);

        $this->assertSame(
            'Please complete your contact, address and shipping details before paying with Affirm.',
            $exception->getMessage()
        );
    }

    public function testPhysicalCartNeedsAShippingMethod(): void
    {
        $cart = $this->cart();
        $cart['shipping']['shipping_method'] = ' ';

        $this->decline($cart, AccountingException::REASON_MISSING_SHIPPING_METHOD);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function missingFields(): array
    {
        return [
            'shipping first name' => ['shipping', 'firstname', ''],
            'shipping last name' => ['shipping', 'lastname', null],
            'shipping street' => ['shipping', 'street', "\n "],
            'billing city' => ['billing', 'city', ' '],
            'billing postcode' => ['billing', 'postcode', null],
            'billing country' => ['billing', 'country_id', ''],
        ];
    }

    #[DataProvider('missingFields')]
    public function testIncompleteAddressIsDeclined(string $type, string $field, ?string $value): void
    {
        $cart = $this->cart();
        $cart[$type][$field] = $value;

        $exception = $this->decline($cart, AccountingException::REASON_INCOMPLETE_ADDRESS);

        $this->assertSame(['address' => $type, 'fields' => [$field]], $exception->getDiagnostics());
    }

    public function testRegionFallsBackToTheFreeTextRegion(): void
    {
        $cart = $this->cart();
        $cart['region_codes']['shipping'] = null;
        $cart['shipping']['region'] = 'Some County';

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame('Some County', $contacts['shipping']['address']['state']);
    }

    public function testRegionLookupFailureFallsBackToTheFreeTextRegion(): void
    {
        $cart = $this->cart();
        $cart['region_codes']['shipping'] = new \RuntimeException('region table unavailable');
        $cart['shipping']['region'] = 'Idaho';

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame('Idaho', $contacts['shipping']['address']['state']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function countries(): array
    {
        return [
            'United States' => ['US', 'USA'],
            'lower case' => ['ca', 'CAN'],
            'Puerto Rico' => ['PR', 'PRI'],
            'Guam' => ['GU', 'GUM'],
            'US Virgin Islands' => ['VI', 'VIR'],
            'American Samoa' => ['AS', 'ASM'],
            'Northern Mariana Islands' => ['MP', 'MNP'],
            'other' => ['MX', 'MX'],
        ];
    }

    #[DataProvider('countries')]
    public function testCountryIsSentAsAlpha3(string $countryId, string $expected): void
    {
        $cart = $this->cart();
        $cart['shipping']['country_id'] = $countryId;

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame($expected, $contacts['shipping']['address']['country']);
    }

    public function testStreetAsArray(): void
    {
        $cart = $this->cart();
        $cart['shipping']['street'] = ['  1 Main St ', '', 'Unit 4'];

        $contacts = (new ContactBuilder())->build($this->quote($cart));

        $this->assertSame('1 Main St', $contacts['shipping']['address']['line1']);
        $this->assertSame('Unit 4', $contacts['shipping']['address']['line2']);
    }

    /**
     * @return array<string, mixed>
     */
    private function cart(): array
    {
        return [
            'virtual' => false,
            'quote' => ['customer_email' => 'ada@example.com'],
            'shipping' => [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'street' => "1 Main St\nSuite 2\nBack door",
                'city' => 'Boise',
                'region' => 'Idaho',
                'postcode' => '83702',
                'country_id' => 'US',
                'telephone' => ' 2085550100 ',
                'email' => 'ada@example.com',
                'shipping_method' => 'flatrate_flatrate',
            ],
            'billing' => [
                'firstname' => ' Charles ',
                'lastname' => 'Babbage',
                'street' => '9 Engine Rd',
                'city' => 'Toronto',
                'region' => 'Ontario',
                'postcode' => 'M5V 2T6',
                'country_id' => 'CA',
                'telephone' => '',
                'email' => 'ada@example.com',
            ],
            'region_codes' => ['shipping' => 'ID', 'billing' => 'ON'],
        ];
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function decline(array $cart, string $reason): AccountingException
    {
        try {
            (new ContactBuilder())->build($this->quote($cart));
        } catch (AccountingException $exception) {
            $this->assertSame($reason, $exception->getReason());

            return $exception;
        }
        $this->fail('The cart was not declined.');
    }

    /**
     * @param array<string, mixed> $cart
     */
    private function quote(array $cart): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getData')->willReturnCallback(fn ($key = '') => $cart['quote'][$key] ?? null);
        $quote->method('isVirtual')->willReturn($cart['virtual']);
        $quote->method('getShippingAddress')->willReturn(
            $this->address($cart['shipping'], $cart['region_codes']['shipping'] ?? null)
        );
        $quote->method('getBillingAddress')->willReturn(
            $this->address($cart['billing'], $cart['region_codes']['billing'] ?? null)
        );

        return $quote;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function address(array $data, string|\Throwable|null $regionCode): Address
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(fn ($key = '') => $data[$key] ?? null);
        if ($regionCode instanceof \Throwable) {
            $address->method('getRegionCode')->willThrowException($regionCode);
        } else {
            $address->method('getRegionCode')->willReturn($regionCode);
        }

        return $address;
    }
}
