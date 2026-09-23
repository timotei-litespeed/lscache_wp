<?php
/**
 * The Third Party integration with the Elementor plugin.
 *
 * Detects Elementor editor/preview actions and safely disables LiteSpeed Cache features
 * that could interfere with live editing. Also purges the cache when Elementor changes
 * content, templates or its generated CSS files.
 *
 * Granular purge model (since 7.9.2):
 *
 * Frontend tagging - every cacheable page gets tagged with what it depends on:
 *   Po.{id}          each Elementor document rendered in the page (header, footer, single/archive template,
 *                    Template widget, [elementor-template], loop item) - not only the queried post.
 *   PT.{post_type}   each post type listed by an Elementor widget (Posts, Loop Grid/Carousel...).
 *   ELEM             page rendered Elementor content or enqueued Elementor CSS (Kit / global styles).
 *   ELEM_FILE        page links a file from uploads/elementor/css/ (deleted by Elementor on clear cache).
 *
 * Purge triggers:
 *   Post/CPT publish, update, unpublish, trash, delete   core purge_post() -> Po.{id} (+ PT.{post_type})
 *   Template save / trash / delete                        core purge_post() -> Po.{template_id}
 *   Theme Builder template published or conditions edit   purge all (target pages can't be known in advance)
 *   elementor/core/files/clear_cache, editorial           ELEM + ELEM_FILE (Kit save, Regenerate CSS, settings, experiments...)
 *   elementor/core/files/clear_cache, plugin/core event   ELEM_FILE only (full purge stays under "Purge All On Upgrade")
 *
 * @since      2.9.8.8
 * @package    LiteSpeed
 * @subpackage LiteSpeed_Cache/thirdparty
 */

namespace LiteSpeed\Thirdparty;

defined('WPINC') || exit();

use LiteSpeed\Debug2;
use LiteSpeed\Tag;

/**
 * Handles Elementor compatibility.
 */
class Elementor {

	/**
	 * Tag for pages that render Elementor content or Elementor CSS.
	 *
	 * @since 7.9.2
	 */
	const TAG_PAGE = 'ELEM';

	/**
	 * Tag for pages linking a file from uploads/elementor/css/.
	 *
	 * @since 7.9.2
	 */
	const TAG_FILE = 'ELEM_FILE';

	/**
	 * Hooks that mean an Elementor cache clear came from a plugin/theme/core lifecycle event, not an edit.
	 * Filterable through `litespeed_3rd_elementor_lifecycle_hooks`.
	 *
	 * @since 7.9.2
	 */
	const LIFECYCLE_HOOKS = [
		'activated_plugin',
		'deactivated_plugin',
		'switch_theme',
		'upgrader_process_complete',
		'automatic_updates_complete',
		'admin_action_do-plugin-upgrade',
		'_core_updated_successfully',
		'update_option_elementor_element_cache_ttl',
	];

	/**
	 * Nesting depth of Elementor widgets currently rendering.
	 *
	 * @since 7.9.2
	 * @var int
	 */
	private static $_widget_depth = 0;

	/**
	 * Tags already added in this request.
	 *
	 * @since 7.9.2
	 * @var array
	 */
	private static $_tagged = [];

	/**
	 * Path of uploads/elementor/css/, cached per request.
	 *
	 * @since 7.9.2
	 * @var string|null
	 */
	private static $_css_path = null;

	/**
	 * Preload hooks and disable caching features during Elementor edit/preview flows.
	 *
	 * This method only inspects query/server values to detect editor context.
	 * No privileged actions are performed here, so nonce verification is not required.
	 *
	 * @since 2.9.8.8
	 * @since 7.9.2 Hooks are registered before any early return; unpublish from editor keeps purge active.
	 * @return void
	 */
	public static function preload() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		// Registered first: publish/Kit saves from the editor return early below and must still purge.
		self::_register_purge_hooks();

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

				$status = ! empty( $json['save_builder']['data']['status'] ) ? $json['save_builder']['data']['status'] : '';

				if ( ! empty( $json['save_builder']['action'] ) && 'save_builder' === $json['save_builder']['action'] ) {
					// Publishing from editor — allow normal flow so crawler/purge can run.
					if ( 'publish' === $status ) {
						return;
					}

					// Unpublishing from editor (post is live now, save sends another status) — keep purge active,
					// otherwise Purge never inits and the public page stays cached.
					$editor_post_id = isset( $_REQUEST['editor_post_id'] ) ? absint( $_REQUEST['editor_post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( $editor_post_id && '' !== $status && 'autosave' !== $status && 'publish' === get_post_status( $editor_post_id ) ) {
						return;
					}
				}
			}

			// In all other editor-referrer cases, disable LSCWP features during edit.
			do_action( 'litespeed_disable_all', 'elementor edit mode in HTTP_REFERER' );
		}
	}

	/**
	 * Register tagging and purge hooks.
	 *
	 * @since 7.9.2
	 * @return void
	 */
	private static function _register_purge_hooks() {
		// Elementor regenerated CSS & Data.
		add_action( 'elementor/core/files/clear_cache', __CLASS__ . '::regenerate_litespeed_cache' );

		// Frontend tagging.
		add_action( 'elementor/frontend/get_builder_content', __CLASS__ . '::tag_rendered_document' );
		add_action( 'elementor/css-file/before_enqueue', __CLASS__ . '::tag_elementor_page' );
		add_filter( 'style_loader_tag', __CLASS__ . '::tag_elementor_file', 10, 3 );

		// Posts/CPT listed by Elementor widgets.
		if ( apply_filters( 'litespeed_3rd_elementor_tag_loops', true ) ) {
			add_action( 'elementor/frontend/widget/before_render', __CLASS__ . '::widget_render_start' );
			add_action( 'elementor/frontend/widget/after_render', __CLASS__ . '::widget_render_end' );
			add_action( 'the_post', __CLASS__ . '::tag_loop_post', 10, 2 );
			add_action( 'litespeed_api_purge_post', __CLASS__ . '::purge_loop_posttype' );
		}

		// Theme Builder: display conditions decide which pages a template lands on.
		add_action( 'transition_post_status', __CLASS__ . '::template_status_changed', 10, 3 );
		add_action( 'added_post_meta', __CLASS__ . '::template_conditions_changed', 10, 3 );
		add_action( 'updated_post_meta', __CLASS__ . '::template_conditions_changed', 10, 3 );
		add_action( 'deleted_post_meta', __CLASS__ . '::template_conditions_changed', 10, 3 );
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
	 * Elementor deletes all files in uploads/elementor/css/ and the CSS meta of every post. Cached pages
	 * linking those files would load missing CSS, so ELEM_FILE is always purged. Editorial clears (Kit save,
	 * Regenerate CSS & Data, CSS settings, experiments, Replace URL, DB upgrade...) change the output of every
	 * Elementor page, so ELEM is purged too. Plugin/theme/core lifecycle clears don't change page output;
	 * a full purge on those stays governed by core `Purge All On Upgrade` / purge-all hooks.
	 *
	 * Filter `litespeed_3rd_elementor_clear_cache_purge` gets ( array $tags, string $lifecycle_hook ) and may
	 * return [] to skip or [ '*' ] for the old purge-all behaviour.
	 *
	 * @since 2.9.8.8
	 * @since 7.9.2 Purge tags instead of purge all.
	 * @return void
	 */
	public static function regenerate_litespeed_cache() {
		$lifecycle_hook = self::_lifecycle_hook();

		$tags = '' === $lifecycle_hook ? [ self::TAG_PAGE, self::TAG_FILE ] : [ self::TAG_FILE ];
		$tags = (array) apply_filters( 'litespeed_3rd_elementor_clear_cache_purge', $tags, $lifecycle_hook );
		$from = '' !== $lifecycle_hook ? $lifecycle_hook : 'editorial';

		if ( in_array( '*', $tags, true ) ) {
			do_action( 'litespeed_purge_all', 'Elementor - Regenerate CSS & Data' );
			return;
		}

		if ( ! $tags ) {
			Debug2::debug( '[3rd] Elementor regenerate CSS & Data: purge skipped [trigger] ' . $from );
			return;
		}

		Debug2::debug( '[3rd] Elementor regenerate CSS & Data: purge ' . implode( ',', $tags ) . ' [trigger] ' . $from );
		do_action( 'litespeed_purge', $tags );
	}

	/**
	 * Get the lifecycle hook currently running in the action stack, if any.
	 *
	 * @since 7.9.2
	 * @return string Running lifecycle hook, or empty string.
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

	/**
	 * Add a tag once per request, frontend only.
	 *
	 * @since 7.9.2
	 * @param string $tag Tag.
	 * @return void
	 */
	private static function _tag( $tag ) {
		if ( isset( self::$_tagged[ $tag ] ) || is_admin() ) {
			return;
		}

		if ( isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return;
		}

		self::$_tagged[ $tag ] = true;
		do_action( 'litespeed_tag_add', $tag );
	}

	/**
	 * Tag the page with each Elementor document it renders.
	 *
	 * Saving/trashing/deleting an embedded template runs core purge_post() on its ID, which purges Po.{id}
	 * and so every page that renders it.
	 *
	 * @since 7.9.2
	 * @param \Elementor\Core\Base\Document $document Rendered document.
	 * @return void
	 */
	public static function tag_rendered_document( $document ) {
		self::_tag( self::TAG_PAGE );

		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}

		$id = (int) $document->get_main_id();
		if ( $id && get_queried_object_id() !== $id ) {
			self::_tag( Tag::TYPE_POST . $id );
		}
	}

	/**
	 * Page enqueued an Elementor CSS file (Kit/global or post), inline or external.
	 *
	 * @since 7.9.2
	 * @return void
	 */
	public static function tag_elementor_page() {
		self::_tag( self::TAG_PAGE );
	}

	/**
	 * Page links a stylesheet from uploads/elementor/css/, which Elementor deletes on clear cache.
	 *
	 * @since 7.9.2
	 * @param string $html   Link tag.
	 * @param string $handle Handle.
	 * @param string $href   URL.
	 * @return string Unchanged link tag.
	 */
	public static function tag_elementor_file( $html, $handle, $href ) {
		if ( null === self::$_css_path ) {
			self::$_css_path = '';
			if ( class_exists( '\Elementor\Core\Files\Base' ) ) {
				self::$_css_path = (string) wp_parse_url( \Elementor\Core\Files\Base::get_base_uploads_url() . 'css/', PHP_URL_PATH );
			}
		}

		if ( '' !== self::$_css_path && $href && 0 === strpos( (string) wp_parse_url( $href, PHP_URL_PATH ), self::$_css_path ) ) {
			self::_tag( self::TAG_FILE );
		}

		return $html;
	}

	/**
	 * Widget render started.
	 *
	 * @since 7.9.2
	 * @return void
	 */
	public static function widget_render_start() {
		++self::$_widget_depth;
	}

	/**
	 * Widget render ended.
	 *
	 * @since 7.9.2
	 * @return void
	 */
	public static function widget_render_end() {
		self::$_widget_depth = max( 0, self::$_widget_depth - 1 );
	}

	/**
	 * Tag the page with the post type of each post an Elementor widget lists through a secondary query.
	 *
	 * Reuses the post type archive tag PT.{post_type}, so `litespeed_purge_posttype` clears the archive and
	 * every page listing that type together, for CPTs without an archive too.
	 *
	 * @since 7.9.2
	 * @param \WP_Post  $post  Current loop post.
	 * @param \WP_Query $query Query running the loop.
	 * @return void
	 */
	public static function tag_loop_post( $post, $query = null ) {
		if ( self::$_widget_depth < 1 || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( $query instanceof \WP_Query && $query->is_main_query() ) {
			return;
		}

		self::_tag( Tag::TYPE_ARCHIVE_POSTTYPE . $post->post_type );
	}

	/**
	 * When a post/CPT is purged (publish, update, unpublish, trash, delete), purge the pages listing its type.
	 *
	 * @since 7.9.2
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function purge_loop_posttype( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || 'elementor_library' === $post_type || ! is_post_type_viewable( $post_type ) ) {
			return;
		}

		do_action( 'litespeed_purge_posttype', $post_type );
	}

	/**
	 * A Theme Builder template with display conditions became published (new, restored, draft -> publish).
	 *
	 * No cached page carries its tag yet, so only a purge all reaches the pages it now applies to.
	 * Edits (publish -> publish) and removal (publish -> trash/draft) are covered by Po.{id}.
	 *
	 * @since 7.9.2
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function template_status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'elementor_library' !== $post->post_type ) {
			return;
		}

		if ( get_post_meta( $post->ID, '_elementor_conditions', true ) ) {
			do_action( 'litespeed_purge_all', 'Elementor - Theme template published' );
		}
	}

	/**
	 * Display conditions of a published Theme Builder template changed.
	 *
	 * @since 7.9.2
	 * @param int|array $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public static function template_conditions_changed( $meta_id, $object_id, $meta_key ) {
		if ( '_elementor_conditions' !== $meta_key || 'publish' !== get_post_status( $object_id ) ) {
			return;
		}

		do_action( 'litespeed_purge_all', 'Elementor - Theme template conditions changed' );
	}
}
