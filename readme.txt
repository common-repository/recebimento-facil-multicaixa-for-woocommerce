=== Recebimento Fácil - Multicaixa payment by reference for WooCommerce ===
Contributors: recebimentofacil, idnpro
Tags: woocommerce, payment, angola, multicaixa, kwanza
Tested up to: 6.5
Requires PHP: 7.0
Stable tag: 1.4
License: GPLv3 or later

This plugin allows customers with an Angolan bank account to pay WooCommerce orders using Multicaixa (Pag. por Referência) through the Banco Económico payment gateway.

== Description ==

“Pagamento por Referência” at Multicaixa, or home banking services, is one of the most popular ways to pay for services and (online) purchases in Angola.

Allows the generation of a payment Reference the customer can then use to pay for his WooCommerce order in Kwanzas, through an ATM, the Multicaixa Express app, at PSP (Payment Services Providers), or a home banking service.

This plugin works with the [Recebimento Fácil](https://www.bancoeconomico.ao/pt/empresas/servicos/recebimento-facil/) gateway, and a contract with [Banco Económico](https://www.bancoeconomico.ao/) (Banking Financial Institution that supplies “Recebimento Fácil”) is required. Technical support is provided by [IDN](https://idn.co.ao/).

= Features: =

* Generates a Multicaixa Reference for simple payment of WooCommerce orders;
* Configurable reference expiration date;
* Automatically changes the order status to “Processing” (or “Completed” if the order only contains virtual downloadable products) and notifies both the customer and the store owner, if the automatic “Callback” upon payment is activated;
* Shop owner can set minimum and maximum order totals for the payment gateway to be available;
* Allows searching orders (in the admin area) by Multicaixa reference;

= Requirements: =

* WordPress 5.4 or above
* WooCommerce 5.0 or above, with currency set to Angolan Kwanza
* PHP 7.0 or above
* SOAP support on PHP
* Valid SSL certificate on your website
* TCP port 8443 open on the firewall for outbound communication to `spf-webservices.bancoeconomico.ao` (and 7443 for `spf-webservices-uat.bancoeconomico.ao` if you need to use the test environment)

== Frequently Asked Questions ==

= Is this plugin compatible with the new WooCommerce High-Performance Order Storage? =

Not yet. We’re working on it.

= Is this plugin compatible with the new WooCommerce block-based Cart and Checkout? =

Not yet. We’re working on it.

= Can I contribute with a translation? =

Sure. Go to [GlotPress](https://translate.wordpress.org/projects/wp-plugins/recebimento-facil-multicaixa-for-woocommerce/) and help us out.

= I need help, can I get technical support? =

For technical support, open a ticket on the [support forum](https://wordpress.org/support/plugin/recebimento-facil-multicaixa-for-woocommerce/).

For commercial support, you should contact [Banco Económico](https://www.bancoeconomico.ao/).

== Changelog ==

= 1.4 - 2024-01-04 =
* Fix some callback response strings
* Change the “Order not found” error to 404 on the callback
* Fix WPML instructions on the settings page
* Remove non-necessary code regarding WPML compatibility on emails
* Tested with WordPress 6.5-alpha-57240 and WooCommerce 8.5.0-rc.1

= 1.3 - 2023-11-21 =
* Fix the order screen “Check payment status” button not working in production mode
* Fix the review link on the settings screen

= 1.2 - 2023-11-20 =
* Fix the version number and support link on the settings screen

= 1.1 - 2023-11-18 =
* Remove language packs from the plugin as they should exist only on Glotpress
* Minor plugin header and readme.txt changes
* Requires WordPress 5.4

= 1.0 - 2023-11-17 =
* Initial release
* Tested with WordPress 6.4.1 and WooCommerce 8.3.0-rc.2