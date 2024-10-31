<?php
/**
 * Plugin Name: Recebimento Fácil - Multicaixa payment by reference for WooCommerce
 * Plugin URI: https://www.bancoeconomico.ao/pt/empresas/servicos/recebimento-facil/
 * Description: This plugin allows customers with an Angolan bank account to pay WooCommerce orders using Multicaixa (Pag. por Referência) through the Banco Económico payment gateway.
 * Version: 1.4
 * Author: Banco Económico
 * Author URI: https://www.bancoeconomico.ao
 * Text Domain: recebimento-facil-multicaixa-for-woocommerce
 * Domain Path: /languages/
 * Requires at least: 5.4
 * Tested up to: 6.4
 * Requires PHP: 7.0
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 **/

namespace IDN\WPWC_RecebimentoFacil;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define( 'RECEBIMENTOFACIL_START_TIME', microtime( true ) );
define( 'RECEBIMENTOFACIL_REQUIRED_WC', '5.0' );

/**
 * Begins execution of the plugin.
 *
 * @since 1.0
 */
function init() {
	// i18n
	load_plugin_textdomain( 'recebimento-facil-multicaixa-for-woocommerce' );
	// Check for: WooCommerce
	if (
		class_exists( 'WooCommerce' ) && version_compare( WC_VERSION, RECEBIMENTOFACIL_REQUIRED_WC, '>=' )
	) {
		add_action( 'after_setup_theme', '\IDN\WPWC_RecebimentoFacil\init_plugin', 11 );
		/**
		 * Begins execution of the plugin.
		 *
		 * @since 1.0
		 */
		function init_plugin() {
			define( 'RECEBIMENTOFACIL_PLUGIN_FILE', __FILE__ );
			define( 'RECEBIMENTOFACIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
			require_once dirname( __FILE__ ) . '/includes/class-wpwc-recebimentofacil.php';
			require_once dirname( __FILE__ ) . '/includes/class-wpwc-recebimentofacil-woocommerce.php';
			$GLOBALS['WPWC_RecebimentoFacil'] = WPWC_RecebimentoFacil();
		}
		/* Main class */
		function WPWC_RecebimentoFacil() {
			return \WPWC_RecebimentoFacil::instance();
		}
	} else {
		add_action( 'admin_notices', '\IDN\WPWC_RecebimentoFacil\dependencies_notice' );
	}
}
add_action( 'after_setup_theme', '\IDN\WPWC_RecebimentoFacil\init' );

/**
 * Admin notice if dependencies are not met.
 *
 * @since 1.0
 */
function dependencies_notice() {
	$class   = 'notice notice-error';
	$message = sprintf(
		/* translators: %s: WooCommerce Version */
		__( '<strong>Recebimento Fácil - Multicaixa payment by reference for WooCommerce</strong> requires <strong>WooCommerce %s</strong> or above.', 'recebimento-facil-multicaixa-for-woocommerce' ),
		RECEBIMENTOFACIL_REQUIRED_WC
	);
	echo wp_kses_post(
		sprintf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			$message
		)
	);
}
