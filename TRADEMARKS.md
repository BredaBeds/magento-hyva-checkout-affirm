# Trademarks

"Affirm" and the Affirm logo are trademarks of Affirm, Inc. "Hyvä", "Hyvä Themes" and "Hyvä Checkout" are
trademarks of Hyvä Themes B.V. "Magento" is a trademark of Adobe Inc. This project is not affiliated with or
endorsed by any of them.

## The Affirm logo in this package

`src/view/frontend/web/images/affirm.svg` is Affirm's logo, unmodified, as published by Affirm at
`https://cdn-assets.affirm.com/images/blue_logo-transparent_bg.svg` (the vector form of the PNG that Affirm's own
Magento extension uses as its default payment method icon). It is included with Affirm's permission solely to
identify Affirm as the payment method in Hyvä Checkout.

- The logo is **not** covered by this project's MIT licence. No right to use it for any other purpose is granted.
- Do not modify, recolour, crop or combine the logo with other artwork; follow Affirm's brand guidelines
  (Affirm Business Hub). Questions about logo use go to Affirm Merchant Care.
- To use a different icon in the payment method list, override the `metadata` argument of the block
  `checkout.payment.method.affirm_gateway` in your theme's layout (Affirm's Icon setting only adds an icon to the
  method body).

Permission status: preliminary approval from Affirm (2026); final approval of the artwork is a condition for
publishing a release that contains it.
