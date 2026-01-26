=== ITMAROON EC RELATE BLOCKS ===
Contributors:      itmaroon
Tags:              shopify, ecommerce, checkout, inventory, cart
Requires at least: 6.4
Tested up to:      6.9
Stable tag:        0.1.0
Requires PHP:      8.2
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Gutenberg blocks that connect WordPress product pages to Shopify (inventory + checkout) using Shopify APIs.

== Description ==
ITMAROON EC RELATE BLOCKS provides two Gutenberg blocks to integrate a headless Shopify commerce flow into a WordPress site:

* `itmar/product-block`
  - Links a WordPress post type to Shopify products.
  - When the linked posts are published/updated, the plugin can create/update the corresponding Shopify product data (depending on your configuration and permissions).
  - Designed for WordPress as the content layer (product pages) while Shopify remains the commerce layer (inventory + checkout).

* `itmar/cart-block`
  - Provides a cart UI powered by Shopify Storefront API Cart.
  - Sends shoppers to Shopify Checkout to complete payment.

Important concept (recommended operation):
* Shopify is the source of truth for inventory and checkout.
* WordPress is the source of truth for marketing/product content.
* This plugin focuses on a smooth purchase flow from WordPress pages to Shopify Checkout.

=== Security / Data Handling (Important) ===
* Admin API Token and Storefront API Token are saved server-side in WordPress options via REST and are NOT embedded into post content or rendered into front-end HTML.
* The editor UI no longer persists secret tokens as block attributes. Tokens should never be stored in post_content.

== Block Overview ==
= itmar/product-block =
Use this block in the Site Editor (template) to enable Shopify connection and product linkage for a selected post type.

Main settings (Block Inspector → “EC setting”):
* Select Product Post Type
* Store Site URL (Shopify domain)
* Shop ID (for Customer Account API)
* Channel Name (publication / sales channel display name)
* Headless Client ID (Customer Account API client_id)
* Admin API Token (saved server-side; not stored in post content)
* Storefront API Token (saved server-side; not stored in post content)
* Optional display controls (fields selector, display count, cart icon media ID, etc.)

= itmar/cart-block =
Use this block to show the cart UI. It uses Shopify Storefront API Cart endpoints to:
* Create/update cart lines
* Show totals
* Provide a Checkout URL for completing purchase in Shopify Checkout

== Shopify Setup (Required) ==
You need a Shopify store and API credentials.

1) Admin API Access Token (for product/customer management)
* Create a Shopify app (Custom app) and generate an Admin API access token.
* Grant only the minimum required permissions.

2) Storefront API Access Token (for product/cart/checkout flow)
* Create a Storefront access token in Shopify.

3) Customer Accounts (Headless) (optional but recommended if you enable customer login)
* Enable the new Customer Accounts / Customer Account API in Shopify.
* Install Shopify “Headless” (sales channel) and obtain the “Client ID” used for OAuth (Customer Account API).
* You will also need the “Shop ID”.
  - Tip: You can derive Shop ID from the issuer value in:
    https://{your-shop-domain}/.well-known/openid-configuration

== Installation ==
1. From the WordPress admin panel, go to “Plugins” → “Add New”.
2. Search for “EC RELATE BLOCKS”.
3. Install and activate the plugin.

OR…

1. Download the plugin .zip.
2. In WordPress admin, go to “Plugins” → “Add New”.
3. Click “Upload Plugin”, select the .zip file, then “Install Now”.
4. Activate the plugin.

== Quick Start (Recommended Workflow) ==
1) Configure connection settings
* Open the Site Editor.
* Add `itmar/product-block` to a global template (e.g., Header, or a dedicated template used site-wide).
* In the block Inspector → “EC setting”, enter:
  - Shopify domain (Store Site URL)
  - Channel Name (e.g., “Online Store”)
  - Headless Client ID / Shop ID (if using customer login)
  - Admin API Token and Storefront API Token (saved to WordPress options)

2) Choose the linked post type
* In `itmar/product-block`, select your product post type (e.g., “product”).

3) Add the cart
* Add `itmar/cart-block` to a template/page where you want the cart UI to appear.

4) Publish product posts
* Create or edit posts in the selected post type.
* Publish/update them to trigger Shopify-side creation/update (based on your configuration).

== Frequently Asked Questions ==
= Does this plugin process credit card data on my WordPress site? =
No. Checkout is handled by Shopify Checkout. This plugin does not process or store card data.

= Where are API tokens stored? Are they exposed to visitors? =
Tokens are stored server-side in WordPress options. They are not embedded into post content and are not printed into front-end HTML.

= Do I need Shopify Webhooks? =
Not in the current version.
This plugin is designed so that Shopify remains the source of truth for inventory/checkout, and the site can fetch the latest values from Shopify when needed.
Webhooks may be added later as an optional feature (e.g., cache invalidation or advanced sync).

= Can Shopify changes (inventory/price) instantly update WordPress pages? =
By default, Shopify remains the source of truth. If you need near real-time reflection on WordPress pages, you typically use short-lived caching or add Webhooks (advanced setup).

= What do I need from Shopify to enable customer login (Headless)? =
You need the Customer Account API (new Customer Accounts) enabled and a Headless Client ID. The login flow uses OAuth 2.0 Authorization Code with PKCE.

== Screenshots ==
1. Product block settings (EC setting)
2. Product listing / product card output (example)
3. Cart block UI
4. Checkout button redirecting to Shopify Checkout
5. (Optional) Customer login flow (Headless)

== Changelog ==
= 0.1.0 =
* Initial release.
* Product block (post type linking + Shopify integration).
* Cart block (Storefront Cart + Checkout redirect).
* Customer Accounts (Headless) integration support.

== Upgrade Notice ==
= 0.1.0 =
Initial release.

== Related Links ==
* ec-relate-blocks: GitHub
  https://github.com/itmaroon/ec-relate-bloks
* block-class-package: GitHub
  https://github.com/itmaroon/block-class-package
* block-class-package: Packagist
  https://packagist.org/packages/itmar/block-class-package
* itmar-block-packages: npm
  https://www.npmjs.com/package/itmar-block-packages
* itmar-block-packages: GitHub
  https://github.com/itmaroon/itmar-block-packages

== Developer Notes ==
1. PHP class management is done using Composer.
2. Shared functions/components are published as an npm package and reused across plugins.

== External Services ==
This plugin connects to external services operated by Shopify to provide e-commerce functionality.

Service provider:
* Shopify

APIs used (depending on your configuration/features enabled):
* Shopify Admin API (product/customer management)
* Shopify Storefront API (product/cart/checkout flow)
* Shopify Customer Account API / OAuth endpoints (optional customer login)

Data that may be sent to Shopify:
* Store domain, channel name, and API credentials (server-side)
* Product data required for creating/updating products (title, description, image, sales price, list price, quantity in stock)
- The title is the title of the post data sent.
- The description is an excerpt of the post data sent.
- The image is the featured image, if one is set. Furthermore, if the ACF custom field has a gallery field named gallery, images in that field will also be added.
- The sales price is the price set in the prces group set in the ACF custom field if sales_price is set as a member.
- The list price is the price set in the above group if list_price is set.
- The stock quantity is set as the default quantity if quantity is set in the ACF custom field. However, this does not reflect your sales history in Shopify, so do not use it outside of the initial setup.
* Cart line items (variant IDs and quantities)
* Customer authentication and identity linkage (OAuth code exchange, customer access tokens when enabled)

When data is sent:
* When saving settings (admin only)
* When rendering product/cart features or performing cart actions
* When creating/updating Shopify products based on WordPress post changes
* When performing customer authentication (if enabled)

Important:
* Shopify is responsible for payment processing and checkout.
* Please review Shopify’s terms and privacy policies:
  https://www.shopify.com/legal/terms
  https://www.shopify.com/legal/privacy
