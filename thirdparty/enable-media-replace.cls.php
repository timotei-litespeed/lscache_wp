<?php
/**
 * The Third Party integration with the Enable Media Replace plugin.
 *
 * @since 7.9
 * @package LiteSpeed
 * @subpackage LiteSpeed_Cache\Thirdparty
 */

namespace LiteSpeed\Thirdparty;

defined( 'WPINC' ) || exit();

/**
 * Provides compatibility for the Enable Media Replace plugin.
 *
 * When an attachment's file is replaced in-place (same post ID), the old
 * optimization data must be torn down and the new file re-queued for
 * optimization. EMR's standard replace flow triggers WP's metadata
 * regeneration, which LiteSpeed already handles — but EMR can also replace
 * the file without regenerating metadata, leaving stale optimization
 * records that claim the old file is still optimized.
 *
 * This ties into EMR's "upload done" action (fired in all replace modes,
 * after EMR finished its work) and re-queues the attachment via the public
 * `litespeed_img_optm_requeue` action.
 */
class Enable_Media_Replace {

	/**
	 * Preload hooks for Enable Media Replace integration.
	 *
	 * @since 7.9
	 * @access public
	 * @return void
	 */
	public static function preload() {
		if ( ! defined( 'EMR_VERSION' ) ) {
			return;
		}

		// Fires last in EMR's replace flow, after files/metadata are settled.
		add_action( 'enable-media-replace-upload-done', __CLASS__ . '::requeue_optimization', 20, 3 );
	}

	/**
	 * Re-queue the replaced attachment for image optimization.
	 *
	 * @since 7.9
	 * @access public
	 * @param string $target_url The new file URL.
	 * @param string $source_url The old file URL.
	 * @param int    $post_id    The attachment post ID (not passed by older EMR versions).
	 * @return void
	 */
	public static function requeue_optimization( $target_url, $source_url, $post_id = 0 ) {
		$post_id = (int) $post_id;
		if ( ! $post_id && $target_url ) {
			// Older EMR versions fire the action without the attachment ID.
			$post_id = attachment_url_to_postid( $target_url );
		}
		if ( ! $post_id ) {
			return;
		}

		do_action( 'litespeed_debug', '[3rd] EMR replaced attachment file, requeue image optimization [pid] ' . $post_id );

		// No clean: leave existing optimized files/backup in place; re-optimization overwrites them on pull.
		do_action( 'litespeed_img_optm_requeue', $post_id );
	}
}
