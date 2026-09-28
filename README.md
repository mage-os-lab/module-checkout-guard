# MageOS_CheckoutGuard

Guards against card-testing (carding) bots, for Magento Open Source and Mage-OS stores. Off by default. Stores > Configuration > Sales > Checkout > Checkout Guard.

## Failed Payment Attempts per Cart

Counts failed payment attempts per quote in `quote.payment_failures` (a nullable column added by this module). The count happens around `Magento\Quote\Model\QuoteManagement::submit`, the one place every order path goes through: the storefront checkout, REST, GraphQL and PayPal Express. `0` disables the guard.

At the limit one of two actions runs:

- **Deactivate the cart** (default): the quote is closed, the customer starts over with an empty cart. A bot has to build a new cart, address and shipping rate for the next batch of cards.
- **Reset the checkout**: the items stay, the addresses, shipping method and payment are cleared and the counter starts again. The customer returns to the shipping step. Friendlier for a real customer with card trouble, slightly cheaper for a bot.

Every failure and every action is written to `var/log/checkout_guard.log` with quote id, store id, customer id or `guest`, masked email, remote IP, payment method, failure count, grand total and the gateway message. The IP field is meant for fail2ban or CDN rules.

A limit of 10 keeps a real customer with a few declines out of trouble and still cuts a 1000-card run into 100 checkout rebuilds.

## Block REST Payment Routes

A multiselect of the REST routes that set a payment or place an order. Selected routes answer 404 under both `/V1/guest-carts/:cartId/` and `/V1/carts/mine/`:

- `payment-information` (set payment and place order)
- `order` (place order, `PUT`, not covered by core reCAPTCHA)
- `set-payment-information`
- `selected-payment-method`

Select them when the storefront checkout places orders through its own transport, for example Hyva Checkout (Magewire) or a headless frontend with its own API. Card-testing bots use these routes because the checkout form and its reCAPTCHA never run there. Other cart routes (`estimate-shipping-methods`, `totals-information`, `shipping-information`) stay open.

Leave the field empty on a Luma checkout, which places orders through `payment-information`.

Integrators can add further regular expressions through the `extraPathPatterns` DI argument of `MageOS\CheckoutGuard\Plugin\Webapi\BlockPaymentRoutes`. They apply whenever at least one route is selected.

## Compatibility

Magento 2.4.x and Mage-OS. PHP 8.1 or later. No frontend code.
