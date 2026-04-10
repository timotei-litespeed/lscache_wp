<?php
/**
 * The Third Party integration with WCML.
 *
 * @since 3.0
 * @package LiteSpeed
 * @subpackage LiteSpeed_Cache\Thirdparty
 */

namespace LiteSpeed\Thirdparty;

defined('WPINC') || exit();

/**
 * Provides compatibility with WCML for currency handling.
 */
class WCML {

	/**
	 * Detect if WCML is active and register hooks.
	 *
	 * @since 3.0
	 * @access public
	 * @return void
	 */
	public static function detect() {
		if ( ! defined( 'WCML_VERSION' ) ) {
			return;
		}

		// Register wcml_client_currency in the rewrite-rules vary (always needed).
		add_filter( 'litespeed_vary_cookies', __CLASS__ . '::register_cookies' );

		// Add wcml_client_currency to the current request's vary when multi-currency is active.
		add_filter( 'litespeed_vary_curr_cookies', __CLASS__ . '::check_cookies' );

		// Force vary finalization when currency switches via AJAX.
		add_action( 'wcml_set_client_currency', __CLASS__ . '::set_client_currency' );

		// Inject WCML currencies into the crawler cookie simulation list.
		add_filter( 'litespeed_crawler_cookie_factors', __CLASS__ . '::add_crawler_cookie_factors' );
	}

	/**
	 * Register wcml_client_currency in the global vary cookie registry.
	 *
	 * Ensures the cookie is always written to the rewrite-rules vary so
	 * LiteSpeed Server separates cache entries per currency.
	 *
	 * @since 3.0
	 * @access public
	 * @param string[] $cookies Current list of vary cookies.
	 * @return string[] Updated list including wcml_client_currency.
	 */
	public static function register_cookies( $cookies ) {
		$cookies[] = 'wcml_client_currency';
		return $cookies;
	}

	/**
	 * Add wcml_client_currency to the current request's vary cookies.
	 *
	 * Only applied when WCML multi-currency is active so single-currency
	 * sites do not generate unnecessary vary entries.
	 *
	 * @since 3.0
	 * @access public
	 * @param string[] $cookies Current list of vary cookies for this response.
	 * @return string[] Updated list.
	 */
	public static function check_cookies( $cookies ) {
		global $woocommerce_wpml;

		if ( empty( $woocommerce_wpml->multi_currency ) ) {
			return $cookies;
		}

		$cookies[] = 'wcml_client_currency';
		return $cookies;
	}

	/**
	 * Force vary finalization when currency switches via AJAX.
	 *
	 * WCML fires wcml_set_client_currency during AJAX currency-switch
	 * requests. Without this, LSC may skip vary header output for AJAX.
	 *
	 * @since 3.0
	 * @access public
	 * @return void
	 */
	public static function set_client_currency() {
		do_action( 'litespeed_vary_ajax_force' );
	}

	/**
	 * Inject wcml_client_currency cookie factors into the crawler list.
	 *
	 * Adds one crawler entry per active WCML currency so each
	 * currency-specific page variant gets its own cache entry.
	 *
	 * @since 3.0
	 * @access public 
	 * @param array $crawler_factors Existing crawler cookie factors.
	 * @return array Updated crawler factors including WCML currencies.
	 */
	public static function add_crawler_cookie_factors( $crawler_factors ) {
		global $woocommerce_wpml;

		if ( empty( $woocommerce_wpml->multi_currency ) || ! method_exists( $woocommerce_wpml->multi_currency, 'get_currencies' ) ) {
			return $crawler_factors;
		}

		$currencies = $woocommerce_wpml->multi_currency->get_currencies( true );
		if ( empty( $currencies ) || ! is_array( $currencies ) ) {
			return $crawler_factors;
		}

		$cookie_key                     = 'cookie:wcml_client_currency';
		$crawler_factors[ $cookie_key ] = [];
		foreach ( array_keys( $currencies ) as $currency_code ) {
			$crawler_factors[ $cookie_key ][ $currency_code ] = esc_html( $currency_code );
		}

		return $crawler_factors;
	}
}
