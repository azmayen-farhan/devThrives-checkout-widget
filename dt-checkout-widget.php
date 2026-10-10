<?php
/**
 * Plugin Name: DevThrives Checkout Widget
 * Description: One-page WooCommerce checkout widget and an advanced thank-you page order summary widget for Elementor. Pick products manually or by query, show the shipping and payment methods enabled in WooCommerce, and edit every text from the Elementor panel.
 * Version: 1.1.0
 * Author: DevThrives
 * Author URI: https://devthrives.com
 * Text Domain: dt-checkout
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DTC_VERSION', '1.1.0' );
define( 'DTC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DTC_URL', plugin_dir_url( __FILE__ ) );

// Tell WooCommerce this plugin works with High-Performance Order Storage.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', 'dtc_boot', 20 );

function dtc_boot() {
	if ( ! class_exists( 'WooCommerce' ) || ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>DevThrives Checkout Widget</strong> needs WooCommerce and Elementor to be active.</p></div>';
		} );
		return;
	}

	require_once DTC_PATH . 'includes/class-dtc-core.php';
	require_once DTC_PATH . 'includes/class-dtc-summary.php';
	DTC_Core::init();

	add_action( 'elementor/elements/categories_registered', function ( $manager ) {
		$manager->add_category( 'devthrives', [
			'title' => 'DevThrives',
			'icon'  => 'eicon-woocommerce',
		] );
	} );

	add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
		require_once DTC_PATH . 'includes/class-dtc-widget.php';
		$widgets_manager->register( new DTC_Widget() );

		require_once DTC_PATH . 'includes/trait-dtc-controls.php';
		require_once DTC_PATH . 'includes/class-dtc-summary-widget.php';
		$widgets_manager->register( new DTC_Summary_Widget() );
	} );
}
