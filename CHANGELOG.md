# 1.3.0
- Import report: before writing an order the module reads the shop price and the stock of each ordered article. Differences appear as a warning in the protocol, and everysize is told once per item. The order is always imported at the price paid at everysize; a failed report never stops the import and is not repeated
- Refunds made safer: only a clear refusal of the refund request itself (422 PAYPAL_REFUND_FAILED or REFUND_AMOUNT_INVALID, 400 ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS) counts as "no money moved"; every other answer (timeout, 401, 403, 404, 429, 5xx, ...) leaves the result unclear, and a 2xx without a refund ID too. The open entry (one row per order, own table; migration included, entries of the earlier draft are moved) is reserved first, then the PayPal refund list is read and stored with it; an unreadable list, a changed list (stale tab) or a failure before the request withdraws the new entry. The right to send is taken over atomically and counted from the last hand-over (150 s), so parallel clicks send once, and no lock can be left behind without a way out. "Repeat" reads the list first: a refund that is new since the entry was made, completed or pending, of the same amount (any for "rest") and unknown to the shop closes the entry as success; an old or foreign refund never does. A refused repeat leaves the entry open (the first request may have gone through). "Mark as settled" sends nothing, removes only the entry the merchant saw and only after 150 s, and keeps the automatic refund away; the open entry is shown even if the list cannot be read. Success: the refund ID is noted in the entry, then the report is written, then the entry goes; a failed delete never leads to a second send. A refund list counts only if every entry has a refund ID and a status, amounts are read with the thousands separator ("1,234.56"). "Paid" in the tab is what everysize charged (grossPrice), so cancelling positions in OXID (which lowers the order total) no longer hides the open amount or the form; with an unreadable list the form is not offered. The automatic refund after a cancellation sends at most once: it asks the setting and every condition again for exactly that order, never repeats an open entry (it only reads the list and closes it on a new matching refund, the protocol shows entries older than 15 minutes), blocks on signs of shipping (shipping date, tracking code), a discount or voucher given in OXID, or an unreadable amount paid, and writes a note once for cancellations it skips silently. Errors are caught per order. A failed shipping report is retried before a return is reported. A discount or voucher on an order is recorded (refund report DISCOUNT) before OXID recalculates the order when a position is cancelled - the recalculation resets both fields - so the automatic refund stays off for good (new extension of the order model; update as described in the README). An unfinished update (table or extension missing) switches the automatic refund off with an error in the protocol. A refund list with a completed or pending refund without a readable amount counts as unreadable. Limit: a credit note booked only in the shop is not recognised


# 1.2.0
- Tab "everysize" reorganised: three titled sections – the everysize order as an info table, refunds through everysize with tiles (paid / refunded / open), refund list with column headings, labelled form and notice box, reports to everysize as a table (cancellation and return named separately, amounts with currency, empty values as "–")
- Times in the tab and the protocol formatted the same way, in the shop's time zone
- Shipping is reported also if the order was cancelled between "Jetzt versenden" and the next run: first shipping with tracking code, then the return (before: cancellation `MERCHANT_DECLINE`, the tracking code was lost)
- Automatic refund after a cancellation before shipping only if nothing has been refunded through everysize yet (before, after a partial refund in the tab the rest was refunded automatically)

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
