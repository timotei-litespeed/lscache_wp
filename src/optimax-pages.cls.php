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
		$types = implode( ',', array_map( [ $data, 'file_type_id' ], [ 'optimax', 'optimax_js', 'optimax_ucss', 'optimax_imgs', 'optimax_src' ] ) );
		$until = time() + 86400 * apply_filters( 'litespeed_url_file_expired_days', 20 );

		$q = "UPDATE `$tb` SET expired = %d WHERE url_id = %d AND type IN ($types) AND expired = 0";
		$n = $wpdb->query( $wpdb->prepare( $q, $until, (int) $url_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $n ) {
			self::debug( 'Expired OptimaX builds [url_id] ' . (int) $url_id . ' [rows] ' . (int) $n );
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
			'optimax'      => 'html',
			'optimax_js'   => 'js',
			'optimax_ucss' => 'css',
			'optimax_imgs' => 'json',
			'optimax_src'  => 'json',
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

		$dir = LITESPEED_STATIC_DIR . $this->_build_filepath_prefix( 'optimax' );
		$q   = "SELECT COUNT(*) FROM `$tb_file` WHERE filename = %s AND type = %d";
		foreach ( $rows as $f ) {
			$file = $dir . $f['filename'] . '.' . $ext_by_type[ (int) $f['type'] ];
			if ( ! $wpdb->get_var( $wpdb->prepare( $q, $f['filename'], (int) $f['type'] ) ) && file_exists( $file ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				wp_delete_file( $file );
			}
		}

		return count( $rows );
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
}
