<?php
/**
 * OptimaX builds per page: their fingerprints, expiry, stats and rebuilds.
 *
 * @since   8.0
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * OptimaX pages.
 *
 * Every cacheable page can get OptimaX: there is no list. A page is its
 * `litespeed_url` row; its versions are its OptimaX build rows (one per vary)
 * and its queue rows.
 *
 * @since 8.0
 */
class Optimax_Pages extends Root {

	const LOG_TAG = '🚀';

	/**
	 * Suffix of a build's asset manifest, beside its `<md5>.html`.
	 */
	const ASSETS_EXT = '.assets.json';

	/**
	 * How long a finished page-change check stays listed, in seconds.
	 */
	const CHECK_KEEP = 86400;

	/**
	 * Most page-change checks listed; the oldest go first.
	 */
	const CHECK_MAX = 100;

	/**
	 * One spelling per URL: bytes outside a URL's character set (raw UTF-8, say)
	 * percent-encoded, every escape upper-cased.
	 *
	 * WordPress links a post as `caf%c3%a9`, a browser sends a typed address as
	 * `caf%C3%A9`; both are one page, so one set of builds.
	 *
	 * @since 8.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function canonical_url( $url ) {
		$url = preg_replace_callback(
			'/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?#%\[\]]/',
			function ( $m ) {
				return rawurlencode( $m[0] );
			},
			(string) $url
		);

		return preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			function ( $m ) {
				return strtoupper( $m[0] );
			},
			$url
		);
	}

	/**
	 * Whether a query has an arg other than those LSCache drops.
	 *
	 * With pretty permalinks a page URL leaves the query out, so `/?s=term` would
	 * read as the front page. Only args in Drop Query String (`*` matching any
	 * characters, as there) keep it the same page.
	 *
	 * @since 8.0
	 *
	 * @param array $keys    Query arg names.
	 * @param array $drop_qs Drop Query String patterns.
	 * @return bool
	 */
	public static function has_other_query( $keys, $drop_qs ) {
		foreach ( (array) $keys as $key ) {
			$dropped = false;
			foreach ( (array) $drop_qs as $pattern ) {
				if ( preg_match( '/^' . str_replace( '\*', '.*', preg_quote( (string) $pattern, '/' ) ) . '\z/', (string) $key ) ) {
					$dropped = true;
					break;
				}
			}
			if ( ! $dropped ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A vary in the form build rows store it (md5 when longer than 32 chars).
	 *
	 * @since 8.0
	 *
	 * @param string $vary Raw vary.
	 * @return string
	 */
	public static function vary_key( $vary ) {
		$vary = (string) $vary;
		return strlen( $vary ) > 32 ? md5( $vary ) : $vary;
	}

	/**
	 * Which visitor groups a version's vary (its cache key) stands for.
	 *
	 * Read from the vary's `ismobile`/`webp`/`avif` tokens. A build row stores a
	 * long vary as its md5, which has no tokens; its `mobile`/`webp` columns are
	 * used then.
	 *
	 * @since 8.0
	 *
	 * @param string    $vary   Raw vary (queue row) or stored vary (build row).
	 * @param bool|null $mobile Build row `mobile` column, used only for a hashed vary.
	 * @param bool|null $webp   Build row `webp` column, used only for a hashed vary.
	 * @return array{mobile:bool,nextgen:bool}
	 */
	public static function version_groups( $vary, $mobile = null, $webp = null ) {
		$vary = (string) $vary;
		if ( preg_match( '/^[a-f0-9]{32}$/', $vary ) ) {
			return [
				'mobile'  => (bool) $mobile,
				'nextgen' => (bool) $webp,
			];
		}

		$tokens = explode( '+', $vary );
		return [
			'mobile'  => in_array( 'ismobile', $tokens, true ),
			'nextgen' => (bool) array_intersect( [ 'webp', 'avif' ], $tokens ),
		];
	}

	/**
	 * The vary of the same version on the other device: desktop <-> mobile.
	 *
	 * The server's rewrite rules append `+ismobile`, then `+webp` (for AVIF too), to
	 * whatever vary cookies the request carries, so `+webp` <-> `+ismobile+webp`.
	 *
	 * @since 8.0
	 *
	 * @param string $vary Raw vary.
	 * @return string
	 */
	public static function device_sibling( $vary ) {
		$vary = (string) $vary;
		if ( false !== strpos( $vary, '+ismobile' ) ) {
			return str_replace( '+ismobile', '', $vary );
		}
		foreach ( [ '+webp', '+avif' ] as $token ) {
			$pos = strpos( $vary, $token );
			if ( false !== $pos ) {
				return substr_replace( $vary, '+ismobile', $pos, 0 );
			}
		}
		return $vary . '+ismobile';
	}

	/**
	 * Add a version's queue row, and its other device's when that one needs a rebuild.
	 *
	 * A new page is added for the device the visit came from only. When the design
	 * changed, every version expired: the other device is queued with it, or it would
	 * stay without OptimaX until someone on it happens to visit.
	 *
	 * @since 8.0
	 *
	 * @param array $queue Queue, changed in place.
	 * @param array $row   Queue row: `url`, `is_mobile`, `is_nextgen`, `uid`, `vary`, `url_tag`.
	 * @param int   $max   Queue size limit.
	 * @return string|false Queue key of the version asked for; false when the queue is full.
	 */
	public function add_versions( &$queue, $row, $max ) {
		$queue_k = self::vary_key( $row['vary'] ) . ' ' . $row['url_tag'];
		if ( ! isset( $queue[ $queue_k ] ) ) {
			if ( count( $queue ) >= $max ) {
				self::debug( 'Queue is full - ' . $max );
				return false;
			}
			$queue[ $queue_k ] = $row;
		}

		if ( $this->conf( Base::O_CACHE_MOBILE ) ) {
			$sibling              = $row;
			$sibling['vary']      = self::device_sibling( $row['vary'] );
			$sibling['is_mobile'] = ! $row['is_mobile'];
			$sibling['uid']       = 0;
			$sibling['reuse']     = 0;
			$sibling['sync']      = 0;
			$sibling_k            = self::vary_key( $sibling['vary'] ) . ' ' . $row['url_tag'];
			if ( ! isset( $queue[ $sibling_k ] ) && count( $queue ) < $max && $this->_needs_rebuild( $row['url_tag'], $sibling['vary'] ) ) {
				$queue[ $sibling_k ] = $sibling;
				self::debug( 'Queued the other device too [vary] ' . $sibling['vary'] );
			}
		}

		return $queue_k;
	}

	/**
	 * Whether a version was built before and all its builds expired.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Vary, raw or stored.
	 * @return bool
	 */
	private function _needs_rebuild( $url_tag, $vary ) {
		global $wpdb;

		$url_id = $this->url_id( $url_tag );
		if ( ! $url_id ) {
			return false;
		}

		if ( strlen( $vary ) > 32 ) {
			$vary = md5( $vary );
		}

		$tb  = Data::cls()->tb( 'url_file' );
		$min = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(expired) FROM `$tb` WHERE url_id = %d AND vary = %s AND type = %d", $url_id, $vary, Data::cls()->file_type_id( 'optimax' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null !== $min && (int) $min > 0;
	}

	/**
	 * The `litespeed_url` row id of a page, or 0.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @return int
	 */
	public function url_id( $url_tag ) {
		global $wpdb;

		$tb = Data::cls()->tb( 'url' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$tb` WHERE url = %s", $url_tag ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Stop serving a page's builds: its design changed.
	 *
	 * Uses the expiry Data::save_url() gives a replaced build, so load_url_file()
	 * stops returning them at once and visitors get the page without OptimaX. Each
	 * version is queued again on its next visit (serve()), or all at once with
	 * rebuild_all(). clean_expired() deletes them once the rebuild is stored and
	 * their grace period is over.
	 *
	 * @since 8.0
	 *
	 * @param int $url_id Page row id.
	 * @return void
	 */
	public function expire( $url_id ) {
		global $wpdb;

		$data  = Data::cls();
		$tb    = $data->tb( 'url_file' );
		$types = implode( ',', array_map( [ $data, 'file_type_id' ], [ 'optimax', 'optimax_src' ] ) );
		$until = time() + 86400 * apply_filters( 'litespeed_url_file_expired_days', 20 );

		$q = "UPDATE `$tb` SET expired = %d WHERE url_id = %d AND type IN ($types) AND expired = 0";
		$n = $wpdb->query( $wpdb->prepare( $q, $until, (int) $url_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $n ) {
			self::debug( 'Expired OptimaX builds [url_id] ' . (int) $url_id . ' [rows] ' . (int) $n );
		}
	}

	/**
	 * Stop serving one version's build: its render changed, and a sync for it is queued.
	 *
	 * Like expire(), for one vary only. The page's other versions keep their builds
	 * and compare their own next render: a sync answers one version's change, and the
	 * other device's render may not have changed the same way.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return void
	 */
	public function expire_version( $url_tag, $vary ) {
		global $wpdb;

		$url_id = $this->url_id( $url_tag );
		if ( ! $url_id ) {
			return;
		}

		$data  = Data::cls();
		$tb    = $data->tb( 'url_file' );
		$types = implode( ',', array_map( [ $data, 'file_type_id' ], [ 'optimax', 'optimax_src' ] ) );
		$until = time() + 86400 * apply_filters( 'litespeed_url_file_expired_days', 20 );

		$q = "UPDATE `$tb` SET expired = %d WHERE url_id = %d AND vary = %s AND type IN ($types) AND expired = 0";
		$n = $wpdb->query( $wpdb->prepare( $q, $until, (int) $url_id, self::vary_key( $vary ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $n ) {
			self::debug( 'Expired an OptimaX version for a sync [url_id] ' . (int) $url_id . ' [rows] ' . (int) $n );
		}
	}

	/**
	 * Delete a page's builds whose grace period is over.
	 *
	 * Data::save_url() only rotates old builds out when it replaces a live one,
	 * and expire() leaves none live, so the rebuild after a change would keep
	 * the expired rows and their files for good.
	 *
	 * @since 8.0
	 *
	 * @param int $url_id Page row id.
	 * @return void
	 */
	public function clean_expired( $url_id ) {
		global $wpdb;

		$n = $this->_delete_builds( $wpdb->prepare( 'url_id = %d AND expired BETWEEN 1 AND %d', (int) $url_id, time() ) );
		if ( $n ) {
			self::debug( 'Deleted expired OptimaX builds [url_id] ' . (int) $url_id . ' [rows] ' . $n );
		}
	}

	/**
	 * Delete OptimaX build rows, and each file no remaining row names.
	 *
	 * Files are named by content md5, so another page or version may share one.
	 * A deleted build takes its asset manifest along, and the JS bundle and used
	 * CSS it names once no remaining build names them.
	 *
	 * @since 8.0
	 *
	 * @param string $where Prepared SQL condition on the url_file table.
	 * @return int Rows deleted.
	 */
	private function _delete_builds( $where ) {
		global $wpdb;

		$data        = Data::cls();
		$tb_file     = $data->tb( 'url_file' );
		$exts        = [
			'optimax'     => 'html',
			'optimax_src' => 'json',
		];
		$ext_by_type = [];
		foreach ( $exts as $name => $ext ) {
			$ext_by_type[ $data->file_type_id( $name ) ] = $ext;
		}
		$types = implode( ',', array_map( 'intval', array_keys( $ext_by_type ) ) );

		$rows = $wpdb->get_results( "SELECT filename, type FROM `$tb_file` WHERE type IN ($types) AND $where", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			return 0;
		}
		$wpdb->query( "DELETE FROM `$tb_file` WHERE type IN ($types) AND $where" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$dir     = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' );
		$html_id = $data->file_type_id( 'optimax' );
		$q       = "SELECT COUNT(*) FROM `$tb_file` WHERE filename = %s AND type = %d";
		$assets  = [];
		foreach ( $rows as $f ) {
			if ( $wpdb->get_var( $wpdb->prepare( $q, $f['filename'], (int) $f['type'] ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				continue;
			}

			$file = $dir . $f['filename'] . '.' . $ext_by_type[ (int) $f['type'] ];
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}

			if ( $html_id === (int) $f['type'] ) {
				$manifest = $this->load_assets( $f['filename'] );
				if ( $manifest ) {
					$assets = array_merge( $assets, array_filter( [ $manifest['js'], $manifest['css'] ] ) );
				}
				if ( file_exists( $dir . $f['filename'] . self::ASSETS_EXT ) ) {
					wp_delete_file( $dir . $f['filename'] . self::ASSETS_EXT );
				}
			}
		}

		$this->_delete_unused_assets( array_unique( $assets ) );

		return count( $rows );
	}

	/**
	 * Delete JS bundles and used CSS files no remaining build's manifest names.
	 *
	 * Named by content md5, so pages with the same bundle share one file.
	 *
	 * @since 8.0
	 *
	 * @param array $files File names, e.g. `<md5>.js`.
	 * @return void
	 */
	private function _delete_unused_assets( $files ) {
		global $wpdb;

		if ( ! $files ) {
			return;
		}

		$data = Data::cls();
		$tb   = $data->tb( 'url_file' );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT filename FROM `$tb` WHERE type = %d", $data->file_type_id( 'optimax' ) ) ) as $filename ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$manifest = $this->load_assets( $filename );
			if ( $manifest ) {
				$files = array_diff( $files, [ $manifest['js'], $manifest['css'] ] );
			}
			if ( ! $files ) {
				return;
			}
		}

		$dir = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' );
		foreach ( $files as $file ) {
			if ( file_exists( $dir . $file ) ) {
				wp_delete_file( $dir . $file );
				self::debug( 'Deleted unused OptimaX asset ' . $file );
			}
		}
	}

	/**
	 * The post or page a URL shows, for its VPI post meta; 0 for any other page.
	 *
	 * @since 8.0
	 *
	 * @param string $url Page URL.
	 * @return int
	 */
	public static function post_id( $url ) {
		$post_id = url_to_postid( $url );
		if ( ! $post_id && 'page' === get_option( 'show_on_front' ) && untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
			$post_id = (int) get_option( 'page_on_front' );
		}

		return (int) $post_id;
	}

	/**
	 * Drop a page's UCSS and CCSS: its design changed, so they no longer fit.
	 *
	 * OptimaX saved them for the old design. Until the rebuild, visitors get the
	 * page with the plugin's own optimization, which would load them; the rebuild
	 * also must not send them, or the VPI, back to QUIC.cloud. Every version of
	 * the page goes: a design change expires them all.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @return void
	 */
	public function clear_kept( $url_tag ) {
		global $wpdb;

		$url_id = $this->url_id( $url_tag );
		if ( $url_id ) {
			$data  = Data::cls();
			$tb    = $data->tb( 'url_file' );
			$types = (int) $data->file_type_id( 'ucss' ) . ',' . (int) $data->file_type_id( 'ccss' );
			$until = time() + 86400 * apply_filters( 'litespeed_url_file_expired_days', 20 );
			$q     = "UPDATE `$tb` SET expired = %d WHERE url_id = %d AND type IN ($types) AND expired = 0";
			$wpdb->query( $wpdb->prepare( $q, $until, $url_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		// VPI stays: it is the post's own setting, which editors may have filled by
		// hand, and the rebuild saves its fresh list over it. A version queued
		// before the change must not send it back, though.
		$queue = $this->load_queue( 'optimax' );
		foreach ( $queue as $k => $row ) {
			if ( is_array( $row ) && ! empty( $row['reuse'] ) && isset( $row['url_tag'] ) && $url_tag === $row['url_tag'] ) {
				$queue[ $k ]['reuse'] = 0;
			}
		}
		$this->save_queue( 'optimax', $queue );

		self::debug( 'Cleared the page UCSS/CCSS: design changed [url] ' . $url_tag );
	}

	/**
	 * Treat every page as redesigned: its next-gen images changed under it.
	 *
	 * A next-gen format switch or destroying all optimized images changes the image
	 * files every build and every OptimaX UCSS/CCSS links. The builds expire (each
	 * page is queued again on its next visit, or with Rebuild now), the UCSS and
	 * CCSS of the pages OptimaX built expire with them so no rebuild sends them
	 * back, and so do the fingerprints. VPI stays: it names images, not formats.
	 *
	 * @since 8.0
	 *
	 * @param string $why What changed, for the log and the purge.
	 * @return void
	 */
	public function all_designs_changed( $why ) {
		global $wpdb;

		$data  = Data::cls();
		$tb    = $data->tb( 'url_file' );
		$ox    = (int) $data->file_type_id( 'optimax' ) . ',' . (int) $data->file_type_id( 'optimax_src' );
		$css   = (int) $data->file_type_id( 'ucss' ) . ',' . (int) $data->file_type_id( 'ccss' );
		$until = time() + 86400 * apply_filters( 'litespeed_url_file_expired_days', 20 );

		// The CSS first: the pages OptimaX built are known by their build rows.
		$q     = "UPDATE `$tb` SET expired = %d WHERE expired = 0 AND type IN ($css) AND url_id IN ( SELECT url_id FROM ( SELECT DISTINCT url_id FROM `$tb` WHERE type IN ($ox) ) AS p )";
		$n_css = $wpdb->query( $wpdb->prepare( $q, $until ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$q     = "UPDATE `$tb` SET expired = %d WHERE expired = 0 AND type IN ($ox)";
		$n     = $wpdb->query( $wpdb->prepare( $q, $until ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$queue = $this->load_queue( 'optimax' );
		foreach ( $queue as $k => $row ) {
			if ( is_array( $row ) && ! empty( $row['reuse'] ) ) {
				$queue[ $k ]['reuse'] = 0;
			}
		}
		$this->save_queue( 'optimax', $queue );

		self::debug( 'All pages redesigned: ' . $why . ' [build rows] ' . (int) $n . ' [css rows] ' . (int) $n_css );

		// Cached pages still carry the old OptimaX HTML.
		if ( $n ) {
			Purge::purge_all( 'OptimaX: ' . $why );
		}
	}

	/**
	 * Delete everything OptimaX made: builds (HTML, JS bundle, used CSS copy, asset
	 * manifest) and fingerprints.
	 *
	 * The page's UCSS, CCSS and VPI stay, in their own url_file rows and post
	 * meta, and so do the next-gen images beside their originals: the rebuild
	 * reads them from there and sends them back to QUIC.cloud.
	 *
	 * @since 8.0
	 *
	 * @return void
	 */
	public function purge_builds() {
		$subsite_id = is_multisite() && ! is_network_admin() ? get_current_blog_id() : '';
		$dir        = LITESPEED_STATIC_DIR . '/optimax/' . $subsite_id;

		$n = 0;
		if ( is_dir( $dir ) ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( preg_match( '/\.(html|js|css|json)$/', $file->getFilename() ) ) {
					wp_delete_file( $file->getPathname() );
					++$n;
				}
			}
		}

		Data::cls()->url_file_clean( 'optimax' );
		Data::cls()->url_file_clean( 'optimax_src' );
		// Fingerprints and snapshots are gone, so no check can go on.
		self::update_option( 'checks', [] );
		self::debug( 'Purged all OptimaX builds and fingerprints [files] ' . $n );
	}

	/**
	 * Save a build's asset manifest beside its HTML.
	 *
	 * Lists the files the HTML depends on: the JS bundle and used CSS saved in
	 * the OptimaX folder, and the next-gen images saved beside their originals.
	 * Checked before the build is served, and read when the build is deleted.
	 *
	 * @since 8.0
	 *
	 * @param string $html_md5 Build HTML filename, without extension.
	 * @param array  $assets   `js` and `css` file names (or ''), `imgs` local paths.
	 * @return bool
	 */
	public function save_assets( $html_md5, $assets ) {
		$con = wp_json_encode(
			[
				'js'   => (string) $assets['js'],
				'css'  => (string) $assets['css'],
				'imgs' => array_values( array_unique( (array) $assets['imgs'] ) ),
			]
		);
		if ( ! is_string( $con ) ) {
			return false;
		}

		$file = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . $html_md5 . self::ASSETS_EXT;
		if ( ! File::save_atomic( $file, $con ) ) {
			self::debug( '❌ Failed to save asset manifest [file] ' . $file );
			return false;
		}

		return true;
	}

	/**
	 * A build's asset manifest.
	 *
	 * @since 8.0
	 *
	 * @param string $html_md5 Build HTML filename, without extension.
	 * @return array|null `js`, `css`, `imgs`; null when it is missing or broken.
	 */
	public function load_assets( $html_md5 ) {
		$file = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . $html_md5 . self::ASSETS_EXT;
		if ( ! file_exists( $file ) ) {
			return null;
		}

		$manifest = json_decode( (string) File::read( $file ), true );
		if ( ! is_array( $manifest ) || ! isset( $manifest['js'], $manifest['css'], $manifest['imgs'] ) || ! is_array( $manifest['imgs'] ) ) {
			return null;
		}

		return $manifest;
	}

	/**
	 * Versions whose builds all expired (the design changed): newest build row of each.
	 *
	 * @since 8.0
	 *
	 * @return array Rows with `url_id`, `vary`, `file_id`.
	 */
	private function _refresh_rows() {
		global $wpdb;

		$tb   = Data::cls()->tb( 'url_file' );
		$type = Data::cls()->file_type_id( 'optimax' );
		$q    = "SELECT url_id, vary, MAX(id) AS file_id FROM `$tb` WHERE type = %d GROUP BY url_id, vary HAVING MIN(expired) > 0";

		return (array) $wpdb->get_results( $wpdb->prepare( $q, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts for the Summary tab.
	 *
	 * @since 8.0
	 *
	 * @return array `pages` with an OptimaX build in use, `versions` in use, `refresh` versions waiting for a rebuild.
	 */
	public function stats() {
		global $wpdb;

		$tb   = Data::cls()->tb( 'url_file' );
		$type = Data::cls()->file_type_id( 'optimax' );
		$q    = "SELECT COUNT(DISTINCT url_id) AS pages, COUNT(*) AS versions FROM `$tb` WHERE type = %d AND expired = 0";
		$live = $wpdb->get_row( $wpdb->prepare( $q, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return [
			'pages'    => $live ? (int) $live['pages'] : 0,
			'versions' => $live ? (int) $live['versions'] : 0,
			'refresh'  => count( $this->_refresh_rows() ),
		];
	}

	/**
	 * Pages with an OptimaX build in use, each with its versions.
	 *
	 * @since 8.0
	 *
	 * @param int $limit Pages to return.
	 * @return array Page URL => list of version groups (`mobile`, `nextgen`).
	 */
	public function optimized( $limit = 50 ) {
		global $wpdb;

		$data    = Data::cls();
		$tb_url  = $data->tb( 'url' );
		$tb_file = $data->tb( 'url_file' );
		$q       = "SELECT u.url, f.vary, f.mobile, f.webp FROM `$tb_file` f JOIN `$tb_url` u ON u.id = f.url_id
			WHERE f.type = %d AND f.expired = 0 AND f.url_id IN ( SELECT url_id FROM ( SELECT DISTINCT url_id FROM `$tb_file` WHERE type = %d AND expired = 0 ORDER BY url_id LIMIT %d ) AS p )
			ORDER BY u.url, f.vary";
		$rows    = $wpdb->get_results( $wpdb->prepare( $q, $data->file_type_id( 'optimax' ), $data->file_type_id( 'optimax' ), (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$pages = [];
		foreach ( (array) $rows as $r ) {
			$pages[ $r['url'] ][] = self::version_groups( $r['vary'], $r['mobile'], $r['webp'] );
		}
		return $pages;
	}

	/**
	 * Queue every version whose design changed for a rebuild now, instead of on its next visit.
	 *
	 * @since 8.0
	 *
	 * @return int Versions queued.
	 */
	public function rebuild_all() {
		$n = 0;
		foreach ( $this->_refresh_rows() as $row ) {
			if ( $this->queue_version( (int) $row['file_id'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Queue a version again from its build row.
	 *
	 * No user agent is sent: QC renders with its own, and a build row keeps none.
	 *
	 * @since 8.0
	 *
	 * @param int $file_id An `optimax` build row id.
	 * @return string|false The queue key.
	 */
	public function queue_version( $file_id ) {
		global $wpdb;

		$data    = Data::cls();
		$tb_url  = $data->tb( 'url' );
		$tb_file = $data->tb( 'url_file' );

		$q   = "SELECT f.vary, f.mobile, f.webp, u.url FROM `$tb_file` f JOIN `$tb_url` u ON u.id = f.url_id WHERE f.id = %d AND f.type = %d";
		$row = $wpdb->get_row( $wpdb->prepare( $q, (int) $file_id, $data->file_type_id( 'optimax' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return false;
		}

		// The stored vary is already in its 32-char-at-most form, so this is the key serve() would build.
		$queue   = $this->load_queue( 'optimax' );
		$queue_k = $this->add_versions(
			$queue,
			[
				'url'        => apply_filters( 'litespeed_optimax_url', $row['url'] ),
				'is_mobile'  => (bool) $row['mobile'],
				'is_nextgen' => $row['webp'] ? $this->cls( 'Media' )->webp_support( true ) : '',
				'uid'        => 0,
				'vary'       => $row['vary'],
				'url_tag'    => $row['url'],
			],
			$this->cls( 'Optimax' )->max_queue_size()
		);
		$this->save_queue( 'optimax', $queue );
		self::debug( 'Re-queued from build row [file_id] ' . (int) $file_id );

		return $queue_k;
	}

	/**
	 * Whether a queue row is still waiting, i.e. not yet handed to QUIC.cloud.
	 *
	 * @since 8.0
	 *
	 * @param mixed $row Queue row.
	 * @return bool
	 */
	public static function is_waiting( $row ) {
		return ! is_array( $row ) || ! isset( $row['_status'] ) || 'queued' !== $row['_status'];
	}

	/**
	 * Drop the waiting queue rows.
	 *
	 * A row QUIC.cloud is already building stays: dropping it would leave the
	 * build running there with its result never collected.
	 *
	 * @since 8.0
	 *
	 * @return int Rows kept.
	 */
	public function clear_waiting() {
		$queue = array_filter(
			$this->load_queue( 'optimax' ),
			function ( $row ) {
				return ! self::is_waiting( $row );
			}
		);
		$this->save_queue( 'optimax', $queue );

		return count( $queue );
	}

	/**
	 * The fingerprint stored beside a version's live build.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Vary, raw or stored.
	 * @return array|null `design`, `lines`, `items`, `patches`; null when there is none.
	 */
	public function load_fingerprint( $url_tag, $vary ) {
		$filename = Data::cls()->load_url_file( $url_tag, $vary, 'optimax_src' );
		if ( ! $filename ) {
			return null;
		}

		$file = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . $filename . '.json';
		$fp   = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;
		if ( ! is_array( $fp ) || ! isset( $fp['v'] ) || Optimax_Sync::VERSION !== (int) $fp['v'] || ! isset( $fp['design'], $fp['lines'], $fp['items'] ) || ! is_array( $fp['lines'] ) || ! is_array( $fp['items'] ) ) {
			return null;
		}

		return $fp + [
			'patches' => 0,
			'built'   => 0,
			'pending' => '',
			'base'    => '',
		];
	}

	/**
	 * Store the fingerprint of the render a version's live build matches.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param array  $fp      Optimax_Sync::fingerprint().
	 * @param int    $patches Times the build had its text updated.
	 * @param int    $built   When the build was first served (0: now). Patches keep it, so the Rebuild Interval counts from the build.
	 * @return bool
	 */
	public function save_fingerprint( $url_tag, $vary, $fp, $patches, $built = 0 ) {
		// The page's URL and vary are part of the content, so two versions never share a file.
		$con = wp_json_encode(
			[
				'v'       => Optimax_Sync::VERSION,
				'url_tag' => $url_tag,
				'vary'    => $vary,
				'design'  => $fp['design'],
				'lines'   => $fp['lines'],
				'items'   => $fp['items'],
				'patches' => (int) $patches,
				'built'   => $built ? (int) $built : time(),
				'pending' => empty( $fp['pending'] ) ? '' : (string) $fp['pending'],
				// The render itself (pack_base()), for a sync on QUIC.cloud. Optional:
				// a fingerprint without it still compares, only never syncs.
				'base'    => empty( $fp['base'] ) ? '' : (string) $fp['base'],
			]
		);
		if ( ! is_string( $con ) ) {
			return false;
		}

		$filecon_md5 = md5( $con );
		$static_file = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . $filecon_md5 . '.json';
		$ok          = File::save( $static_file, $con, true );
		if ( false === $ok || ! file_exists( $static_file ) ) {
			self::debug( '❌ Failed to save fingerprint [file] ' . $static_file );
			return false;
		}

		$groups = self::version_groups( $vary );
		Data::cls()->save_url( $url_tag, $vary, 'optimax_src', $filecon_md5, dirname( $static_file ), $groups['mobile'], $groups['nextgen'] );

		return true;
	}

	/**
	 * Drop a version's fingerprint: a new build matches a render not seen yet.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return void
	 */
	public function drop_fingerprint( $url_tag, $vary ) {
		global $wpdb;

		$id = $this->url_id( $url_tag );
		if ( $id ) {
			$this->_delete_builds( $wpdb->prepare( 'url_id = %d AND vary = %s AND type = %d', $id, self::vary_key( $vary ), Data::cls()->file_type_id( 'optimax_src' ) ) );
		}
	}

	/**
	 * A render as a fingerprint keeps it: compressed, base64.
	 *
	 * @since 8.0
	 *
	 * @param string $html Render.
	 * @return string Empty when it cannot be packed.
	 */
	public static function pack_base( $html ) {
		$packed = function_exists( 'gzdeflate' ) ? gzdeflate( (string) $html, 6 ) : false;
		return false === $packed ? '' : base64_encode( $packed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * A render pack_base() kept.
	 *
	 * @since 8.0
	 *
	 * @param string $packed pack_base() output.
	 * @return string Empty when there is none, or it cannot be read.
	 */
	public static function unpack_base( $packed ) {
		$raw  = '' === (string) $packed ? false : base64_decode( (string) $packed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$html = false === $raw || ! function_exists( 'gzinflate' ) ? false : gzinflate( $raw );
		return false === $html ? '' : $html;
	}

	/**
	 * Keep what a sync of one version needs until its result is stored.
	 *
	 * The render its build matches (`base`), the render that showed the change
	 * (`now`), and the build that served it (`file`), which expire_version() stops
	 * serving but clean_expired() keeps until a result replaces it. `now` is
	 * fingerprinted here, in the request that rendered it: a stylesheet counts by
	 * its file's content, which may change before the result comes back. A `.json`,
	 * so Purge All OptimaX takes it with the builds.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $base    pack_base() of the build's render.
	 * @param string $now     The render that showed the change.
	 * @param string $file    The build's filename (its HTML without `.html`).
	 * @return bool
	 */
	public function save_sync( $url_tag, $vary, $base, $now, $file ) {
		$con = wp_json_encode(
			[
				'url_tag' => $url_tag,
				'vary'    => $vary,
				'base'    => (string) $base,
				'now'     => self::pack_base( $now ),
				'fp'      => self::render_fingerprint( $now ),
				'file'    => (string) $file,
			]
		);

		return is_string( $con ) && false !== File::save( $this->_sync_file( $url_tag, $vary ), $con, true );
	}

	/**
	 * What a queued sync of one version sends: `base`, `now` and the build's `html`.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return array|null Null when any part is gone: the version is then built as usual.
	 */
	public function load_sync( $url_tag, $vary ) {
		$file = $this->_sync_file( $url_tag, $vary );
		$snap = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;
		if ( ! is_array( $snap ) || empty( $snap['file'] ) || ! is_string( $snap['file'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $snap['file'] ) ) {
			return null;
		}

		$build = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . $snap['file'] . '.html';
		$html  = file_exists( $build ) ? (string) File::read( $build ) : '';
		$base  = self::unpack_base( isset( $snap['base'] ) ? $snap['base'] : '' );
		$now   = self::unpack_base( isset( $snap['now'] ) ? $snap['now'] : '' );
		if ( '' === $html || '' === $base || '' === $now ) {
			return null;
		}

		return [
			'base' => $base,
			'now'  => $now,
			'html' => $html,
			'file' => $snap['file'],
		];
	}

	/**
	 * The build a queued sync of one version was taken from, if one is queued.
	 *
	 * A synced result links that build's JS bundle, used CSS and images, so it
	 * takes over its asset manifest.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return string The build's filename; empty when there is no snapshot.
	 */
	public function sync_source( $url_tag, $vary ) {
		$file = $this->_sync_file( $url_tag, $vary );
		$snap = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;

		return is_array( $snap ) && ! empty( $snap['file'] ) && is_string( $snap['file'] ) && preg_match( '/^[a-f0-9]{32}$/', $snap['file'] ) ? $snap['file'] : '';
	}

	/**
	 * Forget one version's sync: a result was stored, or it is no longer wanted.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return void
	 */
	public function drop_sync( $url_tag, $vary ) {
		$file = $this->_sync_file( $url_tag, $vary );
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * The render a queued sync of one version showed its change in, with the
	 * fingerprint taken when it was rendered.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return array `render` (empty when no sync is queued) and `fp` (null when not kept).
	 */
	public function sync_render( $url_tag, $vary ) {
		$file = $this->_sync_file( $url_tag, $vary );
		$snap = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;

		return [
			'render' => is_array( $snap ) && isset( $snap['now'] ) ? self::unpack_base( $snap['now'] ) : '',
			'fp'     => is_array( $snap ) ? self::kept_fingerprint( $snap ) : null,
		];
	}

	/**
	 * Keep the render a version's build was requested with, until its result is stored.
	 *
	 * A later request for the same version replaces it. Fingerprinted here, in the
	 * request that rendered it (see save_sync()). A `.json` beside the builds, so
	 * Purge All OptimaX takes it with them.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $render  The request's render.
	 * @return bool
	 */
	public function save_queued_render( $url_tag, $vary, $render ) {
		$con = wp_json_encode(
			[
				'url_tag' => $url_tag,
				'vary'    => $vary,
				'render'  => self::pack_base( $render ),
				'fp'      => self::render_fingerprint( $render ),
			]
		);

		return is_string( $con ) && false !== File::save( $this->_queued_file( $url_tag, $vary ), $con, true );
	}

	/**
	 * The render a version's build was requested with, and its fingerprint taken
	 * when it was rendered, forgotten once read.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return array `render` (empty when there is none) and `fp` (null when not kept).
	 */
	public function take_queued_render( $url_tag, $vary ) {
		$file = $this->_queued_file( $url_tag, $vary );
		if ( ! file_exists( $file ) ) {
			return [
				'render' => '',
				'fp'     => null,
			];
		}

		$kept = json_decode( (string) File::read( $file ), true );
		wp_delete_file( $file );

		return [
			'render' => is_array( $kept ) && isset( $kept['render'] ) ? self::unpack_base( $kept['render'] ) : '',
			'fp'     => is_array( $kept ) ? self::kept_fingerprint( $kept ) : null,
		];
	}

	/**
	 * A render's fingerprint as kept beside it, tagged with its format.
	 *
	 * @since 8.0
	 *
	 * @param string $render The render.
	 * @return array|null Null when the render cannot be read.
	 */
	private static function render_fingerprint( $render ) {
		$fp = '' !== (string) $render ? Optimax_Sync::fingerprint( $render ) : null;
		if ( ! $fp ) {
			return null;
		}
		$fp['v'] = Optimax_Sync::VERSION;
		return $fp;
	}

	/**
	 * The fingerprint kept with a render, when it is of the current format.
	 *
	 * A render kept by an older plugin has none: the caller fingerprints it then.
	 *
	 * @since 8.0
	 *
	 * @param array $kept The stored record.
	 * @return array|null
	 */
	private static function kept_fingerprint( $kept ) {
		$fp = isset( $kept['fp'] ) ? $kept['fp'] : null;
		if ( ! is_array( $fp ) || ! isset( $fp['v'], $fp['design'], $fp['lines'], $fp['items'] ) || Optimax_Sync::VERSION !== (int) $fp['v'] ) {
			return null;
		}
		unset( $fp['v'] );
		return $fp;
	}

	/**
	 * Where one version's queued render lives.
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return string
	 */
	private function _queued_file( $url_tag, $vary ) {
		return LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . 'queued-' . md5( self::vary_key( $vary ) . ' ' . $url_tag ) . '.json';
	}

	/**
	 * Where one version's sync snapshot lives.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return string
	 */
	private function _sync_file( $url_tag, $vary ) {
		return LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . 'sync-' . md5( self::vary_key( $vary ) . ' ' . $url_tag ) . '.json';
	}

	/**
	 * Record where one version's page-change check stands, for the OptimaX page.
	 *
	 * Stages, in order: `confirming` (a render showed a change; the next one confirms
	 * it), `sync` (sent to QUIC.cloud, which carries a small change into the build or
	 * builds the version in full) or `rebuild` (a full build queued), then the
	 * outcome (end_check()). A small list beside the builds, so the admin page reads
	 * it without opening every fingerprint.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $stage   `confirming`, `sync` or `rebuild`.
	 * @param string $what    What changed, for the admin page (`design: ...`, `content: ...`).
	 * @return void
	 */
	public function note_check( $url_tag, $vary, $stage, $what = '' ) {
		$checks = $this->_load_checks();
		$key    = self::check_key( $url_tag, $vary );
		$now    = time();
		$check  = isset( $checks[ $key ] ) ? $checks[ $key ] : [];
		// A check already finished starts over: this is a new change.
		$open = $check && empty( $check['done'] );

		$groups         = self::version_groups( $vary );
		$checks[ $key ] = [
			'url'     => $url_tag,
			'mobile'  => $groups['mobile'] ? 1 : 0,
			'nextgen' => $groups['nextgen'] ? 1 : 0,
			'stage'   => $stage,
			'what'    => '' !== $what ? substr( $what, 0, 200 ) : ( $open && isset( $check['what'] ) ? $check['what'] : '' ),
			'since'   => $open && ! empty( $check['since'] ) ? (int) $check['since'] : $now,
			'at'      => $now,
		];
		$this->_save_checks( $checks );
	}

	/**
	 * End one version's page-change check, if it has one.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $outcome `synced` or `rebuilt`, listed for CHECK_KEEP; empty when
	 *                        the change was a one-off, which forgets the check.
	 * @return void
	 */
	public function end_check( $url_tag, $vary, $outcome = '' ) {
		$checks = $this->_load_checks();
		$key    = self::check_key( $url_tag, $vary );
		if ( empty( $checks[ $key ] ) || ! empty( $checks[ $key ]['done'] ) ) {
			return;
		}

		if ( '' === $outcome ) {
			unset( $checks[ $key ] );
		} else {
			$checks[ $key ]['stage'] = $outcome;
			$checks[ $key ]['done']  = 1;
			$checks[ $key ]['at']    = time();
		}
		$this->_save_checks( $checks );
	}

	/**
	 * Page-change checks under way, and those finished in the last CHECK_KEEP, newest first.
	 *
	 * @since 8.0
	 *
	 * @return array check_key() => `url`, `mobile`, `nextgen`, `stage`, `what`, `since`, `at` and, once finished, `done`.
	 */
	public function checks() {
		$checks = $this->_load_checks();
		uasort(
			$checks,
			function ( $a, $b ) {
				return $b['at'] - $a['at'];
			}
		);
		return $checks;
	}

	/**
	 * Which version a check belongs to.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Vary, raw or stored.
	 * @return string
	 */
	public static function check_key( $url_tag, $vary ) {
		return md5( self::vary_key( $vary ) . ' ' . $url_tag );
	}

	/**
	 * The stored checks, without those finished more than CHECK_KEEP ago.
	 *
	 * @return array
	 */
	private function _load_checks() {
		$checks = self::get_option( 'checks', [] );
		if ( ! is_array( $checks ) ) {
			return [];
		}

		$cutoff = time() - self::CHECK_KEEP;
		return array_filter(
			$checks,
			function ( $check ) use ( $cutoff ) {
				return is_array( $check ) && isset( $check['url'], $check['stage'], $check['at'] ) && ( empty( $check['done'] ) || $check['at'] > $cutoff );
			}
		);
	}

	/**
	 * Store the checks, the newest CHECK_MAX of them.
	 *
	 * @param array $checks check_key() => check.
	 * @return void
	 */
	private function _save_checks( $checks ) {
		if ( count( $checks ) > self::CHECK_MAX ) {
			uasort(
				$checks,
				function ( $a, $b ) {
					return $b['at'] - $a['at'];
				}
			);
			$checks = array_slice( $checks, 0, self::CHECK_MAX, true );
		}
		self::update_option( 'checks', $checks );
	}
}
