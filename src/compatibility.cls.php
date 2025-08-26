<?php
/**
 * The compatibility class.
 * 
 * Add compatibity functions with other themes and plugins.
 *
 * @since   7.5.0
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined('WPINC') || exit();

/**
 * Class Compatibility
 *
 * Add compatibilties to other themes and plugins.
 */
class Compatibility extends Base {
	/**
	 * Log tag for Compatiblity.
	 *
	 * @var string
	 */
	const LOG_TAG = '🪛';

	/**
	 * Action key - apply changes.
	 *
	 * @var string
	 */
	const ACTION_APPLY_CHANGES = 'apply_changes';

	/**
	 * Compatibility key.
	 *
	 * @var string
	 */
	const ACTION_COMPATIBILITY_KEY = 'compatibility';
	
	/**
	 * Fucntion key.
	 *
	 * @var string
	 */
	const ACTION_FUNCTION_KEY = 'function';

	/**
	 * Compatibilites sources. Plugins or themes can add theirs.
	 *
	 * @var array
	 */
	private $_comp_source = [];
	/**
	 * Active Compatibilites.
	 *
	 * @var array
	 */
	private $_comp_active = [];
	/**
	 * Activated plugin slug.
	 *
	 * @var array
	 */
	private $_activated_plugin = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		/*
			Structure details:
				array key => use plugin slug
					title     => title that will appear in compatibility
					text      => description from admin area(not required)
					is_active => function to run if (not required, default true)
					functions => functions that contains changes for fixes
						function_key => unique identifier for function to run(appears in LSC Debug logs)
							function  => function that run
							text      => text button from admin area
							info      => more info about fix
							condition => function to run to decide if compatibility is showed or runned(not required, default true)

			Example of integration:
			[
				'elementor' => [
					'title'     => 'Elementor',
					'text'      => 'Details about changes',
					'is_active' => [ $this,  'isElementorActive' ],
					'functions' => [
						'css_print' => [
							'function' => [ $this,  'applyElementorCSS' ],
							'text'     => 'Fix CSS Print',
							'info'     => 'More info about CSS print fix',
						],
						'cache_ttl' => [
							'function'  => [ $this,  'applyElementorCache' ],
							'text'      => 'Fix Cache TTL',
							'condition' => [ $this,  'showElementorCache' ], 
						],
					],
				],
			]
		*/
		$this->_comp_source = apply_filters( 'litespeed_compatibilties', [] );
	}

	/**
	 * Init.
	 * 
	 * @return void
	 */
	public function init() {
		$this->getActiveCompatibilities();

		// Show compatibility notice
		add_action( 'activated_plugin', array( $this, 'plugin_activated' ), 10, 2 );
	}

	/**
	 * Init.
	 * 
	 * @param string $plugin Activated plugin name.
	 * @param bool $network_wide Activated network wide?
	 * @return void
	 */
	public function plugin_activated( $plugin, $network_wide ) {
		if( $this->hasCompatibilities($plugin) ){
			$plugin_name = 'Elementor';

			\LiteSpeed\Admin_Display::note(
				'<div id="lscwp-compatibility-notice">' .
					sprintf( __('We have found compatibilities fixes for plugin: %s.', 'litespeed-cache'), '<strong>' . $plugin_name . '</strong>' ) .
					' <a href="/wp-admin/admin.php?page=litespeed-toolbox#compatibility">' . __( 'Click here to see the fixes.', 'litespeed-cache') . '</a>' .
				'</div>'
			);
		}
	}

	/**
	 * Test if plugin has compatibilites.
	 *
	 * @param string $plugin Plugin name to test.
	 * @return bool
	 */
	public function hasCompatibilities( $plugin ) {
		foreach ( $this->_comp_source as $comp_key => $comp ) {
			if( $plugin === $comp_key ) return true;
		}

		return false;
	}

	/**
	 * Get compatibilites.
	 *
	 * @return void
	 */
	public function getActiveCompatibilities() {
		foreach ( $this->_comp_source as $comp_key => $comp ) {
			$is_active = true;
			if ( isset($comp['is_active']) && is_callable( $comp['is_active'] ) ) {
				$is_active = call_user_func( $comp['is_active'] );
			}

			if ( isset( $comp['title'] ) && $is_active ) {
				$this->_comp_active[] = $comp_key;
			}
		}
	}

	/**
	 * Get compatibilites list.
	 *
	 * @return array
	 */
	public function getList() {
		$data = [];
		foreach ( $this->_comp_source as $comp_key => $comp ) {
			if ( in_array ( $comp_key, $this->_comp_active, true ) ) {
				$data[ $comp_key ] = $comp;
			}
		}

		return $data;
	}

	/**
	 * Test if compatibility is active.
	 *
	 * @param string $compatibility Compatibility id.
	 * @return bool
	 */
	public function isActiveCompatibility( $compatibility ) {
		return in_array( $compatibility, $this->_comp_active, true );
	}

	/**
	 * Test if compatibility function can be showed.
	 *
	 * @param string $compatibility Compatibility id.
	 * @param string $func_key Compatibility function key.
	 * @return bool
	 */
	public function canShowFunction( $compatibility, $func_key ) {
		if ( $this->isActiveCompatibility( $compatibility ) ) {
			$condition_data = isset( $this->_comp_source[ $compatibility ] ) 
						&& isset( $this->_comp_source[ $compatibility ][ 'functions' ]) 
						&& isset( $this->_comp_source[ $compatibility ][ 'functions' ][ $func_key ] ) 
						&& isset( $this->_comp_source[ $compatibility ][ 'functions' ][ $func_key ][ 'condition' ] ) 
						? $this->_comp_source[ $compatibility ][ 'functions' ][ $func_key ][ 'condition' ] : false;

			if ( $condition_data && is_callable( $condition_data ) ) {
				return call_user_func( $condition_data );
			}
		}

		return true;
	}

	/**
	 * Get apply compatibility link.
	 *
	 * @param string $compatibility Compatibility id.
	 * @param string $func_key Compatibility function key.
	 * @return string
	 */
	public function getLink( $compatibility, $func_key ) {
		if ( $this->isActiveCompatibility( $compatibility ) && $func_key ) {
			return Utility::build_url(
				Router::ACTION_COMPATIBILITY,
				self::ACTION_APPLY_CHANGES,
				false,
				null,
				[
					'litespeed_i' => $compatibility . '|' . $func_key,
				],
			) ;
		}

		return false;
	}

	/**
	 * Apply compatibility function.
	 *
	 * @param string $compatibility Compatibility id.
	 * @param string $func_key      Compatibility function key.
	 * 
	 * @return bool
	 */
	public function applyCompatibility( $compatibility, $func_key ) {
		if ( $this->canShowFunction( $compatibility, $func_key ) ) {
			$function_data = isset( $this->_comp_source[ $compatibility ] ) 
				&& isset( $this->_comp_source[ $compatibility ][ 'functions' ]) 
				&& isset( $this->_comp_source[ $compatibility ][ 'functions' ][ $func_key ] ) 
				&& isset( $this->_comp_source[ $compatibility ][ 'functions' ][ $func_key ][ 'function' ] ) 
				? $this->_comp_source[ $compatibility ][ 'functions' ][ $func_key ][ 'function' ]
				: false;
			if ( !$function_data ) {
				return false; 
			}
			
			if ( is_callable( $function_data ) ) {
				return call_user_func( $function_data );
			} else {
				self::debug( 'Function for ' . $this->_comp_source[ $compatibility ][ 'title' ] . '->' . $func_key . ' is not callable.' );
			}
		}

		return false;
	}

	/**
	 * Handle all request actions from main cls
	 */
	public function handler() {
		$type = Router::verify_type();

		switch ($type) {
			case self::ACTION_APPLY_CHANGES:
				$this->getActiveCompatibilities();
				$msg   = __( 'There was an error applying compatibility.', 'litespeed-cache' );
				$color = Admin_Display::NOTICE_RED;

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$data = isset( $_REQUEST[ 'litespeed_i' ] ) 
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					? wp_specialchars_decode( sanitize_text_field( wp_unslash($_REQUEST[ 'litespeed_i' ]) ) )
					: false;
				
				if ( $data ) {
					$data          = explode('|', $data);
					$compatibility = $data[0] ?? false;
					$func_key      = $data[1] ?? false;
					if ( !$compatibility || !$func_key ) {
						$msg = __( 'Incorrect parameters sent.', 'litespeed-cache' );
						Admin_Display::add_notice( $color, $msg );
						Admin::redirect();
						return;
					}
				}

				if ( $this->isActiveCompatibility( $compatibility ) &&  $this->applyCompatibility( $compatibility, $func_key ) ) {
					$msg   = __( 'Compatibility was applied successfully.', 'litespeed-cache' );
					$color = Admin_Display::NOTICE_GREEN;
				}

				Admin_Display::add_notice( $color, $msg );
				Admin::redirect();
				return;

			default:
				break;
		}

		Admin::redirect();
	}
}
