# PayGlocal for WooCommerce

Storewide currency conversion and PayGlocal checkout for WooCommerce.

Product prices stay stored in Indian Rupees. Shoppers see and pay in their local currency when that currency is enabled for the merchant account and an exchange rate is saved. Otherwise they see and pay in INR.

**Owner:** [VEDMINT CONSULTANCY SERVICES](https://www.vedmint.com)  
**Website:** [https://www.vedmint.com](https://www.vedmint.com)

## What you need before you start

| Requirement | Detail |
| --- | --- |
| WordPress | 6.2 or newer |
| WooCommerce | 8.2 or newer |
| PHP | 8.0 or newer |
| PHP extensions | OpenSSL, JSON, and either GMP or BCMath |
| Store currency | Indian Rupee (INR) |
| PayGlocal account | Merchant ID, public key ID, private key ID, PayGlocal public PEM, and your private PEM |

Have the PEM files and key IDs from the PayGlocal merchant dashboard before you enable payments. Prices in the catalog must already be entered in INR. This plugin does not rewrite those prices.

## Step 1. Set WooCommerce to INR

1. In WordPress admin, open **WooCommerce → Settings → General**.
2. Set **Currency** to **Indian Rupee (₹)**.
3. Save changes.
4. Enter or confirm every product price, including variations and sale prices, in INR.

Example: a product priced at ₹8,500 stays ₹8,500 in the database. With a USD rate of 85, a shopper in the United States sees $100.

## Step 2. Install the plugin

1. Zip the `payglocal` folder, or upload the plugin zip from **Plugins → Add New Plugin → Upload Plugin**.
2. Click **Install Now**, then **Activate**.
3. Confirm WooCommerce is already active. If it is not, the plugin shows an admin error and does not load.

You can also copy the `payglocal` folder into `wp-content/plugins/payglocal/` and activate it from **Plugins**.

## Step 3. Open the PayGlocal settings

Open **WooCommerce → PayGlocal**.

The same screen is linked as **Settings** on the Plugins list. Payment fields are also available under **WooCommerce → Settings → Payments → PayGlocal**. Use **WooCommerce → PayGlocal** as the main setup screen. It saves the account, the currencies, and the rates together.

## Step 4. Enter the PayGlocal account

On **WooCommerce → PayGlocal**, under **Payments**:

1. Leave **Enable PayGlocal** unchecked until the keys are saved and the admin error list is clear.
2. Set **Checkout title** and **Checkout description**. These are what the customer sees at checkout.
3. Set **Environment** to **Sandbox** for the first setup.
4. Enter **Merchant ID**.
5. Enter **Public key ID** and **Private key ID**.
6. Paste the PayGlocal public key into **PayGlocal public key (PEM)**. The text must include the `BEGIN` and `END` lines.
7. Paste your merchant private key into **Merchant private key (PEM)**.
8. Click **Save PayGlocal settings**.

If a key is not valid RSA PEM, it is not saved and the previous key stays in place. Payments remain off until the merchant ID, both key IDs, and both keys are valid. The reason is shown in a red admin notice at the top of the screen.

To replace a saved key, paste the new PEM and save. Leave a key box unchanged if you want to keep the key already stored. An empty box on a later save keeps the saved key.

## Step 5. Choose currencies

Still on **WooCommerce → PayGlocal**, under **Price conversion**:

1. Leave **Convert storefront prices from INR** checked.
2. Leave **Show the country and currency selector** checked, unless you will place the selector yourself.
3. Tick every currency this PayGlocal merchant account can charge.
4. Leave **INR** ticked. It cannot be removed.
5. Leave **JPY** unticked unless the merchant account can charge Japanese Yen and you will enter a rate. A shopper in Japan then sees INR.

Currency rules:

- The shopper’s country currency is used when it is ticked and has a rate.
- Otherwise the shopper sees INR and pays in INR.
- If the country cannot be detected, the store uses USD when USD is ticked and has a rate. Otherwise it uses INR.

## Step 6. Set exchange rates

Rates are **how many INR equal 1 unit of the other currency**.

| Currency | Rate | A ₹8,500 product displays as |
| --- | ---: | --- |
| USD | 85 | $100 |
| EUR | 100 | €85 |
| GBP | 115 | £73.91 |

1. Under **Rate source**, choose **Refresh from the exchange-rate feed and cache the result** to keep rates updated, or **Use only the rates saved on this screen** to lock the numbers you type.
2. Fill **INR per 1 unit** for every currency you ticked, except INR.
3. Click **Save PayGlocal settings**.
4. Or click **Refresh rates now** to download a fresh cache. If the download fails, the last saved rates stay in use and an admin error explains that.

A ticked currency with no rate is not shown to shoppers. Those shoppers see INR, and the settings screen lists the currency that still needs a rate.

Sample USD, EUR, and GBP rates are included so the store can be reviewed before the first refresh. Replace them before you take live payments.

## Step 7. Turn payments on

1. Return to **WooCommerce → PayGlocal**.
2. Check **Enable PayGlocal**.
3. Save.
4. Open **WooCommerce → Settings → Payments**.
5. Confirm **PayGlocal** is enabled there as well.
6. If a red PayGlocal notice is still on screen, fix the item it names and save again. The gateway will not stay enabled while the account is incomplete.

## Step 8. Check the storefront

1. Open a product that costs a known INR amount.
2. Use the country and currency selector. It sits at the bottom of the storefront, or wherever you place the shortcode `[payglocal_currency_switcher]`.
3. Choose **United States**. Prices, the mini cart, cart, checkout, shipping, tax, and fixed coupons should show USD.
4. Choose **Germany**. The same pages should show EUR when EUR is enabled and has a rate.
5. Choose **India**. Prices should show INR.
6. Choose **Japan** while JPY is not enabled. Prices should show INR.
7. Add the product to the cart and open checkout. The currency must match the product page.
8. Place a Sandbox order. PayGlocal must receive the checkout total and the checkout currency, not a different amount typed into the page.

The selector updates the mini cart without waiting for a full reload, then reloads catalog, cart, and checkout pages so every price uses one currency.

## Step 9. Go live

1. Complete a Sandbox payment and confirm the WooCommerce order is paid.
2. In **WooCommerce → PayGlocal**, change **Environment** from **Sandbox** to **Production**.
3. Replace the Sandbox merchant ID, key IDs, and PEM files with the production values if PayGlocal issued a separate set.
4. Save, confirm there is no admin error, and place one low-value production order.

## Where each control lives

| Task | Screen |
| --- | --- |
| Merchant ID, keys, currencies, and rates | **WooCommerce → PayGlocal** |
| Enable the payment method in the checkout list | **WooCommerce → Settings → Payments** |
| Currency only, if you prefer the WooCommerce settings tab | **WooCommerce → Settings → PayGlocal Currency** |
| Store currency | **WooCommerce → Settings → General** |

## Admin errors

A red **PayGlocal** notice appears when the saved setup cannot be used. Open **Manage PayGlocal settings** on that notice. Typical causes:

- WooCommerce currency is not INR.
- The merchant ID or a key ID is empty while payments are enabled.
- A PEM is missing or is not a valid RSA key.
- PHP OpenSSL is missing, or both GMP and BCMath are missing.
- A selected currency has no exchange rate.
- The rate refresh could not reach the rate feed.

Invalid keys and invalid rates are not written over the last good values.

## Ownership

This plugin is owned and maintained by **VEDMINT CONSULTANCY SERVICES**.

Website: [https://www.vedmint.com](https://www.vedmint.com)
