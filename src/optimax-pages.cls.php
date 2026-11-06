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
	 * Whether a version is one OptimaX serves under the Next-Gen Image Format setting.
	 *
	 * With the setting on, the next-gen versions (`+webp` vary); with it off, the
	 * plain ones, which are built without image work. Nothing serves the other
	 * kind, so a build made under the previous setting stays unused until it is
	 * deleted, and turning the setting back on does not bring it back (every
	 * build expires when the format changes, all_designs_changed()).
	 *
	 * @since 8.0
	 *
	 * @param string    $vary       Raw vary (queue row) or stored vary (build row).
	 * @param bool      $nextgen_on Whether Next-Gen Image Format is on (Optimax::nextgen_on()).
	 * @param bool|null $mobile     Build row `mobile` column, used only for a hashed vary.
	 * @param bool|null $webp       Build row `webp` column, used only for a hashed vary.
	 * @return bool
	 */
	public static function is_served_version( $vary, $nextgen_on, $mobile = null, $webp = null ) {
		return self::version_groups( $vary, $mobile, $webp )['nextgen'] === (bool) $nextgen_on;
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

		$rows = $wpdb->get_results( "SELECT filename, type, url_id, vary FROM `$tb_file` WHERE type IN ($types) AND $where", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
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
		$this->_drop_orphan_sheet_copies( $rows );

		return count( $rows );
	}

	/**
	 * Drop the builder stylesheet copies of each version whose deleted fingerprint
	 * row was its last: they belonged to the build that fingerprint matched.
	 *
	 * @since 8.0
	 *
	 * @param array $rows Deleted build rows: `type`, `url_id`, `vary`.
	 * @return void
	 */
	private function _drop_orphan_sheet_copies( $rows ) {
		global $wpdb;

		$data   = Data::cls();
		$tb     = $data->tb( 'url_file' );
		$tb_url = $data->tb( 'url' );
		$src_id = (int) $data->file_type_id( 'optimax_src' );
		$done   = [];
		foreach ( $rows as $f ) {
			$key = (int) $f['url_id'] . ' ' . $f['vary'];
			if ( $src_id !== (int) $f['type'] || isset( $done[ $key ] ) ) {
				continue;
			}
			$done[ $key ] = true;

			if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$tb` WHERE url_id = %d AND vary = %s AND type = %d", (int) $f['url_id'], $f['vary'], $src_id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				continue;
			}
			$url_tag = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM `$tb_url` WHERE id = %d", (int) $f['url_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $url_tag ) {
				// A stored vary is already vary_key()'d, which vary_key() keeps as it is.
				$this->drop_sheet_copies( $url_tag, $f['vary'] );
			}
		}
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
		$this->update_queue(
			'optimax',
			function ( &$queue ) use ( $url_tag ) {
				foreach ( $queue as $k => $row ) {
					if ( is_array( $row ) && ! empty( $row['reuse'] ) && isset( $row['url_tag'] ) && $url_tag === $row['url_tag'] ) {
						$queue[ $k ]['reuse'] = 0;
					}
				}
			}
		);

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

		$this->update_queue(
			'optimax',
			function ( &$queue ) {
				foreach ( $queue as $k => $row ) {
					if ( is_array( $row ) && ! empty( $row['reuse'] ) ) {
						$queue[ $k ]['reuse'] = 0;
					}
				}
			}
		);

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
	 * @param array  $assets   `js` and `css` file names (or ''), `imgs` local paths; `level`, the served level, when known.
	 * @return bool
	 */
	public function save_assets( $html_md5, $assets ) {
		$manifest = [
			'js'   => (string) $assets['js'],
			'css'  => (string) $assets['css'],
			'imgs' => array_values( array_unique( (array) $assets['imgs'] ) ),
		];
		if ( ! empty( $assets['level'] ) ) {
			$manifest['level'] = (string) $assets['level'];
		}
		$con = wp_json_encode( $manifest );
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
	 * @return array|null `js`, `css`, `imgs` and, when known, `level`; null when it is missing or broken.
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
	 * Only the versions the Next-Gen Image Format setting serves now
	 * (is_served_version()): a build made under the other setting is not rebuilt.
	 *
	 * @since 8.0
	 *
	 * @return array Rows with `url_id`, `vary`, `file_id`.
	 */
	private function _refresh_rows() {
		global $wpdb;

		$tb   = Data::cls()->tb( 'url_file' );
		$type = Data::cls()->file_type_id( 'optimax' );
		$q    = "SELECT url_id, vary, MAX(id) AS file_id, MAX(webp) AS webp FROM `$tb` WHERE type = %d GROUP BY url_id, vary HAVING MIN(expired) > 0";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $q, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$nextgen_on = Optimax::nextgen_on();
		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $nextgen_on ) {
					return self::is_served_version( $row['vary'], $nextgen_on, null, $row['webp'] );
				}
			)
		);
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
	 * @return array Page URL => list of version groups (`mobile`, `nextgen`, `level`: the served level or '').
	 */
	public function optimized( $limit = 50 ) {
		global $wpdb;

		$data    = Data::cls();
		$tb_url  = $data->tb( 'url' );
		$tb_file = $data->tb( 'url_file' );
		$q       = "SELECT u.url, f.vary, f.mobile, f.webp, f.filename FROM `$tb_file` f JOIN `$tb_url` u ON u.id = f.url_id
			WHERE f.type = %d AND f.expired = 0 AND f.url_id IN ( SELECT url_id FROM ( SELECT DISTINCT url_id FROM `$tb_file` WHERE type = %d AND expired = 0 ORDER BY url_id LIMIT %d ) AS p )
			ORDER BY u.url, f.vary";
		$rows    = $wpdb->get_results( $wpdb->prepare( $q, $data->file_type_id( 'optimax' ), $data->file_type_id( 'optimax' ), (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$pages = [];
		foreach ( (array) $rows as $r ) {
			$manifest             = $this->load_assets( $r['filename'] );
			$pages[ $r['url'] ][] = self::version_groups( $r['vary'], $r['mobile'], $r['webp'] ) + [ 'level' => $manifest && ! empty( $manifest['level'] ) ? (string) $manifest['level'] : '' ];
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
		$version = [
			'url'        => apply_filters( 'litespeed_optimax_url', $row['url'] ),
			'is_mobile'  => (bool) $row['mobile'],
			'is_nextgen' => $row['webp'] ? $this->cls( 'Media' )->webp_support( true ) : '',
			'uid'        => 0,
			'vary'       => $row['vary'],
			'url_tag'    => $row['url'],
		];
		$max     = $this->cls( 'Optimax' )->max_queue_size();
		$queue_k = false;
		$this->update_queue(
			'optimax',
			function ( &$queue ) use ( $version, $max, &$queue_k ) {
				$queue_k = $this->add_versions( $queue, $version, $max );
			}
		);
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
		$queue = $this->update_queue(
			'optimax',
			function ( &$queue ) {
				$queue = array_filter(
					$queue,
					function ( $row ) {
						return ! self::is_waiting( $row );
					}
				);
			}
		);

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
		// The stylesheet copies belong to the build the fingerprint matched.
		$this->drop_sheet_copies( $url_tag, $vary );
	}

	/**
	 * Keep the builder stylesheets a version's live build was cut from (Optimax_Sync::same_sheets()).
	 *
	 * Beside the fingerprint, not in it: a patch writes the fingerprint again from its
	 * own render, while the build's CSS stays the one it was cut from. Written only
	 * when a real build is stored (Optimax::_record_build_render()). A `.json` beside
	 * the builds, so Purge All OptimaX takes it with them.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param array  $sheets  URL path => pack_base() of the CSS, as kept with the render (pack_sheets()). Empty drops the copies.
	 * @return void
	 */
	public function save_sheet_copies( $url_tag, $vary, $sheets ) {
		// Each copy's md5 beside it: a render whose verdict is known inflates none.
		$md5 = [];
		foreach ( $sheets as $path => $packed ) {
			$css = self::unpack_base( $packed, Optimax_Sync::SHEET_COPY_MAX );
			if ( '' !== $css ) {
				$md5[ $path ] = md5( $css );
			}
		}
		if ( ! $md5 ) {
			$this->drop_sheet_copies( $url_tag, $vary );
			return;
		}

		$con = wp_json_encode(
			[
				'url_tag' => $url_tag,
				'vary'    => $vary,
				'sheets'  => array_intersect_key( $sheets, $md5 ),
				'md5'     => $md5,
			]
		);
		if ( ! is_string( $con ) || false === File::save( $this->_sheets_file( $url_tag, $vary ), $con, true ) ) {
			// Without them every builder stylesheet change counts, as before.
			$this->drop_sheet_copies( $url_tag, $vary );
		}
	}

	/**
	 * The builder stylesheets a version's live build was cut from, and the verdicts
	 * on them remembered for that build (save_sheet_verdicts()).
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $build   The build's HTML md5: verdicts of another build are left out.
	 * @return array `md5`: URL path => md5 of the copy, empty when none are kept; `packed`: URL path =>
	 *               pack_base() of the copy (unpack_sheet()); `known`: Optimax_Sync::same_sheets() verdicts.
	 */
	public function load_sheet_copies( $url_tag, $vary, $build ) {
		$file = $this->_sheets_file( $url_tag, $vary );
		$kept = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;
		$out  = [
			'md5'    => [],
			'packed' => [],
			'known'  => [],
		];
		if ( ! is_array( $kept ) || ! isset( $kept['sheets'], $kept['md5'] ) || ! is_array( $kept['sheets'] ) || ! is_array( $kept['md5'] ) ) {
			return $out;
		}

		foreach ( $kept['md5'] as $path => $md5 ) {
			if ( is_string( $md5 ) && preg_match( '/^[a-f0-9]{32}$/', $md5 ) && isset( $kept['sheets'][ $path ] ) && is_string( $kept['sheets'][ $path ] ) ) {
				$out['md5'][ (string) $path ]    = $md5;
				$out['packed'][ (string) $path ] = $kept['sheets'][ $path ];
			}
		}
		if ( isset( $kept['verdicts']['build'], $kept['verdicts']['known'] ) && $build === $kept['verdicts']['build'] && is_array( $kept['verdicts']['known'] ) ) {
			$out['known'] = array_filter( $kept['verdicts']['known'], 'is_bool' );
		}
		return $out;
	}

	/**
	 * One kept builder stylesheet copy, read back.
	 *
	 * @since 8.0
	 *
	 * @param string $packed pack_base() of the copy.
	 * @return string|null Null when it cannot be read, or is over the copy limit.
	 */
	public static function unpack_sheet( $packed ) {
		$css = self::unpack_base( $packed, Optimax_Sync::SHEET_COPY_MAX );
		return '' === $css ? null : $css;
	}

	/**
	 * Remember the verdicts on a version's builder stylesheets, so a repeat render
	 * does not parse them again (Optimax_Sync::same_sheets()).
	 *
	 * Kept beside the copies, for one build only; the newest 16. Copies written
	 * again for a new build (save_sheet_copies()) start with none.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $build   The build's HTML md5.
	 * @param array  $known   Verdicts, `path|copy md5|file md5` => bool.
	 * @return void
	 */
	public function save_sheet_verdicts( $url_tag, $vary, $build, $known ) {
		$file = $this->_sheets_file( $url_tag, $vary );
		$kept = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;
		if ( ! is_array( $kept ) || empty( $kept['sheets'] ) ) {
			return;
		}

		$kept['verdicts'] = [
			'build' => (string) $build,
			'known' => array_slice( $known, -16, null, true ),
		];
		$con              = wp_json_encode( $kept );
		if ( is_string( $con ) ) {
			File::save( $file, $con, true );
		}
	}

	/**
	 * Forget the builder stylesheets a version's build was cut from.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return void
	 */
	public function drop_sheet_copies( $url_tag, $vary ) {
		$file = $this->_sheets_file( $url_tag, $vary );
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * A render's builder stylesheets as kept with it: URL path => pack_base() of the CSS.
	 *
	 * @since 8.0
	 *
	 * @param string $render The render.
	 * @return array
	 */
	private static function pack_sheets( $render ) {
		$out    = [];
		$copies = '' !== (string) $render ? Optimax_Sync::sheet_copies( $render ) : [];
		foreach ( $copies as $path => $css ) {
			$packed = self::pack_base( $css );
			if ( '' !== $packed ) {
				$out[ $path ] = $packed;
			}
		}
		return $out;
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
	 * @param int    $max    Most bytes it may inflate to; 0: no limit.
	 * @return string Empty when there is none, it cannot be read, or it is over `$max`.
	 */
	public static function unpack_base( $packed, $max = 0 ) {
		$raw  = '' === (string) $packed ? false : base64_decode( (string) $packed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$html = false === $raw || ! function_exists( 'gzinflate' ) ? false : @gzinflate( $raw, $max ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- warns when over $max.
		return false === $html ? '' : $html;
	}

	/**
	 * Keep what a sync of one version needs until its result is stored.
	 *
	 * The render its build matches (`base`), the render that showed the change
	 * (`now`), and the build that served it (`file`), which expire_version() stops
	 * serving but clean_expired() keeps until a result replaces it. `now` is
	 * fingerprinted here, in the request that rendered it: a stylesheet counts by
	 * its file's content, which may change before the result comes back. Its builder
	 * stylesheets are copied here too (pack_sheets()), for the full build that follows
	 * a refused sync. A `.json`, so Purge All OptimaX takes it with the builds.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @param string $base    pack_base() of the build's render.
	 * @param string $now     The render that showed the change.
	 * @param string $file    The build's filename (its HTML without `.html`).
	 * @param array  $same    Optimax_Sync::same_sheets() of the render: a synced result reads those sheets as its copies (Optimax::_record_build_render()).
	 * @return bool
	 */
	public function save_sync( $url_tag, $vary, $base, $now, $file, $same = [] ) {
		$con = wp_json_encode(
			[
				'url_tag' => $url_tag,
				'vary'    => $vary,
				'base'    => (string) $base,
				'now'     => self::pack_base( $now ),
				'fp'      => self::render_fingerprint( $now ),
				'css'     => self::pack_sheets( $now ),
				'same'    => $same,
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
	 * @return array `render` (empty when no sync is queued), `fp` (null when not kept), `css` (kept_sheets()) and `same` (save_sync()).
	 */
	public function sync_render( $url_tag, $vary ) {
		$file = $this->_sync_file( $url_tag, $vary );
		$snap = file_exists( $file ) ? json_decode( (string) File::read( $file ), true ) : null;

		return [
			'render' => is_array( $snap ) && isset( $snap['now'] ) ? self::unpack_base( $snap['now'] ) : '',
			'fp'     => is_array( $snap ) ? self::kept_fingerprint( $snap ) : null,
			'css'    => is_array( $snap ) ? self::kept_sheets( $snap ) : [],
			'same'   => is_array( $snap ) && self::kept_fingerprint( $snap ) && isset( $snap['same'] ) && is_array( $snap['same'] ) ? array_filter( $snap['same'], [ __CLASS__, 'is_same_sheet' ] ) : [],
		];
	}

	/**
	 * Keep the render a version's build was requested with, until its result is stored.
	 *
	 * A later request for the same version replaces it. Fingerprinted, and its builder
	 * stylesheets copied, here, in the request that rendered it (see save_sync()). A
	 * `.json` beside the builds, so Purge All OptimaX takes it with them.
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
				'css'     => self::pack_sheets( $render ),
			]
		);

		return is_string( $con ) && false !== File::save( $this->_queued_file( $url_tag, $vary ), $con, true );
	}

	/**
	 * The render a version's build was requested with, and its fingerprint and
	 * builder stylesheets taken when it was rendered, forgotten once read.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return array `render` (empty when there is none), `fp` (null when not kept) and `css` (kept_sheets()).
	 */
	public function take_queued_render( $url_tag, $vary ) {
		$file = $this->_queued_file( $url_tag, $vary );
		if ( ! file_exists( $file ) ) {
			return [
				'render' => '',
				'fp'     => null,
				'css'    => [],
			];
		}

		$kept = json_decode( (string) File::read( $file ), true );
		wp_delete_file( $file );

		return [
			'render' => is_array( $kept ) && isset( $kept['render'] ) ? self::unpack_base( $kept['render'] ) : '',
			'fp'     => is_array( $kept ) ? self::kept_fingerprint( $kept ) : null,
			'css'    => is_array( $kept ) ? self::kept_sheets( $kept ) : [],
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
	 * The builder stylesheets kept with a render (pack_sheets()), still packed, when
	 * they belong to a fingerprint of the current format.
	 *
	 * They are the build's CSS only together with that fingerprint: a render kept by
	 * an older plugin has neither.
	 *
	 * @since 8.0
	 *
	 * @param array $kept The stored record.
	 * @return array URL path => pack_base() of the CSS.
	 */
	private static function kept_sheets( $kept ) {
		if ( ! self::kept_fingerprint( $kept ) || ! isset( $kept['css'] ) || ! is_array( $kept['css'] ) ) {
			return [];
		}
		return array_filter( $kept['css'], 'is_string' );
	}

	/**
	 * Whether a stored Optimax_Sync::same_sheets() entry is well formed.
	 *
	 * @since 8.0
	 *
	 * @param mixed $entry Entry.
	 * @return bool
	 */
	private static function is_same_sheet( $entry ) {
		return is_array( $entry ) && isset( $entry['from'], $entry['to'] ) && is_string( $entry['from'] ) && is_string( $entry['to'] )
			&& preg_match( '/^[a-f0-9]{32}$/', $entry['from'] ) && preg_match( '/^[a-f0-9]{32}$/', $entry['to'] );
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
	 * Where the builder stylesheets one version's live build was cut from live.
	 *
	 * @since 8.0
	 *
	 * @param string $url_tag Page identity.
	 * @param string $vary    Raw vary.
	 * @return string
	 */
	private function _sheets_file( $url_tag, $vary ) {
		return LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' ) . 'sheets-' . md5( self::vary_key( $vary ) . ' ' . $url_tag ) . '.json';
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
