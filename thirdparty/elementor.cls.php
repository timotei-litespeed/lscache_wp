<?php
// phpcs:ignoreFile
/**
 * The Third Party integration with the bbPress plugin.
 *
 * @since       2.9.8.8
 */
namespace LiteSpeed\Thirdparty;

defined('WPINC') || exit();

use LiteSpeed\Debug2;

class Elementor {

	public static function preload() {
		add_filter(
			'litespeed_compatibilties',
			[ __CLASS__ , 'addCompatibility' ]
		);

		if (!defined('ELEMENTOR_VERSION')) {
			return;
		}

		if (!is_admin()) {
			// add_action( 'init', __CLASS__ . '::disable_litespeed_esi', 4 ); // temporarily comment out this line for backward compatibility
		}

		if (isset($_GET['action']) && $_GET['action'] === 'elementor') {
			do_action('litespeed_disable_all', 'elementor edit mode');
		}

		if (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'action=elementor')) {
			if (!empty($_REQUEST['actions'])) {
				$json = json_decode(stripslashes($_REQUEST['actions']), true);
				// Debug2::debug( '3rd Elementor', $json );
				if (
					!empty($json['save_builder']['action']) &&
					$json['save_builder']['action'] == 'save_builder' &&
					!empty($json['save_builder']['data']['status']) &&
					$json['save_builder']['data']['status'] == 'publish'
				) {
					return; // Save post, don't disable all in case we will allow fire crawler right away after purged
				}
			}
			do_action('litespeed_disable_all', 'elementor edit mode in HTTP_REFERER');
		}

		// Clear LSC cache on Elementor Regenerate CSS & Data
		add_action('elementor/core/files/clear_cache', __CLASS__ . '::regenerate_litespeed_cache');
	}

	/**
	 * Add Elemnentor compatibility
	 *
	 * @param array $current Current values.
	 * @return array
	 */
	public static function addCompatibility( $current ) {
		$current[ 'elementor/elementor.php' ] = [
			'title'          => 'Elementor',
			'text'           => 'Add compatibility fixes.',
			'functions' => [
				'fix_css' => [
					'text'      => __( 'Fix CSS Print Method', 'litespeed-cache' ),
					'info'      => __( 'Set CSS print method to "Internal"', 'litespeed-cache' ),
					'condition' => [ __CLASS__ , 'showElementorCSS' ], 
					'function'  => [ __CLASS__ , 'applyElementorCSS' ],
				],
				'disable_element' => [
					'text'      => __( 'Fix Element Cache', 'litespeed-cache' ),
					'info'      => __( 'Disable Element Cache', 'litespeed-cache' ),
					'condition' => [ __CLASS__ , 'showElementorCache' ], 
					'function'  => [ __CLASS__ , 'applyElementorCache' ],
				],
			],
		];

		return $current;
	}

	public static function disable_litespeed_esi() {
		define('LITESPEED_ESI_OFF', true);
	}

	public static function regenerate_litespeed_cache() {
		do_action('litespeed_purge_all', 'Elementor - Regenerate CSS & Data');
	}

	/**
	 * Show Elementor compatibility: CSS print method.
	 *
	 * @access private
	 * 
	 * @return bool Return if changes need to be done or not.
	 */
	public static function showElementorCSS() {
		return 'internal' !== get_option('elementor_css_print_method');
	}
	/**
	 * Show Elementor compatibility: Element cache.
	 *
	 * @access private
	 * 
	 * @return bool Return if changes need to be done or not.
	 */
	public static function showElementorCache() {
		return 'disable' !== get_option('elementor_element_cache_ttl');
	}

	/**
	 * Apply Elementor compatibility: CSS print method.
	 *
	 * @access private
	 * 
	 * @return bool Return if changes were done or not.
	 */
	public static function applyElementorCSS() {
		// When Elementor settings are changed, a clear cache happen. Because of action we added to: elementor/core/files/clear_cache.
		update_option('elementor_css_print_method', 'internal' );

		return true;
	}

	/**
	 * Apply Elementor compatibility: element cache.
	 *
	 * @access private
	 * 
	 * @return bool Return if changes were done or not.
	 */
	public static function applyElementorCache() {
		// When Elementor settings are changed, a clear cache happen. Because of action we added to: elementor/core/files/clear_cache.
		update_option('elementor_element_cache_ttl', 'disable' );

		return true;
	}
}
