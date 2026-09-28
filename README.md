# MageOS_CheckoutGuard

Two guards against card-testing (carding) bots, for Magento Open Source and Mage-OS stores.

Both are off by default. Stores > Configuration > Sales > Checkout > Checkout Guard.

## Block REST Payment Routes

Answers 404 on the REST routes that set a payment or place an order:

- `POST|GET /V1/guest-carts/:cartId/payment-information`
- `POST /V1/guest-carts/:cartId/set-payment-information`
- `PUT|GET /V1/guest-carts/:cartId/selected-payment-method`
- `PUT /V1/guest-carts/:cartId/order`
- the same four routes under `/V1/carts/mine/`

Enable it when the storefront checkout places orders through its own transport, for example Hyva Checkout (Magewire) or a headless frontend with its own API. Card-testing bots use these routes because the checkout form and its reCAPTCHA never run there, and core reCAPTCHA does not cover `PUT .../order` at all. Other cart routes (`estimate-shipping-methods`, `totals-information`, `shipping-information`) stay open.

Do not enable it on a Luma checkout, which places orders through `payment-information`.

## Failed Payment Attempts per Cart

Counts failed payment attempts per quote in `quote.payment_failures` (added by this module, nullable). The count happens around `Magento\Quote\Model\QuoteManagement::submit`, the one place every order path goes through: the storefront checkout, REST, GraphQL and PayPal Express. At the configured limit the quote is deactivated, so the customer lands on an empty cart, and a bot has to build a new cart, address and shipping rate for the next batch of cards. `0` disables the limit.

Every failure and every deactivation is written to `var/log/checkout_guard.log` with quote id, store id, customer id or `guest`, masked email, remote IP, payment method, failure count, grand total and the gateway message. The IP field is meant for fail2ban or CDN rules.

A limit of 10 keeps a real customer with a few declines out of trouble and still cuts a 1000-card run into 100 cart rebuilds.

## Compatibility

Magento 2.4.x and Mage-OS. PHP 8.1 or later. No frontend code.
