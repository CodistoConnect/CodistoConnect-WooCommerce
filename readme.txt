=== WooCommerce eBay Integration - MarketPlace Connect by Codisto ===
Contributors: codisto
Tags: ecommerce, e-commerce, woocommerce, ebay, paypal, integration, multi-channel, listings, store, sales, sell, shop
Requires at least: 4.0
Tested up to: 4.6.0
Stable tag: 1.2.44
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

MarketPlace Connect by Codisto - WooCommerce eBay Integration - Convert a WooCommerce store into a fully integrated eBay store in minutes

== Description ==

Convert your WooCommerce store into a fully integrated eBay store in minutes.  Codisto Marketplace Connect is the new standard for integrated eBay selling. We believe alternative solutions are too hard & time consuming to setup and use. So we applied 15 years' experience with ecommerce platforms, eBay integration, developer work flows and merchant selling processes to make eBay better for both merchants and developers.

Marketplace Connect incorporates maximum automation to get you selling quickly and clever product design to make managing product catalogs on eBay quick & easy. By auto-categorizing products into the most appropriate eBay category, including a professional mobile optimized sales template and applying eBay best practice selling defaults to your products as standard suggestions out the box, you're ready to list your entire catalog on eBay without any configuration. There is no more building from ground zero, product by product, going through the time consuming process of having to create profiles and templates first. If you’re not happy with a suggested setting, our XpressGrid interface makes changing the suggestions fast & simple. Or you can simply apply profiles for traditional listing management.

Powerful control via full real time inventory synchronization between your ecommerce platform and eBay, full template editing & logic, custom attribute mapping, advanced freight  functionality & multi-account support means Marketplace Connect is also designed for the largest online businesses looking for advanced options and deeper configuration.

= Key Features =

* Real time, two-way synchronization of product catalog & inventory between WooCommerce & eBay
* Orders from eBay sent to WooCommerce for efficient fulfilment
* Products auto-categorized into most appropriate eBay category
* Professional, full editable, mobile optimized template apply to every listing
* List on any eBay marketplace worldwide
* Supports multi-account/multi eBay marketplace
* XpressGrid multi edit for simple management
* Order control for sending to WooCommerce
* Automatic feedback& shipping tracking on eBay
* Domestic and international shipping options
* Platform calculated shipping rate support
* Supports multi-variant products
* CDN for image hosting
* Retain sales history of existing eBay listings by mapping to products in WooCommerce
* Lightweight plugin design – minimal files in WordPress

= More Information =

Visit <https://codisto.com/connect/> to read more about Marketplace Connect including documentation, installation instructions and pricing.

Pricing starts at $49 USD per month.

== Installation ==

1. Install MarketPlace Connect by Codisto either via the WordPress.org plugin repository, or by uploading the files to your server
2. Choose either “Connect your eBay account” or “Enter your email address” and follow the setup instructions.
3. Choose which items you wish to list from the Codisto > Listings screen and click publish

== Frequently Asked Questions ==

= Does MarketPlace Connect work with all eBay sites? =

Yes, it does.

= What are the requirements to run MarketPlace Connect? =

MarketPlace Connect requires a recent version of WordPress (4.2+) with WooCommerce (2.2+) installed. Your server should run on Linux and have PHP 5.3.

= Does MarketPlace Connect just list my products on eBay or provide full catalog synchronization? =

MarketPlace connect is more than just a listing tool.  It keeps your whole catalog – products, prices, images, descriptions, inventory levels and more in sync with eBay in real time.  Orders from eBay are automatically sent to WooCommerce and inventory reduced in real time so you never oversell on either channel.

= How easy is MarketPlace Connect to setup and use? =

MarketPlace Connect was designed for merchants to increase their sales by adding the eBay channel for virtually no effort and keep their current WooCommerce fulfilment processes.
This has been achieved by applying the maximum automation to setting up and managing eBay.  There are many areas of automation from categorizing products on eBay, applying a professional template, upscaling images, UPC/EAN error prevention, automatic tax rate detection for orders and many more.
If you’re happy with our standard template, eBay best practice selling values e.g. 30 days returns and free shipping, once MarketPlace Connect has installed, you can list your entire catalog in just 4 clicks.  There is no easier way to list on eBay anywhere, let alone have full integration.

= I already have listed my products on eBay. Can MarketPlace Connect import them to WooCommerce? =

No, MarketPlace Connect is designed to let WooCommerce be the “source of true” – you contine to manage your products in WordPress - and use MarketPlace connect to manage which products and how they are listed on eBay.
But it is possible to map existing eBay listings to products in WooCommerce once they are in WooCommerce with the ‘map products’ function.

= Does MarketPlace Connect support auction style listings? =

No, MarketPlace Connect is designed for merchants with catalogs who want to create fixed price, good til cancelled listings.

= Are there any more FAQ? =

Yes, there are! Please check out our growing knowledgebase at <http://help.codisto.com/>

= Is there a MarketPlace Connect for Amazon? =

No, MarketPlace Connect currently only works with eBay

== Screenshots ==

1. XpressGrid listing mananagement
2. Standard, mobile optimized template (fully editable)
3. Order Management

== Changelog ==

= 1.2.44 - 16/11/2016 =

* suppress warnings and user legacy tax function naming for support on older versions

= 1.2.43 - 16/11/2016 =

* clear output buffer before proxying or syncing, ensures clean output for all plugin endpoints

= 1.2.42 - 02/11/2016 =

* improved support for 'select' style globally defined attributes at product level
* started synchronising variation level attributes

= 1.2.41 - 26/09/2016 =

* add Account menu item

= 1.2.40 - 26/09/2016 =

* stop PHP warnings when accessing offsets that don't exist in an object during catalogue sync

= 1.2.39 - 15/09/2016 =

* read correct values from global attributes

= 1.2.38 - 6/09/2016 =

* wordpress 4.6 doesn't properly honour compress/decompress in new http api, disable compression in proxy

= 1.2.37 - 30/08/2016 =

* fix variation labels

= 1.2.35 - 24/08/2016 =

* change compression settings

= 1.2.34 - 04/08/2016 =

* fix variation stock control

= 1.2.33 - 11/07/2016 =

* change order sync to avoid sending emails as the customer is already in communication via eBay
* product options that aren't variations converted to multi-variant listings correctly

= 1.2.31 - 30/06/2016 =

* change unit price on order lines to ex tax to match standard woocommerce orders

= 1.2.30 - 09/06/2016 =

* work around wp super cache shutdown function that corrupts output

= 1.2.29 - 09/06/2016 =

* ensure exit() called on sync and proxy functions so that additional plugins do not foul the output

= 1.2.28 - 08/06/2016 =

* disable sending taxes if they are turned off in woocommerce settings
* check for header_remove existence before using it (introduced in php 5.2)

= 1.2.27 - 03/06/2016 =

* support multiple shipping options in the shipping callback
* properly add any applicable tax on shipping to the inc tax freight result

= 1.2.26 - 05/05/2016 =

* fixed an issue with rewrite generation in plugin activation when site and home urls had different protocols
* added dynamic shipping callback for realtime bulky item rates on ebay

= 1.2.25 - 04/05/2016 =

* updated readme to include base pricing based on customer feedback
* flush option cache on registration
* modified acf detection function

= 1.2.22 - 29/04/2016 =

* use Advanced Custom Fields - https://www.advancedcustomfields.com/ to map field values and galleries to your eBay listings

= 1.2.21 - 26/04/2016 =

* force decompression headers off if required to decompress in proxy

= 1.2.2 - 25/04/2016 =

* forced currency setting during sync to match home currency with aelia currency switcher installed
* fixed UI to avoid scroll in scroll when wordpress left menu grew too long

= 1.2.1 - 22/04/2016 =

* switched http headers over to be codisto specific to avoid collision with other extensions

= 1.2.0 - 21/04/2016 =

* handle missing _GET variables during registrations

= 1.1.99 - 21/04/2016 =

* tested against wordpress 4.5

= 1.1.98 - 04/04/2016 =

* fallback to get_product defined in earlier versions of WooCommerce if wc_get_product is not defined

= 1.1.97 - 04/04/2016 =

* handle older versions of wordpress by not assuming wp_json_encode

= 1.1.96 - 30/03/2016 =

* shift to full size image download - wp_get_attachment_image_src takes a default 2nd arg of 'thumbnail' which is now overriden to 'full'

= 1.1.95 - 30/03/2016 =

* had trouble updating wordpress svn so bumped version number

= 1.1.94 - 30/03/2016 =

* fix rewrite rules to honour the difference between home and site url

= 1.1.87 - 21/03/2016 =

* Initial public release
