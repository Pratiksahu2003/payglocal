# PayGlocal

Standalone currency and PayGlocal payments for WordPress. WooCommerce catalog, cart, checkout, and payment features attach when WooCommerce is active. The plugin installs and runs without it.

Product prices stay stored in Indian Rupees. Shoppers see and pay in their local currency when that currency is enabled for the merchant account and an exchange rate is saved. Otherwise they see and pay in INR.

**Owner:** [VEDMINT CONSULTANCY SERVICES](https://www.vedmint.com)  
**Website:** [https://www.vedmint.com](https://www.vedmint.com)  
**Version:** 1.0.0  
**Text domain:** `payglocal`

## What the plugin does

- Keeps INR as the master price in the database. Converted prices are never written back onto products.
- Detects the shopper country and chooses a PayGlocal currency for the whole session.
- Converts every storefront price from that INR amount: catalog, variations, sale prices, cart, checkout, shipping, taxes, and fixed coupons.
- Shows one currency symbol across classic templates and WooCommerce Blocks.
- Charges PayGlocal the WooCommerce order total in the order currency. A posted amount or currency is ignored.
- Gives the store admin one screen for the merchant account, currencies, and rates, and shows an admin error when the setup is incomplete or invalid.

## Requirements

| Requirement | Detail |
| --- | --- |
| WordPress | 6.2 or newer |
| WooCommerce | Optional. Tested to 9.4. Price conversion and checkout run when it is active |
| PHP | 8.0 or newer |
| PHP extensions | OpenSSL, JSON, and either GMP or BCMath |
| Store currency | Indian Rupee (INR) |
| PayGlocal account | Merchant ID, public key ID, private key ID, PayGlocal public PEM, and your private PEM |
| Order storage | Compatible with High-Performance Order Storage (HPOS) |
| Checkout | Compatible with Cart and Checkout Blocks |

## Step-by-step setup

### Step 1. Set WooCommerce to INR

1. Open **WooCommerce → Settings → General**.
2. Set **Currency** to **Indian Rupee (₹)**.
3. Save.
4. Enter every product price, variation price, and sale price in INR.

A product stored as ₹8,500 stays ₹8,500 in the database. With a USD rate of 85, a shopper in the United States sees $100.

### Step 2. Install and activate

1. Zip the `payglocal` folder, or upload it from **Plugins → Add New Plugin → Upload Plugin**.
2. Click **Install Now**, then **Activate**.
3. WooCommerce is optional. The plugin activates on its own. Catalog, cart, checkout, and PayGlocal payment attach when WooCommerce is active.

You can also copy the folder to `wp-content/plugins/payglocal/` and activate it from **Plugins**.

Activation schedules the cron event `payglocal_refresh_exchange_rates` twice a day. Deactivation clears that event. Uninstall deletes PayGlocal options only. Orders and product prices are left in place.

### Step 3. Open PayGlocal settings

Open **WooCommerce → PayGlocal**.

The Plugins list also has a **Settings** link to that screen. The same payment fields exist under **WooCommerce → Settings → Payments → PayGlocal**. Use **WooCommerce → PayGlocal** for the full setup.

### Step 4. Enter the PayGlocal account

Under **Payments**:

1. Leave **Enable PayGlocal** unchecked until the keys validate.
2. Set **Checkout title** and **Checkout description**.
3. Set **Environment** to **Sandbox**.
4. Enter **Merchant ID**, **Public key ID**, and **Private key ID**.
5. Paste the PayGlocal public key and your merchant private key, including the `BEGIN` and `END` lines.
6. Click **Save PayGlocal settings**.

An invalid PEM is not saved. The previous key stays. Payments stay off until the merchant ID, both key IDs, and both RSA keys are valid. The admin notice names the problem.

Leave a key box as it is when you want to keep the saved key. Pasting a new valid PEM replaces it.

### Step 5. Choose currencies

Under **Price conversion**:

1. Leave **Convert storefront prices from INR** checked.
2. Leave **Show the country and currency selector** checked unless you place the selector yourself.
3. Tick every currency this merchant account can charge.
4. INR stays ticked and cannot be removed.
5. Leave JPY unticked unless the account can charge yen and you will save a rate. A shopper in Japan then sees INR.

Rules:

- A known country uses its local currency when that currency is ticked and has a rate.
- Otherwise the shopper sees and pays in INR.
- An unknown country uses USD when USD is ticked and has a rate. Otherwise it uses INR.

### Step 6. Set exchange rates

A rate is how many INR equal 1 unit of the other currency.

| Currency | Rate | ₹8,500 displays as |
| --- | ---: | --- |
| USD | 85 | $100.00 |
| EUR | 100 | €85.00 |
| GBP | 115 | £73.91 |

1. Choose **Refresh from the exchange-rate feed and cache the result**, or **Use only the rates saved on this screen**.
2. Fill **INR per 1 unit** for every ticked currency except INR.
3. Save, or click **Refresh rates now**.

Sample rates shipped before the first refresh are USD 85, EUR 100, and GBP 115. Replace them before live payments. A ticked currency with no rate is not used. Those shoppers see INR, and the settings screen names the missing rate.

The automatic feed reads `https://open.er-api.com/v6/latest/USD` and, if that fails, `https://api.frankfurter.app/latest?from=USD`. Manual mode does not overwrite saved rates.

### Step 7. Turn payments on

1. Check **Enable PayGlocal** and save.
2. Open **WooCommerce → Settings → Payments** and confirm **PayGlocal** is enabled.
3. Clear any red PayGlocal notice before taking an order.

### Step 8. Check the storefront

1. Open a product with a known INR price.
2. Use the country and currency selector. It is rendered in the footer when the selector option is on, from the **PayGlocal Currency** widget, or from `[payglocal_currency_switcher]`.
3. United States shows USD. Germany shows EUR when EUR has a rate. India shows INR. Japan shows INR while JPY is off.
4. Add the product to the cart and open checkout. The currency must match the product page.
5. Place a Sandbox order. PayGlocal receives the checkout total and checkout currency.

The selector refreshes the mini cart, then reloads catalog, cart, and checkout pages so prices are not mixed. `min_price` and `max_price` are removed from the URL on that reload because those bounds belong to the previous currency.

### Step 9. Go live

1. Confirm the Sandbox order is paid in WooCommerce.
2. Set **Environment** to **Production**.
3. Save the production merchant ID, key IDs, and PEM files if they differ from Sandbox.
4. Place one low-value production order.

## Currency behavior

| Shopper | Result when that currency is enabled and has a rate |
| --- | --- |
| India | INR, unchanged |
| United States | USD. ₹8,500 at rate 85 is $100.00 |
| Germany | EUR. ₹8,500 at rate 100 is €85.00 |
| United Kingdom | GBP. ₹8,500 at rate 115 is £73.91 |
| Japan, JPY not enabled | INR |
| Country unknown | USD if USD is usable, otherwise INR |
| Country known, currency not usable | INR |

Country detection order:

1. The country already stored in the WooCommerce session.
2. For a logged-in shopper with an empty session, the user meta saved earlier.
3. The `CF-IPCountry` header when it is a real country code.
4. WooCommerce geolocation (`WC_Geolocation::geolocate_ip`).
5. No country. The default rule above applies.

Changing the country clears a manual currency choice and selects the country currency again. Changing the currency keeps the country and accepts only a supported currency that has a rate. `UK` is treated as `GB`.

The active currency is stored in the WooCommerce session for guests and logged-in customers, and copied to user meta for logged-in customers. It survives refresh, navigation, add to cart, AJAX, and checkout. A `payglocal_currency` cookie is set only as a cache hint. The plugin never reads that cookie to decide a price or a payment.

On the first detection, if the customer billing country is empty or still the store base country, billing and shipping country are aligned to the detected country. A country the shopper later chooses at checkout replaces it.

Admin, cron, WP-CLI, product editing, and WooCommerce REST routes other than the Store API do not convert prices. That keeps INR values from being saved back onto products.

## What is converted

| Area | How |
| --- | --- |
| Homepage, shop, search, related, upsell, cross-sell | Product price filters and price HTML |
| Simple, variable, variation, sale, and regular prices | `get_price`, `get_regular_price`, `get_sale_price` on products and variations |
| Variation price ranges | Variation price filters, with the currency in the variation price hash |
| Mini cart, cart, checkout totals | Cart uses the converted product price. Totals HTML is tagged with the active currency |
| Shipping rates and shipping tax | Converted once per rate |
| Free-shipping minimum | The INR threshold is converted before it is compared with the cart |
| Percentage coupons | Not converted. 10% stays 10% |
| Fixed cart, fixed product, subscription sign-up, and recurring fee coupons | Converted from INR |
| Coupon minimum and maximum spend | Converted from INR |
| Subscription sign-up fee | Converted when WooCommerce Subscriptions asks for it |
| Classic price slider | Slider ends display the converted range. The query compares INR prices |
| WooCommerce Blocks and Store API | Product prices use the same filters. Collection price ranges and `min_price` / `max_price` query args are rewritten |
| Wishlist and recently viewed products | Covered when they print normal WooCommerce product prices |

Empty sale prices stay empty. Zero stays zero. Rounding is half away from zero, to the currency’s decimal places. JPY, KRW, VND, and similar currencies use 0 decimals. BHD, KWD, OMR, and similar currencies use 3. INR uses the WooCommerce decimal setting.

## Payment behavior

PayGlocal is a redirect gateway. Checkout builds the charge from the order total and order currency only.

Sandbox endpoint: `https://api.uat.payglocal.in/gl/v1/`  
Production endpoint: `https://api.prod.payglocal.in/gl/v1/`

Initiate: `POST payments/initiate/paycollect`  
Refund: `POST payments/{gid}/refund`

The body is a JWE (`RSA-OAEP-256` and `A128CBC-HS256`). The `x-gl-token-external` header is a RS256 JWS over a SHA-256 digest of that JWE. The return URL is the WooCommerce API endpoint `payglocal` (`woocommerce_api_payglocal`).

A successful callback status is `SENT_FOR_CAPTURE`, `CAPTURED`, or `SUCCESS`. The signature is checked with the PayGlocal public key. The merchant id, transaction id, and amount must match the order when those fields are present. The shopper is then sent to the thank-you page. Any other status marks the order failed and returns to checkout.

Refunds use the order currency. A full refund sends `refundType` `F`. A partial refund sends `refundType` `P` plus the refund amount.

Request fields named `amount`, `currency`, `totalAmount`, and `txnCurrency` are discarded.

## Admin screens

| Screen | What it manages |
| --- | --- |
| **WooCommerce → PayGlocal** | Gateway on/off, title, description, sandbox or production, merchant ID, key IDs, both PEM keys, conversion on/off, selector on/off, rate source, supported currencies, and every rate |
| **WooCommerce → Settings → Payments → PayGlocal** | The same payment account fields. Invalid keys are rejected here too |
| **WooCommerce → Settings → PayGlocal Currency** | Conversion switch, selector switch, supported currencies, rate source, and the rate table |
| **Plugins** row | **Settings** opens **WooCommerce → PayGlocal** |

Admin errors are shown in a red **PayGlocal** notice:

| Code | When it is thrown |
| --- | --- |
| `store_currency` | Conversion is on and the WooCommerce currency is not INR |
| `merchant_id` | Payments are enabled and the merchant ID is empty |
| `public_kid` | Payments are enabled and the public key ID is empty |
| `private_kid` | Payments are enabled and the private key ID is empty |
| `public_pem_missing` | Payments are enabled and the public PEM is empty |
| `public_pem_invalid` | The public PEM is not a valid RSA key and was not saved |
| `private_pem_missing` | Payments are enabled and the private PEM is empty |
| `private_pem_invalid` | The private PEM is not a valid RSA key and was not saved |
| `openssl_missing` | PHP OpenSSL is not available |
| `rsa_math_missing` | Neither GMP nor BCMath is available |
| `currency_without_rate:XXX` | Currency XXX is enabled and has no positive rate |

Missing-rate notices show on **WooCommerce → PayGlocal** and **WooCommerce → Settings**. Account and store-currency errors show across the admin. A failed rate refresh and a skipped invalid rate are shown once after that action. Saving settings also shows **PayGlocal settings saved.**

If payments are enabled while any gateway-blocking code is present, the gateway is saved as disabled.

## Files

| File | Role |
| --- | --- |
| `payglocal.php` | Plugin header, constants, HPOS and Blocks compatibility, boot, activation cron |
| `uninstall.php` | Deletes PayGlocal options and the cron event |
| `includes/autoload.php` | Loads `PayGlocal\...` classes from `includes/` |
| `includes/functions.php` | Public PHP helpers |
| `includes/Plugin.php` | Wires every component |
| `includes/Storefront.php` | Decides whether the current request may convert prices |
| `includes/Currency/Country_Currency_Map.php` | ISO country to local currency |
| `includes/Currency/Currency_Resolver.php` | Country, support, and rate rules |
| `includes/Currency/Price_Converter.php` | INR math, decimals, and rounding |
| `includes/Currency/Conversion_Guard.php` | Stops a second conversion in the same request |
| `includes/Currency/Rate_Table.php` | Sample rates, default currencies, and feed parsing |
| `includes/Currency/Exchange_Rates.php` | Cached rates in WordPress options |
| `includes/Currency/Currency_Manager.php` | Session, user meta, detection, and cookie hint |
| `includes/Pricing/Price_Adjustment.php` | Converts one product price field |
| `includes/Pricing/Coupon_Adjustment.php` | Converts fixed coupon amounts only |
| `includes/Pricing/Shipping_Adjustment.php` | Converts a shipping rate once |
| `includes/Pricing/Price_Filters.php` | WooCommerce price, currency, cart, and subscription hooks |
| `includes/Pricing/Cart_Adjustments.php` | Coupons, shipping, checkout country, and order stamps |
| `includes/Pricing/Catalog_Query.php` | Classic price-filter widget and SQL bounds |
| `includes/Frontend/Switcher.php` | Selector, shortcode, assets, and body class |
| `includes/Frontend/Switcher_Widget.php` | **PayGlocal Currency** widget |
| `includes/Frontend/Ajax.php` | Country and currency changes |
| `includes/Integration/Store_Api.php` | Store API ranges, cart data, and Blocks registration |
| `includes/Integration/Blocks_Payment_Method.php` | Checkout block payment method |
| `includes/Gateway/Payment_Charge.php` | Order total and currency, ignoring the request |
| `includes/Gateway/Token_Service.php` | JWE encryption and JWS signing |
| `includes/Gateway/Gateway.php` | WooCommerce payment gateway |
| `includes/Admin/Config_Check.php` | Admin validation codes and messages |
| `includes/Admin/Admin_Flash.php` | One-request admin success and error notes |
| `includes/Admin/Notices.php` | Admin notice output |
| `includes/Admin/Settings_Screen.php` | **WooCommerce → PayGlocal** screen |
| `includes/Admin/Currency_Settings.php` | **WooCommerce → Settings → PayGlocal Currency** |
| `assets/js/switcher.js` | Selector AJAX, fragments, and reload |
| `assets/js/blocks.js` | Registers the Blocks payment method |
| `assets/css/switcher.css` | Selector layout |
| `tests/run.php` | Currency, payment, token, and admin validation checks |
| `README.md` | This guide |
| `readme.txt` | WordPress plugin readme |

## Constants

| Constant | Meaning |
| --- | --- |
| `PAYGLOCAL_VERSION` | `1.0.0` |
| `PAYGLOCAL_FILE` | Absolute path of `payglocal.php` |
| `PAYGLOCAL_DIR` | Plugin directory with trailing slash |

## Public PHP functions

These are the functions themes and other plugins can call.

| Function | What it does |
| --- | --- |
| `payglocal()` | Returns the `PayGlocal\Plugin` instance |
| `payglocal_convert_price( $amount, $currency = null )` | Converts an INR amount into the active currency, or into `$currency` when passed. Empty values are unchanged. The product record is not modified |
| `payglocal_get_currency()` | Returns the active payment currency for this request, for example `USD` |

`payglocal()` then exposes:

| Method | What it does |
| --- | --- |
| `Plugin::instance()` | Creates the plugin once |
| `currency()` | Returns `Currency_Manager` |
| `exchange_rates()` | Returns `Exchange_Rates` |
| `converter()` | Returns `Price_Converter` |
| `switcher()` | Returns `Switcher` |
| `boot_currency()` | Loads the WooCommerce decimal setting and exchange rates, then resolves the session currency. Runs on `woocommerce_init` |
| `load_textdomain()` | Loads translations from `languages/` |
| `register_gateway( $gateways )` | Adds `PayGlocal\Gateway\Gateway` to WooCommerce gateways |
| `register_settings( $pages )` | Adds the PayGlocal Currency settings tab |
| `plugin_links( $links )` | Adds the Plugins row **Settings** link |
| `convert_price( $amount, $currency = null )` | Implementation behind `payglocal_convert_price()` |

## Every class and function

### `PayGlocal\Storefront`

| Function | What it does |
| --- | --- |
| `is_request()` | Returns true for storefront, Store API, and storefront AJAX. Returns false for admin screens, wp-admin AJAX, cron, and WP-CLI |

### `PayGlocal\Currency\Country_Currency_Map`

| Function | What it does |
| --- | --- |
| `currency_for_country( $country )` | Returns the ISO currency for an ISO country, or null when the country is unknown. `UK` maps to `GB`. Bulgaria maps to EUR |
| `knows( $country )` | Returns whether the country is in the map |

### `PayGlocal\Currency\Currency_Resolver`

| Function | What it does |
| --- | --- |
| `resolve_for_country( $country, $supported, $rates )` | Applies the country, support, and rate rules and returns `INR`, `USD`, or the local currency |
| `accept_currency( $currency, $supported, $rates )` | Returns the currency when a shopper may select it, otherwise null |
| `usable( $currency, $supported, $rates )` | True for INR always. True for another currency only when it is enabled and has a positive rate |

`Currency_Resolver::BASE` is `INR`.

### `PayGlocal\Currency\Price_Converter`

| Function | What it does |
| --- | --- |
| `__construct( $rates, $base_decimals )` | Stores INR-per-unit rates and the INR decimal count |
| `set_rates( $rates )` | Replaces the rate table with positive numeric rates |
| `set_base_decimals( $decimals )` | Sets INR decimal places from WooCommerce |
| `has_rate( $currency )` | True for INR or a stored positive rate |
| `rate( $currency )` | Returns `1` for INR, or the INR-per-unit rate. Throws if the rate is missing |
| `decimals( $currency )` | Decimal places for display and charging |
| `convert_amount( $amount, $currency )` | Divides an INR amount by the rate and rounds |
| `to_base_amount( $amount, $currency )` | Multiplies a displayed amount back into INR |
| `rewrite_query_minor( $display_minor, $decimals, $currency )` | Turns a Store API minimum or maximum into the INR minor amount WooCommerce queries |
| `rewrite_result_minor( $base_minor, $decimals, $currency )` | Turns an INR lookup range into the shopper’s minor units |
| `normalize( $amount )` | Turns a numeric string into a plain decimal |
| `round_half_up( $number, $decimals )` | Rounds half away from zero |
| `div_round()` | Private divide-and-round |
| `mul_round()` | Private multiply-and-round |

### `PayGlocal\Currency\Conversion_Guard`

| Function | What it does |
| --- | --- |
| `once( $owner, $prop, $incoming, $convert )` | Converts a raw INR field once. If the same object later receives the already converted value, that value is returned unchanged |
| `reset()` | Clears the memory for the request |
| `is_amount()` | Private numeric check |
| `same()` | Private equality check |

### `PayGlocal\Currency\Rate_Table`

| Function | What it does |
| --- | --- |
| `sample_rates()` | Returns USD `85`, EUR `100`, GBP `115` |
| `default_supported()` | Returns INR, USD, EUR, GBP, AUD, CAD, SGD, AED, CHF, HKD, NZD, SEK, NOK, DKK. JPY is absent |
| `catalog()` | Returns every currency the settings screen can tick, including JPY and the other codes listed in that method |
| `from_usd_table( $usd_rates )` | Converts a “units per 1 USD” table into INR per 1 unit |
| `trim_rate()` | Private trailing-zero cleanup |

### `PayGlocal\Currency\Exchange_Rates`

| Function | What it does |
| --- | --- |
| `all()` | Returns saved rates merged over the sample USD, EUR, and GBP rates |
| `cache_token()` | Hash of the rate cache, used so shipping and cart caches change when rates change |
| `updated_at()` | Unix time of the last save, or 0 |
| `source()` | `sample`, `manual`, `saved`, or `automatic` |
| `save( $rates, $source )` | Writes option `payglocal_exchange_rates` |
| `refresh()` | Downloads rates when rate mode is automatic. Manual mode returns false and keeps the saved rates |
| `download()` | Private feed request |

`Exchange_Rates::OPTION` is `payglocal_exchange_rates`.

### `PayGlocal\Currency\Currency_Manager`

| Function | What it does |
| --- | --- |
| `is_enabled()` | True when `payglocal_conversion_enabled` is `yes` |
| `supported()` | Saved currency list. INR is always included. The default list is used until the merchant saves one |
| `boot()` | Resolves country and currency for this request |
| `get_active_currency()` | Active ISO currency. Calls `boot()` if needed |
| `get_country()` | Active ISO country, or an empty string |
| `apply_country( $country )` | Saves a valid country, derives the currency, and syncs the customer country |
| `apply_currency( $currency )` | Saves a usable currency as a manual choice. Returns null when the currency is rejected |
| `resolve()` | Private session, user, and geo resolution |
| `read_stored()` | Private session and user-meta read |
| `detect_country()` | Private geo detection |
| `persist()` | Private session, user meta, and hint cookie write |
| `align_customer_country()` | Private first-visit country alignment |
| `sync_customer_country()` | Private billing and shipping country write |
| `set_hint_cookie()` | Private non-authoritative cookie |
| `session()` | Private WooCommerce session |

Session keys: `payglocal_country`, `payglocal_currency`, `payglocal_manual`.  
User meta: `_payglocal_country`, `_payglocal_currency`, `_payglocal_manual`.  
`Currency_Manager::BASE` is `INR`.

### `PayGlocal\Pricing\Price_Adjustment`

| Function | What it does |
| --- | --- |
| `guard()` | Returns the conversion guard |
| `adjust( $price, $product, $prop, $currency )` | Converts one raw price. Empty and non-numeric values, and INR, pass through |
| `is_amount()` | Private numeric check |

### `PayGlocal\Pricing\Coupon_Adjustment`

| Function | What it does |
| --- | --- |
| `amount( $amount, $type, $currency, $converter )` | Converts `fixed_cart`, `fixed_product`, `sign_up_fee`, and `recurring_fee`. Percentage types are unchanged |

### `PayGlocal\Pricing\Shipping_Adjustment`

| Function | What it does |
| --- | --- |
| `convert_rate( $cost, $taxes, $currency, $converter, $already )` | Converts the rate cost and each tax once. A rate already marked converted is left alone |

### `PayGlocal\Pricing\Price_Filters`

| Function | What it does |
| --- | --- |
| `register()` | Attaches every price, currency, cart HTML, and sign-up fee hook |
| `filter_product_price( $price, $product )` | Converts product and variation price, regular price, and sale price |
| `filter_variation_price( $price, $variation, $parent )` | Converts variation-range prices |
| `variation_hash( $hash )` | Adds the active currency so variation prices are not reused across currencies |
| `filter_currency( $currency )` | Returns the active currency on the storefront |
| `filter_symbol( $symbol, $currency )` | Keeps the symbol WooCommerce resolved for that currency |
| `filter_decimals( $decimals )` | Uses the active currency’s decimals. INR keeps the WooCommerce setting |
| `filter_price_args( $args )` | Sets decimal places when `wc_price()` is called for a non-INR currency |
| `filter_price_html( $html, $product )` | Wraps price HTML with `payglocal-money` and `data-currency` |
| `tag_money_html()` | Same wrap for cart item price and line subtotal |
| `tag_money_html_single()` | Same wrap for cart subtotal and order total HTML |
| `filter_sign_up_fee( $fee, $product )` | Converts a subscription sign-up fee from INR |
| `tag()` | Private HTML wrap. Does not wrap twice |
| `should_convert()` | Private check that conversion is on, this is the storefront, and the currency is not INR |
| `should_localize()` | Private check that the currency code and symbol may change |

Hooks registered here, all at priority 1000 except the variation hash:

- `woocommerce_product_get_price`
- `woocommerce_product_get_regular_price`
- `woocommerce_product_get_sale_price`
- `woocommerce_product_variation_get_price`
- `woocommerce_product_variation_get_regular_price`
- `woocommerce_product_variation_get_sale_price`
- `woocommerce_variation_prices_price`
- `woocommerce_variation_prices_regular_price`
- `woocommerce_variation_prices_sale_price`
- `woocommerce_get_variation_prices_hash`
- `woocommerce_currency`
- `woocommerce_currency_symbol`
- `wc_get_price_decimals`
- `wc_price_args`
- `woocommerce_get_price_html`
- `woocommerce_cart_item_price`
- `woocommerce_cart_item_subtotal`
- `woocommerce_cart_subtotal`
- `woocommerce_cart_totals_order_total_html`
- `woocommerce_subscriptions_product_sign_up_fee`

Cart HTML hooks do not divide the amount again. The amount is already in the active currency.

### `PayGlocal\Pricing\Cart_Adjustments`

| Function | What it does |
| --- | --- |
| `register()` | Attaches coupon, shipping, cart hash, checkout, and order hooks |
| `coupon_amount( $amount, $coupon )` | Converts fixed coupon amounts and ignores a second pass on the same coupon |
| `coupon_threshold( $amount, $coupon )` | Converts coupon minimum and maximum spend |
| `package_rates( $rates, $package )` | Converts each shipping rate and its taxes, then marks `payglocal_fx` |
| `shipping_packages( $packages )` | Adds the currency and rate token so WooCommerce does not reuse another currency’s shipping cache |
| `free_shipping_option( $value, $key, $method )` | Converts the free-shipping `min_amount` |
| `cart_hash( $hash, $cart )` | Mixes currency and rate token into the cart hash |
| `checkout_country( $post_data )` | On classic checkout, applies billing or shipping country and clears shipping cache |
| `blocks_customer_country( $customer, $request )` | On Blocks checkout, applies the customer country |
| `stamp_order( $order )` | Sets the order currency and stores base currency, active currency, country, and rate |
| `stamp_line( $item, $cart_item_key, $values, $order )` | Stores the raw INR `_price` on the line as `_payglocal_base_unit_price` |
| `flush_shipping()` | Clears cached shipping packages after a currency change |
| `should_convert()` | Private storefront and non-INR check |

### `PayGlocal\Pricing\Catalog_Query`

| Function | What it does |
| --- | --- |
| `register()` | Attaches the price-widget and query hooks |
| `display_bound( $amount )` | Converts the slider minimum or maximum from INR for display |
| `to_base_before_query( $clauses, $query )` | Converts `min_price` and `max_price` from the display currency into INR before WooCommerce filters posts |
| `restore_after_query( $clauses, $query )` | Puts the original display bounds back into the URL parameters |
| `is_price_filter_query()` | Private main-query check |
| `should_convert()` | Private storefront and non-INR check |

### `PayGlocal\Frontend\Switcher`

| Function | What it does |
| --- | --- |
| `register()` | Registers the shortcode, widget, assets, body class, and optional footer output |
| `assets()` | Enqueues `switcher.css` and `switcher.js`, and passes the AJAX URL and nonce as `PayGlocalCurrency` |
| `body_class( $classes )` | Adds `payglocal-currency-usd` and the same pattern for the active currency |
| `shortcode()` | Renders `[payglocal_currency_switcher]` |
| `render()` | Prints the country and currency `<select>` elements |
| `register_widget()` | Registers `Switcher_Widget` |
| `country_options()` | Private country list from WooCommerce, limited to countries the map knows |
| `currency_options()` | Private list of usable currencies |

### `PayGlocal\Frontend\Switcher_Widget`

| Function | What it does |
| --- | --- |
| `__construct()` | Widget id `payglocal_currency_switcher`, title **PayGlocal Currency** |
| `widget( $args, $instance )` | Prints the selector in a widget area |

### `PayGlocal\Frontend\Ajax`

| Function | What it does |
| --- | --- |
| `register()` | Hooks logged-in and guest AJAX for country and currency |
| `set_country()` | Nonce-checks, saves a two-letter country, recalculates the cart, returns fragments |
| `set_currency()` | Nonce-checks, saves a usable currency, or returns HTTP 400 |
| `authorize()` | Private nonce and cart load |
| `recalculate()` | Private shipping flush and cart totals |
| `payload()` | Private JSON body: country, currency, symbol, fragments |
| `fragments()` | Private mini-cart HTML passed through `woocommerce_add_to_cart_fragments` |

AJAX actions: `payglocal_set_country`, `payglocal_set_currency`.  
Nonce action: `payglocal_currency`.  
The handlers do not read an amount.

`assets/js/switcher.js` posts that request, replaces mini-cart fragments, triggers `payglocal_currency_changed` and `wc_fragment_refresh`, and reloads pages that contain product, cart, or checkout prices.

### `PayGlocal\Integration\Store_Api`

| Function | What it does |
| --- | --- |
| `register()` | Hooks Store API request and response filters, and Blocks registration |
| `normalize_price_query( $result, $server, $request )` | Rewrites Store API `min_price` and `max_price` from display minor units into INR query units |
| `convert_price_range( $result, $server, $request )` | Converts a collection `price_range` from INR into the active currency |
| `convert_range()` | Private range-node conversion |
| `register_store_data()` | Adds read-only `payglocal` data on the cart Store API: `currency`, `country`, `baseCurrency` |
| `register_payment_method()` | Registers `Blocks_Payment_Method` with the Blocks payment registry |
| `is_store_route()` | Private `/wc/store/` check |
| `should_convert()` | Private non-INR check |

The cart update callback accepts `country` or `currency` under the `payglocal` namespace. Country wins when both are sent.

### `PayGlocal\Integration\Blocks_Payment_Method`

| Function | What it does |
| --- | --- |
| `initialize()` | Reads `woocommerce_payglocal_settings` |
| `is_active()` | True when the gateway is enabled |
| `get_payment_method_script_handles()` | Registers `assets/js/blocks.js` |
| `get_payment_method_data()` | Returns title, description, and support for products and refunds |

`assets/js/blocks.js` registers the `payglocal` payment method with `wc.wcBlocksRegistry`.

### `PayGlocal\Gateway\Payment_Charge`

| Function | What it does |
| --- | --- |
| `from_order( $currency, $total, $request )` | Formats the order total in the order currency. `$request` is ignored |
| `line_matches( $base_unit, $qty, $actual_subtotal, $currency, $converter )` | True when a line subtotal matches the INR catalog within one minor unit per quantity |

### `PayGlocal\Gateway\Token_Service`

| Function | What it does |
| --- | --- |
| `seal( $payload, $merchant_id, $public_kid, $public_pem, $private_kid, $private_pem )` | Returns `jwe` and `jws` |
| `encrypt( $payload, $merchant_id, $public_kid, $public_pem )` | Builds the JWE body |
| `sign( $jwe, $merchant_id, $private_kid, $private_pem )` | Signs the JWE digest |
| `verify( $token, $public_pem )` | Verifies a callback JWS and returns its payload |
| `decrypt( $jwe, $private_pem )` | Decrypts a JWE. Used to prove the token round-trips. PayGlocal decrypts live charges |
| `compact_jws()` | Private compact JWS |
| `rsa_oaep_encrypt()` | Private RSA-OAEP-256 |
| `rsa_oaep_decrypt()` | Private RSA-OAEP-256 decrypt |
| `oaep_encode()` | Private OAEP padding |
| `oaep_decode()` | Private OAEP unpadding |
| `mgf1()` | Private mask generation |
| `xor_bytes()` | Private byte XOR |
| `rsa_pow()` | Private RSA operation through GMP, then BCMath |
| `binary_to_gmp()` | Private helper |
| `gmp_to_binary()` | Private helper |
| `binary_to_bc()` | Private helper |
| `bc_to_binary()` | Private helper |
| `encode_json()` | Private JSON encoding |
| `base64url()` | Private base64url encode |
| `base64url_decode()` | Private base64url decode |

### `PayGlocal\Gateway\Gateway`

Gateway id: `payglocal`. Supports products and refunds. No card fields on the store. The shopper is redirected to PayGlocal.

| Function | What it does |
| --- | --- |
| `__construct()` | Loads settings and hooks the settings save and the return URL |
| `init_form_fields()` | Defines enable, title, description, environment, merchant ID, key IDs, and both PEM fields |
| `sanitize_pem( $value )` | Trims a PEM without stripping the key material |
| `process_admin_options()` | Saves the WooCommerce payment screen, rejects invalid PEM values, throws admin errors, and turns the gateway off when the account is incomplete |
| `process_payment( $order_id )` | Seals the order total and redirects to PayGlocal |
| `process_refund( $order_id, $amount, $reason )` | Sends a full or partial refund in the order currency |
| `handle_callback()` | Verifies the return token and completes or fails the order |
| `keep_previous_key_when_invalid()` | Private: restores the previous PEM when the posted one is invalid |
| `settings_snapshot()` | Private settings array passed to validation |
| `note_catalog_drift()` | Private order note when a line does not match the INR price at the rate saved on the order. The checkout total is still what gets charged |
| `assert_callback_amount()` | Private amount and currency match |
| `order_from_payload()` | Private order lookup by merchant transaction id or merchant unique id |
| `payload()` | Private PayGlocal initiate body, including billing data |
| `post()` | Private HTTP POST of the JWE and JWS |
| `endpoint()` | Private Sandbox or Production URL |
| `currency_allowed()` | Private check that the order currency is enabled |
| `unique_id()` | Private 16-digit merchant unique id |
| `ignored_request_amounts()` | Private collection of posted amount and currency fields that are then discarded |

### `PayGlocal\Admin\Config_Check`

| Function | What it does |
| --- | --- |
| `codes( $settings )` | Returns the error codes for a settings snapshot |
| `blocks_gateway( $codes )` | True when those codes must keep payments off |
| `messages( $codes )` | Turns codes into admin sentences |
| `message( $code )` | One sentence for one code |
| `public_key_is_valid( $pem )` | True for a valid RSA public PEM |
| `private_key_is_valid( $pem )` | True for a valid RSA private PEM |
| `key_codes()` | Private missing or invalid key codes |
| `key_is_valid()` | Private OpenSSL key check |

### `PayGlocal\Admin\Admin_Flash`

| Function | What it does |
| --- | --- |
| `add_error( $message )` | Queues an admin error for this user |
| `add_success( $message )` | Queues an admin success note for this user |
| `consume()` | Returns and deletes the queued notes |
| `add()` | Private writer |
| `key()` | Private transient name `payglocal_admin_flash_{user id}` |
| `strings()` | Private string cleanup |

### `PayGlocal\Admin\Notices`

| Function | What it does |
| --- | --- |
| `render()` | Prints success and error notices for users who can `manage_woocommerce` |
| `is_settings_screen()` | Private check for the PayGlocal and WooCommerce settings screens |
| `live_codes()` | Private read of the saved configuration |

### `PayGlocal\Admin\Settings_Screen`

| Function | What it does |
| --- | --- |
| `register_menu()` | Adds **WooCommerce → PayGlocal** for `manage_woocommerce` |
| `handle_post()` | Validates and saves the form, then redirects |
| `render()` | Prints the payments, conversion, currency, and rate form |
| `incoming_pem()` | Private keep-or-replace PEM handling |
| `checkbox()` | Private field |
| `text()` | Private field |
| `textarea()` | Private field |
| `select()` | Private field |

### `PayGlocal\Admin\Currency_Settings`

| Function | What it does |
| --- | --- |
| `get_settings()` | WooCommerce settings fields for conversion, selector, currencies, and rate source |
| `render_rate_table( $field )` | Prints the rate table and **Refresh rates now** |
| `save_rate_table()` | Saves positive rates and reports a skipped invalid rate |
| `refresh_rates()` | Switches to automatic mode, downloads rates, and redirects back with a success or error note |

## Stored data

### Options

| Option | Contents |
| --- | --- |
| `woocommerce_payglocal_settings` | `enabled`, `title`, `description`, `mode` (`uat` or `production`), `merchant_id`, `public_kid`, `private_kid`, `public_pem`, `private_pem` |
| `payglocal_conversion_enabled` | `yes` or `no`. Default `yes` |
| `payglocal_show_switcher` | `yes` or `no`. Default `yes` |
| `payglocal_supported_currencies` | List of ISO currency codes. INR is forced on |
| `payglocal_rate_mode` | `automatic` or `manual`. Default `automatic` |
| `payglocal_exchange_rates` | `rates`, `updated`, and `source` |

`uninstall.php` deletes those options and `payglocal_refresh_exchange_rates`. It does not delete orders or product prices.

### Order meta

| Meta | Meaning |
| --- | --- |
| `_payglocal_base_currency` | Always `INR` |
| `_payglocal_currency` | Currency locked on the order |
| `_payglocal_country` | Country used to choose it |
| `_payglocal_rate` | INR per 1 unit at checkout, or `1` for INR |
| `_payglocal_merchant_txn_id` | Alphanumeric initiate id |
| `_payglocal_merchant_unique_id` | 16-digit PayGlocal unique id |
| `_payglocal_gid` | PayGlocal transaction id |
| `_payglocal_status_url` | Status URL returned at initiate |
| `_payglocal_charged_amount` | Amount sent to PayGlocal |
| `_payglocal_charged_currency` | Currency sent to PayGlocal |

Line meta `_payglocal_base_unit_price` is the raw INR `_price` of the product or variation. Hidden meta keys are not shown to the customer.

### Session, user, and cookie

| Store | Keys |
| --- | --- |
| WooCommerce session | `payglocal_country`, `payglocal_currency`, `payglocal_manual` |
| User meta | `_payglocal_country`, `_payglocal_currency`, `_payglocal_manual` |
| Cookie `payglocal_currency` | Cache hint only. HttpOnly, SameSite Lax. Never used to calculate a charge |

## Hooks the plugin listens to

| Hook | Purpose |
| --- | --- |
| `before_woocommerce_init` | Declares HPOS and Cart/Checkout Blocks compatibility |
| `plugins_loaded` | Starts the plugin after WooCommerce, or shows the missing-WooCommerce error |
| `init` | Loads the text domain |
| `woocommerce_init` | Resolves the session currency |
| `admin_menu` | Adds **WooCommerce → PayGlocal** |
| `admin_notices` | Prints PayGlocal errors and the missing-WooCommerce error |
| `plugin_action_links_{file}` | Adds **Settings** |
| `payglocal_refresh_exchange_rates` | Twice-daily rate refresh |
| `admin_post_payglocal_refresh_rates` | **Refresh rates now** |
| `woocommerce_payment_gateways` | Registers the gateway |
| `woocommerce_get_settings_pages` | Registers the currency tab |
| `woocommerce_update_options_payment_gateways_payglocal` | Saves and validates the payment screen |
| `woocommerce_update_options_payglocal_currency` | Saves the rate table |
| `woocommerce_admin_field_payglocal_rate_table` | Renders the rate table |
| `woocommerce_api_payglocal` | PayGlocal browser return |
| `wp_ajax_payglocal_set_country` | Logged-in country change |
| `wp_ajax_nopriv_payglocal_set_country` | Guest country change |
| `wp_ajax_payglocal_set_currency` | Logged-in currency change |
| `wp_ajax_nopriv_payglocal_set_currency` | Guest currency change |
| `wp_enqueue_scripts` | Selector assets |
| `wp_footer` | Footer selector when the option is on |
| `widgets_init` | Currency widget |
| `body_class` | `payglocal-currency-{code}` |
| `woocommerce_blocks_loaded` | Store API data and Blocks payment method |
| `woocommerce_blocks_payment_method_type_registration` | Registers `payglocal` |
| `rest_pre_dispatch` | Store API price bounds into INR |
| `rest_post_dispatch` | Store API price range into the active currency |

Price, cart, coupon, shipping, and catalog hooks are listed on `Price_Filters`, `Cart_Adjustments`, and `Catalog_Query` above.

## Shortcode, widget, and scripts

| Item | Use |
| --- | --- |
| `[payglocal_currency_switcher]` | Prints the country and currency selector |
| Widget **PayGlocal Currency** | Same selector in a widget area |
| Footer selector | Printed on `wp_footer` when **Show the country and currency selector** is on |
| `assets/js/switcher.js` | AJAX update, mini-cart fragments, reload of priced pages |
| `assets/js/blocks.js` | Checkout block payment method |
| `assets/css/switcher.css` | Fixed selector. On cart and checkout it sits in normal document flow so it does not cover the payment button |
| Body class | `payglocal-currency-inr`, `payglocal-currency-usd`, and the same pattern for the active code |
| jQuery event | `payglocal_currency_changed` with the AJAX payload |

## Cron and uninstall

| Event | When |
| --- | --- |
| `payglocal_refresh_exchange_rates` | Scheduled on activation, twice daily, one hour after activation. Cleared on deactivation and uninstall. Runs `Exchange_Rates::refresh()` |

Uninstall removes `payglocal_conversion_enabled`, `payglocal_supported_currencies`, `payglocal_exchange_rates`, `payglocal_rate_mode`, `payglocal_show_switcher`, and `woocommerce_payglocal_settings`.

## Checks

From the plugin directory:

```bash
php tests/run.php
```

`tests/run.php` checks the ₹8,500 examples, country rules, empty sale prices, single conversion, coupons, shipping, price-filter bounds, ignored payment parameters, the exchange-rate feed parser, JWE and JWS round trip, and admin validation of missing and invalid keys.

There is no WordPress site inside this folder, so classic templates, Blocks checkout, and a live PayGlocal payment still need to be placed on the store after setup.

## Ownership

This plugin is owned and maintained by **VEDMINT CONSULTANCY SERVICES**.

Website: [https://www.vedmint.com](https://www.vedmint.com)
