<?php
/**
 * Plugin Name:       Imprenta Online — Addons
 * Plugin URI:        https://imprentaonline.ar
 * Description:       Opciones de acabado (anillado, encuadernado, laminado, plastificado…) con recargo de precio para WooCommerce. Liviano, extensible desde el admin y sin ninguna interacción con la galería de imágenes del producto.
 * Version:           1.0.0
 * Author:            Imprenta Online
 * Author URI:        https://imprentaonline.ar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       io-addons
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package IO_Addons
 */

defined( 'ABSPATH' ) || exit;

define( 'IO_ADDONS_VERSION', '1.0.0' );
define( 'IO_ADDONS_FILE', __FILE__ );
define( 'IO_ADDONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'IO_ADDONS_URL', plugin_dir_url( __FILE__ ) );

/** Meta key donde vive la configuración de addons (producto y plantilla). */
define( 'IO_ADDONS_META', '_io_addons_config' );

/** Meta key del opt-out de la plantilla genérica, a nivel de producto. */
define( 'IO_ADDONS_META_NO_GENERIC', '_io_addons_disable_generic' );

/** Post type de las plantillas reutilizables. */
define( 'IO_ADDONS_TEMPLATE_CPT', 'io_addon_template' );

/**
 * Declarar compatibilidad con HPOS (custom order tables).
 *
 * Toda la persistencia de pedido de este plugin usa la API CRUD de WooCommerce
 * ($item->add_meta_data(), $order->update_meta_data()), nunca $wpdb directo.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', IO_ADDONS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', IO_ADDONS_FILE, false );
		}
	}
);

/**
 * Arranque del plugin. Requiere WooCommerce activo.
 */
function io_addons_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'io_addons_missing_wc_notice' );
		return;
	}

	require_once IO_ADDONS_PATH . 'includes/io-addons-functions.php';
	require_once IO_ADDONS_PATH . 'includes/class-io-addons-pricing.php';
	require_once IO_ADDONS_PATH . 'includes/class-io-addons-templates.php';
	require_once IO_ADDONS_PATH . 'includes/class-io-addons-frontend.php';
	require_once IO_ADDONS_PATH . 'includes/class-io-addons-cart.php';

	if ( is_admin() ) {
		require_once IO_ADDONS_PATH . 'includes/class-io-addons-admin.php';
		new IO_Addons_Admin();
	}

	new IO_Addons_Templates();
	new IO_Addons_Frontend();
	new IO_Addons_Cart();
}
add_action( 'plugins_loaded', 'io_addons_bootstrap', 20 );

/**
 * Aviso si falta WooCommerce.
 */
function io_addons_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Imprenta Online — Addons necesita WooCommerce activo para funcionar.', 'io-addons' );
	echo '</p></div>';
}

/**
 * Traducciones.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'io-addons', false, dirname( plugin_basename( IO_ADDONS_FILE ) ) . '/languages' );
	}
);

/**
 * En la activación registramos el CPT y refrescamos rewrite rules.
 */
register_activation_hook(
	IO_ADDONS_FILE,
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			require_once IO_ADDONS_PATH . 'includes/io-addons-functions.php';
			require_once IO_ADDONS_PATH . 'includes/class-io-addons-templates.php';
			IO_Addons_Templates::register_post_type();
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	IO_ADDONS_FILE,
	function () {
		flush_rewrite_rules();
	}
);
