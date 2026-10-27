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

	const TYPE_REBUILD = 'rebuild';

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
	 * Admin action handler: rebuild the pages whose design changed, clear the queue, run it.
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
		$type  = Router::verify_type();
		$pages = $this->cls( 'Optimax_Pages' );
		// Back to the bare page: the default redirect keeps every query arg.
		$back = admin_url( 'admin.php?page=litespeed-optimax' );

		switch ( $type ) {
			case self::TYPE_REBUILD:
				$n = $pages->rebuild_all();
				/* translators: %d: number of page versions queued */
				Admin_Display::success( sprintf( _n( '%d page version queued for a new OptimaX build.', '%d page versions queued for a new OptimaX build.', $n, 'litespeed-cache' ), $n ) );
				Admin::redirect( $back );
				return;

			case self::TYPE_CLEAR_Q:
				$kept = $pages->clear_waiting();
				if ( $kept ) {
					/* translators: %d: number of pages QUIC.cloud is still optimizing */
					Admin_Display::success( sprintf( _n( 'OptimaX queue cleared. %d page QUIC.cloud is already optimizing was kept.', 'OptimaX queue cleared. %d pages QUIC.cloud is already optimizing were kept.', $kept, 'litespeed-cache' ), $kept ) );
				} else {
					Admin_Display::success( __( 'OptimaX queue cleared.', 'litespeed-cache' ) );
				}
				Admin::redirect( $back );
				return;
		}

		if ( in_array( $type, [ self::TYPE_GEN, self::TYPE_GEN_ITEM ], true ) && ! self::nextgen_ready() ) {
			self::debug( 'Manual run skipped: Next-Gen Image Format is OFF' );
			Admin_Display::note( esc_html( self::paused_msg() ) );
			Admin::redirect( $back );
			return;
		}

		if ( self::TYPE_GEN === $type ) {
			// One request per click, behind the cron's own throttles (the try-later wait, a
			// request still in flight): a manual run never loads QUIC.cloud more than the cron.
			$wait = (int) self::get_summary( $this->_next_run_after_key() ) - time();
			if ( $wait > 0 ) {
				/* translators: %s: time until the queue continues */
				Admin_Display::note( sprintf( __( 'QUIC.cloud is still optimizing a page. The OptimaX queue continues in %s.', 'litespeed-cache' ), Utility::readable_time( $wait, 0, true ) ) );
			} else {
				static::cron();
			}
			Admin::redirect( $back );
			return;
		}

		parent::handler();
	}

	/**
	 * Settings OptimaX needs, with the value each takes while it is on.
	 *
	 * Cache, so its pages can be stored and served. Mobile Cache, so phones get a
	 * build of their own. Browser Cache, so the files an OptimaX page links —
	 * its combined JS bundle, the site's own scripts and fonts — are kept by the
	 * visitor's browser: the server's own expiry rules often cover images and
	 * CSS but not `text/javascript` or fonts, which PageSpeed then reports as
	 * having no cache lifetime. Add Missing Sizes, so its images keep their
	 * sizes. Next-Gen Image Format, which it needs to run at all: AVIF when it
	 * is off.
	 *
	 * @since 8.0
	 *
	 * @return array Option id => value.
	 */
	public static function required_settings() {
		return [
			self::O_CACHE                   => true,
			self::O_CACHE_MOBILE            => true,
			self::O_CACHE_BROWSER           => true,
			self::O_MEDIA_ADD_MISSING_SIZES => true,
			self::O_IMG_OPTM_WEBP           => self::VAL_ON2,
		];
	}

	/**
	 * Turn on the settings OptimaX needs, while it is on (Guest Optimization style).
	 *
	 * The stored settings are left as they are: each one only reads as ON through
	 * its `litespeed_conf_load_option_*` filter, so switching OptimaX off gives the
	 * owner's own values back. A setting already on keeps its value (WebP stays
	 * WebP). OptimaX is read live, so the save that turns it on writes `.htaccess`
	 * with the mobile and next-gen rules already. Runs before Conf::define_cache().
	 *
	 * @since 8.0
	 *
	 * @param Conf $conf Settings, loaded.
	 * @return void
	 */
	public static function force_required( $conf ) {
		foreach ( self::required_settings() as $id => $val ) {
			add_filter(
				'litespeed_conf_load_option_' . $id,
				function ( $setting ) use ( $conf, $val ) {
					return $setting || ! $conf->conf( self::O_OPTIMAX, true ) ? $setting : $val;
				}
			);
		}
	}

	/**
	 * The same settings turned on in a raw options array, for code that reads the
	 * stored array instead of conf(): `.htaccess` gets its mobile and next-gen rules.
	 *
	 * @since 8.0
	 *
	 * @param array $cfg Options, as stored.
	 * @return array
	 */
	public static function with_required( $cfg ) {
		if ( ! empty( $cfg[ self::O_OPTIMAX ] ) ) {
			foreach ( self::required_settings() as $id => $val ) {
				if ( empty( $cfg[ $id ] ) ) {
					$cfg[ $id ] = $val;
				}
			}
		}
		return $cfg;
	}

	/**
	 * Whether OptimaX is what turns this setting on: OptimaX is on, the setting off.
	 *
	 * @since 8.0
	 *
	 * @param string $id Option id.
	 * @return bool
	 */
	public static function forces( $id ) {
		$conf = static::cls();
		return array_key_exists( $id, self::required_settings() ) && $conf->conf( self::O_OPTIMAX, true ) && ! $conf->conf( $id, true );
	}

	/**
	 * Images saved by ready OptimaX builds, as shown in Image Optimization and OptimaX Status.
	 *
	 * @since 8.0
	 *
	 * @return int
	 */
	public static function ox_img_count() {
		return (int) Img_Optm::get_summary( Img_Optm::SUMMARY_OX_IMGS );
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
	 * A page that failed stays queued and is sent again later.
	 *
	 * @since 8.0
	 *
	 * @return bool
	 */
	protected function _keep_failed() {
		return true;
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
		foreach ( [ 'url', 'url_tag', 'vary' ] as $key ) {
			if ( ! is_array( $v ) || ! isset( $v[ $key ] ) || ! is_string( $v[ $key ] ) ) {
				return false;
			}
		}

		// A file (script, stylesheet, image, feed…) is never a page to optimize, however it got queued.
		if ( self::is_file_url( $v['url'] ) || self::is_file_url( $v['url_tag'] ) ) {
			self::debug( 'Not sent: file URL [url] ' . $v['url'] );
			return false;
		}

		return '' !== $queue_k && '' !== $v['url'] && '' !== $v['url_tag'] &&
			( empty( $v['is_nextgen'] ) || in_array( $v['is_nextgen'], [ 'webp', 'avif' ], true ) );
	}

	/**
	 * Whether a URL names a file rather than a page.
	 *
	 * WordPress slugs never contain a dot (sanitize_title() turns it into a dash),
	 * so a page URL only carries an extension from the permalink structure itself
	 * (`/%postname%.html`) or from `index.php`. Any other extension names a file.
	 * A trailing slash is ignored: Utility::request_url() adds one under pretty
	 * permalinks, which would hide the extension.
	 *
	 * @since 8.0
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	public static function is_file_url( $url ) {
		$path = untrailingslashit( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}

		$page_ext = strtolower( pathinfo( untrailingslashit( (string) get_option( 'permalink_structure' ) ), PATHINFO_EXTENSION ) );

		return ! in_array( $ext, [ 'php', $page_ext ], true );
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

		// The page's UCSS, CCSS and VPI, when its design is the one they were made
		// for: QUIC.cloud then reuses them instead of generating them again.
		if ( ! empty( $v['reuse'] ) && ! $this->_pull_only ) {
			$data = array_merge( $data, $this->_kept_assets( $v ) );
		}

		// A change PHP could not patch: the render the build matches, the render that
		// showed the change and the build's HTML, for QUIC.cloud to carry the change
		// into the build (or build the version as usual when it cannot).
		if ( ! empty( $v['sync'] ) && ! $this->_pull_only ) {
			$sync = $this->cls( 'Optimax_Pages' )->load_sync( $v['url_tag'], $v['vary'] );
			if ( $sync ) {
				$data['sync'] = [
					'base' => $sync['base'],
					'now'  => $sync['now'],
					'html' => $sync['html'],
				];
				self::debug( 'Sync requested [url] ' . $v['url_tag'] );
			} else {
				self::debug( 'Sync snapshot missing, a build is requested instead [url] ' . $v['url_tag'] );
			}
		}

		// A collection run must not create work: the service answers with a cached
		// result or try_later, and never queues a new build.
		if ( $this->_pull_only ) {
			$data['pull_only'] = 1;
		}

		return $data;
	}

	/**
	 * Store a result. Once stored, the page's builds past their grace period are deleted.
	 *
	 * @param array  $ox      data_optimax payload.
	 * @param string $queue_k Queue key.
	 * @param array  $v       Queue item.
	 * @return bool
	 */
	protected function _save_result( $ox, $queue_k, $v ) {
		$stored = $this->_store_result( $ox, $queue_k, $v );

		if ( $stored && ! empty( $v['url_tag'] ) ) {
			$pages = $this->cls( 'Optimax_Pages' );
			$pages->end_check( $v['url_tag'], $v['vary'], empty( $ox['synced'] ) ? 'rebuilt' : 'synced' );
			$pages->drop_sync( $v['url_tag'], $v['vary'] );
			$url_id = $pages->url_id( $v['url_tag'] );
			if ( $url_id ) {
				$pages->clean_expired( $url_id );
			}
		}

		return $stored;
	}

	/**
	 * Fan out the nested optimization payload to four save targets.
	 *
	 * @param array  $ox      data_optimax payload.
	 * @param string $queue_k Queue key.
	 * @param array  $v       Queue item.
	 * @return bool False when nothing was stored (a malformed field, or an asset
	 *              that could not be pulled or verified), true once the HTML is.
	 */
	private function _store_result( $ox, $queue_k, $v ) {
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
		// the src must be repointed at the local copy before the HTML is stored. A
		// failed pull stores nothing, like a failed image: HTML still naming the
		// worker URL would lose every combined script at the sweep, and
		// _assets_intact() has no manifest entry to catch it by. The previous build
		// keeps serving and the queue item is retried.
		$assets = [
			'js'   => '',
			'css'  => '',
			'imgs' => [],
		];
		// A synced result is the version's own build with the change carried in: it
		// links that build's JS bundle, used CSS and images, so it takes over that
		// build's manifest, and clean_expired() keeps the files it names.
		if ( ! empty( $ox['synced'] ) ) {
			$pages  = $this->cls( 'Optimax_Pages' );
			$source = $pages->sync_source( $v['url_tag'], $v['vary'] );
			$assets = $source ? $pages->load_assets( $source ) : null;
			if ( ! $assets ) {
				self::debug( '❌ Synced result without the build it came from; result not stored [k] ' . $queue_k );
				return false;
			}
			self::debug( 'Synced result keeps the assets of [build] ' . $source );
		}
		if ( ! empty( $ox['js_url'] ) ) {
			$local_js_url = $this->_save_js( $ox['js_url'], self::_sidecar_sha256( $ox, 'js_sha256' ), $queue_k );
			if ( ! $local_js_url ) {
				self::debug( '❌ JS bundle not saved; result not stored [k] ' . $queue_k );
				return false;
			}
			$ox['html']   = str_replace( $ox['js_url'], $local_js_url, $ox['html'] );
			$assets['js'] = basename( $local_js_url );
		}

		// 1b. Same for the used CSS, when the service links it as an external
		// stylesheet instead of inlining it. The page's own stylesheets are already
		// stripped, so HTML still naming the worker URL would ship unstyled once the
		// artifact is swept. Pull it and repoint the HTML before it is stored, or
		// store nothing.
		if ( ! empty( $ox['css_url'] ) ) {
			$local_css_url = $this->_save_css( $ox['css_url'], self::_sidecar_sha256( $ox, 'css_sha256' ), $queue_k, $v );
			if ( ! $local_css_url ) {
				self::debug( '❌ Used CSS not saved; result not stored [k] ' . $queue_k );
				return false;
			}
			$ox['html']    = str_replace( $ox['css_url'], $local_css_url, $ox['html'] );
			$assets['css'] = basename( $local_css_url );
		}

		// 2. Images, before the HTML that names them. The service rewrites every image
		// it converted to `<src>.<format>` before any file exists, so a link is only
		// good once its variant is saved there. A failed fetch or digest check stores
		// nothing (the previous build keeps serving); an image skipped for want of a
		// URL or a WordPress target goes back to its original link instead, in the
		// HTML and in both stylesheets, so the page never names a file that is not
		// on disk.
		$img_count = 0;
		if ( ! empty( $ox['imgs'] ) ) {
			$imgs = $this->_save_imgs( $ox['imgs'] );
			if ( false === $imgs ) {
				return false;
			}
			$assets['imgs'] = $imgs['saved'];
			$img_count      = $imgs['count'];
			// The page links one format, the one this build was requested in.
			$linked   = $is_nextgen ? [ $is_nextgen ] : [ 'webp', 'avif' ];
			$restored = 0;
			foreach ( $imgs['restore'] as $src => $formats ) {
				$formats = array_values( array_intersect( $formats, $linked ) );
				if ( ! $formats ) {
					continue;
				}
				foreach ( [ 'html', 'ucss', 'ccss' ] as $field ) {
					if ( ! empty( $ox[ $field ] ) ) {
						$ox[ $field ] = self::restore_img_links( $ox[ $field ], (string) $src, $formats );
					}
				}
				++$restored;
			}
			if ( $restored ) {
				self::debug( 'Linked ' . $restored . ' unsaved image(s) back to their originals [k] ' . $queue_k );
			}
		}

		if ( ! empty( $ox['ucss'] ) && ! $this->_save_css_con( 'ucss', $ox['ucss'], $v['url_tag'], $v['vary'], $queue_k, $is_mobile, $is_nextgen ) ) {
			return false;
		}

		if ( ! empty( $ox['ccss'] ) && ! $this->_save_css_con( 'ccss', $ox['ccss'], $v['url_tag'], $v['vary'], $queue_k, $is_mobile, $is_nextgen ) ) {
			return false;
		}

		if ( ! $this->_save_con( $ox['html'], $assets, $queue_k, $is_mobile, $is_nextgen, $v ) ) {
			return false;
		}

		// The build is ready: count its images in Image Optimization's summary,
		// where Destroy All Optimization Data clears them and Soft Reset leaves them.
		if ( $img_count ) {
			Img_Optm::save_summary( [ Img_Optm::SUMMARY_OX_IMGS => self::ox_img_count() + $img_count ], true );
		}

		if ( isset( $ox['vpi'] ) ) {
			$this->_save_vpi( $ox['vpi'], $v, $is_mobile );
		}

		return true;
	}

	/**
	 * The page's kept UCSS, CCSS and VPI, for a build that reuses them.
	 *
	 * Each is sent only when it exists: a missing one is generated as usual. A
	 * UCSS or CCSS file holding only a generation error comment is left out too.
	 *
	 * @since 8.0
	 *
	 * @param array $v Queue item.
	 * @return array `ucss`, `ccss` (CSS text), `vpi` (image names); any of them.
	 */
	private function _kept_assets( $v ) {
		$kept = [];

		foreach ( [ 'ucss', 'ccss' ] as $type ) {
			$filename = $this->cls( 'Data' )->load_url_file( $v['url_tag'], $v['vary'], $type );
			$file     = $filename ? LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( $type ) . $filename . '.css' : '';
			$css      = $file && file_exists( $file ) ? trim( (string) File::read( $file ) ) : '';
			if ( '' !== $css && ! ( '/*' === substr( $css, 0, 2 ) && '*/' === substr( $css, -2 ) ) ) {
				$kept[ $type ] = $css;
			}
		}

		$post_id = Optimax_Pages::post_id( ! empty( $v['url'] ) ? $v['url'] : $v['url_tag'] );
		if ( $post_id ) {
			$vpi = Utility::sanitize_lines( get_post_meta( $post_id, ! empty( $v['is_mobile'] ) ? VPI::POST_META_MOBILE : VPI::POST_META, true ), 'basename' );
			// Not the placeholders a VPI result can hold: `data:` URIs and its "empty result".
			$vpi = array_filter(
				$vpi,
				function ( $name ) {
					return '' !== $name && 0 !== strpos( $name, 'data:' ) && 'empty result' !== $name;
				}
			);
			if ( $vpi ) {
				$kept['vpi'] = array_values( $vpi );
			}
		}

		self::debug( 'Reusing kept ' . ( $kept ? implode( '/', array_keys( $kept ) ) : 'nothing' ) . ' [url] ' . $v['url_tag'] );

		return $kept;
	}

	/**
	 * Save the build's viewport images where VPI keeps them: the page's post meta.
	 *
	 * Only a post or page has one, like for VPI itself; any other page is skipped.
	 *
	 * @since 8.0
	 *
	 * @param mixed $vpi       Viewport image URLs.
	 * @param array $v         Queue item.
	 * @param bool  $is_mobile Mobile build.
	 * @return void
	 */
	private function _save_vpi( $vpi, $v, $is_mobile ) {
		if ( ! is_array( $vpi ) || array_filter( $vpi, 'is_string' ) !== $vpi ) {
			self::debug( '❌ Malformed vpi in data_optimax, not saved' );
			return;
		}

		$url     = ! empty( $v['url'] ) ? $v['url'] : $v['url_tag'];
		$post_id = Optimax_Pages::post_id( $url );
		if ( ! $post_id ) {
			self::debug( 'No post for the page, vpi not saved [url] ' . $url );
			return;
		}

		$name = $is_mobile ? VPI::POST_META_MOBILE : VPI::POST_META;
		$this->cls( 'Metabox' )->save( $post_id, $name, array_map( 'urldecode', $vpi ) );
		self::debug( 'Saved vpi [post_id] ' . $post_id . ' [meta] ' . $name );
	}

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
	public static function page_tag( $url_tag ) {
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
	 * - The URL says so (is_file_url()): a missing file falls through to WordPress
	 *   as a 404, and a plugin can answer a file URL with an HTML document. Either
	 *   way nobody browses to that URL as a page.
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

		// The raw path, not Utility::request_url(): that one appends a trailing slash
		// under pretty permalinks, which hides the extension.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return self::is_file_url( $uri );
	}

	/**
	 * The page identity builds are stored under.
	 *
	 * @since 8.0
	 *
	 * @param string $request_url Current request URL.
	 * @return string
	 */
	public static function get_url_tag( $request_url ) {
		// Dev's filter keys builds by page type; the OptimaX page list cannot track those.
		if ( apply_filters( 'litespeed_optimax_per_pagetype', false ) ) {
			return Utility::page_type();
		}

		return $request_url;
	}

	/**
	 * Whether this request has a build to serve, known before the page renders.
	 *
	 * For Guest Mode, at init: a page with a build is served as is to every
	 * visitor, so the request must not become a guest one (guest vary, tag,
	 * scripts, Guest Optimization). A page without one gets Guest Mode as usual.
	 * Checks what serve() can know this early: the same page identity and vary,
	 * a live build and its assets. serve() still decides at render time
	 * (cacheable, an HTML document, not a 404, the page change check), and may
	 * fall back to the normal page for this one request.
	 *
	 * @since 8.0
	 *
	 * @return bool
	 */
	public function has_build() {
		if ( ! $this->conf( self::O_OPTIMAX ) || ! self::nextgen_ready() || Router::is_logged_in() || self::_is_static_file_request() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $_GET && get_option( 'permalink_structure' ) && Optimax_Pages::has_other_query( array_keys( $_GET ), (array) $this->conf( self::O_CACHE_DROP_QS ) ) ) {
			return false;
		}

		$excludes = apply_filters( 'litespeed_optimax_uri_exc', $this->conf( self::O_OPTIMAX_EXC ) );
		if ( isset( $_SERVER['REQUEST_URI'] ) && REST::str_hit_uri( wp_unslash( $_SERVER['REQUEST_URI'] ), $excludes ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return false;
		}

		$request_url = self::_early_request_url();
		if ( ! $request_url ) {
			return false;
		}

		$url_tag = self::get_url_tag( Optimax_Pages::canonical_url( $request_url ) );
		$vary    = $this->cls( 'Vary' )->finalize_full_varies( true );
		if ( ! Optimax_Pages::version_groups( $vary )['nextgen'] ) {
			return false;
		}

		$filename = $this->cls( 'Data' )->load_url_file( $url_tag, $vary, 'optimax' );
		return $filename && file_exists( LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . $filename . '.html' ) && $this->_assets_intact( $filename );
	}

	/**
	 * The page URL as Utility::request_url() will give it once WordPress has parsed
	 * the request: at init `$wp->request` is still empty, and would name the home page.
	 *
	 * @since 8.0
	 *
	 * @return string Empty when the request has no usable path.
	 */
	private static function _early_request_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}

		// Relative to the home path (a subfolder site: `/one/`).
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = ltrim( $path, '/' );

		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( home_url( $path ) );
		}

		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		return home_url( $path ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Serve optimized page from cache if available.
	 *
	 * Called during buffer finalization as the first priority check.
	 * If ox HTML is found, returns it to skip all other optimization hooks.
	 *
	 * @since 8.0
	 *
	 * @param string $buffer The page WordPress rendered for this request.
	 * @return string|false The optimized HTML content, or false if not available.
	 */
	public function serve( $buffer = '' ) {
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

		// With pretty permalinks the request URL leaves the query out, so `/?s=term` or
		// `/?add-to-cart=1` would read as the listed `/`. Only args LSCache drops too
		// (Drop Query String) leave it the same page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $_GET && get_option( 'permalink_structure' ) && Optimax_Pages::has_other_query( array_keys( $_GET ), (array) $this->conf( self::O_CACHE_DROP_QS ) ) ) {
			self::debug( 'serve() bypassed: query string' );
			return false;
		}

		// The owner's OptimaX URI Excludes.
		$excludes = apply_filters( 'litespeed_optimax_uri_exc', $this->conf( self::O_OPTIMAX_EXC ) );
		$hit      = isset( $_SERVER['REQUEST_URI'] ) ? REST::str_hit_uri( wp_unslash( $_SERVER['REQUEST_URI'] ), $excludes ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $hit ) {
			self::debug( 'serve() bypassed: hit OptimaX URI Excludes: ' . $hit );
			return false;
		}

		// A page whose post is gone renders the 404 page: not a page to optimize.
		if ( is_404() ) {
			self::debug( 'serve() bypassed: 404' );
			return false;
		}

		// One spelling per page: a visitor may spell its escapes another way.
		$pages           = $this->cls( 'Optimax_Pages' );
		$filepath_prefix = $this->_build_filepath_prefix( 'optimax' );
		$url_tag         = self::get_url_tag( Optimax_Pages::canonical_url( $request_url ) );
		// Guest Mode plays no part in a page's OptimaX versions: a guest request and
		// a visitor's are the same page (QUIC.cloud renders it as neither), so both
		// share one build, and turning Guest Mode on or off makes no new versions.
		$vary = $this->cls( 'Vary' )->finalize_full_varies( true );

		// Next-gen versions only (desktop and mobile): every browser takes AVIF or WebP.
		// A visitor that does not (a bot, a monitor) gets the page without OptimaX,
		// and no QUIC.cloud request is spent on a version nobody browses.
		if ( ! Optimax_Pages::version_groups( $vary )['nextgen'] ) {
			self::debug( 'serve() bypassed: visitor without next-gen image support' );
			return false;
		}

		$filename = $this->cls( 'Data' )->load_url_file( $url_tag, $vary, 'optimax' );

		// Tag every render OptimaX could replace, on both the hit and the queue path,
		// so a finished build can evict it. Keyed on the page rather than going through
		// Tag::get_uri_tag(), which (a) switches between a plain and an md5 format with
		// LSCWP_LOG — decided per request, so a visitor and the cron that purges can
		// disagree — and (b) urldecodes at render but not at purge.
		Tag::add( self::page_tag( $url_tag ) );

		if ( $filename && $this->_assets_intact( $filename ) ) {
			$static_file = LITESPEED_STATIC_DIR . $filepath_prefix . $filename . '.html';

			if ( file_exists( $static_file ) ) {
				$html = File::read( $static_file );
				if ( $html ) {
					$html = $this->_sync( $html, $buffer, $url_tag, $vary );
					if ( null === $html ) {
						// The page changed in this render; the next one confirms it or not.
						Core::comment( 'OX: page has changed, checking the differences' );
						return false;
					}
					if ( false === $html && $this->_queue_sync( $request_url, $url_tag, $vary, $buffer, $filename ) ) {
						// QUIC.cloud carries the change into the build, or builds this version
						// when it cannot. Visitors get the page without OptimaX meanwhile.
						Core::comment( 'OX sync queued: page changed' );
						return false;
					}
					if ( false === $html ) {
						// The design changed: rebuild. Visitors get the page without OptimaX meanwhile.
						$pages->note_check( $url_tag, $vary, 'rebuild' );
						$pages->expire( $pages->url_id( $url_tag ) );
						$pages->clear_kept( $url_tag );
						// Its other versions are still cached with their OptimaX HTML; drop them too.
						Purge::add( self::page_tag( $url_tag ) );
						$this->_queue_version( $request_url, $url_tag, $vary, false, false, $buffer );
						Core::comment( 'OX rebuild queued: page changed' );
						return false;
					}
					$this->_maybe_rebuild( $request_url, $url_tag, $vary, $buffer );
					self::debug( 'serve() hit: ' . $filepath_prefix . $filename . '.html' );
					Core::comment( 'OX served ✅' );
					return $html;
				}
				self::debug( 'serve() empty file: ' . $static_file );
			} else {
				self::debug( 'serve() file missing: ' . $static_file );
			}
		}

		// No build in use (never built, or its design changed): queue one. A version
		// whose sync snapshot is still kept is queued as that sync.
		$sync = '' !== $pages->sync_source( $url_tag, $vary );
		if ( $this->_queue_version( $request_url, $url_tag, $vary, ! $sync && $this->_same_design( $buffer, $url_tag, $vary ), $sync, $buffer ) ) {
			Core::comment( 'OX in queue' );
		}

		return false;
	}

	/**
	 * Queue a sync of this version: QUIC.cloud carries the page change into its build.
	 *
	 * Needs the render the build matches, kept with the fingerprint since the build's
	 * first serve or its last patch. The snapshot (Optimax_Pages::save_sync()) holds
	 * it with this render and the build's name; the version then stops serving that
	 * build, as for a rebuild, while the page's other versions keep theirs and
	 * compare their own next render. A sync the queue cannot take now (a crawler, a
	 * full queue) is queued from the snapshot on the version's next visit.
	 *
	 * @since 8.0
	 *
	 * @param string $request_url Request URL.
	 * @param string $url_tag     Page identity.
	 * @param string $vary        Raw vary.
	 * @param string $buffer      This render, which showed the change.
	 * @param string $filename    The build serving this version.
	 * @return bool Whether a sync replaces the rebuild; false leaves the caller to rebuild.
	 */
	private function _queue_sync( $request_url, $url_tag, $vary, $buffer, $filename ) {
		$pages = $this->cls( 'Optimax_Pages' );
		$fp    = $pages->load_fingerprint( $url_tag, $vary );
		if ( ! $fp || '' === $fp['base'] || '' === (string) $buffer || ! $filename ) {
			return false;
		}
		// The build links the stylesheet URLs of its own render: show QUIC.cloud this
		// render with a `?ver=` bump over an unchanged file undone, or it refuses the sync.
		$now = Optimax_Sync::align_stylesheets( Optimax_Pages::unpack_base( $fp['base'] ), $buffer );
		if ( ! $pages->save_sync( $url_tag, $vary, $fp['base'], $now, $filename ) ) {
			self::debug( '❌ Sync snapshot not saved, rebuilding instead [url] ' . $url_tag );
			return false;
		}

		if ( $this->_queue_version( $request_url, $url_tag, $vary, false, true ) ) {
			self::debug( 'Sync queued [url] ' . $url_tag );
		}
		$pages->note_check( $url_tag, $vary, 'sync' );
		$pages->expire_version( $url_tag, $vary );

		return true;
	}

	/**
	 * Whether a page without a build may send its kept UCSS, CCSS and VPI with the build request.
	 *
	 * They live in their own url_file rows and post meta, so they outlive the
	 * build and a Purge All OptimaX. Without a fingerprint (cleared, or never
	 * built) they are taken as they are. With one, left by a build whose design
	 * changed and expired, this render is compared with it: another design
	 * clears the UCSS and CCSS, and the fingerprint, so later renders do not
	 * compare again. A version already queued is not fingerprinted again.
	 *
	 * @since 8.0
	 *
	 * @param string $buffer  This render.
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return bool
	 */
	private function _same_design( $buffer, $url_tag, $vary ) {
		if ( isset( $this->load_queue( 'optimax' )[ Optimax_Pages::vary_key( $vary ) . ' ' . $url_tag ] ) ) {
			return false;
		}

		$old = $this->cls( 'Optimax_Pages' )->load_fingerprint( $url_tag, $vary );
		if ( ! $old ) {
			self::debug( 'Kept UCSS/CCSS/VPI reused: no fingerprint to compare with' );
			return true;
		}
		if ( '' === (string) $buffer ) {
			return false;
		}

		$now = Optimax_Sync::fingerprint( $buffer );
		if ( ! $now ) {
			return false;
		}
		if ( $old['design'] !== $now['design'] ) {
			// Changed while the page had no build: no build to expire, but they no longer fit.
			$pages = $this->cls( 'Optimax_Pages' );
			$pages->clear_kept( $url_tag );
			$pages->drop_fingerprint( $url_tag, $vary );
			return false;
		}

		self::debug( 'Kept UCSS/CCSS/VPI reused: same design' );
		return true;
	}

	/**
	 * Queue this request's version of a page for a build.
	 *
	 * A version already queued is left as it is: QUIC.cloud may be building it. The
	 * LiteSpeed crawler never queues: it warms the cache, and would put the whole site
	 * in the queue at once.
	 *
	 * @since 8.0
	 *
	 * @param string $request_url Request URL.
	 * @param string $url_tag     Page identity.
	 * @param string $vary        Raw vary.
	 * @param bool   $reuse       Send the page's UCSS, CCSS and VPI with the request: its design has not changed since they were made.
	 * @param bool   $sync        Ask QUIC.cloud to carry a page change into the version's build (its sync snapshot).
	 * @param string $buffer      This request's render, kept as the render the build is compared with (_record_build_render()); empty when there is none.
	 * @return bool Whether the version is queued.
	 */
	private function _queue_version( $request_url, $url_tag, $vary, $reuse, $sync = false, $buffer = '' ) {
		if ( ! $this->queueable_request() ) {
			return false;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $ua, Crawler::FAST_USER_AGENT ) || false !== strpos( $ua, Crawler::USER_AGENT ) ) {
			self::debug( 'Not queued: crawler request' );
			return false;
		}

		$this->_queue = $this->load_queue( 'optimax' );

		$queue_k = ( strlen( $vary ) > 32 ? md5( $vary ) : $vary ) . ' ' . $url_tag;
		$item    = [
			'url'        => apply_filters( 'litespeed_optimax_url', $request_url ),
			'is_mobile'  => $this->_separate_mobile(),
			'is_nextgen' => $this->cls( 'Media' )->webp_support(),
			'uid'        => get_current_user_id(),
			'vary'       => $vary,
			'url_tag'    => $url_tag,
			'reuse'      => $reuse ? 1 : 0,
			'sync'       => $sync ? 1 : 0,
		];
		// A version already queued stays as it is: QUIC.cloud may be building it.
		if ( ! isset( $this->_queue[ $queue_k ] ) && ! $this->_enqueue( $queue_k, $item, $request_url ) ) {
			return false;
		}
		// The other device's version too.
		$this->cls( 'Optimax_Pages' )->add_versions( $this->_queue, $item, $this->_max_queue_size() );
		$this->save_queue( 'optimax', $this->_queue );
		self::debug( 'Added Optimax queue item [request] ' . substr( hash( 'sha256', $queue_k ), 0, 12 ) );
		if ( '' !== (string) $buffer ) {
			$this->cls( 'Optimax_Pages' )->save_queued_render( $url_tag, $vary, $buffer );
		}

		// Prepare cache tag for later purge
		Tag::add( 'OPTIMAX.' . md5( $queue_k ) );

		return true;
	}

	/**
	 * The queue size limit, for code queueing versions outside this class.
	 *
	 * @since 8.0
	 *
	 * @return int
	 */
	public function max_queue_size() {
		return $this->_max_queue_size();
	}

	/**
	 * Queue a rebuild of a build older than the Rebuild Interval. It keeps being served meanwhile.
	 *
	 * Its age is counted from its first serve (the fingerprint's `built`), which patches keep.
	 *
	 * @since 8.0
	 *
	 * @param string $request_url Request URL.
	 * @param string $url_tag     Page identity.
	 * @param string $vary        Raw vary.
	 * @param string $buffer      This render, which the build still matches.
	 * @return void
	 */
	private function _maybe_rebuild( $request_url, $url_tag, $vary, $buffer ) {
		$ttl = (int) $this->conf( self::O_OPTIMAX_TTL );
		if ( $ttl <= 0 ) {
			return;
		}

		$fp = $this->cls( 'Optimax_Pages' )->load_fingerprint( $url_tag, $vary );
		if ( ! $fp || empty( $fp['built'] ) || time() - (int) $fp['built'] < $ttl ) {
			return;
		}

		if ( $this->_queue_version( $request_url, $url_tag, $vary, true, false, $buffer ) ) {
			self::debug( 'Rebuild queued: build older than ' . $ttl . 's' );
		}
	}

	/**
	 * Bring a build in step with this render of its page.
	 *
	 * Compares this render with the fingerprint of the render the build matches.
	 * Same design and content: the build as is. Any other change, of the design or
	 * of the content (text, links, images, meta, prices), once a second render
	 * confirms it, goes to QUIC.cloud, which carries it into the build (a sync) or
	 * builds the page again. No fingerprint yet (the build's first serve): this
	 * render becomes it.
	 *
	 * @since 8.0
	 *
	 * @param string $html    Stored OptimaX HTML.
	 * @param string $buffer  This render.
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return string|false|null The HTML to serve; false when a change is confirmed; null while a first sighting waits for the next render (_confirm()).
	 */
	private function _sync( $html, $buffer, $url_tag, $vary ) {
		$now = '' !== (string) $buffer ? Optimax_Sync::fingerprint( $buffer ) : null;
		if ( ! $now ) {
			self::debug( 'sync skipped: render not readable' );
			return $html;
		}

		$pages = $this->cls( 'Optimax_Pages' );
		$old   = $pages->load_fingerprint( $url_tag, $vary );
		if ( ! $old ) {
			$now['base'] = Optimax_Pages::pack_base( $buffer );
			$pages->save_fingerprint( $url_tag, $vary, $now, 0 );
			// A check left open against a fingerprint of an older format has nothing to confirm against now.
			$pages->end_check( $url_tag, $vary );
			self::debug( 'sync: fingerprint recorded' );
			return $html;
		}

		if ( $old['design'] !== $now['design'] ) {
			$where = ( count( $old['lines'] ) - count( $now['lines'] ) ) . ' line(s) removed at the end';
			foreach ( $now['lines'] as $i => $hash ) {
				if ( ! isset( $old['lines'][ $i ] ) || $old['lines'][ $i ] !== $hash ) {
					$where = 'at line ' . $i . ': ' . substr( $now['skel'][ $i ], 0, 300 );
					break;
				}
			}
			self::debug( 'sync: design changed ' . $where );
			return $this->_confirm( $old, $now['design'], $url_tag, $vary, 'design: ' . $where );
		}

		// Only per-render values differed (an image's id): the build as is.
		$changed = Optimax_Sync::changed_item( $old['items'], $now['items'] );
		if ( null === $changed ) {
			// Back to what the build matches: a change seen before was a one-off. Cleared
			// only here, so a content change the next render repeats can still be confirmed.
			if ( ! empty( $old['pending'] ) ) {
				$old['pending'] = '';
				$pages->save_fingerprint( $url_tag, $vary, $old, (int) $old['patches'], (int) $old['built'] );
				$pages->end_check( $url_tag, $vary );
				self::debug( 'sync: the changed render was a one-off; the build stays' );
			}
			return $html;
		}

		self::debug( 'sync: content changed: ' . $changed );
		return $this->_confirm( $old, 'content:' . Optimax_Sync::content_sig( $now['items'] ), $url_tag, $vary, 'content: ' . $changed );
	}

	/**
	 * Whether a page change is confirmed: the render before this one saw it too.
	 *
	 * Some plugins render the page differently once, right after a save, while their
	 * own caches rebuild (Elementor's element cache, Neve's font list), then go back.
	 * So the first render showing a change is served without OptimaX, cached for a
	 * minute only, and the change is remembered. A second render showing it confirms
	 * it; one matching the build again clears it (_sync()).
	 *
	 * @since 8.0
	 *
	 * @param array  $old     Stored fingerprint.
	 * @param string $sig     The change seen: the new design, or an unplaceable content change in it.
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $what    The change, for the OptimaX page's list of checks.
	 * @return false|null False when confirmed (the page needs a refresh), null while waiting.
	 */
	private function _confirm( $old, $sig, $url_tag, $vary, $what ) {
		if ( isset( $old['pending'] ) && $old['pending'] === $sig ) {
			self::debug( 'sync: change confirmed by a second render' );
			return false;
		}

		$old['pending'] = $sig;
		$pages          = $this->cls( 'Optimax_Pages' );
		$pages->save_fingerprint( $url_tag, $vary, $old, (int) $old['patches'], (int) $old['built'] );
		$pages->note_check( $url_tag, $vary, 'confirming', $what );
		Control::set_custom_ttl( 60, 'OptimaX: confirm a page change' );
		// The cache-control header usually went out from the footer hook already; send it again with the short TTL.
		if ( defined( 'LITESPEED_DID_send_headers' ) && ! headers_sent() ) {
			header( $this->cls( 'Control' )->output() );
		}
		self::debug( 'sync: waiting for a second render to confirm the change' );

		return null;
	}

	/**
	 * Whether every file this build's HTML depends on still exists.
	 *
	 * The HTML and its assets are separate files with separate lifetimes: a purge
	 * can empty `wp-content/litespeed/optimax/`, the JS bundle and used CSS are
	 * named by content md5 and shared by every page with the same content (and
	 * deleted with the last build naming them), and the next-gen images live
	 * beside their originals, where an Image Optimization reset, a media cleanup
	 * or a migration can remove them. Serving HTML that names a missing file
	 * costs the visitor that asset; treating it as a miss costs one rebuild.
	 *
	 * The stylesheet is the most damaging. OptiMax strips the page's own
	 * `<link rel=stylesheet>` tags, so apart from the small inlined critical CSS
	 * the used CSS is the page's only styling.
	 *
	 * The build's manifest (Optimax_Pages::save_assets()) lists those files. It is
	 * written for every build before the build goes live, so a build without one
	 * is refused too. An empty `js` or `css` means the service never sent that
	 * asset (the used CSS then arrives inlined in the HTML). An asset that was
	 * sent but could not be pulled or verified never gets this far: the result
	 * is then not stored at all.
	 *
	 * @since 8.0
	 *
	 * @param string $filename Build HTML filename, without extension.
	 * @return bool
	 */
	private function _assets_intact( $filename ) {
		$assets = $this->cls( 'Optimax_Pages' )->load_assets( $filename );
		if ( ! $assets ) {
			self::debug( 'serve() bypassed: no asset manifest for ' . $filename );
			return false;
		}

		$dir = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' );
		foreach ( [ $assets['js'], $assets['css'] ] as $file ) {
			if ( $file && ! file_exists( $dir . $file ) ) {
				self::debug( 'serve() bypassed: missing ' . $dir . $file );
				return false;
			}
		}

		// The images this build saved beside their originals. The HTML names each one
		// at its predicted `<src>.<format>` path. Images the site had already optimized
		// are not in the list: the build only links to them.
		foreach ( $assets['imgs'] as $img ) {
			if ( ! is_string( $img ) || ! file_exists( $img ) ) {
				self::debug( 'serve() bypassed: image missing ' . ( is_string( $img ) ? $img : '' ) );
				return false;
			}
		}

		return true;
	}

	/**
	 * Put an image's links back on the original, in HTML or CSS.
	 *
	 * Undoes the service's rewrite (`rewriteImageLinks` in QC's apps/optimax),
	 * which appends `.<format>` to the path of every reference to a converted
	 * image. Matching the file name alone undoes it in every form the reference
	 * can take — absolute, protocol-relative, root-relative or relative, query and
	 * fragment kept, in `src`, `srcset`, lazy `data-*`, preloads and CSS `url()`.
	 *
	 * It can also reach a same-named file in another folder, and that is the safe
	 * direction to err in: such an image loses its next-gen link and is served
	 * from the original, which is on disk. A reference left pointing at a file
	 * that was never saved is the damaging one, and this leaves none.
	 *
	 * @since 8.0
	 *
	 * @param string $text    HTML or CSS.
	 * @param string $src     The image as the service reported it (`/wp-content/uploads/a.jpg`).
	 * @param array  $formats Formats to undo (`webp`, `avif`).
	 * @return string
	 */
	public static function restore_img_links( $text, $src, $formats ) {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$name = false === strrpos( $path, '/' ) ? $path : substr( $path, strrpos( $path, '/' ) + 1 );
		if ( '' === $name ) {
			return $text;
		}

		// The reference may be encoded even when the service reported it decoded.
		$forms = array_unique( [ $name, rawurldecode( $name ), rawurlencode( rawurldecode( $name ) ) ] );
		foreach ( $formats as $format ) {
			foreach ( $forms as $form ) {
				$text = str_replace( $form . '.' . $format, $form, $text );
			}
		}

		return $text;
	}

	/**
	 * Download and save optimized images locally.
	 *
	 * Each image entry has src and any requested ori/webp/avif artifact.
	 * Optimized images are saved beside their WordPress image targets.
	 *
	 * A failed fetch or digest check fails the whole result: nothing is stored and
	 * the previous build keeps serving. An entry skipped for want of an artifact
	 * URL or a WordPress target is reported under `restore` with the next-gen
	 * formats it did not get, so the caller can put its links back on the original.
	 *
	 * @since 8.0
	 *
	 * @param array $imgs Array of image optimization data.
	 * @return array|false `[ 'saved' => local paths of the next-gen files written,
	 *                     'restore' => [ src => formats not saved ],
	 *                     'count' => images with at least one file saved ]`, or false.
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

		$saved   = [];
		$restore = [];
		$count   = 0;
		foreach ( $imgs as $img ) {
			if ( ! is_array( $img ) ) {
				return false;
			}
			$src = ! empty( $img['src'] ) && is_string( $img['src'] ) && 2048 >= strlen( $img['src'] ) ? $img['src'] : '';

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
				if ( $src ) {
					self::debug( 'Skip Optimax image entry without an artifact URL; linked back to the original.' );
					$restore[ $src ] = [ 'webp', 'avif' ];
				}
				continue;
			}
			if ( ! $src ) {
				self::debug( 'Skip Optimax image entry without a usable local source.' );
				continue;
			}
			$local = $this->_image_target( $img );
			if ( ! $local ) {
				self::debug( 'Skip Optimax image entry without a WordPress image target; linked back to the original.' );
				$restore[ $src ] = [ 'webp', 'avif' ];
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
				if ( 'ori' !== $type ) {
					$saved[] = $target;
				}
			}
			if ( $published ) {
				++$count;
			}
			// A format the service sent no URL for has no file beside the original.
			$unsaved = array_values( array_diff( [ 'webp', 'avif' ], array_keys( $published ) ) );
			if ( $unsaved ) {
				$restore[ $src ] = $unsaved;
			}
			if ( $row ) {
				foreach ( $hooks as $type => $hook ) {
					if ( isset( $published[ $type ] ) ) {
						do_action( $hook, $row, $published[ $type ] );
					}
				}
			}
		}

		return [
			'saved'   => $saved,
			'restore' => $restore,
			'count'   => $count,
		];
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
	 * The digest the service sent for a JS or CSS sidecar, or null when it sent none.
	 *
	 * @since 8.0
	 *
	 * @param array  $ox  data_optimax payload.
	 * @param string $key `js_sha256` or `css_sha256`.
	 * @return mixed
	 */
	private static function _sidecar_sha256( $ox, $key ) {
		return isset( $ox[ $key ] ) && '' !== $ox[ $key ] ? $ox[ $key ] : null;
	}

	/**
	 * Fetch the body of a JS or CSS sidecar the OX service links.
	 *
	 * Held to the rules the images are: the bundle runs as the site's own script,
	 * so it may only come from an https QUIC.cloud URL (Img::normalize_cloud_url),
	 * over a verified TLS connection like every other QUIC.cloud request, without
	 * redirects, within the same size bound, and matching the SHA-256 the service
	 * sent for it (Img::matches_sum).
	 *
	 * @since 8.0
	 *
	 * @param string $url    Remote URL.
	 * @param mixed  $sha256 Hex SHA-256 of the body, or null when the service sent none.
	 * @return string|false Body on success, false when refused or not verified.
	 */
	private function _fetch_con( $url, $sha256 ) {
		$url = Img::normalize_cloud_url( $url );
		if ( ! $url ) {
			self::debug( '❌ Refused sidecar URL: not an https QUIC.cloud artifact' );
			return false;
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 60,
				'redirection'         => 0,
				'limit_response_size' => File::REMOTE_MAX_BYTES + 1,
			]
		);

		if ( is_wp_error( $response ) ) {
			self::debug( 'Failed to fetch ' . $url . ': ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== $code || ! $body || File::REMOTE_MAX_BYTES < strlen( $body ) ) {
			self::debug( 'Unusable response [code] ' . $code . ' [bytes] ' . strlen( (string) $body ) . ' [url] ' . $url );
			return false;
		}

		// TODO(qcos-afhm): refuse a sidecar without a digest once every Worker sends
		// `js_sha256`/`css_sha256`. Until then a result from an older Worker carries
		// none, and is accepted on the URL policy alone.
		if ( null === $sha256 ) {
			self::debug( 'Sidecar has no digest; accepted on URL policy [url] ' . $url );
			return $body;
		}

		if ( ! Img::matches_sum( $body, $sha256, 'sha256' ) ) {
			self::debug( '❌ Sidecar digest mismatch [url] ' . $url );
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
	 * @param string $js_url  Remote URL of the optimized JS.
	 * @param mixed  $sha256  Hex SHA-256 of the bundle, or null when the service sent none.
	 * @param string $queue_k Queue key.
	 * @return string|false Public URL of the stored bundle, or false on failure.
	 */
	private function _save_js( $js_url, $sha256, $queue_k ) {
		$con = $this->_fetch_con( $js_url, $sha256 );
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

		Purge::add( 'JS.' . md5( $queue_k ) );

		return LITESPEED_STATIC_URL . $filepath_prefix . $filecon_md5 . '.js';
	}

	/**
	 * Download the used CSS and store it as a local static file.
	 *
	 * The used CSS used to arrive inline; with the external-stylesheet flag on, the
	 * service links it from the worker's artifact origin instead, which sweeps its
	 * files after a couple of days, so leaving that href in place loses the page's
	 * styles shortly after. Same fix as the JS bundle: fetch the body once, keep it
	 * beside the stored HTML, hand back the local URL.
	 *
	 * @since 8.0
	 *
	 * @param string $css_url Remote URL of the used CSS.
	 * @param mixed  $sha256  Hex SHA-256 of the stylesheet, or null when the service sent none.
	 * @param string $queue_k Queue key.
	 * @param array  $v       Queue item.
	 * @return string|false Public URL of the stored stylesheet, or false on failure.
	 */
	private function _save_css( $css_url, $sha256, $queue_k, $v ) {
		$con = $this->_fetch_con( $css_url, $sha256 );
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

		// Evict the pages this stylesheet belongs to, by the tag serve() gives every
		// OptimaX render. _save_con() purges the same tag a moment later and Purge
		// dedupes, so this only matters if the two steps ever drift apart.
		Purge::add( self::page_tag( $v['url_tag'] ), true );

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
	 * @param array  $assets     Files the HTML depends on, for its manifest (Optimax_Pages::save_assets()).
	 * @param string $queue_k    The queue key.
	 * @param bool   $is_mobile  Whether is mobile.
	 * @param string $is_nextgen Next-gen image format ('webp', 'avif', or '').
	 * @param array  $v          Queue item.
	 * @return bool
	 */
	private function _save_con( $content, $assets, $queue_k, $is_mobile, $is_nextgen, $v ) {
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

		// Before the build goes live: _assets_intact() refuses a build without one.
		if ( ! $this->cls( 'Optimax_Pages' )->save_assets( $filecon_md5, $assets ) ) {
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

		$this->_record_build_render( $url_tag, $vary );

		Purge::add( 'OPTIMAX.' . md5( $queue_k ) );
		Purge::add( self::page_tag( $url_tag ), true );

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

	/**
	 * Record the render a new build is compared with, so a page change made while it
	 * was being built is still seen.
	 *
	 * QUIC.cloud builds from its own read of the page, which LiteSpeed renders without
	 * page optimizations (`before_optm`): not comparable with a visitor's render. So
	 * the fingerprint is taken from a visitor render as close to that read as there is:
	 * the one that showed a synced change (a synced build is that render; a full
	 * build after a refused sync was read right after it), else the one the build was
	 * requested with. Without either, the build's first serve records its render, as
	 * before; a change made between the request and that serve then goes unseen.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return void
	 */
	private function _record_build_render( $url_tag, $vary ) {
		$pages  = $this->cls( 'Optimax_Pages' );
		$queued = $pages->take_queued_render( $url_tag, $vary );
		$synced = $pages->sync_render( $url_tag, $vary );
		$kept   = '' !== $synced['render'] ? $synced : $queued;
		$render = $kept['render'];

		// The fingerprint taken when the render was made: fingerprinting it now would
		// read today's stylesheet files, and miss a CSS change made during the build.
		$fp = $kept['fp'];
		if ( ! $fp && '' !== $render ) {
			// Kept by an older plugin, without one.
			$fp = Optimax_Sync::fingerprint( $render );
		}
		if ( ! $fp ) {
			$pages->drop_fingerprint( $url_tag, $vary );
			return;
		}

		$fp['base'] = Optimax_Pages::pack_base( $render );
		$pages->save_fingerprint( $url_tag, $vary, $fp, 0 );
		self::debug( 'sync: fingerprint recorded from the render ' . ( '' !== $synced['render'] ? 'that showed the synced change' : 'the build was requested with' ) );
	}
}
