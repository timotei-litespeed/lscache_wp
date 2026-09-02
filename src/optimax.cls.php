<?php
/**
 * The Optimax class for full page optimization.
 *
 * Sends entire page (HTML/JS/CSS/Images) to cloud for optimization.
 *
 * @since   8.0
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * Optimax - Full Page Optimization class.
 *
 * @since 8.0
 */
class Optimax extends Cloud_Queue_Svc {

	const LOG_TAG = '🚀';

	/**
	 * Whether this run may only collect finished builds.
	 *
	 * Carried into the request payload so the service returns a cached result or
	 * try_later and never starts a build. Pull and push share one endpoint, so
	 * gating the cron alone cannot stop a collection run from creating work.
	 *
	 * @var bool
	 */
	private $_pull_only = false;

	/**
	 * Init.
	 *
	 * @since 8.0
	 */
	public function __construct() {
		$this->_summary = self::get_summary();
	}

	/**
	 * Whether a build is outstanding and worth collecting.
	 *
	 * The deadline is only stamped once the service has answered try_later, i.e.
	 * something was handed over and is being built. Mirrors Img_Optm::need_pull(),
	 * which registers its pull cron on pending work rather than on a setting.
	 *
	 * @since 8.0
	 *
	 * @return bool
	 */
	public static function need_pull() {
		$_instance = static::cls();

		if ( ! $_instance->load_queue( 'optimax' ) ) {
			return false;
		}

		return ! empty( self::get_summary( 'ox_next_run_after' ) );
	}

	/**
	 * Cron entry point for collecting finished builds.
	 *
	 * Registered whenever need_pull() reports outstanding work, independent of the
	 * cron switch, so results are never stranded on the service.
	 *
	 * @since 8.0
	 *
	 * @return mixed
	 */
	public static function cron_pull() {
		self::debug( 'OX CRON PULL started' );

		// Raise it on the singleton that cron() will reuse, so _build_payload() sees it.
		static::cls()->_pull_only = true;

		return static::cron();
	}

	/**
	 * Cron entry point for submitting queued URLs.
	 *
	 * Takes one item per tick, as VPI and UCSS/CCSS do. The try_later deadline
	 * re-arms the hook once a build finishes, so the queue still drains without a
	 * single run holding one request open for the whole list.
	 *
	 * @since 8.0
	 *
	 * @return mixed
	 */
	public static function cron_push() {
		self::debug( 'OX CRON PUSH started' );

		return static::cron();
	}

	/**
	 * Svc id slug — drives queue type, Cloud::SVC_OPTIMAX, and summary key prefix.
	 *
	 * @return string
	 */
	protected function _svc_id() {
		return 'optimax';
	}

	/**
	 * Response field carrying the optimization payload (nested object).
	 *
	 * @return string
	 */
	protected function _data_key() {
		return 'data_optimax';
	}

	/**
	 * Optimax processes whole pages — needs a longer PHP execution window.
	 *
	 * @return int
	 */
	protected function _php_time_limit() {
		return 1200;
	}

	/**
	 * Legacy summary key for the try_later deadline; kept across upgrades.
	 *
	 * @return string
	 */
	protected function _next_run_after_key() {
		return 'ox_next_run_after';
	}

	/**
	 * Reject malformed legacy queue rows before dispatch.
	 *
	 * @since 7.9.1
	 *
	 * @param string $queue_k Queue key.
	 * @param array  $v       Queue item.
	 * @return bool
	 */
	protected function _valid_queue_item( $queue_k, $v ) {
		foreach ( [ 'url', 'user_agent', 'url_tag', 'vary' ] as $key ) {
			if ( ! is_array( $v ) || ! isset( $v[ $key ] ) || ! is_string( $v[ $key ] ) ) {
				return false;
			}
		}

		return '' !== $queue_k && '' !== $v['url'] && '' !== $v['url_tag'] &&
			( empty( $v['is_nextgen'] ) || in_array( $v['is_nextgen'], [ 'webp', 'avif' ], true ) );
	}

	/**
	 * Build the request body for Cloud::post.
	 *
	 * @param string $queue_k Queue key.
	 * @param array  $v       Queue item.
	 * @return array
	 */
	protected function _build_payload( $queue_k, $v ) {
		$data = [
			'url'        => $v['url'],
			'queue_k'    => $queue_k,
			'user_agent' => $v['user_agent'],
			'is_mobile'  => ! empty( $v['is_mobile'] ) ? 1 : 0,
			'is_nextgen' => ! empty( $v['is_nextgen'] ) ? $v['is_nextgen'] : '',
		];

		// A collection run must not create work: the service answers with a cached
		// result or try_later, and never queues a new build.
		if ( $this->_pull_only ) {
			$data['pull_only'] = 1;
		}

		return $data;
	}

	/**
	 * Fan out the nested optimization payload to four save targets.
	 *
	 * @param array  $ox      data_optimax payload.
	 * @param string $queue_k Queue key.
	 * @param array  $v       Queue item.
	 * @return bool False when HTML is missing (abort), true otherwise.
	 */
	protected function _save_result( $ox, $queue_k, $v ) {
		if ( empty( $ox['html'] ) ) {
			self::debug( '❌ No HTML in data_optimax [k] ' . $queue_k );
			return false;
		}

		$is_mobile  = ! empty( $v['is_mobile'] );
		$is_nextgen = ! empty( $v['is_nextgen'] ) ? $v['is_nextgen'] : '';

		// The service reports how long the build actually took. Keep it under its own
		// key: _send_req() overwrites the shared last_spent one right after this with
		// the local round-trip, which reads as ~2s whenever a cached result comes back.
		if ( ! empty( $ox['took_ms'] ) ) {
			$this->_summary['last_took_ms_optimax'] = (int) $ox['took_ms'];
			self::debug( 'took_ms ' . (int) $ox['took_ms'] . ' [k] ' . $queue_k );
		}

		// 1. Pull the optimized JS bundle first. The delivered HTML references it by
		// its remote worker URL, and that artifact is swept a couple of days later, so
		// the src must be repointed at the local copy before the HTML is stored.
		if ( ! empty( $ox['js_url'] ) ) {
			$local_js_url = $this->_save_js( $ox['js_url'], $queue_k, $v, $is_mobile, $is_nextgen );
			if ( $local_js_url ) {
				$ox['html'] = str_replace( $ox['js_url'], $local_js_url, $ox['html'] );
			}
		}

		// 2. Save HTML.
		$this->_save_con( $ox['html'], $queue_k, $is_mobile, $is_nextgen, $v );

		// 3. Save UCSS.
		if ( ! empty( $ox['ucss'] ) ) {
			$this->_save_css_con( 'ucss', $ox['ucss'], $v['url_tag'], $v['vary'], $queue_k, $is_mobile, $is_nextgen );
		}

		// 4. Save CCSS.
		if ( ! empty( $ox['ccss'] ) ) {
			$this->_save_css_con( 'ccss', $ox['ccss'], $v['url_tag'], $v['vary'], $queue_k, $is_mobile, $is_nextgen );
		}

		// 5. Save optimized images.
		if ( ! empty( $ox['imgs'] ) ) {
			$this->_save_imgs( $ox['imgs'] );
		}

		// 6. Save viewport images as VPI's own record.
		if ( ! empty( $ox['vpi'] ) ) {
			$this->_save_vpi( $ox['vpi'], $v, $is_mobile );
		}

		// 7. Evict the cached copy of this page.
		//
		// The tag purge in _save_con() only reaches a cached page that carried the
		// OptiMax tag when it was stored, and that tag is only added on the queueing
		// branch of serve(). Anything cached before then keeps being served straight
		// from the page cache, so PHP never runs, serve() is never called, and the
		// build just saved stays invisible. Purging by URL evicts the entry whatever
		// tags it holds, so the next visitor regenerates the page and gets it.
		$this->cls( 'Purge' )->purge_url( $v['url'], false, true );

		return true;
	}

	/**
	 * Generate URL tag for Optimax.
	 *
	 * @since 8.0
	 *
	 * @param string $request_url Current request URL.
	 * @return string The URL tag.
	 */
	public static function get_url_tag( $request_url ) {
		if ( is_404() ) {
			return '404';
		}

		return $request_url;
	}

	/**
	 * Get User Agent.
	 *
	 * @since 8.0
	 *
	 * @return string The user agent string.
	 */
	private function _get_ua() {
		return ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Serve optimized page from cache if available.
	 *
	 * Called during buffer finalization as the first priority check.
	 * If ox HTML is found, returns it to skip all other optimization hooks.
	 *
	 * @since 8.0
	 *
	 * @return string|false The optimized HTML content, or false if not available.
	 */
	public function serve() {
		// Check if ox is enabled
		if ( ! $this->conf( self::O_OPTIMAX ) ) {
			return false;
		}

		// Only a full HTML document can be optimized. check_is_html() runs just before
		// this in Core::send_headers_force(), so REST/AJAX JSON, feeds and ESI fragments
		// are all excluded here — they must never be queued, nor replaced by OX HTML.
		if ( ! defined( 'LITESPEED_IS_HTML' ) ) {
			self::debug( 'serve() bypassed: not an HTML document' );
			return false;
		}

		// A page that will not be cached must not be served from, or added to, the OX
		// queue: the stored HTML would outlive the request it was personalized for.
		if ( ! Control::is_cacheable() ) {
			self::debug( 'serve() bypassed: not cacheable' );
			return false;
		}

		$request_url = $this->_build_request_url();

		// Check URI exclusions
		$exc = apply_filters( 'litespeed_optimax_exc', $this->conf( self::O_OPTIMAX_EXC ) );
		$hit = $exc ? Utility::str_hit_array( $request_url, $exc ) : false;
		if ( $hit ) {
			self::debug( 'serve() bypassed due to URI Exclude: ' . $hit );
			return false;
		}

		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
		$url_tag         = self::get_url_tag( $request_url );
		$vary            = $this->cls( 'Vary' )->finalize_full_varies();
		$filename        = $this->cls( 'Data' )->load_url_file( $url_tag, $vary, 'optimax' );

		if ( $filename && $this->_bundle_intact( $url_tag, $vary ) ) {
			$static_file = LITESPEED_STATIC_DIR . $filepath_prefix . $filename . '.html';

			if ( file_exists( $static_file ) ) {
				$html = File::read( $static_file );
				if ( $html ) {
					self::debug( 'serve() hit: ' . $filepath_prefix . $filename . '.html' );
					Core::comment( 'Optimax served ✅' );
					return $html;
				}
				self::debug( 'serve() empty file: ' . $static_file );
			} else {
				self::debug( 'serve() file missing: ' . $static_file );
			}
		}

		// No cached optimax, add to queue
		$uid = get_current_user_id();
		$ua  = $this->_get_ua();

		$this->_queue = $this->load_queue( 'optimax' );

		if ( count( $this->_queue ) > $this->_max_queue_size() ) {
			self::debug( 'Queue is full - ' . $this->_max_queue_size() );
			return false;
		}

		// VPI keeps its result as post meta, so the post this URL resolves to has
		// to be captured here — the same derivation VPI itself uses, including the
		// blog-home case. Recorded even when it is 0: OptiMax optimizes URLs that
		// are not single posts, and _save_result() decides what to do with that.
		$home_id = (int) get_option( 'page_for_posts' );
		$post_id = ( $home_id > 0 && is_home() ) ? $home_id : (int) get_the_ID();

		$queue_k                  = ( strlen( $vary ) > 32 ? md5( $vary ) : $vary ) . ' ' . $url_tag;
		$this->_queue[ $queue_k ] = [
			'url'        => apply_filters( 'litespeed_optimax_url', $request_url ),
			'post_id'    => $post_id,
			'user_agent' => substr( $ua, 0, 200 ),
			'is_mobile'  => $this->_separate_mobile(),
			'is_nextgen' => $this->cls( 'Media' )->webp_support(),
			'uid'        => $uid,
			'vary'       => $vary,
			'url_tag'    => $url_tag,
		];
		$this->save_queue( 'optimax', $this->_queue );
		self::debug( 'Added to queue [url_tag] ' . $url_tag . ' [UA] ' . $ua . ' [vary] ' . $vary . ' [uid] ' . $uid );

		// Prepare cache tag for later purge
		Tag::add( 'OPTIMAX.' . md5( $queue_k ) );
		Core::comment( 'QUIC.cloud Optimax in queue' );

		return false;
	}

	/**
	 * Store OptiMax's viewport images as a VPI record.
	 *
	 * OptiMax runs VPI itself and has already baked the result into the HTML as
	 * preload and priority hints, so this is not what makes the page fast — it
	 * keeps the plugin's own VPI list in step, so the metabox shows the same
	 * images and a later standalone VPI run does not start from nothing.
	 *
	 * VPI stores per post, and OptiMax optimizes URLs that are not single posts
	 * (archives, the shop, 404s). Those have no meta to write, so they are
	 * skipped rather than guessed at.
	 *
	 * @since 8.0
	 *
	 * @param array $vpi       Viewport image basenames.
	 * @param array $v         Queue item.
	 * @param bool  $is_mobile Whether this build is the mobile variant.
	 * @return void
	 */
	private function _save_vpi( $vpi, $v, $is_mobile ) {
		$post_id = ! empty( $v['post_id'] ) ? (int) $v['post_id'] : 0;
		if ( ! $post_id ) {
			self::debug( 'VPI not saved: no post id for ' . ( isset( $v['url'] ) ? $v['url'] : '' ) );
			return;
		}

		$name = $is_mobile ? VPI::POST_META_MOBILE : VPI::POST_META;
		$this->cls( 'Metabox' )->save( $post_id, $name, array_map( 'urldecode', (array) $vpi ) );

		self::debug( 'Saved vpi [count] ' . count( (array) $vpi ) . ' [post_id] ' . $post_id );
	}

	/**
	 * Whether the combined JS bundle this URL's stored HTML points at still exists.
	 *
	 * The HTML and the bundle are separate files with separate lifetimes: a purge
	 * can take `wp-content/litespeed/js/` while the OptiMax mapping survives, and
	 * the stored HTML then ships a `<script>` whose src 404s — every delayed
	 * script on the page silently never runs. Treating that as a miss costs one
	 * rebuild and keeps the page working; serving it costs the page its JS.
	 *
	 * A URL with no bundle mapping never had one inlined (or `_save_js()` failed
	 * and left the remote URL in place, which the browser can still fetch), so
	 * that case is intact by definition.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag URL tag.
	 * @param string $vary    Vary string.
	 * @return bool
	 */
	private function _bundle_intact( $url_tag, $vary ) {
		$js_filename = $this->cls( 'Data' )->load_url_file( $url_tag, $vary, 'js' );
		if ( ! $js_filename ) {
			return true;
		}

		$js_file = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'js' ) . $js_filename . '.js';
		if ( file_exists( $js_file ) ) {
			return true;
		}

		self::debug( 'serve() bypassed: js bundle missing ' . $js_file );

		return false;
	}

	/**
	 * Build the current request URL from WP globals.
	 *
	 * @since 8.0
	 *
	 * @return string The current request URL.
	 */
	private function _build_request_url() {
		global $wp;

		$permalink_structure = get_option( 'permalink_structure' );
		if ( ! empty( $permalink_structure ) ) {
			return trailingslashit( home_url( $wp->request ) );
		}

		$qs_add = $wp->query_string ? '?' . (string) $wp->query_string : '';
		return home_url( $wp->request ) . $qs_add;
	}

	/**
	 * Download and save optimized images locally.
	 *
	 * Each image entry has src (original path), webp_url, and avif_url.
	 * Optimized images are saved next to original files.
	 *
	 * @since 8.0
	 *
	 * @param array $imgs Array of image optimization data.
	 * @return void
	 */
	private function _save_imgs( $imgs ) {
		if ( ! is_array( $imgs ) ) {
			return false;
		}

		$hooks = [
			'webp' => 'litespeed_img_pull_webp',
			'avif' => 'litespeed_img_pull_avif',
		];

		foreach ( $imgs as $img ) {
			if ( empty( $img['src'] ) || ! is_string( $img['src'] ) ) {
				continue;
			}

			// Resolve through the attachment first, so the file we write is the one
			// WordPress actually serves, and we learn the post it belongs to.
			$local = $this->_image_target( $img );
			if ( ! $local ) {
				self::debug( 'Skip Optimax image entry without a WordPress image target: ' . $img['src'] );
				continue;
			}

			$local_path = $local[0];
			$row        = $local[2];

			foreach ( $hooks as $type => $hook ) {
				if ( empty( $img[ $type . '_url' ] ) ) {
					continue;
				}

				$target = $local_path . '.' . $type;
				if ( ! $this->_fetch_img( $img[ $type . '_url' ], $target ) ) {
					continue;
				}

				// Let Image Optimization record the file it did not fetch itself.
				if ( $row ) {
					do_action( $hook, $row, $target );
				}
			}
		}

		return true;
	}

	/**
	 * Fetch a remote image and save it locally.
	 *
	 * @since 8.0
	 *
	 * @param string $url       The remote image URL.
	 * @param string $save_path The local path to save the image.
	 * @return bool Whether fetch and save succeeded.
	 */
	private function _fetch_img( $url, $save_path ) {
		$body = $this->_fetch_con( $url );
		if ( false === $body ) {
			return false;
		}

		File::save( $save_path, $body, true );
		self::debug( 'Saved img: ' . $save_path );

		return true;
	}

	/**
	 * Fetch the body of a remote asset returned by the OX service.
	 *
	 * @since 8.0
	 *
	 * @param string $url Remote URL.
	 * @return string|false Body on success, false on transport error or empty body.
	 */
	private function _fetch_con( $url ) {
		$response = wp_remote_get(
			$url,
			[
				'timeout'   => 60,
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			self::debug( 'Failed to fetch ' . $url . ': ' . $response->get_error_message() );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			self::debug( 'Empty response: ' . $url );
			return false;
		}

		return $body;
	}

	/**
	 * Download the optimized JS and store it as a local static file.
	 *
	 * Mirrors how UCSS/CCSS are persisted, except the OX response carries a URL
	 * rather than inline content, so the body is fetched first.
	 *
	 * @since 8.0
	 *
	 * @param string $js_url     Remote URL of the optimized JS.
	 * @param string $queue_k    Queue key.
	 * @param array  $v          Queue item.
	 * @param bool   $is_mobile  Whether this is the mobile variant.
	 * @param string $is_nextgen Next-gen image format flag from the queue item.
	 * @return string|false Public URL of the stored bundle, or false on failure.
	 */
	private function _save_js( $js_url, $queue_k, $v, $is_mobile, $is_nextgen ) {
		$con = $this->_fetch_con( $js_url );
		// An empty body is a failed fetch too. md5( '' ) is a stable filename, so every
		// page reaching here would collide on one file and silently overwrite each
		// other's bundle instead of failing.
		if ( false === $con || '' === trim( (string) $con ) ) {
			self::debug( '❌ Failed to fetch js_url [k] ' . $queue_k );
			return false;
		}

		$filecon_md5     = md5( $con );
		$filepath_prefix = $this->_build_filepath_prefix( 'js' );
		$static_file     = LITESPEED_STATIC_DIR . $filepath_prefix . $filecon_md5 . '.js';

		$ok = File::save( $static_file, $con, true );
		// `File::save` reports failure by return value; without checking it the debug
		// line below claims a success that may not have happened, and the HTML is then
		// rewritten to point at a file that is not there.
		if ( false === $ok || ! file_exists( $static_file ) ) {
			self::debug( '❌ Failed to save js [file] ' . $static_file . ' [err] ' . var_export( $ok, true ) );
			return false;
		}
		self::debug( 'Saved js: ' . $static_file );

		$this->cls( 'Data' )->save_url( $v['url_tag'], $v['vary'], 'js', $filecon_md5, dirname( $static_file ), $is_mobile, $is_nextgen );

		Purge::add( 'JS.' . md5( $queue_k ) );

		return LITESPEED_STATIC_URL . $filepath_prefix . $filecon_md5 . '.js';
	}

	/**
	 * Resolve an image to a local target and optional attachment hook context.
	 *
	 * @param array $img Cloud image entry.
	 * @return array|false `[ path, bound root, hook row ]`, or false.
	 */
	private function _image_target( $img ) {
		$post_id = attachment_url_to_postid( $img['src'] );
		if ( 0 < $post_id ) {
			$uploads  = wp_upload_dir();
			$base     = ! empty( $uploads['basedir'] ) ? trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) : '';
			$attached = get_attached_file( $post_id, true );
			$attached = is_string( $attached ) ? wp_normalize_path( $attached ) : '';
			$url_path = wp_parse_url( $img['src'], PHP_URL_PATH );
			$filename = is_string( $url_path ) ? rawurldecode( basename( $url_path ) ) : '';
			if ( $base && 0 === strpos( $attached, $base ) && $filename ) {
				$dir   = dirname( substr( $attached, strlen( $base ) ) );
				$short = ( '.' === $dir ? '' : trailingslashit( $dir ) ) . $filename;
				$local = Img::normalize_cloud_path( $short ) === $short ? Img::local_file( apply_filters( 'litespeed_realpath', $base . $short ) ) : false;
				if ( $local && ( file_exists( $local[0] ) || $this->cls( 'Media' )->info( $short, $post_id ) ) ) {
					return [ $local[0], $local[1], (object) [ 'post_id' => $post_id, 'src' => $short ] ];
				}
			}
		}

		$local = Utility::is_internal_file( $img['src'] );
		$local = $local && ! empty( $local[0] ) ? Img::local_file( $local[0] ) : false;
		return $local ? [ $local[0], $local[1], false ] : false;
	}

	/**
	 * Save optimized HTML content.
	 *
	 * @param string $content    The optimized content.
	 * @param string $queue_k    The queue key.
	 * @param bool   $is_mobile  Whether is mobile.
	 * @param string $is_nextgen Next-gen image format ('webp', 'avif', or '').
	 * @param array  $v          Queue item.
	 * @return void
	 */
	private function _save_con( $content, $queue_k, $is_mobile, $is_nextgen, $v ) {
		$content = apply_filters( 'litespeed_optimax', $content, $queue_k );
		self::debug2( 'con: ', $content );

		// Write to file
		$filecon_md5 = md5( $content );

		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
		$static_file     = LITESPEED_STATIC_DIR . $filepath_prefix . $filecon_md5 . '.html';

		File::save( $static_file, $content, true );

		$url_tag = $v['url_tag'];
		$vary    = $v['vary'];
		self::debug2( "Save URL to file [file] $static_file [vary] $vary" );

		$this->cls( 'Data' )->save_url( $url_tag, $vary, 'optimax', $filecon_md5, dirname( $static_file ), $is_mobile, $is_nextgen );

		Purge::add( 'OPTIMAX.' . md5( $queue_k ) );
	}
}
