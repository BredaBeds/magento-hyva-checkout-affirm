# Affirm for Hyvä Checkout (`Hyva_CheckoutAffirm`)

Lets shoppers pay with Affirm in Hyvä Checkout, using Affirm's official Magento 2 extension
([`affirm/magento2`](https://github.com/Affirm/Magento2_Affirm), module `Astound_Affirm`).

- Affirm as a payment method, in Affirm's modal (lightbox) or redirect mode
- Affirm's inline checkout widget ("edu" messaging) in the payment method
- the order is created only after Affirm has confirmed the loan, through Magento's standard order placement
- an admin grid and CLI commands for the rare payment that needs a manual check

| | |
|---|---|
| Composer package | `bredabeds/magento-hyva-checkout-affirm` |
| Magento module | `Hyva_CheckoutAffirm` |
| Licence | MIT (the Affirm logo is not covered, see [Licence](#licence)) |
| Storefront | Use [`bredabeds/magento-hyva-affirm`](https://github.com/BredaBeds/magento-hyva-affirm) for "as low as" messages, banners and the cart button. The two modules work alone or together. |

## Why this module exists

Affirm's FAQ says its extension "is not natively compatible with Hyva". Hyvä Checkout lists Affirm as a payment
method, but the order cannot be placed: Affirm needs a checkout token that only its Luma JavaScript obtains.

This module replaces that JavaScript and Affirm's confirm step. Everything else stays Affirm's: settings, the
gateway (authorize, capture, void, refund), financing programs, the admin, REST and GraphQL.

## Requirements

| | Supported | Tested |
|---|---|---|
| PHP | 8.1 – 8.5 (Hyvä Checkout 3 needs 8.2+) | 8.5 |
| Magento Open Source | 2.4.6 – 2.4.9 | 2.4.9 (unit tests also on 2.4.7) |
| Affirm extension | `affirm/magento2` ^4.0.11 | 4.0.11, US sandbox, USD |
| Hyvä Checkout | ^1.3.14 or ^3.0 | 1.3.15 in the browser; 3.0.0 checked against its source |
| Magewire | ^1.13.3 (Checkout 1.3) or ^3.7.2 (Checkout 3) | 1.13.3 in the browser |
| Hyvä theme module | ^1.3.11 | 1.5.x with Tailwind 4 |

**Hyvä Checkout 3:** the module does not need Checkout 3's Magewire 1 compatibility layer. Its source has been
compared with Hyvä Checkout 3.0.0 and Magewire 3.7.2, and the payment JavaScript handles the two differences that
matter (see [`docs/reference.md`](docs/reference.md#magewire-1-and-3-hyvä-checkout-13-and-30)). A browser run on
Checkout 3 is still to do.

Not tested yet in a browser: Magento 2.4.6 and 2.4.8, PHP 8.1 – 8.4, CAD stores, virtual-only and
bundle/configurable carts (covered by unit tests) and keyboard use inside Affirm's own modal.

## Installation

```sh
composer require bredabeds/magento-hyva-checkout-affirm
bin/magento module:enable Hyva_CheckoutAffirm
bin/magento setup:upgrade          # creates the table hyva_affirm_checkout_attempt
bin/magento hyva:config:generate
```

Then rebuild the CSS of the Hyvä theme the checkout uses:

```sh
cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm run build-prod
```

In production mode, also run `bin/magento setup:di:compile`, `bin/magento setup:static-content:deploy` and
`bin/magento cache:flush`. Magento's cron must run: the module has a cleanup job and a job that checks on stuck
payments.

If Composer cannot find the package, add the repository first:
`composer config repositories.magento-hyva-checkout-affirm vcs https://github.com/BredaBeds/magento-hyva-checkout-affirm`.

## Configuration

The module has no settings of its own. It uses Affirm's payment settings
(Stores > Configuration > Sales > Payment Methods > Affirm):

- **Enabled, Mode, API keys:** as usual. CAD carts use the `_ca` keys.
- **Checkout Flow Type:** `Redirect` sends the shopper to Affirm and back; anything else opens Affirm in a modal on
  the checkout page.
- **Inline Checkout Messaging:** shows Affirm's widget in the payment method (US only).
- **Minimum / Maximum Order Total, countries, currencies, backorder setting:** Affirm is offered only where these
  allow it. The module also requires USD or CAD, with the base currency equal to the cart currency.
- **Title, Sort Order, Icon, Plain Text Appearance, Payment Method HTML:** how the method looks in the list.

The full list is in [`docs/reference.md`](docs/reference.md#affirm-settings-honoured).

## How a payment works

1. The shopper picks Affirm and clicks Place Order. Hyvä runs its usual checks.
2. The server checks again that Affirm is available, reserves the order number and records a **checkout
   attempt** (one row in `hyva_affirm_checkout_attempt`).
3. Affirm opens in a modal or as a redirect. The shopper applies for the loan.
4. When Affirm approves, the server reads the checkout back from Affirm's API and checks its status, order number,
   total and currency. Only then is the order placed, through Magento's standard placement, which sends Affirm's
   authorization.
5. The shopper lands on the success page.

If the shopper cancels or is declined, no order exists and the cart is untouched. Refreshing the return page or
coming back twice never creates a second order.

The cart is checked again before the order is placed. If it changed while the shopper was at Affirm (items, prices,
addresses, shipping), the shopper is asked to pay again.

Amounts sent to Affirm are calculated on the server in whole cents, exactly as Affirm's own validator does.
Carts with charges Affirm cannot itemise (gift cards, store credit, reward points, unknown fees) are declined with
"Affirm is not available for this cart". See [Accounting rules](docs/reference.md#accounting-rules).

## When a payment needs review

Rarely, a placement can fail after Affirm has already authorized the loan (for example the server crashes or
times out mid-placement). The module never retries such a payment on its own. Instead:

- the attempt is marked `uncertain`;
- the shopper sees "We are confirming your Affirm payment for order #…. Please do not pay again";
- the cart is blocked for every payment method, so the shopper cannot be charged twice;
- an admin inbox message ("Affirm payment needs review") and a CRITICAL log line are written.

A cron job (every 5 minutes) checks placements that have not finished after 15 minutes: it links the order if it
exists, otherwise it marks the attempt `uncertain` as above.

**What to do:**

1. Find the attempt in the admin under Sales > Operations > Affirm Checkout Attempts, or run
   `bin/magento hyva:affirm:attempt:list`.
2. Look up the payment in the Affirm merchant dashboard, by the order number or Affirm transaction id shown.
3. Then either:
   - **an order exists:** `bin/magento hyva:affirm:attempt:resolve <attempt-id> --outcome=placed --order=<number>`
     (the command checks the order belongs to this attempt and closes the cart), or
   - **no order exists:** void the authorization (or refund the capture) in Affirm's dashboard, then run
     `bin/magento hyva:affirm:attempt:resolve <attempt-id> --outcome=voided`. This unblocks the cart.

`bin/magento hyva:affirm:attempt:resolve --help` prints the same steps. Old attempts are deleted after 90 days,
except placed and unresolved ones.

## Security and privacy

- Checkout tokens never reach Magento's logs. A log processor removes them from Affirm's log and Magento's main log,
  and the module's own log lines carry only reason codes, exception classes and order numbers.
- A return URL alone cannot confirm a payment: it needs the browser session that started it (or the same customer
  account), and Affirm must confirm the checkout for this order number and amount.
- The redirect return uses GET, so it keeps working with `SameSite=Lax` session cookies (a known problem with
  Affirm's own POST return). Confirm pages are never cached.
- **Web-server logs:** the redirect return URL carries `?checkout_token=`. Configure your web server, load balancer
  and CDN to drop the query string for `/hyva_affirm/checkout/confirm/` in their access logs.

More detail: [Security and privacy](docs/reference.md#security-and-privacy).

## Content Security Policy and accessibility

- Works under Hyvä Checkout's strict CSP: every inline script is registered with Hyvä's CSP helper, and Affirm's
  own CSP whitelist covers its hosts.
- Errors appear in Hyvä's payment message area (`role="alert"`), and focus returns to Place Order.

## For developers

[`docs/reference.md`](docs/reference.md) covers the details: the attempt states, the confirmation steps, the
verification at Affirm, accounting rules, the cron jobs, Magewire 1 and 3, the `window.hyvaAffirm` loader, sandbox
testing tips and known Affirm issues.

Extension points, briefly:

- **Hide Affirm:** a standard `payment_method_is_active` observer (in `etc/events.xml`) is honoured before Affirm
  opens, on the return from Affirm and at order placement.
- **Allow a custom total:** add its code to the `ignoredTotalCodes` argument of
  `Hyva\CheckoutAffirm\Model\Checkout\TotalsBuilder`, if you know Affirm may finance it.
- **Services:** the checkout object builder, verifier, confirmation pipeline, cart snapshot, owner key and attempt
  repository are behind interfaces and can be replaced or plugged.
- **Templates:** override them in your theme under `Hyva_CheckoutAffirm/templates/`.

## Testing

| Suite | Command |
|---|---|
| PHP unit tests on a Magento install | `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/bredabeds/magento-hyva-checkout-affirm/Test/Unit` |
| Without a Magento install (what CI runs) | see [`Test/ci/README.md`](Test/ci/README.md) |
| JavaScript (Node 20+) | `node --test Test/js/*.test.mjs` |

GitHub Actions runs PHP syntax checks on 8.1 – 8.5, then php-cs-fixer, PHPCS, PHPStan level 6 and PHPUnit on
PHP 8.1 and 8.3, and the JavaScript tests. The `Test/` folder is left out of release archives.

Browser tests (Playwright against Affirm's US sandbox, not in this repository) cover both flows, cancel and decline,
replays, parallel returns, a fault after authorization, strict CSP, the cart button preselection, log redaction, and
capture, refund and void in the admin.

## Turning it off

1. **Stop new Affirm checkouts:** set `payment/affirm_gateway/active` to 0, then
   `bin/magento cache:clean config full_page`. Existing orders can still be captured, refunded and voided.
2. **Settle open payments:** wait a few minutes, then resolve anything `bin/magento hyva:affirm:attempt:list` still
   shows (see [When a payment needs review](#when-a-payment-needs-review)).
3. **Disable the module:** `bin/magento module:disable Hyva_CheckoutAffirm`, `bin/magento setup:upgrade`,
   `bin/magento hyva:config:generate`, rebuild the theme CSS, and in production mode `setup:di:compile` and
   `setup:static-content:deploy`; then flush the caches.

Keep Affirm disabled while the module is off: Hyvä Checkout would list Affirm again but could not place the order.
Disabling the module also removes the log redaction. The attempt table is kept.

## Licence

MIT, see [`LICENSE`](LICENSE). The licence does not cover the Affirm name or logo. The SVG logo in
`src/view/frontend/web/images/affirm.svg` is Affirm's trademark, included with Affirm's permission only to identify
Affirm as the payment method; see [`TRADEMARKS.md`](TRADEMARKS.md). To show another icon, override the `metadata`
argument of the block `checkout.payment.method.affirm_gateway` in your theme's `hyva_checkout_components.xml`.
The package calls Affirm's classes and copies none of Affirm's code; [`NOTICE`](NOTICE) carries Affirm's
BSD-3-Clause notice in case a fragment is adapted in future. This project is not affiliated with or endorsed by
Affirm, Inc. or Hyvä Themes B.V.

Contributing: see [`CONTRIBUTING.md`](CONTRIBUTING.md). Changes: see [`CHANGELOG.md`](CHANGELOG.md).
