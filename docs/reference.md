# Hyva_CheckoutAffirm technical reference

Details behind the [README](../README.md): settings, the payment flow, the checkout attempt state machine, accounting rules, security, the cron jobs, Magewire compatibility and the extension points.

## Affirm settings honoured

The module has no settings of its own. These Affirm settings (`payment/affirm_gateway/*`, Stores > Configuration >
Sales > Payment Methods > Affirm) are honoured:

| Setting | Path | Effect in Hyvä Checkout |
|---|---|---|
| Enabled | `active` | Affirm is listed; the loader and the payment JavaScript are emitted only when it is on |
| Mode | `mode` | Sandbox or production affirm.js, API endpoints and keys |
| API keys (US and Canada) | `public_api_key_*`, `private_api_key_*` (`_ca` suffix for Canada) | Read for the cart's store and currency: CAD carts use the `_ca` keys. The public key goes into the checkout object and the loader; the private key authenticates the verification request |
| Payment Action | `payment_action` | Affirm's gateway at placement (authorize, or authorize and capture) |
| Enable Partial Capture | `partial_capture` | Affirm's gateway, in the admin; whether Affirm accepts partial captures depends on the Affirm account |
| Disable for backordered items | `disable_for_backordered_items` | Affirm's own `payment_method_is_active` observer, honoured at every availability check |
| Minimum / Maximum Order Total | `min_order_total`, `max_order_total` | Magento's method checks, at preparation and at placement |
| Applicable countries | `allowspecific`, `specificcountry` | Magento's country check |
| Allowed currencies | `allowspecificcurrency`, `currency` | Magento's currency check; in addition the module accepts only USD and CAD with base currency = quote currency (see [Accounting rules](#accounting-rules)) |
| Sort Order | `sort_order` | Position in Hyvä's method list |
| Title | `title` | The method label (Hyvä prints it) |
| Plain Text Appearance | `control` | Off (Affirm's default: no value saved): the body shows the merchant icon, if any, and the payment method HTML. On: neither |
| Icon | `icon` | Shown in the method body only when it is an `https://` URL different from Affirm's default |
| Affirm Payment Method HTML | `info` | Shown in the method body, filtered to the tags `a`, `b`, `br`, `em`, `i`, `p`, `span`, `strong` |
| Checkout Flow Type | `checkout_flow_type` | `Redirect` (any case) = redirect mode; anything else = modal mode |
| Inline Checkout Messaging | `edu` | Affirm's inline widget in the method body; Affirm offers it for US stores only |
| Debug | `debug` | Affirm's request log in `payment.log` (keys in `debugReplaceKeys` masked). Affirm writes `var/log/affirm.log` regardless of this setting; both are redacted by this module |
| Multiple Financing Programs | Affirm's MFP settings | `financing_program` of the checkout object, from Affirm's `Helper\FinancingProgram` |

### Behaviour notes

- **Method label and icon.** Hyvä renders the method title and the module's SVG of Affirm's logo
  (`Hyva_CheckoutAffirm::images/affirm.svg`, 66 × 20, `alt="Affirm"`). There is no subtitle: Hyvä escapes
  subtitles, so Affirm's HTML goes into the method body instead.
- **Method body.** In icon mode ("Plain Text Appearance" off): the merchant's own icon (only a non-default `https`
  URL; Affirm's default icon would duplicate the label's logo) and the filtered payment method HTML. In plain-text
  mode the body does not repeat the title, which Hyvä already prints in the label. Below that, one line of copy:
  Affirm's own redirect description in redirect mode without the inline widget, otherwise "You will be redirected to
  Affirm to complete your purchase." or "An Affirm window will open to complete your purchase.". With the inline
  widget on, the widget follows.
- **Place Order label.** Hyvä's Place Order button label is global and is not changed per method; Affirm's Luma
  label "Continue with Affirm" is not reproduced.
- **Redirect mode** keeps Place Order busy until the browser leaves for Affirm. The button comes back when Affirm
  reports a problem, when the page returns from the back-forward cache, or after 60 seconds without navigation.
- **Errors** are shown in Hyvä's payment messenger (`role="alert"`) and focus returns to Place Order once Hyvä
  re-enables it. Placement is never retried by the browser; only affirm.js may be requested again.
- **Evaluation.** While Affirm is selected but not available (for example the cart fell below the minimum order
  total in another tab), Hyvä's evaluation blocks Place Order with "Affirm is not available for this cart. Please
  choose another payment method."
- **Addresses stay validated.** Affirm's Luma confirm path skips Magento's address validation; this module places
  the order through Magento's standard placement, so address validation, order e-mails, `checkout_submit_before`
  and `checkout_submit_all_after` (used by Hyvä for the order comment) run as for any Hyvä Checkout order.
- **Luma CSS.** `Astound_Affirm::affirm.css` and `affirm-promo.css` are removed on the Hyvä checkout page.
- **Return pages.** A shopper returning without an order lands on the payment step (`checkout?step=payment`, or
  `checkout` when the active Hyvä checkout has no step named `payment`) with a message.
- **Cart button preselection.** A cart "Checkout with Affirm" button (for example `Hyva_AstoundAffirm`'s) selects
  Affirm and opens the checkout. Hyvä Checkout's "Enable first available payment method"
  (`hyva_themes_checkout/developer/experimental/enable_first_payment_method_available`) replaces that method on the
  first checkout page load of a session, so a guest reached the payment step with another method checked. The
  plugin `Plugin\HyvaCheckout\CartButtonPreselect` on Hyvä's payment method list
  (`Hyva\Checkout\Magewire\Checkout\Payment\MethodList::boot()`) keeps the button's promise. Contract: the button
  stores the quote id (int) in the checkout session (`Magento\Checkout\Model\Session`) under
  `hyva_affirm_preselect_quote_id` (`CartButtonPreselect::SESSION_KEY`; this module does not depend on the module
  that writes it). When the method list is built (a page load, or Hyvä's step navigation) and the record belongs
  to the current quote, Affirm is selected through Magento's `PaymentMethodManagementInterface::set()` if it is
  among the quote's available methods, and the record is removed. While Affirm is not available nothing is
  selected and the record waits. It is removed as soon as the shopper picks a method in the list, and dropped when
  it belongs to another quote, so it applies at most once and never over the shopper's own choice. With
  Magewire 1 the list's own update requests are skipped (Magewire 1 restores the browser's copy of the method after
  `boot()`); Magewire 3 restores it before `boot()`. Without a record the list behaves as stock Hyvä Checkout.

## How it works

### Flow

**Modal mode**

1. The shopper selects Affirm; the method initialises and loads affirm.js through the
   [loader](#the-windowhyvaaffirm-loader-contract-v1). With the inline widget on, `prepareInline()` builds the
   widget's checkout object.
2. Place Order: after Hyvä's validations, the JavaScript calls the Magewire action `prepareCheckout()` and reads
   the public properties `checkout`, `attemptId` and `errorMessage`. The server re-checks availability and the
   checkout data, reserves the order number, creates (or reuses) the checkout attempt and builds the checkout
   object. A refusal sets `errorMessage`; Affirm is not opened.
3. `affirm.checkout(object)` and `affirm.checkout.open({onSuccess, onFail, onValidationError})`.
4. `onSuccess` stores `checkout_token` and `attempt_id` in Hyvä's session storage group `payment` and awaits
   Hyvä's `fallback()`, which runs the module's place-order service. The service runs the
   [confirmation pipeline](#confirmation-pipeline) and returns the order id; Hyvä redirects to the success page.

**Redirect mode**

1. As above up to the checkout object, which carries `merchant.user_confirmation_url_action: GET`.
2. `affirm.checkout.open()` navigates to Affirm.
3. Affirm returns to `hyva_affirm/checkout/confirm/attempt/<attempt id>/?checkout_token=<token>`. The controller
   runs the same confirmation pipeline and redirects to the success page, or to the payment step with a message.
4. Cancel returns to `hyva_affirm/checkout/cancel/attempt/<attempt id>/`, decline to
   `hyva_affirm/checkout/cancel/attempt/<attempt id>/reason/declined/`: the attempt is marked failed and the shopper
   is back on the payment step with the cart intact.

### The checkout attempt

One row per Affirm checkout start, in `hyva_affirm_checkout_attempt`. The attempt id (64 random hex characters) is
the opaque value in the return URLs and in browser storage. The row records the expected values (reserved order
number, currency, grand total in cents, payment method, a snapshot hash of the cart), the owner (the hash of a
random key kept in the checkout session, plus the customer id for logged-in customers), the hash of the checkout
token, the resulting order and Affirm's transaction id. Browser state is disposable; rows are durable. Every state
change is a compare-and-swap (`UPDATE … WHERE state IN (…)`), so concurrent returns, refreshes and duplicate
callbacks place one order.

| State | Meaning |
|---|---|
| `initiated` | Prepared by `prepareCheckout()`; return URLs issued. Expires after 60 minutes |
| `confirmed` | Affirm confirmed the checkout (verified) and the token hash is recorded; not yet claimed |
| `placing` | Claimed by one request; Magento's placement is running; Affirm's authorization not yet sent |
| `authorizing` | Affirm's authorize request has been sent for this attempt |
| `placed` | The order exists; order id and number recorded |
| `failed` | Definitive failure with a reason (`cancelled`, `declined`, `expired`, `verification_failed`, `cart_changed`, `unavailable`, `superseded`, `placement_failed`); no authorization exists |
| `uncertain` | The placement failed after the authorize request was sent and no order exists, or its process died and the [escalation job](#escalation-cron) found no order: Affirm may hold an authorization. Blocks the cart and needs [reconciliation](../README.md#when-a-payment-needs-review) |
| `resolved` | An uncertain (or stuck) attempt closed by an operator, as `resolved_placed` or `resolved_voided` |

A new preparation reuses the cart's `initiated` attempt when owner, cart snapshot, order number, store and method
are unchanged and at least 15 minutes of its lifetime are left. Otherwise it creates a new attempt and then marks
every other open attempt of the cart `failed (superseded)` in one update, so parallel preparations cannot leave two
open attempts. At most 10 attempts are created per cart per hour.

The quote payment keeps what Affirm's gateway needs (`checkout_token`, `country_code` = `USA` or `CAN` by currency)
and the attempt id (`hyva_affirm_attempt_id`); Magento copies all three to the order payment.

### Confirmation pipeline

The same pipeline runs for the modal place-order request and for the redirect return:

1. **Resolve the attempt.** Unknown id → "We could not find this Affirm payment".
2. **Ownership,** before anything else (including replays): the owner key in the session must match, or the
   logged-in customer must be the attempt's customer. A return in another browser is refused without touching the
   attempt.
3. **Finished or busy attempts.** `placed` → [replay](#replay-return-urls-and-session-cookies); `placing` or
   `authorizing` → "in progress"; `uncertain` → "we are confirming your payment" with the order reference;
   `failed` or `resolved` → the recorded reason. Past its expiry an open attempt becomes `failed (expired)`. A
   `placing` or `authorizing` attempt that has not changed for 15 minutes (the
   [stale threshold](#escalation-cron)) is reported like `uncertain`: its process died, so the in-progress page stops
   refreshing and shows the order reference; the escalation job settles the attempt.
4. **Cart.** Modal mode uses Hyvä's quote, redirect mode loads the attempt's quote. It must be active, belong to the
   attempt's store (which must also be the current store) and still have Affirm as its payment method. Totals are
   collected again and the snapshot hash (items, quantities, row totals, both addresses, shipping method, payment
   method, currencies, totals, rule ids, coupon, e-mail) must match → otherwise `failed (cart_changed)`.
5. **Availability.** The payment method's `isAvailable($quote)`, which dispatches `payment_method_is_active` →
   otherwise `failed (unavailable)`.
6. **Verification at Affirm** (see below). Nothing is written before it. A malformed token causes no request and
   leaves the attempt open. When Affirm cannot answer (timeout, network error, HTTP 5xx or 429) the attempt also
   stays open and the outcome is "in progress, retry": the redirect return's page refreshes with its token until
   Affirm answers or the attempt expires. Any other failure → `failed (verification_failed)`.
7. **Confirm:** `initiated → confirmed` with the token hash (a retry with the same token is accepted).
8. **Claim:** `confirmed → placing` where the token hash matches. The loser of a race gets "in progress" or the
   replay. If another attempt of the same cart is unresolved, the claim is refused and this attempt stays
   `confirmed` and the shopper is sent back to the payment step with "Payment for this cart is still being
   confirmed". Once the other attempt is resolved, placing the order again starts a new Affirm checkout.
9. **Write** `checkout_token`, `country_code` and `hyva_affirm_attempt_id` onto the quote payment (only the claim
   winner) and save the payment.
10. **Place** through `CartManagementInterface::placeOrder()` inside Magento's cart mutex. The
    [submit guard](#availability-and-the-unresolved-attempt-guard) lets this placement through only under the
    attempt's reserved order number. A plugin on Affirm's `ClientService::placeRequest()` moves the attempt
    `placing → authorizing` immediately before Affirm's authorize POST and records Affirm's transaction id when the
    response says `authorized`.
11. **Record:** `placed` with the order ids; Affirm's event `affirm_place_order_success` (`order`, `quote`) is
    dispatched exactly once; a failing observer is logged and does not change the outcome.

Exceptions from step 10:

| Situation | Result |
|---|---|
| Cart mutex contention (`CartLockedException`) | Back to `confirmed` (nothing reached Affirm); "in progress, retry". In redirect mode the in-progress page retries with the token. In modal mode the stored token is dropped: the shopper is asked to place the order again in a moment, which starts a new Affirm checkout (the first, unused one lapses at Affirm) |
| Magento would submit the order under another number than the reserved one (another order used the reserved number meanwhile, so Magento reserved a new one) | The submit guard refuses before the authorization (Affirm's checkout carries the reserved number): `failed (placement_failed)`, the shopper starts again |
| The attempt's order exists anyway (for example a post-commit observer threw) | Treated as placed: quote deactivated, success-page session set, a log line notes that `checkout_submit_all_after` did not run |
| An order with the reserved number exists but belongs to another placement | Treated as no order (warning with the order reference). The order found by reserved number and store must come from the attempt's cart, be paid with the attempt's method and carry the attempt id (`hyva_affirm_attempt_id`) on its payment |
| Still `placing`, no order (the authorization was never sent) | `failed (placement_failed)`, token removed from the quote; the shopper may pay again |
| Affirm answers the authorize request with an error that proves nothing was authorized (HTTP 4xx other than 408 and 409, no transaction id) | The tracker hands the attempt back to `placing`, so it ends as `failed (placement_failed)` like the row below: the cart stays usable and nobody is alerted |
| `authorizing`, no order (timeout, 5xx, 408/409, a failure after Affirm authorized) | `uncertain`, a CRITICAL log line and an admin inbox message; the shopper sees "We are confirming your Affirm payment for order #…" |
| The order lookup itself fails | Reported as uncertain to the shopper, state unchanged: the shopper is never told that no payment was taken unless that is proven |
| The process dies mid-placement (request timeout, out of memory, PHP-FPM reload) | The attempt stays `placing` or `authorizing`. After 15 minutes the shopper's page shows the order reference instead of refreshing, and the [escalation job](#escalation-cron) links the attempt to its order or makes it `uncertain` with the alerts above |

### Verification at Affirm

A small dedicated client (Magento's HTTP client, 10 s timeout) calls Affirm's
[Read Checkout](https://docs.affirm.com/developers/reference/read_checkout) `GET /api/v2/checkout/<token>` with
Basic authentication. Mode, base URL and keys are read for the attempt's store explicitly, and the key pair and
`Country-Code` header follow the attempt's currency (CAD → `_ca` keys, `CAN`); Affirm's own getters use the current
store and currency instead. The response must contain:

- `checkout_status` exactly `confirmed`,
- `order_id` equal to the attempt's reserved order number,
- `total` (integer cents) equal to the attempt's grand total in cents,
- `currency` equal to the attempt's currency.

A missing or unexpected field fails closed. Only the reason code and the HTTP status are logged. Affirm's own
validators compare the total only; the order number and currency checks are this module's.

### Availability and the unresolved-attempt guard

Affirm's availability is checked three times, and every check dispatches `payment_method_is_active`, so merchant
observers can refuse Affirm at any point:

1. at preparation (`prepareCheckout()`, plus Hyvä's evaluation on every render), together with Magento's own
   method checks (checkout, country, currency, minimum/maximum total, zero total);
2. in the confirmation pipeline, before verification and claim (the return request in redirect mode);
3. by Magento inside `placeOrder()` (`Quote\Payment::importData()`).

While a cart has an attempt in `placing`, `authorizing` or `uncertain`, an observer refuses to submit that cart
with any other payment ("Payment for this cart is still being confirmed. Please wait a moment or contact us."):
paying again could charge the shopper twice. It observes two events, both dispatched before any payment is
authorized:

- `sales_model_service_quote_submit_before`, dispatched by `QuoteManagement::submit()` as well as `placeOrder()`, so
  it also covers placement paths that bypass `placeOrder()`, such as Magento's PayPal Express (which calls
  `submit()`), REST, GraphQL and admin orders;
- `checkout_type_multishipping_create_orders_single`, dispatched by Magento's multishipping checkout for each order
  before it pays them (multishipping does not go through `QuoteManagement`).

A placement path that dispatches neither event is not covered. The guard is keyed by quote id: it is a safety net
against accidental double payment of the same cart, not a hard control (a new cart, or a login that merges carts,
starts without it).

Only the placement the pipeline itself runs in the same request is exempt, and only under the attempt's reserved
order number: when another order used that number in the meantime, Magento reserves a new one, and the guard
refuses the placement before Affirm's authorization (Affirm's checkout carries the reserved number). A new Affirm
preparation for a blocked cart is refused too. The guard fails open on a database read error (logged), so a read
error cannot stop every payment method of the store. There is no automatic authorization retry, ever.

### Replay, return URLs and session cookies

- **Attempt id in the path.** Return URLs are `hyva_affirm/checkout/confirm/attempt/<id>/` and
  `hyva_affirm/checkout/cancel/attempt/<id>/[reason/declined/]`. Affirm appends `?checkout_token=…` to the confirm
  URL; `checkout_id` (query or body) is accepted as a fallback name.
- **GET for SameSite.** A cross-site POST back to the store does not carry a `SameSite=Lax` session cookie (the
  cause of the "Chrome SameSite" failures in Affirm's FAQ), so redirect mode asks Affirm to return with `GET`. A
  POST to the confirm URL (Affirm's default action) changes nothing: no session is started for it (a new session
  cookie would replace the shopper's), and it is answered with `303 See Other` to the same URL as a GET, with the
  token moved to the query. CSRF validation is skipped for this controller only.
- **Idempotent replay.** Confirming a `placed` attempt again (refresh, back button, duplicate callback) places
  nothing: the success-page session fields are set again from the stored ids and the shopper lands on the receipt,
  also after Magento's success page has cleared them. The module dispatches no events on a replay, but Magento's
  success page fires its usual `checkout_onepage_controller_success_action` observers (often analytics) again on
  each visit.
- **In-progress page.** While another request places the order, the confirm URL renders "Confirming your payment"
  with a `<meta http-equiv="refresh" content="3">` (no script needed under a strict CSP) and reloads when restored
  from the back-forward cache. The page keeps the token in its URL while it refreshes, because a placement that
  hands the attempt back (cart locked, Affirm unreachable) needs it again. An `uncertain` attempt is first
  redirected to the same URL without the token, then shows the order reference and no refresh.
- **Success-page cart refresh.** A GET return does not renew Magento's `private_content_version` cookie, so the
  controller sets a fresh one, and a block on the Hyvä success page deletes `mage-cache-sessid` and dispatches
  `reload-customer-section-data` (Hyvä's documented forced refresh). The minicart is therefore empty on the success
  page and afterwards. The block runs on every Hyvä success page, which costs at most one extra section request.

## Accounting rules

The checkout object is built on the server (its fields are listed at the end of this section). The rules exist so
that the amount Affirm authorizes is exactly the amount of the Magento order, and so that Affirm's itemization adds
up.

- **Currency.** USD and CAD only, and the base currency must equal the quote currency: there is no currency
  conversion. All amounts and `currency` are in the quote currency, the currency of `amount_ordered` that Affirm's
  validator uses. Otherwise: "Affirm is not available for the currency of this cart."
- **Integer cents.** `total`, `shipping_amount`, `tax_amount` and discount amounts are
  `Util::formatToCents()` (Affirm's helper) of the amount after Magento's own rounding
  (`PriceCurrencyInterface::round()`), which is bit-identical with the figure Affirm's validator computes at
  authorization. `formatToCents()` alone is off by a cent on four-decimal totals (100.0050 gives 10000; the
  validator expects 10001).
- **Unit prices.** `unit_price` = `(int) round(round(price, 2) * 100)`: the item price excluding tax and before
  discounts, rounded half up. The sum of `unit_price × qty` may differ from the subtotal by at most one cent per
  quote row (logged); more declines the cart.
- **Items.** Simple, virtual and downloadable items: their row. Configurable: the parent row with the selected
  child's SKU (and the child's image when it has one). Fixed-price bundle: the parent row. Dynamic-price bundle: the
  priced child rows with quantity = child quantity × bundle quantity; the parent is omitted. Non-integer quantities
  decline the cart.
- **Same SKU.** Affirm keys checkout items by SKU: in the sandbox, two rows with one SKU (20.00 and 100.00) came
  back as one item, 2 × 20.00. So every SKU is sent once:
  - rows with the same SKU **and** the same unit price are merged into the first row (quantities added; the first
    row's name, image and URL kept);
  - when one SKU comes with different unit prices, the first price keeps the SKU and each further price, in order
    of first appearance, is sent as the SKU with `-2`, `-3`, … appended; a suffix that would equal another SKU of
    the cart (or one already assigned) is skipped for the next number.

  No unit price or quantity changes, so Affirm's itemization adds up to the cart's rows.
- **Unsupported adjustments.** Detected from the amounts the store's total collectors **collected** (the
  `<code>_amount` values Magento copies onto the totals address), never from display segments, so a display-only
  segment never declines a cart. Supported and summed: `subtotal`, `shipping`, `discount`, `tax`,
  `discount_tax_compensation`, `shipping_discount_tax_compensation`, `weee*`. Informational: `grand_total`,
  `shipping_discount` (already part of `discount`), `tax_subtotal`, `tax_shipping`. Any other non-zero collected
  amount (gift cards, store credit, reward points, gift wrapping, third-party fees) declines the cart with "Affirm is
  not available for this cart." and one log line naming the codes. A positive discount (a surcharge in disguise)
  declines too.
- **Reconciliation.** The grand total must equal the sum of the supported amounts within one cent; otherwise the
  cart is declined ("unexplained difference"). This also catches adjustments applied straight to the grand total
  without a collected amount. No residual is ever relabelled as a discount. Multishipping and zero-total carts are
  declined.
- **Your own totals.** Total codes that are part of the grand total and that you know Affirm may finance can be
  allowed through the `ignoredTotalCodes` argument. A listed code never declines a cart by itself, but the grand
  total must still reconcile, with or without its amount:

  ```xml
  <type name="Hyva\CheckoutAffirm\Model\Checkout\TotalsBuilder">
      <arguments>
          <argument name="ignoredTotalCodes" xsi:type="array">
              <item name="my_fee" xsi:type="string">my_fee</item>
          </argument>
      </arguments>
  </type>
  ```
- **Discounts.** One discount object per sales rule (keyed by the rule's label) when the rules' amounts add up
  exactly to the cart's discount amount, which includes shipping discounts; otherwise one object with the cart's
  discount description (or "Discount"). Purely numeric labels are prefixed with "Discount " so the JSON stays an
  object.
- **Financing program.** From Affirm's `Helper\FinancingProgram::getFinancingProgramValue()`; omitted when empty.
  If the helper fails, the cart is declined rather than silently offering the shopper different terms.
- **Addresses.** Affirm's current schema: `name {first, last}`, `address {line1, line2, city, state, zipcode,
  country}`, `phone_number`, `email`; virtual carts send the billing contact for both. Countries are sent as ISO
  3166-1 alpha-3 for the countries in Affirm's default country list (US → `USA`, CA → `CAN`, AS → `ASM`,
  GU → `GUM`, MP → `MNP`, PR → `PRI`, VI → `VIR`); others as stored. E-mail, first and last name, street, city,
  postcode, country and, for physical carts, a shipping method are required before Affirm opens ("Please complete
  your contact, address and shipping details before paying with Affirm.").

Checkout object fields:

| Field | Source |
|---|---|
| `merchant.public_api_key` | Affirm's key for the cart's store, mode and currency |
| `merchant.user_confirmation_url`, `user_cancel_url`, `user_decline_url` | the return URLs with the attempt id in the path |
| `merchant.user_confirmation_url_action` | `GET`, redirect mode only |
| `merchant.name` | store frontend name |
| `shipping`, `billing`, `items`, `discounts` | the rules above |
| `metadata` | `platform_type` "Magento 2", `platform_version` "<version> <edition>", `platform_affirm` (Affirm module version), `platform_integration` "hyva-checkout-affirm/<installed package version>" (read from Composer), `mode` (`modal`, `redirect` or `inline`), `shipping_type` (the shipping description) |
| `order_id` | the reserved order number (compared by the verifier) |
| `shipping_amount`, `tax_amount`, `total`, `currency` | quote currency, integer cents |
| `financing_program` | Affirm's helper, when set |

## Security and privacy

- **No tokens or attempt ids in logs.** Affirm's client logs the pre-authorization URI, which carries the checkout
  token, and full API responses. A Monolog processor is registered globally on Affirm's logger
  (`Astound\Affirm\Logger\Logger`) and on Magento's main logger (`Magento\Framework\Logger\Monolog`): it replaces
  `/api/v2/checkout/<token>` and `checkout_token=` / `checkout_id=` query values with `[redacted]` in messages and,
  recursively, in context and extra data, and redacts context keys named `checkout_token` or `checkout_id`. It
  supports Monolog 2 and 3. The module also adds `uri`, `checkout_id` and `checkout_token` to
  `payment/affirm_gateway/debugReplaceKeys`, which masks them in `payment.log`. The module's own log lines never
  contain tokens, attempt ids or customer data: they carry reason codes, exception classes, quote ids and order
  references.
- **Stack traces.** Every parameter that carries an attempt id or a checkout token is marked
  `#[\SensitiveParameter]`, so PHP 8.2+ prints it as `SensitiveParameterValue`, also in traces written by
  third-party loggers. PHP 8.1 accepts the attribute but ignores it.
- **Response headers.** Confirm and cancel responses send `Cache-Control: no-store`; confirm responses also send
  `Referrer-Policy: no-referrer`. A reverse proxy or CDN may replace that header with its own policy; check what
  reaches the browser. Redirects away from the confirm URL never carry the token.
- **A token alone is not enough.** A confirmation needs the session that started the attempt (or the same
  logged-in customer), and the verifier requires Affirm to confirm the checkout for this order number and amount.
- **Admin notification** names the order reference only.

**Deployment note: web-server access logs.** The redirect return is a GET with `?checkout_token=` in the query
string, so web-server, load-balancer and CDN access logs record the token. Configure them to drop or mask the query
string of requests to `/hyva_affirm/checkout/confirm/` (for example by logging the path without the query string
for those requests).

## Escalation cron

Job `hyva_affirm_attempt_escalate` (cron group `default`, every 5 minutes) settles attempts that a dead process left
in `placing` or `authorizing` (request timeout, out of memory, a PHP-FPM reload or container restart during a
deployment). A placement takes seconds; an attempt counts as abandoned once it has not changed for 15 minutes
(`staleAfterSeconds` of `Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy`, at least 60). For each such attempt,
oldest first:

1. the order with the attempt's reserved number in the attempt's store is looked up and accepted only when it
   belongs to the attempt (same cart, the attempt's payment method, `hyva_affirm_attempt_id` on its payment);
2. with that order, the attempt becomes `placed` with the order ids and the cart is deactivated. A warning notes that
   `checkout_submit_all_after` and `affirm_place_order_success` did not run for the order (no shopper request is
   involved, so neither is dispatched);
3. without it, the attempt becomes `uncertain` from either state, with the CRITICAL log line and the admin inbox
   message described above. `placing` is not failed automatically: if the authorization tracker's write was rolled
   back with a surrounding database transaction (logged as CRITICAL when it happens), a `placing` row does not prove
   that the authorize request was never sent.

Every change is a compare-and-swap on the state and `updated_at` as read, so a placement that is still alive and a
second run of the job leave the attempt alone; the job is idempotent. It handles up to `batchSize` (50) attempts per
run (argument of `Hyva\CheckoutAffirm\Cron\EscalateStaleAttempts`); the rest waits for the next run. A failing
attempt is logged with its order reference and exception class and does not stop the others; the run then reports
an error to the cron schedule.

## Cleanup cron

Job `hyva_affirm_attempt_cleanup` (cron group `default`, daily at 03:17):

1. deletes `failed` and `resolved` attempts not updated for 90 days, and `initiated`/`confirmed` attempts whose
   expiry is more than 90 days old. `placing`, `authorizing`, `uncertain` and `placed` attempts are never deleted;
2. marks `initiated`/`confirmed` attempts that expired more than 15 minutes ago as `failed (expired)`, in batches.

If one step fails the other still runs, and the job reports the error to the cron schedule. Arguments of
`Hyva\CheckoutAffirm\Cron\CleanupAttempts`: `retentionDays` (90), `expiryGraceSeconds` (900), `batchSize` (500),
`maxBatches` (20 per run). The deletion step can also be run by hand with another retention:

```sh
bin/magento hyva:affirm:attempt:prune --days=30
```

## Magewire 1 and 3 (Hyvä Checkout 1.3 and 3.0)

Hyvä Checkout 1.3 runs on Magewire 1, Hyvä Checkout 3.0 on Magewire 3. The module runs on both without relying on
the Magewire 1 compatibility layer (`hyva-themes/magento2-hyva-checkout-v1bc`) that Checkout 3 installs:

- **PHP.** The component (`Hyva\CheckoutAffirm\Magewire\Payment\Method\AffirmGateway`, block
  `checkout.payment.method.affirm_gateway`) extends `Magewirephp\Magewire\Component` and implements Hyvä's
  `EvaluationInterface`. Public properties and methods only; a `$listeners` array (no `#[On]`); no `emit()`,
  `dispatchBrowserEvent()`, `redirect()`, `wire:model`, forms or custom hydrators.
- **Browser-callable surface.** Magewire lets the browser call a component's public methods, so apart from the
  lifecycle hook `mount()` (never callable from the browser) the component has exactly four:
  `prepareCheckout()` and `prepareInline()` (actions), `refreshInline()` (listener target for
  `shipping_method_selected`, `shipping_address_saved`, `shipping_address_activated`, `billing_address_saved`,
  `billing_address_activated`, `coupon_code_applied`, `coupon_code_revoked` and `payment_method_selected`; it only
  rebuilds the inline widget's object and has no other side effects) and `evaluateCompletion()` (Hyvä's evaluation
  hook; listed in Magewire 1's `$uncallables`). Public properties (`checkout`, `attemptId`, `errorMessage`,
  `inlineCheckout`, `inlineVersion`, `mode`, `edu`) are results for the page: the browser can write them, so they are
  never read back as input. The authoritative state is the attempt row.
- **Templates.** `wire:key` on the single root element, `wire:ignore` around the subtree affirm.js owns, data
  attributes for state.
- **JavaScript.** No Magewire hooks. `Magewire.find('checkout.payment.method.affirm_gateway')` returns the `$wire`
  proxy in both versions; the code awaits the action and then reads the properties. Everything else goes through
  `hyvaCheckout.*` and window events.
- **Checkout 3 differences handled in the payment JavaScript.** A Magewire 3 action call never settles when its
  request fails, so the script calls actions through Checkout 3's `hyvaCheckout.misc.call()`, which rejects instead
  (Checkout 1.3 has no `misc.call()`; there the action is called directly). Checkout 3 also passes every error of
  `placeOrder()` to the method's `handleException()`, whose default adds "Something went wrong while placing the
  order". The method's own `handleException()` stays quiet for errors the script has already shown and passes any
  other error to Hyvä's default.
- **Server side** the place-order service, evaluation API, payment method list and the events the component listens
  to are unchanged in Checkout 3.0.0 (compared against its source). If Hyvä Checkout's "Place Order" reCAPTCHA is
  on, test the redirect return on Checkout 3: its order placement runs in this module's confirm controller, outside
  Hyvä's place-order request.
- **`prepareInline()`** reserves the order number when the cart has none yet (as Affirm's own inline checkout does;
  idempotent while unused); it never creates attempts.
- **Cart button preselection plugin** (on Hyvä's `MethodList::boot()`, see
  [Behaviour notes](#behaviour-notes)): on Magewire 1 it reads the list's own request
  (`getRequest()->isSubsequent()`) to leave the list's update requests alone; Magewire 3, detected by its class
  `Magewirephp\Magewire\Mechanisms\HandleComponents\HandleComponents`, needs no such check and the request API is
  not called there.

Throttle arguments of the component: `attemptLimit` (new attempts per cart, default 10) within
`attemptWindowSeconds` (default 3600):

```xml
<type name="Hyva\CheckoutAffirm\Magewire\Payment\Method\AffirmGateway">
    <arguments>
        <argument name="attemptLimit" xsi:type="number">5</argument>
        <argument name="attemptWindowSeconds" xsi:type="number">1800</argument>
    </arguments>
</type>
```

## The `window.hyvaAffirm` loader contract (v1)

This module and `Hyva_AstoundAffirm` ship the same loader script (`js/sdk-loader.phtml`, identical apart from the
view model it reads its configuration from). On the checkout it is emitted in
`hyva.checkout.api-v1.payment-methods` when Affirm is enabled, the store currency is USD or CAD and a public key
exists. v1 is frozen; a newer loader replaces an older one, so later versions keep the v1 members.

```js
window.hyvaAffirm = {
    version: 1,
    load(),        // Promise<affirm>: sets Affirm's _affirm_config, injects affirm.js once, resolves on affirm.ui.ready,
                   // rejects on a script error or after 10 s; a rejected load may be retried
    refresh(),     // debounced affirm.ui.refresh()
    whenIdle(fn),  // runs fn after DOMContentLoaded and an idle period
};
window.hyvaAffirmConfig = {public_api_key, script, locale, country_code}; // the first definition on a page wins
window.dispatchEvent(new CustomEvent('hyva-affirm:load'));                 // request a load from anywhere
```

With both modules on a page, the first copy defines `window.hyvaAffirm` and the second does nothing. See the
storefront module's README for the full description.

## Extension points

- **Hide Affirm: `payment_method_is_active`.** A standard observer is honoured at preparation, at confirmation
  (also on the return request) and at placement:

  ```php
  public function execute(\Magento\Framework\Event\Observer $observer): void
  {
      if ($observer->getEvent()->getData('method_instance')->getCode() === 'affirm_gateway' && !$this->allowed()) {
          $observer->getEvent()->getData('result')->setData('is_available', false);
      }
  }
  ```

  Register it in `etc/events.xml` (global) so that it covers the frontend and the return requests. Captures, voids
  and refunds of existing orders do not check availability and keep working.
- **Unsupported adjustments: `ignoredTotalCodes`** on `TotalsBuilder` (see [Accounting rules](#accounting-rules)).
- **Item images:** argument `imageId` on `Hyva\CheckoutAffirm\Model\Checkout\ItemsBuilder` (default
  `product_thumbnail_image`, a `view.xml` image id).
- **Cart snapshot: `Snapshot::collect()`.** `Hyva\CheckoutAffirm\Model\Attempt\Snapshot::collect(Quote $quote): array`
  is public so an `after` plugin can add fields that should invalidate an attempt (a custom fee, a gift option) or
  drop fields that legitimately change between preparation and confirmation without changing what Affirm charges.
  The verifier still requires Affirm's total to equal the attempt's total, so amounts cannot drift either way.
- **Affirm's event `affirm_place_order_success`** (`order`, `quote`) is dispatched once per placed attempt, as
  Affirm's own confirm controller does.
- **Lifetimes and limits** (DI arguments): `lifetimeSeconds` (3600) and `reuseMinRemainingSeconds` (900) on
  `Hyva\CheckoutAffirm\Model\Attempt\AttemptRepository`; `attemptLimit`/`attemptWindowSeconds` on the component;
  the cleanup arguments in [Cleanup cron](#cleanup-cron); `staleAfterSeconds` (900) on
  `Hyva\CheckoutAffirm\Model\Attempt\StaleAttemptPolicy` (when a placing/authorizing attempt counts as abandoned:
  shopper's page, escalation job and `resolve --force`) and `batchSize` (50) on
  `Hyva\CheckoutAffirm\Cron\EscalateStaleAttempts`.
- **Services behind interfaces** (global preferences, replaceable): `CheckoutObjectBuilderInterface`,
  `VerifierInterface`, `ConfirmationPipelineInterface`, `SnapshotInterface`, `OwnerKeyInterface`,
  `AttemptRepositoryInterface`. The classes are non-final, so plugins work.
- **Templates** (override in your theme under `Hyva_CheckoutAffirm/templates/…`):
  `checkout/payment/method/affirm-gateway.phtml` (method body; keep the single root element with `wire:key` and the
  `data-*` attributes), `checkout/payment/method/inline.phtml` (widget container), `checkout/payment/affirm-gateway-js.phtml`
  (payment JavaScript), `checkout/attempt/in-progress.phtml`, `checkout/success/cart-refresh.phtml`,
  `js/sdk-loader.phtml` (keep it byte-identical with the storefront module's copy).

## Sandbox testing tips

Observed in Affirm's US sandbox on 2026-10-08; see Affirm's
[test and go-live guide](https://docs.affirm.com/payments/docs/partner-test-and-go-live) and
[sandbox testing](https://docs.affirm.com/developers/docs/telesales-testing).

- The verification PIN is **123456** (the sandbox banner says so), not 1234.
- The SSN last-4 bypass is **5678**; other values decline the identity check.
- **Decline in the sandbox returns through the cancel URL.** Affirm's "Return to checkout" after a declined identity
  check uses `user_cancel_url`, so the shopper sees the cancel notice; `user_decline_url` was not called. The decline
  URL itself works when called.
- Turn **AutoPay** off before Affirm's Confirm button becomes enabled.
- Use a fresh random mobile number and a random adult birth date per checkout; a known name and birth date trigger an
  identity-conflict screen. Affirm rejects e-mail domains without mail exchangers (such as `example.com`) for the
  Affirm identity; the Magento order can keep its own e-mail address.
- Closing Affirm raises a native `confirm()` dialog; browser automation must accept it. In redirect mode, the close
  button does nothing on the phone-number screen.
- **Partial capture depends on the Affirm account:** the sandbox account used refused partial captures with
  `409 capture_not_equal_to_auth`; full capture, partial and full refunds and void (through order cancel) worked.
- **The inline widget may not render** for accounts without the inline feature: affirm.js loaded the widget once and
  removed it again, also in a plain container outside Magewire. The module's widget data still updates.
- **Promotional text:** Affirm's promos API answers with CORS errors for store origins that are not allow-listed on
  the Affirm account; affirm.js then shows generic text.
- Return URLs carry checkout tokens: switch off browser traces, videos and screenshots that record URLs in test
  automation, or mask the tokens.

## Known upstream issues

### Affirm extension 4.0.11

| Issue | Status in this module |
|---|---|
| `view/frontend/web/js/model/affirm.js` reads `configData.CountryCode`; `country_code` is undefined in the checkout object | worked around: the checkout object is built on the server and `country_code` is set on the payment from the currency |
| The confirm controller is POST-only with CSRF disabled, so returns fail under `SameSite=Lax` | worked around: own confirm controller with a GET return and POST → 303 → GET |
| Checkout tokens are logged in request URIs by `ClientService::placeRequest()` | worked around: log redaction (see [Security and privacy](#security-and-privacy)) |
| A fresh random idempotency key per request defeats safe retries | mitigated: no automatic retry; the attempt state machine, the `uncertain` state and reconciliation |
| `InlineCheckout::formatItems()` sends dollars and a raw thumbnail path | not used: the inline widget's object comes from this module's builder (cents, image URLs) |
| `AffirmCheckoutManager::getRegionCode()` ignores its argument; the wrong exception class is caught in `initCheckout()` | not used: the module builds addresses itself and does not call `initCheckout()` |
| The `affirm_customer_mfp` customer attribute is read but never created | not worked around: affects Affirm's financing program resolution, which this module uses unchanged |
| Admin address and shipment sync use the US keys for CAD orders | outside this module (admin) |
| The `CanCapturePartial` and `UpdateAuthMessage` plugins affect every payment method | outside this module (global Affirm plugins) |

### Affirm's sandbox (observed 2026-10-08)

- Decline returns through `user_cancel_url`; the inline widget is not displayed for the account used; partial capture
  refused (`409`); promos API CORS errors for origins that are not allow-listed (details in
  [Sandbox testing tips](#sandbox-testing-tips)).
- Back from Magento's receipt after a redirect checkout lands on Affirm's own "This page isn't loading" page, which
  does not post again; no second order.

### Hyvä Checkout

- When another tab switches the cart's payment method, Place Order in the stale tab is routed to the other method on
  the server and Hyvä shows its generic "Something went wrong" dialog. Affirm is not involved.
