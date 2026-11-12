# Affirm for Hyvä Checkout (`Hyva_CheckoutAffirm`)

Lets shoppers pay with Affirm in Hyvä Checkout, using Affirm's official Magento 2 extension
([`affirm/magento2`](https://github.com/Affirm/Magento2_Affirm), module `Astound_Affirm`), in Affirm's modal or
redirect mode.

Without it, Hyvä Checkout lists Affirm but cannot place the order: Affirm needs a checkout token that only its Luma
JavaScript obtains. This module replaces that JavaScript and Affirm's confirm step. Settings, the gateway
(authorize, capture, void, refund), financing programs and the admin stay Affirm's.

For "as low as" messages, banners and the cart button, add
[`bredabeds/magento-hyva-affirm`](https://github.com/BredaBeds/magento-hyva-affirm). Each module works without the
other.

## Requirements

| | Supported | Tested |
|---|---|---|
| PHP | 8.1 – 8.5 (Hyvä Checkout 3 needs 8.2+) | 8.5 |
| Magento Open Source | 2.4.6 – 2.4.9 | 2.4.9 |
| Affirm extension | `affirm/magento2` ^4.0.11 | 4.0.11, US sandbox, USD |
| Hyvä Checkout | ^1.3.14 or ^3.0 | 1.3.15 |
| Magewire | ^1.13.3 or ^3.7.2 | 1.13.3 |
| Hyvä theme module | ^1.3.11 | 1.5.x with Tailwind 4 |

Hyvä Checkout 3 is supported from its source (see
[Magewire 1 and 3](docs/reference.md#magewire-1-and-3-hyvä-checkout-13-and-30)); a browser run on it is still to
do, as are older Magento and PHP versions and CAD stores.

## Installation

```sh
composer require bredabeds/magento-hyva-checkout-affirm
bin/magento module:enable Hyva_CheckoutAffirm
bin/magento setup:upgrade          # creates the table hyva_affirm_checkout_attempt
bin/magento hyva:config:generate
```

Then rebuild the CSS of the checkout's Hyvä theme (`npm run build-prod` in its `web/tailwind` folder). In production
mode also run `setup:di:compile`, `setup:static-content:deploy` and `cache:flush`. **Magento's cron must run:** one
job checks on stuck payments, another cleans up.

If Composer cannot find the package:
`composer config repositories.magento-hyva-checkout-affirm vcs https://github.com/BredaBeds/magento-hyva-checkout-affirm`.

## Configuration

The module has no settings of its own. It uses Affirm's (Stores > Configuration > Sales > Payment Methods > Affirm):

- **Checkout Flow Type:** `Redirect` sends the shopper to Affirm and back; anything else opens Affirm in a modal.
- **Minimum / Maximum Order Total, countries, currencies, backorders:** Affirm is offered only where these allow it.
  The cart must be in USD or CAD, and that must be the base currency.
- **Title, Sort Order, Icon and the other display settings:** how the method looks in the list.

Full list: [`docs/reference.md`](docs/reference.md#affirm-settings-honoured).

## How a payment works

1. The shopper picks Affirm and clicks Place Order. The server reserves the order number and records a
   **checkout attempt**.
2. Affirm opens. The shopper applies for the loan.
3. When Affirm approves, the server reads the checkout back from Affirm and checks its status, order number, total
   and currency. Only then is the order placed, through Magento's standard placement.

Cancelled or declined: no order, cart untouched. Refreshing the return page never creates a second order. If the
cart changed while the shopper was at Affirm, they are asked to pay again. Carts with charges Affirm cannot itemise
(gift cards, store credit, unknown fees) are declined; see [Accounting rules](docs/reference.md#accounting-rules).

## When a payment needs review

Rarely, a placement fails after Affirm has already authorized the loan (a crash or timeout mid-placement). The
module never retries it. The attempt is marked `uncertain`, the shopper is told not to pay again, **the cart is
blocked for every payment method**, and an admin inbox message and a CRITICAL log line are written.

1. Find the attempt under Sales > Operations > Affirm Checkout Attempts, or with
   `bin/magento hyva:affirm:attempt:list`.
2. Look up the payment in the Affirm merchant dashboard by the order number or transaction id shown.
3. Resolve it:
   - **an order exists:** `bin/magento hyva:affirm:attempt:resolve <attempt-id> --outcome=placed --order=<number>`
   - **no order exists:** void the authorization in Affirm's dashboard, then
     `bin/magento hyva:affirm:attempt:resolve <attempt-id> --outcome=voided`. This unblocks the cart.

## Security

- A return URL alone cannot confirm a payment: it needs the session that started it (or the same customer account),
  and Affirm must confirm the checkout for this order number and amount.
- Checkout tokens are kept out of Magento's and Affirm's logs.
- **Your web server's access logs are not covered:** the redirect return carries `?checkout_token=`. Configure the
  web server, load balancer and CDN to drop the query string for `/hyva_affirm/checkout/confirm/`.

More: [Security and privacy](docs/reference.md#security-and-privacy).

## For developers

- **Hide Affirm:** a standard `payment_method_is_active` observer is honoured before Affirm opens, on the return and
  at order placement.
- **Allow a custom total:** add its code to the `ignoredTotalCodes` argument of
  `Hyva\CheckoutAffirm\Model\Checkout\TotalsBuilder`.
- [`docs/reference.md`](docs/reference.md) has the rest: attempt states, the confirmation steps, cron jobs, the
  other extension points, sandbox tips and known Affirm issues.
- Tests: `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/bredabeds/magento-hyva-checkout-affirm/Test/Unit`
  on a Magento install, `node --test Test/js/*.test.mjs` for the JavaScript, and
  [`Test/ci/README.md`](Test/ci/README.md) for what CI runs without one.

## Turning it off

1. Set `payment/affirm_gateway/active` to 0 and run `bin/magento cache:clean config full_page`. Existing orders can
   still be captured, refunded and voided.
2. Resolve anything `bin/magento hyva:affirm:attempt:list` still shows.
3. `bin/magento module:disable Hyva_CheckoutAffirm`, `setup:upgrade`, `hyva:config:generate`, rebuild the theme CSS.

Keep Affirm disabled while the module is off: Hyvä Checkout would list it again but could not place the order.

## Licence

MIT, see [`LICENSE`](LICENSE). The licence does not cover the Affirm name or logo; the bundled logo is Affirm's
trademark, see [`TRADEMARKS.md`](TRADEMARKS.md) and [`NOTICE`](NOTICE). Not affiliated with or endorsed by Affirm,
Inc. or Hyvä Themes B.V.

[Contributing](CONTRIBUTING.md) · [Changelog](CHANGELOG.md)
