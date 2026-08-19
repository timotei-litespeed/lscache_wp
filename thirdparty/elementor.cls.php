<?php
/**
 * The Third Party integration with the Elementor plugin.
 *
 * Detects Elementor editor/preview actions and safely disables LiteSpeed Cache features
 * that could interfere with live editing. Also hooks cache purge when Elementor regenerates
 * its CSS & data.
 *
 * @since      2.9.8.8
 * @package    LiteSpeed
 * @subpackage LiteSpeed_Cache/thirdparty
 */

namespace LiteSpeed\Thirdparty;

defined('WPINC') || exit();

use LiteSpeed\Debug2;

/**
 * Handles Elementor compatibility.
 */
class Elementor {

	/**
	 * Hooks that mean an Elementor cache clear came from a plugin/core lifecycle event, not an edit.
	 *
	 * LiteSpeed already governs these through its own `Purge All On Upgrade` setting, so purging here
	 * would override that opt-out and flush the whole site on every plugin activate/deactivate/update.
	 *
	 * Filterable through `litespeed_3rd_elementor_lifecycle_hooks`.
	 *
	 * @since 7.9.1
	 * @var array
	 */
	const LIFECYCLE_HOOKS = [
		'activated_plugin',
		'deactivated_plugin',
		'upgrader_process_complete',
		'automatic_updates_complete',
		'admin_action_do-plugin-upgrade',
		'_core_updated_successfully',
	];

	/**
	 * Preload hooks and disable caching features during Elementor edit/preview flows.
	 *
	 * This method only inspects query/server values to detect editor context.
	 * No privileged actions are performed here, so nonce verification is not required.
	 *
	 * @since 2.9.8.8
	 * @return void
	 */
	public static function preload() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		// If user explicitly opened the Elementor editor, disable all LSCWP features.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'elementor' === $action ) {
			do_action( 'litespeed_disable_all', 'elementor edit mode' );
		}

		// If the referrer indicates an Elementor editor context, inspect possible save actions.
		$http_referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $http_referer && false !== strpos( $http_referer, 'action=elementor' ) ) {
			// Elementor posts JSON in the 'actions' request field when saving from editor.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$actions_raw = isset( $_REQUEST['actions'] ) ? wp_unslash( $_REQUEST['actions'] ) : '';
			if ( '' !== $actions_raw ) {
				// Use a forgiving sanitizer for JSON strings, then decode.
				$json = json_decode( sanitize_textarea_field( $actions_raw ), true );
				// Debug2::debug( '3rd Elementor', $json );

				if (
					! empty( $json['save_builder']['action'] ) &&
					'save_builder' === $json['save_builder']['action'] &&
					! empty( $json['save_builder']['data']['status'] ) &&
					'publish' === $json['save_builder']['data']['status']
				) {
					// Publishing from editor — allow normal flow so crawler/purge can run.
					return;
				}
			}

			// In all other editor-referrer cases, disable LSCWP features during edit.
			do_action( 'litespeed_disable_all', 'elementor edit mode in HTTP_REFERER' );
		}

		// Clear LSC cache when Elementor regenerates CSS & Data.
		add_action( 'elementor/core/files/clear_cache', __CLASS__ . '::regenerate_litespeed_cache' );
	}

	/**
	 * Disable LiteSpeed ESI explicitly (kept for backward compatibility if re-enabled).
	 *
	 * @since 2.9.8.8
	 * @return void
	 */
	public static function disable_litespeed_esi() {
		if ( ! defined( 'LITESPEED_ESI_OFF' ) ) {
			define( 'LITESPEED_ESI_OFF', true );
		}
	}

	/**
	 * Purge LiteSpeed Cache when Elementor regenerates its CSS & Data.
	 *
	 * Elementor fires `elementor/core/files/clear_cache` both for editorial changes (kit save, settings
	 * update, manual regeneration) and for plugin/core lifecycle events. Only the former warrants a
	 * purge; the latter is left to LiteSpeed's own `Purge All On Upgrade` setting.
	 *
	 * @since 2.9.8.8
	 * @since 7.9.1 Skip regenerations triggered by a plugin/core lifecycle event.
	 * @return void
	 */
	public static function regenerate_litespeed_cache() {
		$lifecycle_hook = self::_lifecycle_hook();
		$purge_all      = apply_filters( 'litespeed_3rd_elementor_purge_all', '' === $lifecycle_hook, $lifecycle_hook );

		if ( ! $purge_all ) {
			Debug2::debug( '[3rd] Elementor regenerate CSS & Data purge all skipped [trigger] ' . ( '' !== $lifecycle_hook ? $lifecycle_hook : 'filter' ) );
			return;
		}

		do_action( 'litespeed_purge_all', 'Elementor - Regenerate CSS & Data' );
	}

	/**
	 * Get the lifecycle hook currently running in the action stack, if any.
	 *
	 * @since 7.9.1
	 * @return string The running lifecycle hook, or an empty string when none is running.
	 */
	private static function _lifecycle_hook() {
		$hooks = apply_filters( 'litespeed_3rd_elementor_lifecycle_hooks', self::LIFECYCLE_HOOKS );

		foreach ( (array) $hooks as $hook ) {
			if ( $hook && doing_action( $hook ) ) {
				return $hook;
			}
		}

		return '';
	}
}
