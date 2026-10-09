# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Initial module skeleton, licence files and CI harness.
- Affirm as a payment method in Hyvä Checkout 1.3.14 and later (Magewire 1.13.3+ and 3.7.2+): method block,
  Magewire component, place-order service and CSP-compatible payment JavaScript for Affirm's modal and redirect
  flows, and Affirm's inline ("edu") widget, which follows shipping, address and coupon changes.
- Checkout attempts (table `hyva_affirm_checkout_attempt`) with a compare-and-swap state machine: the order is
  created only after Affirm confirms, through Magento's standard placement, at most once per attempt.
- Server-side checkout object with accounting rules: USD and CAD with base currency equal to quote currency,
  integer cents identical to Affirm's validator, unit price rounding, unsupported adjustments detected from
  collected totals plus a one-cent grand-total reconciliation (`ignoredTotalCodes` extension point), discounts per
  sales rule when they add up, non-integer quantities declined, one item per SKU (rows with the same SKU and price
  merged, further prices sent with `-2`, `-3` suffixes), ISO alpha-3 countries,
  `metadata.platform_integration`.
- Verification of every checkout at Affirm (Read Checkout: status `confirmed`, order number, total and currency)
  before anything is written.
- Return controllers with the attempt id in the path; GET return for `SameSite=Lax` session cookies; a POST return
  is answered with a 303 to the GET URL without starting a session; in-progress page; replay of a placed attempt to
  the receipt; ownership check by session owner key or customer id. A placed order always leads to the success
  page, also when preparing the success-page session fails (logged).
- Retry semantics: a cart-lock refusal hands the attempt back as `confirmed`; the redirect return retries with its
  token, while in modal mode the shopper is asked to place the order again in a moment, which starts a new Affirm
  checkout (the stored token is dropped after a failed placement).
- Availability checked at preparation, at confirmation and at placement (`payment_method_is_active` observers
  honoured at each).
- Guard that blocks every other payment for a cart whose Affirm attempt may hold an authorization, on
  `sales_model_service_quote_submit_before` (placeOrder, submit, REST, GraphQL, PayPal Express, admin) and
  `checkout_type_multishipping_create_orders_single` (Magento's multishipping checkout).
- Reserved order number guard: the pipeline's own placement is refused before Affirm's authorization when Magento
  would submit the order under another number than the attempt's reserved one (the number was used by another
  order meanwhile).
- Strict order matching after an interrupted placement: an order found by the reserved number is linked to the
  attempt only when it comes from the attempt's cart, was paid with the attempt's method and carries the attempt id
  on its payment; otherwise the attempt fails (no authorization sent) or becomes uncertain, with a warning naming
  the order reference.
- Handling of uncertain outcomes: admin inbox notification, admin grid (Sales → Operations → Affirm Checkout
  Attempts), CLI commands `hyva:affirm:attempt:list`, `hyva:affirm:attempt:resolve` and `hyva:affirm:attempt:prune`,
  and the daily cleanup cron job `hyva_affirm_attempt_cleanup`.
- Escalation of placements abandoned by a dead process: attempts unchanged in `placing` or `authorizing` for 15
  minutes are reported to the shopper with the order reference instead of an endlessly refreshing page, and the cron
  job `hyva_affirm_attempt_escalate` (every 5 minutes, batch-limited, compare-and-swap on the row as read) links
  them to their order and deactivates the cart, or makes them `uncertain` with a CRITICAL log line and an admin
  inbox message. One threshold (`staleAfterSeconds` of `StaleAttemptPolicy`, 900 s) serves the return page, the
  job and `hyva:affirm:attempt:resolve --force`.
- Redaction of checkout tokens in Affirm's and Magento's logs (Monolog processor and `debugReplaceKeys`),
  `#[\SensitiveParameter]` on attempt ids and tokens, `Cache-Control: no-store` and `Referrer-Policy: no-referrer`
  on return responses.
- Cart refresh on the Hyvä success page after a GET return.
- The `window.hyvaAffirm` loader contract v1, shared with `Hyva_AstoundAffirm`.
- Cart button preselection: a plugin on Hyvä Checkout's payment method list re-applies Affirm once when a cart
  button recorded the choice under the checkout session key `hyva_affirm_preselect_quote_id` (written by
  `Hyva_AstoundAffirm`), so Hyvä's "Enable first available payment method" no longer replaces it on the first
  checkout page load of a session. Only when Affirm is available for the quote; the shopper's own pick wins.
- Removal of Affirm's Luma stylesheets on the Hyvä checkout page.
- Affirm's logo as the method icon (see `TRADEMARKS.md`).
- en_US and fr_CA translations.
- Unit tests, JavaScript tests and README.
