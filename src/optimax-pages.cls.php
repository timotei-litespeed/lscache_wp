<?php
/**
 * The OptimaX page list: which pages OptimaX may optimize.
 *
 * @since   8.0
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * OptimaX page list.
 *
 * A page is a `litespeed_url` row with `ox = 1`. Its versions are not stored:
 * they are read from its OptimaX build rows and its queue rows when needed.
 *
 * @since 8.0
 */
class Optimax_Pages extends Root {

	const LOG_TAG = '🚀';

	/**
	 * Width of `litespeed_url.cache_tags`.
	 */
	const TAGS_MAX = 1000;

	const STATUS_WORKING = 'working';
	const STATUS_QUEUED  = 'queued';
	const STATUS_IN_USE  = 'in_use';
	const STATUS_REFRESH = 'refresh';

	/**
	 * The listed page this render serves: `id` and its stored `tags`, or null.
	 *
	 * @var array|null
	 */
	private $_record = null;

	/**
	 * The page's final cache tags, once Core::send_headers() has built them.
	 *
	 * @var array|null
	 */
	private $_final_tags = null;

	/**
	 * Set while OptimaX stores a build: its own purges are not content changes.
	 *
	 * @var bool
	 */
	private $_ignore_purges = false;

	/**
	 * Normalize a page the owner typed into the URL serve() computes for it.
	 *
	 * Mirrors Utility::request_url(): `trailingslashit( home_url( $wp->request ) )`
	 * with pretty permalinks (any query is ignored there, as WordPress ignores it
	 * for the request), `home_url( $wp->request ) . '?' . query` without. Scheme
	 * and host always come from home, so a pasted http:// link still matches, and
	 * the result is spelled by canonical_url().
	 *
	 * @since 8.0
	 *
	 * @param string $input  A path relative to home (`/sample-page/`) or a full URL on this site.
	 * @param string $home   home_url().
	 * @param bool   $pretty Whether a permalink structure is set.
	 * @return string|false The page URL, or false when it is not a page on this site.
	 */
	public static function normalize( $input, $home, $pretty ) {
		$input = trim( (string) $input );
		if ( '' === $input || preg_match( '/[\x00-\x20\x7f]/', $input ) ) {
			return false;
		}

		$home       = untrailingslashit( (string) $home );
		$home_parts = wp_parse_url( $home );
		if ( empty( $home_parts['host'] ) ) {
			return false;
		}
		$home_path = isset( $home_parts['path'] ) ? trim( $home_parts['path'], '/' ) : '';

		if ( preg_match( '#^(https?:)?//#i', $input ) ) {
			$parts = wp_parse_url( 0 === strpos( $input, '//' ) ? 'https:' . $input : $input );
			if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
				return false;
			}
			$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
			// A full URL names its path from the domain root; the page is what follows home's own path.
			if ( '' !== $home_path ) {
				if ( $path !== $home_path && 0 !== strpos( $path, $home_path . '/' ) ) {
					return false;
				}
				$path = trim( (string) substr( $path, strlen( $home_path ) ), '/' );
			}
		} else {
			$parts = wp_parse_url( '/' . ltrim( $input, '/' ) );
			if ( ! is_array( $parts ) ) {
				return false;
			}
			$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
			// On a site at /blog, `/blog/page/` is typed as the address bar shows it.
			if ( '' !== $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) {
				$path = trim( (string) substr( $path, strlen( $home_path ) ), '/' );
			}
		}
		$query = isset( $parts['query'] ) ? $parts['query'] : '';

		if ( preg_match( '#(^|/)\.{1,2}(/|$)#', $path ) ) {
			return false;
		}

		$url = $home . ( '' !== $path ? '/' . $path : '' );
		if ( $pretty ) {
			return self::canonical_url( trailingslashit( $url ) );
		}

		return self::canonical_url( '' !== $query ? $url . '?' . $query : $url );
	}

	/**
	 * One spelling per URL: bytes outside a URL's character set (raw UTF-8, say)
	 * percent-encoded, every escape upper-cased.
	 *
	 * WordPress links a post as `caf%c3%a9`, a browser sends a typed address as
	 * `caf%C3%A9`; both are one page, so one list entry.
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
	 * read as the listed front page. Only args in Drop Query String (`*` matching
	 * any characters, as there) keep it the same page.
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
	 * A page's cache tags, as stored in `cache_tags`: `,tag1,tag2,`.
	 *
	 * Comma-wrapped so one tag is matched exactly with `LIKE '%,tag,%'`. Left out:
	 * '' (the blog's main tag) and 'guest', purged only by site-wide purges that
	 * must not expire builds, and `OPTIMAX.*`, our own. Cut at a tag boundary to
	 * fit the column; a page losing tags that way still expires through the
	 * direct triggers.
	 *
	 * @since 8.0
	 *
	 * @param array $tags Raw tags, without the blog prefix.
	 * @return string
	 */
	public static function tags_to_column( $tags ) {
		$col = '';
		foreach ( array_unique( array_map( 'strval', (array) $tags ) ) as $tag ) {
			if ( '' === $tag || 'guest' === $tag || 0 === strpos( $tag, 'OPTIMAX.' ) || false !== strpos( $tag, ',' ) ) {
				continue;
			}
			$next = ( '' === $col ? ',' : $col ) . $tag . ',';
			if ( strlen( $next ) > self::TAGS_MAX ) {
				break;
			}
			$col = $next;
		}

		return $col;
	}

	/**
	 * The purged tags worth matching against stored pages.
	 *
	 * A site-wide purge — '*' (Purge All) or '' (the blog's main tag) — returns
	 * nothing: Purge All must not expire OptimaX builds.
	 *
	 * @since 8.0
	 *
	 * @param array $tags Raw purge tags, before the blog prefix.
	 * @return array
	 */
	public static function purge_tags_to_match( $tags ) {
		$tags = array_map( 'strval', (array) $tags );
		if ( in_array( '*', $tags, true ) || in_array( '', $tags, true ) ) {
			return [];
		}

		$match = [];
		foreach ( $tags as $tag ) {
			if ( 'guest' === $tag || 0 === strpos( $tag, 'OPTIMAX.' ) || false !== strpos( $tag, ',' ) ) {
				continue;
			}
			$match[ $tag ] = true;
		}

		return array_keys( $match );
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
	 * A version's status. Precedence: Working on it > In queue > In Use > Needs refresh.
	 *
	 * @since 8.0
	 *
	 * @param string|null $queue_status Null when not queued; the queue row's `_status` otherwise ('queued' = sent).
	 * @param bool        $live         Has a build row with `expired = 0`.
	 * @param bool        $expired      Has a build row with `expired > 0`.
	 * @return string One of the STATUS_* constants, or '' for none.
	 */
	public static function version_status( $queue_status, $live, $expired ) {
		if ( null !== $queue_status ) {
			return 'queued' === $queue_status ? self::STATUS_WORKING : self::STATUS_QUEUED;
		}
		if ( $live ) {
			return self::STATUS_IN_USE;
		}
		if ( $expired ) {
			return self::STATUS_REFRESH;
		}
		return '';
	}

	/**
	 * Whether this blog's URL table can hold the list.
	 *
	 * @since 8.0
	 *
	 * @return bool
	 */
	public function ready() {
		return Data::cls()->url_has_ox_col();
	}

	/**
	 * Add the `ox` column when this blog has not run its 8.0 upgrade yet.
	 *
	 * Sites already on 8.0.0 never reach an `8.0-bX` updater, and a subsite only
	 * upgrades on its own first request after an update; the OptimaX admin page
	 * calls this so the list works without waiting for either. It also lists the
	 * homepage, which is always an OptimaX page.
	 *
	 * @since 8.0
	 *
	 * @return bool Whether the column exists now.
	 */
	public function ensure_ready() {
		if ( ! $this->ready() ) {
			require_once LSCWP_DIR . 'src/data.upgrade.func.php';
			\litespeed_url_add_ox_column();
			if ( ! Data::cls()->url_has_ox_col( true ) ) {
				return false;
			}
		}

		$this->add( '/' );

		return true;
	}

	/**
	 * normalize() for this site.
	 *
	 * @since 8.0
	 *
	 * @param string $input Path or full URL.
	 * @return string|false
	 */
	public static function page_url( $input ) {
		return self::normalize( $input, home_url(), (bool) get_option( 'permalink_structure' ) );
	}

	/**
	 * Whether this page is the homepage, which is always listed.
	 *
	 * @since 8.0
	 *
	 * @param string $url Page URL, as page_url() builds it.
	 * @return bool
	 */
	public static function is_home( $url ) {
		return self::page_url( '/' ) === self::canonical_url( (string) $url );
	}

	/**
	 * The listed page with this URL.
	 *
	 * @since 8.0
	 *
	 * @param string $url Page URL, as page_url() / Utility::request_url() build it (spelled any way).
	 * @return array|null `id`, `url`, `cache_tags`.
	 */
	public function get( $url ) {
		global $wpdb;

		if ( ! is_string( $url ) || '' === $url || ! $this->ready() ) {
			return null;
		}

		$tb  = Data::cls()->tb( 'url' );
		$q   = "SELECT id, url, cache_tags FROM `$tb` WHERE url = %s AND ox = 1";
		$row = $wpdb->get_row( $wpdb->prepare( $q, self::canonical_url( $url ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $row ? $row : null;
	}

	/**
	 * Every listed page.
	 *
	 * @since 8.0
	 *
	 * @return array Rows with `id`, `url`, `cache_tags`.
	 */
	public function all() {
		global $wpdb;

		if ( ! $this->ready() ) {
			return [];
		}

		$tb = Data::cls()->tb( 'url' );
		return (array) $wpdb->get_results( "SELECT id, url, cache_tags FROM `$tb` WHERE ox = 1 ORDER BY url", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * How many pages are listed.
	 *
	 * @since 8.0
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		if ( ! $this->ready() ) {
			return 0;
		}

		$tb = Data::cls()->tb( 'url' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tb` WHERE ox = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * How many pages the list may hold; 0 = no limit.
	 *
	 * The one place the QUIC.cloud account limit will be enforced. Lowering it
	 * never removes pages; it only stops new ones being added.
	 *
	 * @since 8.0
	 *
	 * @return int
	 */
	public static function max_links() {
		return 0;
	}

	/**
	 * Add a page to the list.
	 *
	 * @since 8.0
	 *
	 * @param string $input Path or full URL the owner typed.
	 * @return true|string True, or an error code: 'not_ready', 'invalid', 'exists', 'limit'.
	 */
	public function add( $input ) {
		global $wpdb;

		if ( ! $this->ready() ) {
			return 'not_ready';
		}

		$url = self::page_url( $input );
		if ( ! $url ) {
			return 'invalid';
		}
		if ( $this->get( $url ) ) {
			return 'exists';
		}
		$max = self::max_links();
		if ( $max && $this->count() >= $max ) {
			return 'limit';
		}

		// UCSS, CCSS or an earlier OptimaX build may already own a row for this URL: flag it, never duplicate it.
		$tb = Data::cls()->tb( 'url' );
		$q  = "SELECT id FROM `$tb` WHERE url = %s";
		$id = (int) $wpdb->get_var( $wpdb->prepare( $q, $url ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $id ) {
			$q = "UPDATE `$tb` SET ox = 1 WHERE id = %d";
			$wpdb->query( $wpdb->prepare( $q, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			// A build made before the page was listed may be stale.
			$this->expire( $id );
		} else {
			$q = "INSERT INTO `$tb` SET url = %s, ox = 1";
			$wpdb->query( $wpdb->prepare( $q, $url ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		// LSCache serves its copy without reaching serve(): the page would not be queued until it expired.
		$this->cls( 'Purge' )->purge_url( $url, false, true );

		self::debug( 'Page added to OptimaX list: ' . $url );
		return true;
	}

	/**
	 * Remove a page and everything OptimaX holds for it.
	 *
	 * Its builds and their files (a file another page still uses is kept: files
	 * are named by content md5), its queue rows, its cached copy. The URL row
	 * itself stays for UCSS/CCSS; the next prune drops it if nothing uses it.
	 *
	 * @since 8.0
	 *
	 * @param int $id Page row id.
	 * @return bool False when no listed page has this id, or it is the homepage.
	 */
	public function remove( $id ) {
		global $wpdb;

		if ( ! $this->ready() ) {
			return false;
		}

		$tb_url = Data::cls()->tb( 'url' );

		$q   = "SELECT id, url FROM `$tb_url` WHERE id = %d AND ox = 1";
		$row = $wpdb->get_row( $wpdb->prepare( $q, (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || self::is_home( $row['url'] ) ) {
			return false;
		}

		// Unlist first: the purges below reach the change listeners, which then find nothing to expire.
		$q = "UPDATE `$tb_url` SET ox = 0, cache_tags = '' WHERE id = %d";
		$wpdb->query( $wpdb->prepare( $q, (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$this->_delete_builds( $wpdb->prepare( 'url_id = %d', (int) $id ) );

		$queue  = $this->load_queue( 'optimax' );
		$before = count( $queue );
		foreach ( $queue as $k => $v ) {
			if ( is_array( $v ) && isset( $v['url_tag'] ) && $v['url_tag'] === $row['url'] ) {
				unset( $queue[ $k ] );
			}
		}
		if ( count( $queue ) !== $before ) {
			$this->save_queue( 'optimax', $queue );
		}

		Purge::add( Optimax::page_tag( $row['url'] ) );
		$this->cls( 'Purge' )->purge_url( $row['url'], false, true );

		self::debug( 'Page removed from OptimaX list: ' . $row['url'] );
		return true;
	}

	/**
	 * Stop serving a page's builds until they are rebuilt.
	 *
	 * Uses the expiry Data::save_url() gives a replaced build, so load_url_file()
	 * stops returning them at once, the next visitor of each version queues a
	 * rebuild, and clean_expired() deletes them once that is stored and their
	 * grace period is over.
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
		$types = implode( ',', array_map( [ $data, 'file_type_id' ], [ 'optimax', 'optimax_js', 'optimax_ucss', 'optimax_imgs' ] ) );
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
	 * A page's versions: one per vary, from its builds and its queue rows.
	 *
	 * @since 8.0
	 *
	 * @param array $page A row from get()/all().
	 * @return array List of `vary`, `groups`, `status`, `q_k` (md5 of the queue key, '' if not queued), `file_id` (a build row id, 0 if none).
	 */
	public function versions( $page ) {
		global $wpdb;

		$tb       = Data::cls()->tb( 'url_file' );
		$type     = Data::cls()->file_type_id( 'optimax' );
		$versions = [];

		$q    = "SELECT id, vary, mobile, webp, expired FROM `$tb` WHERE url_id = %d AND type = %d ORDER BY id DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $q, (int) $page['id'], $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $r ) {
			$k = (string) $r['vary'];
			if ( ! isset( $versions[ $k ] ) ) {
				$versions[ $k ] = [
					'vary'         => $k,
					'groups'       => self::version_groups( $k, $r['mobile'], $r['webp'] ),
					'live'         => false,
					'expired'      => false,
					'file_id'      => 0,
					'q_k'          => '',
					'queue_status' => null,
				];
			}
			if ( 0 === (int) $r['expired'] ) {
				$versions[ $k ]['live']    = true;
				$versions[ $k ]['file_id'] = (int) $r['id'];
			} else {
				$versions[ $k ]['expired'] = true;
				if ( ! $versions[ $k ]['file_id'] ) {
					$versions[ $k ]['file_id'] = (int) $r['id'];
				}
			}
		}

		foreach ( $this->load_queue( 'optimax' ) as $queue_k => $v ) {
			if ( ! is_array( $v ) || ! isset( $v['url_tag'], $v['vary'] ) || $v['url_tag'] !== $page['url'] ) {
				continue;
			}
			$k = self::vary_key( $v['vary'] );
			if ( ! isset( $versions[ $k ] ) ) {
				$versions[ $k ] = [
					'vary'    => $k,
					'live'    => false,
					'expired' => false,
					'file_id' => 0,
				];
			}
			// The queue keeps the raw vary, which reads better than a hashed one.
			$versions[ $k ]['groups']       = self::version_groups( $v['vary'], ! empty( $v['is_mobile'] ), ! empty( $v['is_nextgen'] ) );
			$versions[ $k ]['q_k']          = md5( $queue_k );
			$versions[ $k ]['queue_status'] = ! empty( $v['_status'] ) ? (string) $v['_status'] : '';
		}

		$out = [];
		foreach ( $versions as $ver ) {
			$out[] = [
				'vary'    => $ver['vary'],
				'groups'  => $ver['groups'],
				'status'  => self::version_status( $ver['queue_status'], $ver['live'], $ver['expired'] ),
				'q_k'     => $ver['q_k'],
				'file_id' => $ver['file_id'],
			];
		}
		return $out;
	}

	/**
	 * Queue a version again from its build row.
	 *
	 * No user agent is sent: QC renders with its own, and a build row keeps none.
	 *
	 * @since 8.0
	 *
	 * @param int $file_id An `optimax` build row id of a listed page.
	 * @return string|false The queue key.
	 */
	public function queue_version( $file_id ) {
		global $wpdb;

		$data    = Data::cls();
		$tb_url  = $data->tb( 'url' );
		$tb_file = $data->tb( 'url_file' );

		$q   = "SELECT f.vary, f.mobile, f.webp, u.url FROM `$tb_file` f JOIN `$tb_url` u ON u.id = f.url_id WHERE f.id = %d AND f.type = %d AND u.ox = 1";
		$row = $wpdb->get_row( $wpdb->prepare( $q, (int) $file_id, $data->file_type_id( 'optimax' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return false;
		}

		// The stored vary is already in its 32-char-at-most form, so this is the key serve() would build.
		$queue_k = $row['vary'] . ' ' . $row['url'];
		$queue   = $this->load_queue( 'optimax' );
		if ( ! isset( $queue[ $queue_k ] ) ) {
			$queue[ $queue_k ] = [
				'url'        => apply_filters( 'litespeed_optimax_url', $row['url'] ),
				'is_mobile'  => (bool) $row['mobile'],
				'is_nextgen' => $row['webp'] ? $this->cls( 'Media' )->webp_support( true ) : '',
				'uid'        => 0,
				'vary'       => $row['vary'],
				'url_tag'    => $row['url'],
			];
			$this->save_queue( 'optimax', $queue );
			self::debug( 'Re-queued from build row [file_id] ' . (int) $file_id );
		}

		return $queue_k;
	}

	/**
	 * Drop one queue row.
	 *
	 * @since 8.0
	 *
	 * @param string $q_k md5 of the queue key, as versions() reports it.
	 * @return bool
	 */
	public function dequeue( $q_k ) {
		$queue = $this->load_queue( 'optimax' );
		foreach ( array_keys( $queue ) as $k ) {
			if ( md5( $k ) === $q_k ) {
				unset( $queue[ $k ] );
				$this->save_queue( 'optimax', $queue );
				return true;
			}
		}
		return false;
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
	 * Register the listeners.
	 *
	 * The change listeners run while OptimaX is off too: its builds stay, and a
	 * page changed meanwhile must not be served stale once it is back on.
	 *
	 * @since 8.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'litespeed_purge_tags_added', [ $this, 'on_purge_tags' ] );
		add_action( 'litespeed_purged_post', [ $this, 'on_purged_post' ] );
		add_action( 'litespeed_purged_link', [ $this, 'on_purged_link' ] );

		if ( $this->conf( Base::O_OPTIMAX ) ) {
			add_action( 'litespeed_tags_finalized', [ $this, 'on_tags_finalized' ] );
		}
	}

	/**
	 * Remember that this render is a listed page, so its final tags get stored.
	 *
	 * @since 8.0
	 *
	 * @param int    $id      Page row id.
	 * @param string $current Its stored `cache_tags`.
	 * @return void
	 */
	public function record_tags_for( $id, $current ) {
		$this->_record = [
			'id'   => (int) $id,
			'tags' => (string) $current,
		];
		$this->_store_tags();
	}

	/**
	 * Keep the page's final cache tags, to store once serve() says the page is listed.
	 *
	 * Core::send_headers() usually runs from the footer hook, before the output
	 * buffer reaches serve(), so either call can come first; the second stores.
	 *
	 * @since 8.0
	 *
	 * @param array $tags Tag::output_tags() after Tag::output().
	 * @return void
	 */
	public function on_tags_finalized( $tags ) {
		$this->_final_tags = (array) $tags;
		$this->_store_tags();
	}

	/**
	 * Store the listed page's final cache tags, when both are known and they changed.
	 *
	 * @since 8.0
	 *
	 * @return void
	 */
	private function _store_tags() {
		global $wpdb;

		if ( ! $this->_record || null === $this->_final_tags || ! Control::is_cacheable() ) {
			return;
		}

		$col = self::tags_to_column( $this->_final_tags );
		if ( $col !== $this->_record['tags'] ) {
			$tb = Data::cls()->tb( 'url' );
			$q  = "UPDATE `$tb` SET cache_tags = %s WHERE id = %d AND ox = 1";
			$wpdb->query( $wpdb->prepare( $q, $col, $this->_record['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			self::debug( 'Recorded cache tags [url_id] ' . $this->_record['id'] );
		}
		$this->_record = null;
	}

	/**
	 * Turn the change listeners off while OptimaX stores a build.
	 *
	 * @since 8.0
	 *
	 * @param bool $on Ignore purges.
	 * @return void
	 */
	public function ignore_purges( $on ) {
		$this->_ignore_purges = (bool) $on;
	}

	/**
	 * Expire every listed page cached with one of the purged tags.
	 *
	 * @since 8.0
	 *
	 * @param array $tags Raw purge tags.
	 * @return void
	 */
	public function on_purge_tags( $tags ) {
		global $wpdb;

		if ( $this->_ignore_purges || ! $this->ready() ) {
			return;
		}
		$match = self::purge_tags_to_match( (array) $tags );
		if ( ! $match ) {
			return;
		}

		$tb = Data::cls()->tb( 'url' );
		foreach ( array_chunk( $match, 50 ) as $chunk ) {
			$where = [];
			$args  = [];
			foreach ( $chunk as $tag ) {
				$where[] = 'cache_tags LIKE %s';
				$args[]  = '%,' . $wpdb->esc_like( $tag ) . ',%';
			}
			$q   = "SELECT id FROM `$tb` WHERE ox = 1 AND (" . implode( ' OR ', $where ) . ')';
			$ids = $wpdb->get_col( $wpdb->prepare( $q, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $ids as $id ) {
				$this->expire( (int) $id );
			}
		}
	}

	/**
	 * A post was purged: expire its page if listed.
	 *
	 * With "Purge All" in the post purge rules, a post change purges only '*',
	 * which on_purge_tags() ignores so a manual Purge All never rebuilds. Here it
	 * is a content change, so every listed page is stale. Drafts are skipped:
	 * saving one changes no public page, and its permalink is `/?p=123`, which
	 * pretty permalinks would read as the front page.
	 *
	 * @since 8.0
	 *
	 * @param int $pid Post id.
	 * @return void
	 */
	public function on_purged_post( $pid ) {
		$status = get_post_status( $pid );
		if ( $this->conf( Base::O_PURGE_POST_ALL ) && in_array( $status, [ 'publish', 'trash', 'private' ], true ) ) {
			$this->_expire_all();
			return;
		}
		if ( 'publish' !== $status ) {
			return;
		}
		$this->_expire_url( get_permalink( $pid ) );
	}

	/**
	 * Expire every listed page.
	 *
	 * @since 8.0
	 *
	 * @return void
	 */
	private function _expire_all() {
		if ( $this->_ignore_purges ) {
			return;
		}
		foreach ( $this->all() as $page ) {
			$this->expire( (int) $page['id'] );
		}
	}

	/**
	 * A URL was purged: expire its page if listed.
	 *
	 * @since 8.0
	 *
	 * @param string $url Purged URL, full or relative.
	 * @return void
	 */
	public function on_purged_link( $url ) {
		// With pretty permalinks a query string is another URL WordPress serves the same page at; it is not this page.
		if ( false !== strpos( (string) $url, '?' ) && get_option( 'permalink_structure' ) ) {
			return;
		}
		$this->_expire_url( $url );
	}

	/**
	 * Expire the listed page at this URL.
	 *
	 * @since 8.0
	 *
	 * @param string|false $url Full or relative URL.
	 * @return void
	 */
	private function _expire_url( $url ) {
		if ( $this->_ignore_purges || ! $url ) {
			return;
		}
		$page = $this->get( (string) self::page_url( $url ) );
		if ( $page ) {
			$this->expire( (int) $page['id'] );
		}
	}
}
