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
	 * Registered image sizes the owner excluded from optimization.
	 *
	 * Null until first read — the empty array is a legitimate value.
	 *
	 * @var array|null
	 */
	private $_sizes_skipped = null;

	/**
	 * The optimized image sizes, in the shape the payload carries them.
	 *
	 * Null until first built — the empty array is a legitimate value.
	 *
	 * @var array|null
	 */
	private $_img_sizes = null;

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
		if ( ! self::nextgen_ready() ) {
			return false;
		}

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
		if ( ! static::cls()->conf( self::O_OPTIMAX ) ) {
			return;
		}

		// Paused: queued items stay queued until Next-Gen is turned back on.
		if ( ! self::nextgen_ready() ) {
			self::debug( 'OX CRON PULL skipped: Next-Gen Image Format is OFF' );
			return;
		}

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
		// The trigger is keyed on the cron switch alone, so a leftover queue would
		// otherwise keep being sent after the feature itself was turned off — paying
		// for builds that serve() then refuses to serve.
		if ( ! static::cls()->conf( self::O_OPTIMAX ) ) {
			return;
		}

		// Paused: queued items stay queued until Next-Gen is turned back on.
		if ( ! self::nextgen_ready() ) {
			self::debug( 'OX CRON PUSH skipped: Next-Gen Image Format is OFF' );
			return;
		}

		self::debug( 'OX CRON PUSH started' );

		return static::cron();
	}

	/**
	 * Admin action handler: the queue's manual "Run" actions honour the pause too.
	 *
	 * "Run queue" and "Run item" reach the service through cron( true ) and
	 * gen_item(), bypassing cron_push(). While paused nothing may be sent, so
	 * those two actions show the paused message instead. Clearing the queue
	 * still works.
	 *
	 * @since 8.0
	 *
	 * @return void
	 */
	public function handler() {
		if ( in_array( Router::verify_type(), [ self::TYPE_GEN, self::TYPE_GEN_ITEM ], true ) && ! self::nextgen_ready() ) {
			self::debug( 'Manual run skipped: Next-Gen Image Format is OFF' );
			Admin_Display::note( esc_html( self::paused_msg() ) );
			Admin::redirect();
			return;
		}

		parent::handler();
	}

	/**
	 * Whether the Next-Gen Image Format setting lets OptiMax run.
	 *
	 * OptiMax needs Next-Gen on, WebP (1) or AVIF (2): it tells the service which
	 * page images already have their next-gen copy beside them, and the service
	 * links those instead of converting them again. With the setting OFF (0)
	 * nothing is served, queued or sent, and the page gets LSCWP's normal
	 * optimizations. The single gate for serve(), the cron entry points and
	 * need_pull().
	 *
	 * @since 8.0
	 *
	 * @param mixed $setting Optional `O_IMG_OPTM_WEBP` value; read from the settings when null.
	 * @return bool
	 */
	public static function nextgen_ready( $setting = null ) {
		if ( null === $setting ) {
			$setting = static::cls()->conf( self::O_IMG_OPTM_WEBP );
		}

		return 0 !== (int) $setting;
	}

	/**
	 * Whether OptiMax is switched on but paused by the Next-Gen gate.
	 *
	 * Drives the admin notice and the note on the OptiMax settings screen.
	 *
	 * @since 8.0
	 *
	 * @param mixed $optimax Optional `O_OPTIMAX` value; read from the settings when null.
	 * @param mixed $setting Optional `O_IMG_OPTM_WEBP` value; read from the settings when null.
	 * @return bool
	 */
	public static function is_paused( $optimax = null, $setting = null ) {
		if ( null === $optimax ) {
			$optimax = static::cls()->conf( self::O_OPTIMAX );
		}

		return (bool) $optimax && ! self::nextgen_ready( $setting );
	}

	/**
	 * The translated "OptiMax is paused" message. Not escaped: callers escape it.
	 *
	 * @since 8.0
	 *
	 * @return string
	 */
	public static function paused_msg() {
		return __( 'OptiMax is paused: it needs Next-Gen Image Format turned on (WebP or AVIF) in Image Optimization.', 'litespeed-cache' );
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
			'optm_ori'   => $this->conf( self::O_IMG_OPTM_ORI ) ? 1 : 0,
		];

		// The image sizes this site optimizes, so the service can give an `<img>` that
		// lacks one a srcset naming files WordPress has already generated. Only the
		// sizes are sent, never per-image data: every filename follows from the size
		// box, the crop flag and the image's own dimensions, all of which the service
		// already has.
		$img_sizes = $this->_img_sizes();
		if ( $img_sizes ) {
			$data['img_sizes'] = $img_sizes;
		}

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
		if ( ! is_array( $ox ) || empty( $ox['html'] ) || ! is_string( $ox['html'] ) ) {
			self::debug( '❌ No HTML in data_optimax.' );
			return false;
		}
		if ( isset( $ox['imgs'] ) && ! is_array( $ox['imgs'] ) ) {
			return false;
		}
		foreach ( [ 'ucss', 'ccss' ] as $field ) {
			if ( isset( $ox[ $field ] ) && ! is_string( $ox[ $field ] ) ) {
				return false;
			}
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

		// 1b. Same for the used CSS. The service links it as an external stylesheet
		// now instead of inlining it, so the delivered HTML carries a worker URL that
		// is both swept a couple of days later and plain http — which an https site's
		// browser blocks as mixed content long before the sweep. Pull it and repoint
		// the HTML before it is stored, or the page ships with no styles at all.
		if ( ! empty( $ox['css_url'] ) ) {
			$local_css_url = $this->_save_css( $ox['css_url'], $queue_k, $v, $is_mobile, $is_nextgen );
			if ( $local_css_url ) {
				$ox['html'] = str_replace( $ox['css_url'], $local_css_url, $ox['html'] );
			}
		}

		if ( ! empty( $ox['imgs'] ) && ! $this->_save_imgs( $ox['imgs'] ) ) {
			return false;
		}

		if ( ! empty( $ox['ucss'] ) && ! $this->_save_css_con( 'ucss', $ox['ucss'], $v['url_tag'], $v['vary'], $queue_k, $is_mobile, $is_nextgen ) ) {
			return false;
		}

		if ( ! empty( $ox['ccss'] ) && ! $this->_save_css_con( 'ccss', $ox['ccss'], $v['url_tag'], $v['vary'], $queue_k, $is_mobile, $is_nextgen ) ) {
			return false;
		}

		return $this->_save_con( $ox['html'], $queue_k, $is_mobile, $is_nextgen, $v );
	}

	/**
	 * Generate URL tag for Optimax.
	 *
	 * @since 8.0
	 *
	 * @param string $request_url Current request URL.
	 * @return string The URL tag.
	 */
	/**
	 * Cache tag for every stored page an OptimaX build can replace.
	 *
	 * Built from the url_tag alone, not the vary: evicting a sibling vary costs one
	 * re-render, whereas missing one leaves a stale page up until a Purge All.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity from get_url_tag().
	 * @return string
	 */
	private static function _page_tag( $url_tag ) {
		return 'OPTIMAX.' . md5( $url_tag );
	}

	/**
	 * Whether this request is for a file rather than a page.
	 *
	 * Either of two signals is enough:
	 *
	 * - The browser says so. `Sec-Fetch-Dest` names what the response is for, and
	 *   an image, stylesheet, script or font is not a page anyone views. `empty`
	 *   is not taken as a file: prefetchers (`<link rel=prefetch>`, instant.page)
	 *   fetch pages with it. Only browsers send the header, so it cannot be the
	 *   whole test.
	 * - The URL says so. A file the web server has never reaches WordPress, so a
	 *   file URL that does is a 404. WordPress slugs never contain a dot
	 *   (sanitize_title() turns it into a dash), so a page URL only carries an
	 *   extension from the permalink structure itself (`/%postname%.html`) or from
	 *   `index.php`. Any other extension on a 404 names a missing file.
	 *
	 * @since 8.0
	 *
	 * @return bool
	 */
	private static function _is_static_file_request() {
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) ) : '';
		if ( '' !== $dest && ! in_array( $dest, [ 'document', 'iframe', 'frame', 'empty' ], true ) ) {
			return true;
		}

		if ( ! is_404() ) {
			return false;
		}

		// The raw path, not Utility::request_url(): that one appends a trailing slash
		// under pretty permalinks, which hides the extension.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}

		$page_ext = strtolower( pathinfo( untrailingslashit( (string) get_option( 'permalink_structure' ) ), PATHINFO_EXTENSION ) );

		return ! in_array( $ext, [ 'php', $page_ext ], true );
	}

	/**
	 * Whether this 404 should share one build with every other 404.
	 *
	 * On by default: a 404 is normally the same page whatever was requested, so
	 * one build serves all of them and unbounded bot traffic cannot spend a build
	 * per bad URL. Sites whose 404 is genuinely dynamic can opt out:
	 *
	 *     add_filter( 'litespeed_ox_404_one_page', '__return_false' );
	 *
	 * and then get a build per URL and vary, like any other page.
	 *
	 * @since 8.0
	 *
	 * @return bool
	 */
	private static function _is_shared_404() {
		return is_404() && apply_filters( 'litespeed_ox_404_one_page', true );
	}

	public static function get_url_tag( $request_url ) {
		if ( self::_is_shared_404() ) {
			return '404';
		}

		if ( apply_filters( 'litespeed_optimax_per_pagetype', false ) ) {
			return Utility::page_type();
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

		// Paused while Next-Gen Image Format is OFF: neither serve nor queue. The page
		// then gets LSCWP's normal optimizations, exactly as with OptiMax off.
		if ( ! self::nextgen_ready() ) {
			self::debug( 'serve() bypassed: Next-Gen Image Format is OFF' );
			return false;
		}

		// Only a full HTML document can be optimized. check_is_html() runs just before
		// this in Core::send_headers_force(), so REST/AJAX JSON, feeds and ESI fragments
		// are all excluded here — they must never be queued, nor replaced by OX HTML.
		if ( ! defined( 'LITESPEED_IS_HTML' ) ) {
			self::debug( 'serve() bypassed: not an HTML document' );
			return false;
		}

		// A missing image, stylesheet or script falls through to WordPress, which
		// answers with its 404 page: an HTML document that would be queued under the
		// file's URL. Nobody browses to that URL as a page, so it never goes to QC.
		if ( self::_is_static_file_request() ) {
			self::debug( 'serve() bypassed: static file URL' );
			return false;
		}

		// A page that will not be cached must not be served from, or added to, the OX
		// queue: the stored HTML would outlive the request it was personalized for.
		if ( ! Control::is_cacheable() ) {
			self::debug( 'serve() bypassed: not cacheable' );
			return false;
		}

		// Logged-out pages only. With Cache Logged-in Users on, a logged-in view is
		// cacheable and would otherwise be queued once per user vary — builds nobody
		// else can reuse. Optimizing the public page is the whole point.
		if ( Router::is_logged_in() ) {
			self::debug( 'serve() bypassed: logged in' );
			return false;
		}

		$request_url = Utility::request_url();

		// Check URI exclusions
		$exc = apply_filters( 'litespeed_optimax_exc', $this->conf( self::O_OPTIMAX_EXC ) );
		$hit = $exc ? Utility::str_hit_array( $request_url, $exc ) : false;
		if ( $hit ) {
			self::debug( 'serve() bypassed due to URI Exclude: ' . $hit );
			return false;
		}

		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
		$url_tag         = self::get_url_tag( $request_url );
		// The shared tag alone still leaves one build per vary, so collapse that too.
		$vary     = self::_is_shared_404() ? '' : $this->cls( 'Vary' )->finalize_full_varies();
		$filename = $this->cls( 'Data' )->load_url_file( $url_tag, $vary, 'optimax' );

		// Tag every render OptimaX could replace, on both the hit and the queue path,
		// so a finished build can evict it. Keyed on the page rather than going through
		// Tag::get_uri_tag(), which (a) switches between a plain and an md5 format with
		// LSCWP_LOG — decided per request, so a visitor and the cron that purges can
		// disagree — and (b) urldecodes at render but not at purge. It also lets one
		// purge reach every 404 sharing the '404' build, not just the URL on the row.
		Tag::add( self::_page_tag( $url_tag ) );

		if ( $filename && $this->_assets_intact( $url_tag, $vary ) ) {
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

		if ( ! $this->queueable_request() ) {
			return false;
		}

		$this->_queue = $this->load_queue( 'optimax' );

		$queue_k = ( strlen( $vary ) > 32 ? md5( $vary ) : $vary ) . ' ' . $url_tag;
		if ( ! isset( $this->_queue[ $queue_k ] ) && count( $this->_queue ) >= $this->_max_queue_size() ) {
			self::debug( 'Queue is full - ' . $this->_max_queue_size() );
			return false;
		}
		$this->_queue[ $queue_k ] = [
			'url'        => apply_filters( 'litespeed_optimax_url', $request_url ),
			'user_agent' => substr( $ua, 0, 200 ),
			'is_mobile'  => $this->_separate_mobile(),
			'is_nextgen' => $this->cls( 'Media' )->webp_support(),
			'uid'        => $uid,
			'vary'       => $vary,
			'url_tag'    => $url_tag,
		];
		$this->save_queue( 'optimax', $this->_queue );
		self::debug( 'Added Optimax queue item [request] ' . substr( hash( 'sha256', $queue_k ), 0, 12 ) );

		// Prepare cache tag for later purge
		Tag::add( 'OPTIMAX.' . md5( $queue_k ) );
		Core::comment( 'QUIC.cloud Optimax in queue' );

		return false;
	}

	/**
	 * Whether every sidecar file this URL's stored HTML points at still exists.
	 *
	 * The HTML and its assets are separate files with separate lifetimes: a purge
	 * can empty `wp-content/litespeed/optimax/`, and `Data::save_url()` deletes a
	 * file once its mapping row has been expired long enough — which reaches a
	 * file two URLs share, because the name is the md5 of the content and two
	 * pages with identical CSS get one file and two rows. Either way the OptiMax
	 * mapping can outlive the file it names, and the stored HTML then ships a
	 * reference that 404s. Treating that as a miss costs one rebuild and keeps
	 * the page working; serving it costs the visitor the asset.
	 *
	 * The stylesheet is the more damaging of the two. OptiMax strips the page's
	 * own `<link rel=stylesheet>` tags, so apart from the small inlined critical
	 * CSS the used CSS is the page's only styling — a missing bundle mutes the
	 * scripts, a missing stylesheet leaves the page unstyled below the fold.
	 *
	 * A URL with no mapping for one of these types is intact for that type, not
	 * broken, and the two ways that happens are both harmless:
	 *
	 * - the service never sent that asset — the used CSS arrives inlined in a
	 *   `<style id="optimax_ucss">` block unless the external-stylesheet flag is
	 *   on, and such HTML references no CSS file at all;
	 * - `_save_css()`/`_save_js()` failed, so `save_url()` was never reached and
	 *   the HTML still carries the service's own URL, which the browser can
	 *   still fetch.
	 *
	 * Only `save_url()` writes these rows, and only after the fetch and the local
	 * write have both succeeded, so a mapping means the HTML was repointed at
	 * that local file. Mapping present + file gone is therefore the one state
	 * worth refusing to serve, and it is exactly what this checks.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag URL tag.
	 * @param string $vary    Vary string.
	 * @return bool
	 */
	private function _assets_intact( $url_tag, $vary ) {
		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );

		foreach ( [
			'optimax_js'   => 'js',
			'optimax_ucss' => 'css',
		] as $file_type => $ext ) {
			$filename = $this->cls( 'Data' )->load_url_file( $url_tag, $vary, $file_type );
			if ( ! $filename ) {
				continue;
			}

			$file = LITESPEED_STATIC_DIR . $filepath_prefix . $filename . '.' . $ext;
			if ( file_exists( $file ) ) {
				continue;
			}

			self::debug( 'serve() bypassed: ' . $file_type . ' missing ' . $file );

			return false;
		}

		return true;
	}

	/**
	 * Download and save optimized images locally.
	 *
	 * Each image entry has src and any requested ori/webp/avif artifact.
	 * Optimized images are saved beside their WordPress image targets.
	 *
	 * @since 8.0
	 *
	 * @param array $imgs Array of image optimization data.
	 * @return bool
	 */
	private function _save_imgs( $imgs ) {
		if ( ! is_array( $imgs ) ) {
			return false;
		}

		$hooks        = [
			'ori'  => 'litespeed_img_pull_ori',
			'webp' => 'litespeed_img_pull_webp',
			'avif' => 'litespeed_img_pull_avif',
		];
		$types        = [ 'webp', 'avif' ];
		$optm_ori     = (bool) $this->conf( self::O_IMG_OPTM_ORI );
		$preserve_ori = $optm_ori && ! $this->conf( self::O_IMG_OPTM_RM_BKUP );
		if ( $optm_ori ) {
			$types[] = 'ori';
		}

		foreach ( $imgs as $img ) {
			if ( ! is_array( $img ) ) {
				return false;
			}

			$artifacts = [];
			foreach ( $types as $type ) {
				$url_key    = $type . '_url';
				$digest_key = $type . '_sha256';
				if ( empty( $img[ $url_key ] ) ) {
					continue;
				}
				$url = Img::normalize_cloud_url( $img[ $url_key ] );
				if (
					! $url || empty( $img[ $digest_key ] ) || ! is_string( $img[ $digest_key ] ) || ! preg_match( '/^[a-f0-9]{64}$/iD', $img[ $digest_key ] )
				) {
					return false;
				}
				$artifacts[ $type ] = [
					'url'    => $url,
					'digest' => strtolower( $img[ $digest_key ] ),
				];
			}

			if ( empty( $artifacts ) ) {
				continue;
			}
			if ( empty( $img['src'] ) || ! is_string( $img['src'] ) || 2048 < strlen( $img['src'] ) ) {
				self::debug( 'Skip Optimax image entry without a usable local source.' );
				continue;
			}
			$local = $this->_image_target( $img );
			if ( ! $local ) {
				self::debug( 'Skip Optimax image entry without a WordPress image target.' );
				continue;
			}
			list( $local_path, $local_root, $row ) = $local;

			$published = [];
			foreach ( $artifacts as $type => $artifact ) {
				$target = 'ori' === $type ? $local_path : $local_path . '.' . $type;
				$res    = Img::save( $artifact['url'], $target, $artifact['digest'], 'sha256', $type, $local_root, 'ori' === $type && $preserve_ori );
				if ( is_wp_error( $res ) ) {
					// Log the URL without its query string: it may carry a token.
					$parts  = wp_parse_url( $artifact['url'] );
					$label  = $parts['host'] . ( ! empty( $parts['path'] ) ? $parts['path'] : '/' );
					$detail = $res->get_error_data();
					self::debug( '❌ Failed to save img [url] ' . $label . ' [error] ' . $res->get_error_code() . ( '' !== (string) $detail ? ':' . $detail : '' ) );
					return false;
				}
				$published[ $type ] = $target;
			}
			if ( $row ) {
				foreach ( $hooks as $type => $hook ) {
					if ( isset( $published[ $type ] ) ) {
						do_action( $hook, $row, $published[ $type ] );
					}
				}
			}
		}

		return true;
	}

	/**
	 * Image sizes the owner has excluded, honouring the Image Optimization filter.
	 *
	 * `O_IMG_OPTM_SIZES_SKIPPED` is a skip list — the optimized set is every
	 * registered size minus these — and Img_Optm reads it through a filter, so the
	 * same line is reused verbatim here. Reading the option directly would honour a
	 * site's filter in one module and ignore it in the other, and we would report
	 * sizes whose next-gen copy is never built.
	 *
	 * @since 8.0
	 *
	 * @return array
	 */
	private function _get_sizes_skipped() {
		if ( null === $this->_sizes_skipped ) {
			$skipped              = apply_filters( 'litespeed_imgoptm_sizes_skipped', $this->conf( self::O_IMG_OPTM_SIZES_SKIPPED ) );
			$this->_sizes_skipped = is_array( $skipped ) ? $skipped : [];
		}

		return $this->_sizes_skipped;
	}

	/**
	 * The image sizes this site optimizes, in the shape the payload carries them.
	 *
	 * Every registered size minus the ones the owner skipped. Nothing is ever
	 * generated: this only describes the boxes WordPress already resizes uploads
	 * into, so the service can name the files that exist rather than invent any.
	 *
	 * `crop` is load-bearing and not an aside. WordPress names a sub-size after the
	 * dimensions it actually produced, not after the box: an uncropped 300x300 box
	 * on a 1200x800 image yields `hero-300x200.jpg`, while a cropped 150x150 box
	 * yields exactly `hero-150x150.jpg`. Without the flag the service can neither
	 * work out the filename nor keep cropped and proportional variants out of the
	 * same srcset, where their differing aspect ratios would make the browser swap
	 * to a differently-framed image at some viewport widths.
	 *
	 * Keys are short because the list rides on every single request: `n` name,
	 * `w`/`h` the box, `c` the crop setting.
	 *
	 * @since 8.0
	 *
	 * @return array List of `[ 'n' => name, 'w' => width, 'h' => height, 'c' => crop ]`.
	 */
	private function _img_sizes() {
		if ( null !== $this->_img_sizes ) {
			return $this->_img_sizes;
		}

		$this->_img_sizes = [];

		$skipped = $this->_get_sizes_skipped();

		foreach ( $this->cls( 'Media' )->get_image_sizes() as $name => $size ) {
			if ( in_array( $name, $skipped, true ) ) {
				continue;
			}

			$width  = ! empty( $size['width'] ) ? (int) $size['width'] : 0;
			$height = ! empty( $size['height'] ) ? (int) $size['height'] : 0;

			// A size with no box at all resizes nothing and produces no file. It is also
			// hidden from the settings screen by Utility::prepare_image_sizes_array(), so
			// the owner never had the chance to skip it.
			if ( ! $width && ! $height ) {
				continue;
			}

			$this->_img_sizes[] = [
				'n' => (string) $name,
				'w' => $width,
				'h' => $height,
				'c' => $this->_crop_flag( isset( $size['crop'] ) ? $size['crop'] : false ),
			];
		}

		self::debug( 'img_sizes ' . count( $this->_img_sizes ) . ' optimized size(s), ' . count( $skipped ) . ' skipped' );

		return $this->_img_sizes;
	}

	/**
	 * One registered size's crop setting, with its shape kept intact.
	 *
	 * WordPress stores three different things under `crop`, and the difference is
	 * the whole point: `false` scales the image to fit, `true` crops it about the
	 * centre, and an anchor pair such as `[ 'left', 'top' ]` crops it about that
	 * corner. Flattening the pair to a bool would throw away the only record of
	 * which part of the image survives the crop.
	 *
	 * Anything cropped but unreadable as an anchor is reported as a plain crop,
	 * which is what core itself falls back to — `image_resize_dimensions()` swaps
	 * any non-two-element crop for `[ 'center', 'center' ]` — so the reported value
	 * keeps matching the file WordPress actually wrote.
	 *
	 * @since 8.0
	 *
	 * @param mixed $crop Raw `crop` value from the registered size.
	 * @return array|bool Anchor pair, or true/false.
	 */
	private function _crop_flag( $crop ) {
		// Covers false, 0, '' and the empty array, all of which WordPress reads as
		// "scale to fit". The empty array matters: an array the consumer cannot read
		// as an anchor is ambiguous, and "not cropped" has to be unambiguous.
		if ( empty( $crop ) ) {
			return false;
		}

		if ( ! is_array( $crop ) ) {
			return true;
		}

		// array_values() is what guarantees a JSON list. An associative or sparse
		// array would encode as `{"0":"left","1":"top"}`, which the consumer reads as
		// an object rather than an anchor pair — and so as not cropped at all.
		$anchor = array_values( $crop );

		return 2 === count( $anchor ) && is_string( $anchor[0] ) && is_string( $anchor[1] ) ? $anchor : true;
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
		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
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

		$this->cls( 'Data' )->save_url( $v['url_tag'], $v['vary'], 'optimax_js', $filecon_md5, dirname( $static_file ), $is_mobile, $is_nextgen );

		Purge::add( 'JS.' . md5( $queue_k ) );

		return LITESPEED_STATIC_URL . $filepath_prefix . $filecon_md5 . '.js';
	}

	/**
	 * Download the used CSS and store it as a local static file.
	 *
	 * The used CSS used to arrive inline; the service now links it from the worker's
	 * artifact origin, which sweeps its files after a couple of days and speaks plain
	 * http, so leaving that href in place loses the page's styles either immediately
	 * (mixed content on an https site) or shortly after. Same fix as the JS bundle:
	 * fetch the body once, keep it beside the stored HTML, hand back the local URL.
	 *
	 * @since 8.0
	 *
	 * @param string $css_url    Remote URL of the used CSS.
	 * @param string $queue_k    Queue key.
	 * @param array  $v          Queue item.
	 * @param bool   $is_mobile  Whether this is the mobile variant.
	 * @param string $is_nextgen Next-gen image format flag from the queue item.
	 * @return string|false Public URL of the stored stylesheet, or false on failure.
	 */
	private function _save_css( $css_url, $queue_k, $v, $is_mobile, $is_nextgen ) {
		$con = $this->_fetch_con( $css_url );
		// An empty body is a failed fetch too. md5( '' ) is a stable filename, so every
		// page reaching here would collide on one file and silently overwrite each
		// other's stylesheet instead of failing.
		if ( false === $con || '' === trim( (string) $con ) ) {
			self::debug( '❌ Failed to fetch css_url [k] ' . $queue_k );
			return false;
		}

		$filecon_md5     = md5( $con );
		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
		$static_file     = LITESPEED_STATIC_DIR . $filepath_prefix . $filecon_md5 . '.css';

		$ok = File::save( $static_file, $con, true );
		// `File::save` reports failure by return value; without checking it the debug
		// line below claims a success that may not have happened, and the HTML is then
		// rewritten to point at a stylesheet that is not there — an unstyled page, and
		// worse than the remote href it replaced.
		if ( false === $ok || ! file_exists( $static_file ) ) {
			self::debug( '❌ Failed to save css [file] ' . $static_file . ' [err] ' . var_export( $ok, true ) );
			return false;
		}
		self::debug( 'Saved css: ' . $static_file );

		$this->cls( 'Data' )->save_url( $v['url_tag'], $v['vary'], 'optimax_ucss', $filecon_md5, dirname( $static_file ), $is_mobile, $is_nextgen );

		// Evict the pages this stylesheet belongs to, by the tag serve() gives every
		// OptimaX render. _save_con() purges the same tag a moment later and Purge
		// dedupes, so this only matters if the two steps ever drift apart.
		Purge::add( self::_page_tag( $v['url_tag'] ), true );

		return LITESPEED_STATIC_URL . $filepath_prefix . $filecon_md5 . '.css';
	}

	/**
	 * Resolve an image to a local target and optional attachment hook context.
	 *
	 * @param array $img Cloud image entry.
	 * @return array|false `[ path, bound root, hook row ]`, or false.
	 */
	private function _image_target( $img ) {
		// OptiMax reports `src` relative to the site root (`/wp-content/uploads/...`).
		// Both resolvers below want an absolute URL: attachment_url_to_postid()
		// returns 0 for a bare path, and is_internal_file() falls back to
		// $_SERVER['DOCUMENT_ROOT'], which the cron request that saves the result
		// may not have. Without this every image is skipped as having no WordPress
		// target and nothing is ever downloaded.
		if ( '/' === substr( $img['src'], 0, 1 ) && '//' !== substr( $img['src'], 0, 2 ) ) {
			$img['src'] = home_url( $img['src'] );
		}

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
	 * @return bool
	 */
	private function _save_con( $content, $queue_k, $is_mobile, $is_nextgen, $v ) {
		$content = apply_filters( 'litespeed_optimax', $content, $queue_k );
		if ( ! is_string( $content ) ) {
			return false;
		}
		$content = File::remove_zero_space( $content );

		// Write to file
		$filecon_md5 = md5( $content );

		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
		$static_file     = LITESPEED_STATIC_DIR . $filepath_prefix . $filecon_md5 . '.html';

		if ( ! File::save_atomic( $static_file, $content ) ) {
			return false;
		}

		$url_tag = $v['url_tag'];
		$vary    = $v['vary'];
		self::debug2( "Save URL to file [file] $static_file [vary] $vary" );

		$data = $this->cls( 'Data' );
		$data->save_url( $url_tag, $vary, 'optimax', $filecon_md5, dirname( $static_file ), $is_mobile, $is_nextgen );
		if ( $filecon_md5 !== $data->load_url_file( $url_tag, $vary, 'optimax' ) ) {
			return false;
		}

		Purge::add( 'OPTIMAX.' . md5( $queue_k ) );
		Purge::add( self::_page_tag( $url_tag ), true );

		// Evict the cached copy of this page.
		//
		// The tag purges above only reach a cached page that carried the
		// OptiMax tag when it was stored, and that tag is only added on the queueing
		// branch of serve(). Anything cached before then keeps being served straight
		// from the page cache, so PHP never runs, serve() is never called, and the
		// build just saved stays invisible. Purging by URL evicts the entry whatever
		// tags it holds, so the next visitor regenerates the page and gets it.
		$this->cls( 'Purge' )->purge_url( $v['url'], true, true );
		return true;
	}
}
