<?php
/**
 * Plugin Name: WC ↔ FacturaScripts Sync
 * Plugin URI: https://github.com/Wizarck/wc-facturascripts-sync
 * Description: Bidirectional WooCommerce ↔ FacturaScripts sync. HPOS-compatible, VeriFactu-ready, idempotent via correlation_id. Company-agnostic.
 * Version: 0.1.0-dev
 * Author: Wizarck
 * License: LGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/lgpl-3.0.html
 * Text Domain: wc-facturascripts-sync
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 * WC tested up to: 9.9
 *
 * @package WcFacturascriptsSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_FS_SYNC_VERSION', '0.1.0-dev' );
define( 'WC_FS_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_FS_SYNC_URL', plugin_dir_url( __FILE__ ) );

// HPOS declaration.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Version guards — refuse to activate if dependencies out of range.
register_activation_hook(
	__FILE__,
	function () {
		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$wp_ok  = version_compare( get_bloginfo( 'version' ), '6.6', '>=' );
		$wc_ok  = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.0', '>=' );

		if ( ! $php_ok || ! $wp_ok || ! $wc_ok ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__(
					'wc-facturascripts-sync requires PHP 8.1+, WordPress 6.6+, WooCommerce 9.0+',
					'wc-facturascripts-sync'
				)
			);
		}
	}
);

// Composer autoload (PSR-4: WcFacturascriptsSync\ → src/).
if ( file_exists( WC_FS_SYNC_PATH . 'vendor/autoload.php' ) ) {
	require_once WC_FS_SYNC_PATH . 'vendor/autoload.php';
}

// Bootstrap plugin on plugins_loaded priority 20 (after WooCommerce).
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Plugin bootstrap entry — see src/Core/Plugin.php (to be created in Fase 1).
		if ( class_exists( '\WcFacturascriptsSync\Core\Plugin' ) ) {
			\WcFacturascriptsSync\Core\Plugin::instance()->boot();
		}
	},
	20
);
