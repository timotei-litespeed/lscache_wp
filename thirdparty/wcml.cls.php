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
	 * Holds the current WCML currency.
	 *
	 * @var string
	 */
	private static $_currency = '';

	/**
	 * Detect if WCML is active and register hooks.
	 *
	 * @since 3.0
	 * @access public
	 * @return void
	 */
	public static function detect() {
		if (!defined('WCML_VERSION')) {
			return;
		}

		// Force WCML to persist the client currency in a browser cookie (default is wc-session),                                                      
		// so the value is visible to the webserver for cache-key matching.                                                                     
		add_filter('wcml_user_store_strategy', __CLASS__ . '::force_cookie_store');                                                             

		// Register the WCML currency cookie as a LiteSpeed cache-vary cookie so the webserver                                                  
		// keys the cache by its value — this is what makes currency variants served distinctly.                                                
		add_filter('litespeed_vary_cookies', __CLASS__ . '::register_vary_cookies'); 

		add_filter('wcml_client_currency', __CLASS__ . '::apply_client_currency');
		add_action('wcml_set_client_currency', __CLASS__ . '::set_client_currency');
		add_action('litespeed_crawler_cookie_init', __CLASS__ . '::crawler_cookie_init');
	}

	/**
	* Force WCML's user-store strategy to cookie storage.                                                                                    
	*                                                                                                                                        
	* @since 7.9                                                                                                                             
	* @access public                                                                                                                         
	* @return string                                                                                                                         
	*/                                                                                                                                       
    public static function force_cookie_store() {                                                                                             
    	return 'cookie';                                                                                                                        
    }                                                                                                                                         
    
	/**                                                                                                                                       
    * Appends WCML's client currency cookie to the list of cache-vary cookies.                                                               
    *                                                                                                                                        
    * @since 7.9                                                                                                                             
    * @access public                                                                                                                         
    * @param string[] $cookie_list Existing vary cookies.                                                                                    
    * @return string[]                                                                                                                       
    */                                                                                                                                       
    public static function register_vary_cookies( $cookie_list ) {                                                                            
		$cookie_list[] = 'wcml_client_currency';                                                                                                
		return array_unique( $cookie_list );                                                                                                    
    }  

	/**
	 * Registers the WCML currency vary contribution during crawler cookie simulation.
	 *
	 * Called by the crawler when building the _lscache_vary cookie for a simulated
	 * cookie set, so each currency variant gets a distinct cache key.
	 *
	 * @since 7.9
	 * @access public
	 * @param array $cookies Simulated cookie name => value pairs for this crawler variant.
	 * @return void
	 */
	public static function crawler_cookie_init( $cookies ) {
		// error_log( "crawler_cookie_init:\n" . print_r( $cookies, true )."\n", 3, "/home/wpml.litespeedtech.ro/public_html/wp-content/cookies.log" );
		if ( ! empty( $cookies['wcml_client_currency'] ) ) {
			self::apply_client_currency( $cookies['wcml_client_currency'] );
			add_filter('litespeed_vary', __CLASS__ . '::apply_vary');
		}
	}

	/**
	 * Sets the client currency and triggers vary updates.
	 *
	 * @since 3.0
	 * @access public
	 * @param string $currency The currency code to set.
	 * @return void
	 */
	public static function set_client_currency( $currency ) {
		self::apply_client_currency($currency);
		do_action('litespeed_vary_ajax_force');
	}

	/**
	 * Applies the client currency and adjusts vary accordingly.
	 *
	 * @since 3.0
	 * @access public
	 * @param string $currency The currency code to apply.
	 * @return string The applied currency.
	 */
	public static function apply_client_currency( $currency ) {
		self::$_currency = $currency;
		add_filter('litespeed_vary', __CLASS__ . '::apply_vary');

		return $currency;
	}

	/**
	 * Appends WCML currency to vary list.
	 *
	 * @since 3.0
	 * @access public
	 * @param array $vary_list The existing vary list.
	 * @return array The updated vary list including WCML currency.
	 */
	public static function apply_vary( $vary_list ) {
		// error_log( "apply_vary IN:\n" . print_r( $vary_list, true )."\n", 3, "/home/wpml.litespeedtech.ro/public_html/wp-content/cookies.log" );
		// error_log( "_currency: " . print_r( self::$_currency, true )."\n", 3, "/home/wpml.litespeedtech.ro/public_html/wp-content/cookies.log" );
		$vary_list['wcml_currency'] = self::$_currency;
		// error_log( "apply_vary OUT:\n" . print_r( $vary_list, true )."\n", 3, "/home/wpml.litespeedtech.ro/public_html/wp-content/cookies.log" );

		return $vary_list;
	}
}
