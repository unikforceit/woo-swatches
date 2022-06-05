<?php
// my branch
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
// haha

define( 'EMERCE_VARIATION_SWATCHES_FILE', __FILE__ );

add_action( 'woocommerce_loaded', 'emerce_variation_swatches' );

/**
 * Load and init plugin's instance
 */
function emerce_variation_swatches() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/plugin.php';

	return \Emerce\VariationSwatches\Plugin::instance();
}

// haahah

register_deactivation_hook( __FILE__, 'emerce_variation_swatches_deactivate' );

/**
 * Backup all custom attribute types then reset them to "select".
 */
function emerce_variation_swatches_deactivate( $network_deactivating ) {
	// Early return if WooCommerce is not activated.
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	global $wpdb;

	$blog_ids         = [1];
	$original_blog_id = 1;
	$network          = false;

	if ( is_multisite() && $network_deactivating ) {
		$blog_ids         = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
		$original_blog_id = get_current_blog_id();
		$network          = true;
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/backup.php';

	foreach ( $blog_ids as $blog_id ) {
		if ( $network ) {
			switch_to_blog( $blog_id );
		}

		\Emerce\VariationSwatches\Admin\Backup::backup();

		delete_option( 'emerce_variation_swatches_ignore_restore' );
	}

	if ( $network ) {
		switch_to_blog( $original_blog_id );
	}
}
