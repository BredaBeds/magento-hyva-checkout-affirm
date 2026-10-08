<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Model\Checkout;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;

/**
 * Affirm shipping and billing objects in Affirm's current schema:
 * name {first, last}, address {line1, line2, city, state, zipcode, country}, phone_number, email.
 *
 * Virtual quotes send the billing contact for both. The data Affirm needs (names, street, city, postcode,
 * country, e-mail and, for physical carts, a shipping method) is required before Affirm opens.
 */
class ContactBuilder
{
    /**
     * ISO 3166-1 alpha-3 codes for the countries Affirm's extension allows (its default specificcountry list);
     * the sandbox accepts and echoes alpha-3. Any other country is sent as stored.
     */
    private const ALPHA3 = [
        'US' => 'USA',
        'CA' => 'CAN',
        'AS' => 'ASM',
        'GU' => 'GUM',
        'MP' => 'MNP',
        'PR' => 'PRI',
        'VI' => 'VIR',
    ];

    private const REQUIRED_FIELDS = ['firstname', 'lastname', 'street', 'city', 'postcode', 'country_id'];

    /**
     * Shipping and billing contacts.
     *
     * @param Quote $quote
     * @return array{shipping: array<string, mixed>, billing: array<string, mixed>}
     * @throws AccountingException
     */
    public function build(Quote $quote): array
    {
        $email = $this->email($quote);
        $billing = $this->contact($quote->getBillingAddress(), $email, 'billing');
        if ($quote->isVirtual()) {
            return ['shipping' => $billing, 'billing' => $billing];
        }

        $shippingAddress = $quote->getShippingAddress();
        if ($this->text($shippingAddress->getData('shipping_method')) === '') {
            throw new AccountingException(AccountingException::REASON_MISSING_SHIPPING_METHOD);
        }

        return ['shipping' => $this->contact($shippingAddress, $email, 'shipping'), 'billing' => $billing];
    }

    /**
     * Customer e-mail: the quote's (customers and guests alike), else the one on an address.
     *
     * @param Quote $quote
     * @return string
     * @throws AccountingException
     */
    private function email(Quote $quote): string
    {
        foreach ([$quote, $quote->getBillingAddress(), $quote->getShippingAddress()] as $source) {
            $email = $this->text($source->getData($source === $quote ? 'customer_email' : 'email'));
            if ($email !== '') {
                return $email;
            }
        }

        throw new AccountingException(AccountingException::REASON_MISSING_EMAIL);
    }

    /**
     * One contact object.
     *
     * @param Address $address
     * @param string $email
     * @param string $type shipping|billing, for the log only
     * @return array<string, mixed>
     * @throws AccountingException
     */
    private function contact(Address $address, string $email, string $type): array
    {
        $lines = $this->streetLines($address->getData('street'));
        $values = [
            'firstname' => $this->text($address->getData('firstname')),
            'lastname' => $this->text($address->getData('lastname')),
            'street' => $lines[0] ?? '',
            'city' => $this->text($address->getData('city')),
            'postcode' => $this->text($address->getData('postcode')),
            'country_id' => strtoupper($this->text($address->getData('country_id'))),
        ];
        $missing = array_keys(array_filter($values, static fn (string $value): bool => $value === ''));
        if ($missing !== []) {
            throw new AccountingException(
                AccountingException::REASON_INCOMPLETE_ADDRESS,
                null,
                ['address' => $type, 'fields' => array_values(array_intersect(self::REQUIRED_FIELDS, $missing))]
            );
        }

        $contact = [
            'name' => ['first' => $values['firstname'], 'last' => $values['lastname']],
            'address' => [
                'line1' => $values['street'],
                'line2' => implode(', ', array_slice($lines, 1)),
                'city' => $values['city'],
                'state' => $this->state($address),
                'zipcode' => $values['postcode'],
                'country' => self::ALPHA3[$values['country_id']] ?? $values['country_id'],
            ],
        ];
        $phone = $this->text($address->getData('telephone'));
        if ($phone !== '') {
            $contact['phone_number'] = $phone;
        }
        $contact['email'] = $email;

        return $contact;
    }

    /**
     * Region code (state or province), or the free-text region where the country has no region list.
     *
     * @param Address $address
     * @return string
     */
    private function state(Address $address): string
    {
        try {
            $code = $this->text($address->getRegionCode());
        } catch (\Throwable) {
            $code = '';
        }

        return $code !== '' ? $code : $this->text($address->getData('region'));
    }

    /**
     * Non-empty street lines; Magento stores them newline-separated.
     *
     * @param mixed $street
     * @return list<string>
     */
    private function streetLines(mixed $street): array
    {
        $lines = is_array($street) ? $street : explode("\n", $this->text($street));

        return array_values(array_filter(
            array_map(fn (mixed $line): string => $this->text($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * Trimmed string; non-scalars become empty.
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
