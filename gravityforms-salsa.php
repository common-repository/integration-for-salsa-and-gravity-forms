<?php
/*
Plugin Name: Gravity Forms Salsa Add-On
Plugin URI: https://cornershopcreative.com/product/gravity-forms-add-ons/
Description: Integrates Gravity Forms with Salsa Labs CRM, allowing form submissions to automatically create/update Supporters
Version: 1.0.5
Author: Cornershop Creative
Author URI: https://cornershopcreative.com
Text Domain: gfsalsa
*/

define( 'GF_SALSA_VERSION', '1.0.5' );

add_action( 'gform_loaded', array( 'GF_Salsa_Bootstrap', 'load' ), 5 );

/**
 * Tells GravityForms to load up the Add-On
 */
class GF_Salsa_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gf-salsa.php' );

		GFAddOn::register( 'GFSalsa' );
	}
}

function gf_salsa() {
	return GFSalsa::get_instance();
}