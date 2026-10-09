# 1.1.3
- Test environment: `EVERYSIZE_CHECKOUT_API_URL` accepts any `https://` address under `everysize.com` (no port or path) instead of a fixed list; the admin page then shows "test environment"
- Documentation and comments revised

# 1.1.2
- License: MIT (previously proprietary)
- Installation from Packagist: `composer require everysize/checkout-oxid`

# 1.1.1
- The credentials hint names the right place in everysize Business ("Shop integration" → OXID eShop instead of "API management")
- Tab "everysize": spacing between columns, label and value ran together

# 1.1.0
- Refunds from the shop: button in the order tab "everysize", full or partial; everysize refunds the PayPal payment on the merchant's behalf. A form sent twice refunds once
- A cancellation before shipping is refunded in full automatically (setting, on by default; only cancellations after switching it on)
- Order list: new column "everysize" (before cancel/delete) with the everysize order number, filter "all / everysize only / without everysize" and search by everysize order number
- A tracking code entered or corrected after shipping is reported once more, up to 14 days after shipping, if everysize does not have it yet
- Tab "everysize" shows the customer's original e-mail address (information only; the shop's mails still go to the everysize forwarding address)
- Own user agent `everysizeCheckoutPlugin-OXID/<version>`
- Update: deactivate and activate the module once after updating

# 1.0.0
- Initial release for OXID eShop CE 7.0 to 7.5
- Imports everysize orders (with the prices paid at everysize, stock reduction, own payment method) and reports the order number
- Reports shipping (shipping date set, with tracking code and configured carrier), cancellation (MERCHANT_DECLINE) and return (RETOUR, cancellation after shipping) exactly once per order item
- Nothing is reported for orders already revoked at everysize
- The API environment can be switched to an everysize test environment via the environment variable `EVERYSIZE_CHECKOUT_API_URL`; production stays the default and only known everysize URLs are accepted
- Credentials are stored in the shop database, not in the module configuration files
- Admin page with credentials, connection test and protocol; order tab "everysize"
- Console commands `everysize:sync`, `everysize:order:import`, `everysize:order:export` for the cron job
