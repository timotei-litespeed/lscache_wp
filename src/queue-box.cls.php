<?php
/**
 * Shared queue box: one view model for the UCSS / CCSS / VPI / LQIP queues.
 *
 * Feeds tpl/inc/queue_box.tpl.php (settings pages), the Queues overview on
 * the dashboard, the `wp litespeed-queue` CLI and the stuck-queue notice.
 *
 * @since   8.1
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * Class Queue_Box
 *
 * @since 8.1
 */
class Queue_Box extends Base {

	const LOG_TAG = '[Queue]';

	/**
	 * URL groups shown per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Service definitions.
	 *
	 * @return array<string,array>
	 */
	public static function svcs() {
		return [
			'ucss' => [
				'cls'       => 'UCSS',
				'title'     => 'UCSS',
				'action'    => Router::ACTION_UCSS,
				'cloud_svc' => Cloud::SVC_UCSS,
				'cron_opt'  => Base::O_OPTM_UCSS,
				'page'      => 'litespeed-page_optm',
				'tab'       => 'settings_css',
			],
			'ccss' => [
				'cls'       => 'CSS',
				'title'     => 'CCSS',
				'action'    => Router::ACTION_CSS,
				'cloud_svc' => Cloud::SVC_CCSS,
				'cron_opt'  => Base::O_OPTM_CSS_ASYNC,
				'page'      => 'litespeed-page_optm',
				'tab'       => 'settings_css',
			],
			'vpi'  => [
				'cls'       => 'VPI',
				'title'     => 'VPI',
				'action'    => Router::ACTION_VPI,
				'cloud_svc' => Cloud::SVC_VPI,
				'cron_opt'  => Base::O_MEDIA_VPI_CRON,
				'page'      => 'litespeed-page_optm',
				'tab'       => 'settings_vpi',
			],
			'lqip' => [
				'cls'       => 'Placeholder',
				'title'     => 'LQIP',
				'action'    => Router::ACTION_PLACEHOLDER,
				'cloud_svc' => Cloud::SVC_LQIP,
				'cron_opt'  => Base::O_MEDIA_PLACEHOLDER_RESP_ASYNC,
				'page'      => 'litespeed-page_optm',
				'tab'       => 'settings_media',
			],
		];
	}

	/**
	 * Whether a svc id is known.
	 *
	 * @param string $svc Svc id.
	 * @return bool
	 */
	public static function is_svc( $svc ) {
		$svcs = self::svcs();
		return isset( $svcs[ $svc ] );
	}

	/**
	 * Service instance (UCSS / CSS / VPI / Placeholder).
	 *
	 * @param string $svc Svc id.
	 * @return object
	 */
	public static function instance( $svc ) {
		$svcs = self::svcs();
		return Root::cls( $svcs[ $svc ]['cls'] );
	}

	/**
	 * Router type names of the queue actions for one svc.
	 *
	 * @param string $svc Svc id.
	 * @return array<string,string>
	 */
	public static function types( $svc ) {
		$svcs  = self::svcs();
		$cls   = __NAMESPACE__ . '\\' . $svcs[ $svc ]['cls'];
		$types = [
			'gen'           => 'lqip' === $svc ? $cls::TYPE_GENERATE : $cls::TYPE_GEN,
			'clear_q'       => $cls::TYPE_CLEAR_Q,
			'gen_item'      => $cls::TYPE_GEN_ITEM,
			'rm_item'       => $cls::TYPE_RM_ITEM,
			'retry_item'    => $cls::TYPE_RETRY_ITEM,
			'clear_failed'  => $cls::TYPE_CLEAR_FAILED,
			'clear_waiting' => $cls::TYPE_CLEAR_WAITING,
			'clear_all'     => $cls::TYPE_CLEAR_ALL,
			'run_next'      => $cls::TYPE_RUN_NEXT,
			'pause'         => $cls::TYPE_PAUSE,
			'resume'        => $cls::TYPE_RESUME,
		];
		return $types;
	}

	/**
	 * Admin URL (with nonce) for one queue action.
	 *
	 * @param string $svc    Svc id.
	 * @param string $type   Key of types().
	 * @param array  $append Extra query args.
	 * @param bool   $ajax   Build an admin-ajax URL.
	 * @return string
	 */
	public static function url( $svc, $type, $append = [], $ajax = false ) {
		$svcs  = self::svcs();
		$types = self::types( $svc );
		return Utility::build_url( $svcs[ $svc ]['action'], $types[ $type ], $ajax, null, $append );
	}

	/**
	 * Link to the settings tab that hosts the queue box.
	 *
	 * @param string $svc Svc id.
	 * @return string
	 */
	public static function box_link( $svc ) {
		$svcs = self::svcs();
		return admin_url( 'admin.php?page=' . $svcs[ $svc ]['page'] . '#' . $svcs[ $svc ]['tab'] );
	}

	/**
	 * Local HH:MM for a timestamp.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	public static function fmt_time( $ts ) {
		return wp_date( get_option( 'time_format' ), (int) $ts );
	}

	/**
	 * Normalized queue rows for one svc.
	 *
	 * @param string $svc Svc id.
	 * @param string $qs  Case-insensitive substring filter on the URL.
	 * @return array<string,array> hash => row.
	 */
	public static function items( $svc, $qs = '' ) {
		$instance = self::instance( $svc );
		$queue    = $instance->load_q();
		$now      = time();
		$rows     = [];
		$qs       = (string) $qs;

		foreach ( $queue as $k => $v ) {
			$k = (string) $k;
			if ( ! is_array( $v ) ) {
				$v = [];
			}
			$pos   = strpos( $k, ' ' );
			$group = false !== $pos ? substr( $k, 0, $pos ) : '';
			$rest  = false !== $pos ? substr( $k, $pos + 1 ) : $k;

			if ( 'lqip' === $svc ) {
				$url  = $rest;
				$vary = '';
				$size = $group;
			} else {
				$url  = isset( $v['url'] ) ? (string) $v['url'] : $rest;
				$vary = 'vpi' === $svc ? '' : $group;
				$size = '';
			}

			if ( '' !== $qs && false === stripos( $url, $qs ) ) {
				continue;
			}

			$state = Cloud_Queue_Svc::item_state( $v );
			$tries = ! empty( $v['_tries'] ) ? (int) $v['_tries'] : 0;

			$rows[ md5( $k ) ] = [
				'hash'     => md5( $k ),
				'k'        => $k,
				'url'      => $url,
				'vary'     => $vary,
				'size'     => $size,
				'mobile'   => ! empty( $v['is_mobile'] ),
				'nextgen'  => ! empty( $v['is_webp'] ) || ! empty( $v['is_nextgen'] ),
				'state'    => $state,
				'runnable' => Cloud_Queue_Svc::item_runnable( $v, $now ),
				'tries'    => $tries,
				'err'      => ! empty( $v['_err'] ) ? (string) $v['_err'] : '',
				'err_at'   => ! empty( $v['_err_at'] ) ? (int) $v['_err_at'] : 0,
				'retry_at' => ! empty( $v['_err_at'] ) ? (int) $v['_err_at'] + Cloud_Queue_Svc::RETRY_LATER_TTL : 0,
				'added'    => ! empty( $v['_added'] ) ? (int) $v['_added'] : 0,
				'sent_at'  => ! empty( $v['_sent_at'] ) ? (int) $v['_sent_at'] : 0,
				'prio'     => ! empty( $v['_prio'] ) ? (int) $v['_prio'] : 0,
			];
		}

		return $rows;
	}

	/**
	 * Group rows by URL: one line per URL with its versions.
	 *
	 * @param array $rows Rows from items().
	 * @return array<string,array{url:string,versions:array}>
	 */
	public static function group( $rows ) {
		$groups = [];
		foreach ( $rows as $row ) {
			$url = $row['url'];
			if ( ! isset( $groups[ $url ] ) ) {
				$groups[ $url ] = [
					'url'      => $url,
					'versions' => [],
				];
			}
			$groups[ $url ]['versions'][] = $row;
		}
		return $groups;
	}

	/**
	 * Short label for one version badge.
	 *
	 * @param array $row Row.
	 * @return string Plain text.
	 */
	public static function version_label( $row ) {
		$parts = [];
		if ( $row['size'] ) {
			$parts[] = $row['size'];
		}
		if ( $row['mobile'] ) {
			$parts[] = __( 'Mobile', 'litespeed-cache' );
		}
		if ( $row['nextgen'] ) {
			$parts[] = Root::cls( 'Media' )->next_gen_image_title();
		}
		if ( $row['vary'] ) {
			$parts[] = __( 'Vary', 'litespeed-cache' ) . ': ' . $row['vary'];
		}
		if ( ! $parts ) {
			$parts[] = __( 'Desktop', 'litespeed-cache' );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Human text for a row state.
	 *
	 * @param array $row Row.
	 * @return array{0:string,1:string} CSS modifier and text.
	 */
	public static function state_text( $row ) {
		switch ( $row['state'] ) {
			case 'sent':
				return [ 'success', __( 'Sent (building on QUIC.cloud)', 'litespeed-cache' ) ];
			case 'failed':
				/* translators: %s: HH:MM */
				return [ 'warning', sprintf( __( 'Failed, retry at %s', 'litespeed-cache' ), self::fmt_time( $row['retry_at'] ) ) ];
			case 'dead':
				/* translators: %d: number of attempts */
				return [ 'danger', sprintf( __( 'Not retried (failed %d times)', 'litespeed-cache' ), $row['tries'] ) ];
			default:
				return [ 'info', __( 'Waiting', 'litespeed-cache' ) ];
		}
	}

	/**
	 * Queue-level info for one svc: counts, state and the "what happens next" line.
	 *
	 * @param string $svc Svc id.
	 * @return array
	 */
	public static function info( $svc ) {
		$svcs     = self::svcs();
		$conf     = $svcs[ $svc ];
		$instance = self::instance( $svc );
		$queue    = $instance->load_q();
		$rows     = self::items( $svc );
		$state    = $instance->queue_state();
		$counts   = Cloud_Queue_Svc::queue_counts( $queue );
		$now      = time();

		$self       = self::cls();
		$guest_optm = $self->conf( Base::O_GUEST ) && $self->conf( Base::O_GUEST_OPTM ) && in_array( $conf['cron_opt'], [ Base::O_OPTM_CSS_ASYNC, Base::O_OPTM_UCSS ], true );
		$cron_on    = (bool) $self->conf( $conf['cron_opt'] ) || $guest_optm;
		$hook       = Task::hook_name( $conf['cron_opt'] );
		$next_sched = $hook ? (int) wp_next_scheduled( $hook ) : 0;
		$wpcron_off = Task::wp_cron_off();
		$hot        = Root::cls( 'Cloud' )->service_hot( $conf['cloud_svc'] );

		$oldest_added = 0;
		foreach ( $rows as $row ) {
			if ( 'dead' === $row['state'] ) {
				continue;
			}
			if ( $row['added'] && ( ! $oldest_added || $row['added'] < $oldest_added ) ) {
				$oldest_added = $row['added'];
			}
		}

		$info = [
			'svc'            => $svc,
			'title'          => $conf['title'],
			'counts'         => $counts,
			'urls'           => count( self::group( $rows ) ),
			'paused'         => $state['paused'],
			'next_run_after' => $state['next_run_after'] > $now ? $state['next_run_after'] : 0,
			'last_err'       => $state['last_err'],
			'last_err_at'    => $state['last_err_at'],
			'last_progress'  => $state['last_progress'],
			'stuck_notified' => $state['stuck_notified'],
			'cron_on'        => $cron_on,
			'cron_title'     => Lang::title( $conf['cron_opt'] ),
			'wpcron_off'     => $wpcron_off,
			'next_sched'     => $next_sched,
			'hot'            => $hot ? (int) $hot : 0,
			'oldest_added'   => $oldest_added,
			'link'           => self::box_link( $svc ),
		];

		// Show the last error only when it is newer than the last success.
		$info['err_recent'] = $info['last_err'] && $info['last_err_at'] >= $info['last_progress'] ? $info['last_err'] : '';

		$info['next_line'] = self::next_line( $info );
		$info['stuck']     = self::is_stuck( $info );

		return $info;
	}

	/**
	 * "What happens next" line for a queue.
	 *
	 * @param array $info From info().
	 * @return string Plain text.
	 */
	public static function next_line( $info ) {
		if ( $info['paused'] ) {
			return __( 'Paused. Cron skips this queue; use Run to process items by hand.', 'litespeed-cache' );
		}
		if ( $info['next_run_after'] ) {
			/* translators: %s: HH:MM */
			$line = sprintf( __( 'Waiting for QUIC.cloud until %s.', 'litespeed-cache' ), self::fmt_time( $info['next_run_after'] ) );
			if ( $info['err_recent'] ) {
				$line .= ' ' . $info['err_recent'];
			}
			return $line;
		}
		if ( ! $info['cron_on'] ) {
			/* translators: %s: option title */
			return sprintf( __( 'Cron is off (%s) — use Run.', 'litespeed-cache' ), $info['cron_title'] );
		}
		if ( $info['wpcron_off'] ) {
			return __( 'WP-Cron is disabled on this site — use Run or a system cron.', 'litespeed-cache' );
		}
		if ( $info['next_sched'] ) {
			$diff = $info['next_sched'] - time();
			if ( $diff <= 0 ) {
				return __( 'Next run: due now (cron).', 'litespeed-cache' );
			}
			/* translators: %d: minutes */
			return sprintf( __( 'Next run in ~%d min (cron).', 'litespeed-cache' ), max( 1, (int) ceil( $diff / 60 ) ) );
		}
		return __( 'Cron is scheduled on the next page load (every 15 minutes).', 'litespeed-cache' );
	}

	/**
	 * Whether a queue has had items waiting for over an hour with no progress.
	 *
	 * @param array $info From info().
	 * @return bool
	 */
	public static function is_stuck( $info ) {
		if ( $info['paused'] || ! $info['counts']['total'] ) {
			return false;
		}
		// Only dead items left: nothing is "waiting".
		if ( $info['counts']['total'] === $info['counts']['dead'] ) {
			return false;
		}
		$now = time();
		if ( ! $info['oldest_added'] || $now - $info['oldest_added'] < Cloud_Queue_Svc::STUCK_TTL ) {
			return false;
		}
		if ( $info['last_progress'] && $now - $info['last_progress'] < Cloud_Queue_Svc::STUCK_TTL ) {
			return false;
		}
		return true;
	}

	/**
	 * Add a one-time admin notice for every stuck queue (once per episode).
	 *
	 * @return void
	 */
	public static function maybe_stuck_notices() {
		foreach ( array_keys( self::svcs() ) as $svc ) {
			$info = self::info( $svc );
			if ( ! $info['stuck'] ) {
				continue;
			}
			// Already notified since the last progress / since the oldest item was added.
			$episode = max( $info['last_progress'], $info['oldest_added'] );
			if ( $info['stuck_notified'] && $info['stuck_notified'] >= $episode ) {
				continue;
			}

			$msg = sprintf(
				/* translators: 1: service name, 2: waiting count, 3: reason line */
				__( 'The %1$s queue has had %2$d item(s) waiting for over an hour without progress. %3$s', 'litespeed-cache' ),
				$info['title'],
				$info['counts']['total'] - $info['counts']['dead'],
				$info['next_line']
			);
			$msg .= ' <a href="' . esc_url( $info['link'] ) . '">' . esc_html__( 'Open the queue', 'litespeed-cache' ) . '</a>';
			Admin_Display::note( $msg );
			self::instance( $svc )->mark_stuck_notified();
			self::debug( 'Stuck notice added [svc] ' . $svc );
		}
	}

	/**
	 * Render the queue box for one svc (tpl/inc/queue_box.tpl.php).
	 *
	 * Template variables: `$qb` (array) with svc, title, action, types,
	 * ajax_run_next, info, counts, groups (current page), page, pages, qs,
	 * base_url (page URL without qs / qpage), box_id, anchor.
	 *
	 * @param string $svc Svc id.
	 * @return void
	 */
	public static function render( $svc ) {
		if ( ! self::is_svc( $svc ) ) {
			return;
		}
		$svcs = self::svcs();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only paging / search of the current page.
		$qsvc  = isset( $_GET['qsvc'] ) ? sanitize_key( wp_unslash( $_GET['qsvc'] ) ) : '';
		$qs    = $qsvc === $svc && isset( $_GET['qs'] ) ? sanitize_text_field( wp_unslash( $_GET['qs'] ) ) : '';
		$qpage = $qsvc === $svc && isset( $_GET['qpage'] ) ? max( 1, (int) $_GET['qpage'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$info   = self::info( $svc );
		$rows   = self::items( $svc, $qs );
		$groups = self::group( $rows );
		$pages  = max( 1, (int) ceil( count( $groups ) / self::PER_PAGE ) );
		$qpage  = min( $qpage, $pages );

		$qb = [
			'svc'           => $svc,
			'title'         => $svcs[ $svc ]['title'],
			'action'        => $svcs[ $svc ]['action'],
			'types'         => self::types( $svc ),
			'ajax_run_next' => self::url( $svc, 'run_next', [], true ),
			'info'          => $info,
			'counts'        => $info['counts'],
			'matched'       => count( $rows ),
			'groups'        => array_slice( $groups, ( $qpage - 1 ) * self::PER_PAGE, self::PER_PAGE, true ),
			'groups_total'  => count( $groups ),
			'page'          => $qpage,
			'pages'         => $pages,
			'qs'            => $qs,
			'base_url'      => add_query_arg( [ 'qsvc' => $svc ], remove_query_arg( [ 'qsvc', 'qs', 'qpage', Router::ACTION, Router::NONCE, Router::TYPE ] ) ),
			'box_id'        => 'litespeed-queue-' . $svc,
			'anchor'        => $svcs[ $svc ]['tab'],
		];

		require LSCWP_DIR . 'tpl/inc/queue_box.tpl.php';
	}
}
