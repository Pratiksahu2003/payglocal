=== PayGlocal for WooCommerce ===
Contributors: vedmint
Author: VEDMINT CONSULTANCY SERVICES
Author URI: https://www.vedmint.com
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts WooCommerce prices from stored INR amounts into the shopper's PayGlocal currency and charges that same currency.

== Description ==

PayGlocal for WooCommerce keeps Indian Rupee as the price stored on each product. Shoppers see and pay in their local currency when the merchant account supports it and an exchange rate is saved. Otherwise they see and pay in INR.

Owned and maintained by VEDMINT CONSULTANCY SERVICES.
Website: https://www.vedmint.com

The conversion covers the homepage, shop, search, product pages, variations, related products, upsells, cross-sells, mini cart, cart, checkout, shipping, taxes, fixed coupons, and WooCommerce Blocks.

Full setup steps are in README.md.

== Installation ==

1. Install and activate WooCommerce 8.2 or newer on WordPress 6.2 or newer. PHP 8.0 or newer must include OpenSSL, JSON, and either GMP or BCMath.
2. Open WooCommerce, Settings, General. Set the currency to Indian Rupee and save. Enter product prices in INR.
3. Upload the plugin zip from Plugins, Add New Plugin, Upload Plugin. Install and activate it.
4. Open WooCommerce, PayGlocal.
5. Under Payments, set Environment to Sandbox. Enter the Merchant ID, Public key ID, Private key ID, PayGlocal public PEM, and merchant private PEM. Save.
6. Tick the currencies this merchant account can charge. INR stays on. Leave JPY off unless you can charge it and will enter a rate.
7. Enter how many INR equal 1 unit of each enabled currency, or click Refresh rates now. A USD rate of 85 shows a ₹8,500 product as $100. Save.
8. Check Enable PayGlocal and save. Then open WooCommerce, Settings, Payments and confirm PayGlocal is enabled.
9. On the storefront, change the country selector and confirm the product, cart, and checkout use one currency. Place a Sandbox order.
10. After the Sandbox payment succeeds, switch Environment to Production, save the production keys, and take a live order.

Invalid keys are rejected and payments stay off until the account is valid. Admin notices name what still needs to be fixed.

The selector shortcode is `[payglocal_currency_switcher]`.

== Frequently Asked Questions ==

= Who owns this plugin? =

VEDMINT CONSULTANCY SERVICES. Website: https://www.vedmint.com

= Does the plugin overwrite product prices? =

No. The INR amount stored on the product is unchanged. Conversion happens when the price is displayed and when the cart is calculated.

= Can a shopper change the charged amount with a currency parameter? =

No. PayGlocal is charged the WooCommerce order total in the order currency.

= What does a shopper see when their currency is not enabled? =

INR. The same fallback is used when an enabled currency has no exchange rate. Japan sees INR while JPY is left unticked.

== Changelog ==

= 1.0.0 =
* First release for VEDMINT CONSULTANCY SERVICES: storewide INR conversion, admin setup, and PayGlocal payment in the active currency.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Set the WooCommerce currency to INR before taking payments.
