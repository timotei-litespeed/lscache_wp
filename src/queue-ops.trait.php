<?php
/**
 * Shared queue item operations for QUIC.cloud queue services.
 *
 * Used by Cloud_Queue_Svc (UCSS / CCSS / VPI / Optimax) and Placeholder
 * (LQIP). Provides the pure status / ordering helpers, the per-item admin
 * actions (run / retry / remove), selective clearing, pause / resume and the
 * one-item-per-call `run_next` endpoint that drives "Run until empty".
 *
 * The using class must provide: _svc_id(), _svc_const(), _run_item(),
 * _next_run_after_key(), the TYPE_* / MAX_TRIES / RETRY_LATER_TTL constants,
 * and the Root queue + summary API.
 *
 * @since   8.1
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * Trait Queue_Ops
 *
 * @since 8.1
 */
trait Queue_Ops {

	/**
	 * Last short error reason captured while sending one item.
	 *
	 * @var string
	 */
	protected $_last_err = '';

	/*
	 * ---------------------------------------------------------------------
	 * Pure helpers (no WP / IO), also exercised by the standalone smoke test.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Derive the display state of one queue item.
	 *
	 * @param mixed $v Queue item.
	 * @return string One of waiting|sent|failed|dead.
	 */
	public static function item_state( $v ) {
		if ( ! is_array( $v ) ) {
			return 'waiting';
		}
		if ( ! empty( $v['_status'] ) && 'queued' === $v['_status'] ) {
			return 'sent';
		}
		if ( ! empty( $v['_tries'] ) && (int) $v['_tries'] >= static::MAX_TRIES ) {
			return 'dead';
		}
		if ( ! empty( $v['_status'] ) && 'failed' === $v['_status'] ) {
			return 'failed';
		}
		return 'waiting';
	}

	/**
	 * Whether the cron may pick this item up right now.
	 *
	 * Dead items (tries capped) are never picked; failed items wait
	 * RETRY_LATER_TTL after the failure; sent items are re-polled.
	 *
	 * @param mixed $v   Queue item.
	 * @param int   $now Timestamp (defaults to now).
	 * @return bool
	 */
	public static function item_runnable( $v, $now = 0 ) {
		$now   = $now ? (int) $now : time();
		$state = static::item_state( $v );
		if ( 'dead' === $state ) {
			return false;
		}
		if ( 'failed' === $state && ! empty( $v['_err_at'] ) && $now - (int) $v['_err_at'] < static::RETRY_LATER_TTL ) {
			return false;
		}
		return true;
	}

	/**
	 * Return a working copy with `_prio` = 1 items first (stable otherwise).
	 *
	 * @param array $queue Queue.
	 * @return array
	 */
	public static function prioritize( $queue ) {
		$first = [];
		$rest  = [];
		foreach ( $queue as $k => $v ) {
			if ( is_array( $v ) && ! empty( $v['_prio'] ) ) {
				$first[ $k ] = $v;
			} else {
				$rest[ $k ] = $v;
			}
		}
		return $first + $rest;
	}

	/**
	 * Count items per state.
	 *
	 * @param array $queue Queue.
	 * @param int   $now   Timestamp (defaults to now).
	 * @return array{waiting:int,sent:int,failed:int,dead:int,runnable:int,total:int}
	 */
	public static function queue_counts( $queue, $now = 0 ) {
		$counts = [
			'waiting'  => 0,
			'sent'     => 0,
			'failed'   => 0,
			'dead'     => 0,
			'runnable' => 0,
			'total'    => 0,
		];
		if ( ! is_array( $queue ) ) {
			return $counts;
		}
		foreach ( $queue as $v ) {
			$state = static::item_state( $v );
			++$counts['total'];
			if ( 'dead' === $state ) {
				++$counts['failed'];
				++$counts['dead'];
			} else {
				++$counts[ $state ];
			}
			if ( static::item_runnable( $v, $now ) ) {
				++$counts['runnable'];
			}
		}
		return $counts;
	}

	/**
	 * Mark one item as failed (pure).
	 *
	 * @param mixed  $v   Queue item.
	 * @param string $err Short error text (already tag-free).
	 * @param int    $now Timestamp (defaults to now).
	 * @return array
	 */
	public static function fail_item( $v, $err, $now = 0 ) {
		if ( ! is_array( $v ) ) {
			$v = [];
		}
		$err           = function_exists( 'mb_substr' ) ? mb_substr( (string) $err, 0, 200 ) : substr( (string) $err, 0, 200 );
		$v['_status']  = 'failed';
		$v['_err_at']  = $now ? (int) $now : time();
		$v['_tries']   = ( ! empty( $v['_tries'] ) ? (int) $v['_tries'] : 0 ) + 1;
		$v['_err']     = $err;
		return $v;
	}

	/**
	 * Clear the failure fields of one item (pure).
	 *
	 * @param mixed $v Queue item.
	 * @return array
	 */
	public static function reset_item( $v ) {
		if ( ! is_array( $v ) ) {
			$v = [];
		}
		unset( $v['_status'], $v['_err'], $v['_err_at'], $v['_tries'], $v['_sent_at'] );
		return $v;
	}

	/**
	 * Drop items for a selective clear (pure).
	 *
	 * @param array  $queue Queue.
	 * @param string $which failed|waiting|all.
	 * @param bool   $force Also drop items currently building on QUIC.cloud.
	 * @return array{0:array,1:int} Remaining queue and removed count.
	 */
	public static function filter_clear( $queue, $which, $force = false ) {
		$kept    = [];
		$removed = 0;
		foreach ( $queue as $k => $v ) {
			$state = static::item_state( $v );
			$drop  = false;
			if ( 'failed' === $which ) {
				$drop = 'failed' === $state || 'dead' === $state;
			} elseif ( 'waiting' === $which ) {
				$drop = 'waiting' === $state;
			} elseif ( 'all' === $which ) {
				$drop = $force || 'sent' !== $state;
			}
			if ( $drop ) {
				++$removed;
			} else {
				$kept[ $k ] = $v;
			}
		}
		return [ $kept, $removed ];
	}

	/*
	 * ---------------------------------------------------------------------
	 * Summary keys.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Summary key for the paused flag.
	 *
	 * @return string
	 */
	protected function _paused_key() {
		return $this->_svc_id() . '_paused';
	}

	/**
	 * Summary key for the last error text.
	 *
	 * @return string
	 */
	protected function _last_err_key() {
		return $this->_svc_id() . '_last_err';
	}

	/**
	 * Summary key for the last error timestamp.
	 *
	 * @return string
	 */
	protected function _last_err_at_key() {
		return $this->_svc_id() . '_last_err_at';
	}

	/**
	 * Summary key for the last progress (item done or accepted) timestamp.
	 *
	 * @return string
	 */
	protected function _last_progress_key() {
		return $this->_svc_id() . '_last_progress';
	}

	/**
	 * Summary key for the stuck-notice timestamp.
	 *
	 * @return string
	 */
	protected function _stuck_notified_key() {
		return $this->_svc_id() . '_stuck_notified_at';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Queue access.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Load this svc's queue. Concrete classes may normalize legacy rows.
	 *
	 * @return array
	 */
	public function load_q() {
		return $this->load_queue( $this->_svc_id() );
	}

	/**
	 * Find a queue key by its md5 hash (raw keys may carry a leading space
	 * that sanitize_text_field would strip).
	 *
	 * @param string $hash md5 of the queue key.
	 * @return string|false
	 */
	public function find_key( $hash ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $hash ) ) {
			return false;
		}
		foreach ( $this->load_q() as $k => $v ) {
			if ( md5( (string) $k ) === $hash ) {
				return (string) $k;
			}
		}
		return false;
	}

	/**
	 * Queue state for the admin boxes / CLI.
	 *
	 * @return array
	 */
	public function queue_state() {
		return [
			'paused'         => ! empty( $this->_summary[ $this->_paused_key() ] ) ? (int) $this->_summary[ $this->_paused_key() ] : 0,
			'next_run_after' => ! empty( $this->_summary[ $this->_next_run_after_key() ] ) ? (int) $this->_summary[ $this->_next_run_after_key() ] : 0,
			'last_err'       => ! empty( $this->_summary[ $this->_last_err_key() ] ) ? (string) $this->_summary[ $this->_last_err_key() ] : '',
			'last_err_at'    => ! empty( $this->_summary[ $this->_last_err_at_key() ] ) ? (int) $this->_summary[ $this->_last_err_at_key() ] : 0,
			'last_progress'  => ! empty( $this->_summary[ $this->_last_progress_key() ] ) ? (int) $this->_summary[ $this->_last_progress_key() ] : 0,
			'stuck_notified' => ! empty( $this->_summary[ $this->_stuck_notified_key() ] ) ? (int) $this->_summary[ $this->_stuck_notified_key() ] : 0,
		];
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pause / resume.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether the cron is paused for this queue.
	 *
	 * @return bool
	 */
	public function is_paused() {
		return ! empty( $this->_summary[ $this->_paused_key() ] );
	}

	/**
	 * Pause the cron for this queue.
	 *
	 * @return void
	 */
	public function pause() {
		$this->_summary[ $this->_paused_key() ] = time();
		self::save_summary();
		self::debug( 'Queue paused' );
		/* translators: %s: service name */
		Admin_Display::success( sprintf( __( '%s queue paused. Cron will skip it until resumed.', 'litespeed-cache' ), strtoupper( $this->_svc_id() ) ) );
	}

	/**
	 * Resume the cron for this queue.
	 *
	 * @return void
	 */
	public function resume() {
		$this->_summary[ $this->_paused_key() ] = 0;
		self::save_summary();
		self::debug( 'Queue resumed' );
		/* translators: %s: service name */
		Admin_Display::success( sprintf( __( '%s queue resumed.', 'litespeed-cache' ), strtoupper( $this->_svc_id() ) ) );
	}

	/**
	 * Mark the stuck notice as shown for the current episode.
	 *
	 * @return void
	 */
	public function mark_stuck_notified() {
		$this->_summary[ $this->_stuck_notified_key() ] = time();
		self::save_summary();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Bookkeeping used by the run paths.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Honor the try_later / retry-later deadline. Clears it once passed.
	 *
	 * @return bool True when the queue must still wait.
	 */
	protected function _throttled() {
		$next_run_key = $this->_next_run_after_key();
		if ( ! empty( $this->_summary[ $next_run_key ] ) && time() < (int) $this->_summary[ $next_run_key ] ) {
			$wait_seconds = (int) $this->_summary[ $next_run_key ] - time();
			self::debug( 'Waiting for try_later timeout: ' . $wait_seconds . ' seconds remaining' );
			return true;
		}

		if ( ! empty( $this->_summary[ $next_run_key ] ) ) {
			$this->_summary[ $next_run_key ] = 0;
			self::save_summary();
			self::debug( 'Cleared try_later flag, resuming ' . $this->_svc_id() . ' processing' );
		}

		return false;
	}

	/**
	 * Ask the cron to wait before the next attempt.
	 *
	 * @param int    $ttl Seconds to wait.
	 * @param string $err Optional reason to surface in the queue box.
	 * @return void
	 */
	protected function _set_retry_later( $ttl, $err = '' ) {
		$ttl           = (int) $ttl;
		$next_run_time = time() + $ttl;

		$this->_summary[ $this->_next_run_after_key() ] = $next_run_time;
		if ( $err ) {
			$this->_record_err( $err, false );
		}
		self::save_summary();
		self::debug( 'Set next ' . $this->_svc_id() . ' cron run after ' . $ttl . ' seconds (at ' . gmdate( 'Y-m-d H:i:s', $next_run_time ) . ')' );
	}

	/**
	 * Remember the last queue-level error (allowance, QC trouble).
	 *
	 * @param string $err  Error text (tags are stripped).
	 * @param bool   $save Persist immediately.
	 * @return void
	 */
	protected function _record_err( $err, $save = true ) {
		$err = trim( wp_strip_all_tags( (string) $err ) );
		if ( ! $err ) {
			return;
		}
		$this->_summary[ $this->_last_err_key() ]    = function_exists( 'mb_substr' ) ? mb_substr( $err, 0, 200 ) : substr( $err, 0, 200 );
		$this->_summary[ $this->_last_err_at_key() ] = time();
		if ( $save ) {
			self::save_summary();
		}
	}

	/**
	 * Record progress (an item was finished or accepted by QUIC.cloud).
	 *
	 * @return void
	 */
	protected function _mark_progress() {
		$this->_summary[ $this->_last_progress_key() ] = time();
		self::save_summary();
	}

	/**
	 * Keep a failed item: mark it, bump tries and move it to the end.
	 *
	 * @param string $queue_k Queue key.
	 * @param string $err     Short error text.
	 * @return void
	 */
	protected function _mark_failed( $queue_k, $err ) {
		$err = trim( wp_strip_all_tags( (string) $err ) );
		if ( ! $err ) {
			$err = __( 'Request failed', 'litespeed-cache' );
		}

		// Reload to avoid clobbering concurrent writes.
		$queue = $this->load_q();
		if ( ! isset( $queue[ $queue_k ] ) ) {
			return;
		}
		$v = static::fail_item( $queue[ $queue_k ], $err );
		unset( $queue[ $queue_k ] );
		$queue[ $queue_k ] = $v;
		$this->save_queue( $this->_svc_id(), $queue );

		$this->_record_err( $err );
		self::debug( '❌ Item failed [tries] ' . $v['_tries'] . ' [err] ' . $err . ' [q_k] ' . $queue_k );
	}

	/**
	 * QUIC.cloud accepted the item and is building it: mark as sent.
	 *
	 * @param string $queue_k Queue key.
	 * @return void
	 */
	protected function _mark_sent( $queue_k ) {
		$queue = $this->load_q();
		if ( ! isset( $queue[ $queue_k ] ) ) {
			return;
		}
		$v = is_array( $queue[ $queue_k ] ) ? $queue[ $queue_k ] : [];
		unset( $v['_err_at'] );
		$v['_status']        = 'queued';
		$v['_sent_at']       = time();
		$queue[ $queue_k ]   = $v;
		$this->save_queue( $this->_svc_id(), $queue );
		$this->_mark_progress();
	}

	/**
	 * Set the priority flag on one item.
	 *
	 * @param string $queue_k Queue key.
	 * @param int    $prio    Priority (1 = first).
	 * @param bool   $reset   Also clear the failure fields.
	 * @return array|false The stored item or false when missing.
	 */
	protected function _touch_item( $queue_k, $prio, $reset = false ) {
		$queue = $this->load_q();
		if ( ! isset( $queue[ $queue_k ] ) ) {
			return false;
		}
		$v = is_array( $queue[ $queue_k ] ) ? $queue[ $queue_k ] : [];
		if ( $reset ) {
			$v = static::reset_item( $v );
		}
		$v['_prio']        = (int) $prio;
		$queue[ $queue_k ] = $v;
		$this->save_queue( $this->_svc_id(), $queue );
		return $v;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Admin actions on single items / groups.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Run one item now (by key hash). Works while paused: explicit action.
	 *
	 * @param string $hash  md5 of the queue key.
	 * @param bool   $retry Clear its failure fields first.
	 * @return mixed Result of _run_item(), or false when not found.
	 */
	public function gen_item( $hash, $retry = false ) {
		$queue_k = $this->find_key( $hash );
		if ( false === $queue_k ) {
			Admin_Display::error( __( 'Queue item not found. It may have been processed already.', 'litespeed-cache' ) );
			return false;
		}

		$v = $this->_touch_item( $queue_k, 1, $retry );
		if ( false === $v ) {
			return false;
		}

		self::debug( ( $retry ? 'retry' : 'run' ) . ' item [q_k] ' . $queue_k );

		$res = $this->_run_item( $queue_k, $v );
		$this->_notice_for_result( $res );

		return $res;
	}

	/**
	 * Retry one failed item: clear its failure fields and run now.
	 *
	 * @param string $hash md5 of the queue key.
	 * @return mixed
	 */
	public function retry_item( $hash ) {
		return $this->gen_item( $hash, true );
	}

	/**
	 * Remove one item from the queue.
	 *
	 * @param string $hash md5 of the queue key.
	 * @return bool
	 */
	public function rm_item( $hash ) {
		$queue_k = $this->find_key( $hash );
		if ( false === $queue_k ) {
			Admin_Display::error( __( 'Queue item not found. It may have been processed already.', 'litespeed-cache' ) );
			return false;
		}

		$queue = $this->load_q();
		unset( $queue[ $queue_k ] );
		$this->save_queue( $this->_svc_id(), $queue );
		self::debug( 'Removed item [q_k] ' . $queue_k );
		Admin_Display::success( __( 'Removed the item from the queue.', 'litespeed-cache' ) );

		return true;
	}

	/**
	 * Selective clear.
	 *
	 * @param string $which failed|waiting|all.
	 * @param bool   $force With `all`: also drop items building on QUIC.cloud.
	 * @return int Removed count.
	 */
	public function clear_items( $which, $force = false ) {
		list( $kept, $removed ) = static::filter_clear( $this->load_q(), $which, $force );
		$this->save_queue( $this->_svc_id(), $kept );
		self::debug( 'Cleared ' . $which . ' items [removed] ' . $removed . ( $force ? ' [force]' : '' ) );

		if ( 'all' === $which && ! $force && count( $kept ) ) {
			/* translators: 1: removed count, 2: kept count */
			Admin_Display::success( sprintf( __( 'Removed %1$d item(s). Kept %2$d item(s) that QUIC.cloud is still building.', 'litespeed-cache' ), $removed, count( $kept ) ) );
		} else {
			/* translators: %d: removed count */
			Admin_Display::success( sprintf( __( 'Removed %d item(s) from the queue.', 'litespeed-cache' ), $removed ) );
		}

		return $removed;
	}

	/**
	 * Run the first runnable item and report. One item per call so the
	 * admin JS can loop ("Run until empty") and honor server waits.
	 *
	 * @return array{done:bool,remaining:int,total:int,last:array|null,wait:int,stop:string,msg:string}
	 */
	public function run_next() {
		$now   = time();
		$queue = $this->load_q();
		$out   = [
			'done'      => false,
			'remaining' => 0,
			'total'     => count( $queue ),
			'last'      => null,
			'wait'      => 0,
			'stop'      => '',
			'msg'       => '',
		];

		$next_k = null;
		foreach ( static::prioritize( $queue ) as $k => $v ) {
			if ( static::item_runnable( $v, $now ) ) {
				$next_k = $k;
				break;
			}
		}

		if ( null === $next_k ) {
			$out['done'] = true;
			$out['msg']  = $out['total'] ? __( 'No runnable items left (failed items are waiting for their retry time).', 'litespeed-cache' ) : __( 'Queue is empty.', 'litespeed-cache' );
			return $out;
		}

		$hot = $this->cls( 'Cloud' )->service_hot( $this->_svc_const() );
		if ( $hot ) {
			$out['remaining'] = static::queue_counts( $queue, $now )['runnable'];
			$out['wait']      = (int) $hot;
			$out['stop']      = 'svc_hot';
			/* translators: %d: seconds */
			$out['msg'] = sprintf( __( 'Service is cooling down. Available after %d second(s).', 'litespeed-cache' ), (int) $hot );
			return $out;
		}

		$res   = $this->_run_item( $next_k, $queue[ $next_k ] );
		$after = $this->load_q();
		$label = isset( $queue[ $next_k ]['url'] ) ? (string) $queue[ $next_k ]['url'] : (string) $next_k;

		$out['remaining'] = static::queue_counts( $after, time() )['runnable'];
		$out['total']     = count( $after );
		$out['last']      = [
			'url'    => $label,
			'status' => 'done',
			'msg'    => '',
		];

		if ( true === $res ) {
			$out['last']['msg'] = __( 'Generated.', 'litespeed-cache' );
		} elseif ( is_array( $res ) && ! empty( $res['try_later'] ) ) {
			$out['last']['status'] = 'sent';
			$out['wait']           = (int) $res['try_later'];
			/* translators: %d: seconds */
			$out['last']['msg'] = sprintf( __( 'Accepted by QUIC.cloud, checking again in %d second(s).', 'litespeed-cache' ), (int) $res['try_later'] );
		} elseif ( 'retry_later' === $res ) {
			$out['last']['status'] = 'failed';
			$out['wait']           = static::RETRY_LATER_TTL;
			$out['last']['msg']    = __( 'QUIC.cloud is temporarily unavailable.', 'litespeed-cache' ) . ( Cloud::$last_err ? ' ' . wp_strip_all_tags( Cloud::$last_err ) : '' );
		} elseif ( 'failed' === $res ) {
			$out['last']['status'] = 'failed';
			$err                   = isset( $after[ $next_k ]['_err'] ) ? $after[ $next_k ]['_err'] : '';
			$out['last']['msg']    = __( 'Failed.', 'litespeed-cache' ) . ( $err ? ' ' . $err : '' );
		} elseif ( 'out_of_quota' === $res ) {
			$out['last']['status'] = 'skipped';
			$out['stop']           = 'out_of_quota';
			$out['msg']            = __( 'Out of QUIC.cloud quota. Stopped.', 'litespeed-cache' );
		} elseif ( 'svc_hot' === $res ) {
			$out['last']['status'] = 'skipped';
			$out['stop']           = 'svc_hot';
			$out['msg']            = __( 'Service is cooling down. Stopped.', 'litespeed-cache' );
		} else {
			$out['last']['status'] = 'failed';
			$out['stop']           = 'error';
			$out['msg']            = __( 'Unexpected result. Stopped.', 'litespeed-cache' );
		}

		if ( ! $out['remaining'] && ! $out['stop'] ) {
			$out['done'] = true;
		}

		return $out;
	}

	/**
	 * Translate a _run_item() result into an admin notice.
	 *
	 * @param mixed $res Result of _run_item().
	 * @return void
	 */
	protected function _notice_for_result( $res ) {
		if ( true === $res ) {
			Admin_Display::success( __( 'Generated the queue item.', 'litespeed-cache' ) );
		} elseif ( is_array( $res ) && ! empty( $res['try_later'] ) ) {
			/* translators: %d: seconds */
			Admin_Display::note( sprintf( __( 'QUIC.cloud accepted the item and is building it. It will be checked again in %d second(s).', 'litespeed-cache' ), (int) $res['try_later'] ) );
		} elseif ( 'retry_later' === $res ) {
			Admin_Display::note( __( 'QUIC.cloud is temporarily unavailable. The item was kept and will be retried later.', 'litespeed-cache' ) );
		} elseif ( 'failed' === $res ) {
			Admin_Display::error( __( 'The item failed. It was kept in the queue and will be retried.', 'litespeed-cache' ) );
		}
	}

	/**
	 * Dispatch the shared queue admin types. Called from handler().
	 *
	 * @param string $type Router type.
	 * @return bool True when handled.
	 */
	protected function _queue_type_handler( $type ) {
		// Router has already verified the action nonce.
		$hash  = isset( $_GET['q_k'] ) ? sanitize_text_field( wp_unslash( $_GET['q_k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force = ! empty( $_GET['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $type ) {
			case static::TYPE_GEN_ITEM:
				$this->gen_item( $hash );
				return true;

			case static::TYPE_RETRY_ITEM:
				$this->retry_item( $hash );
				return true;

			case static::TYPE_RM_ITEM:
				$this->rm_item( $hash );
				return true;

			case static::TYPE_CLEAR_FAILED:
				$this->clear_items( 'failed' );
				return true;

			case static::TYPE_CLEAR_WAITING:
				$this->clear_items( 'waiting' );
				return true;

			case static::TYPE_CLEAR_ALL:
				$this->clear_items( 'all', $force );
				return true;

			case static::TYPE_PAUSE:
				$this->pause();
				return true;

			case static::TYPE_RESUME:
				$this->resume();
				return true;

			case static::TYPE_RUN_NEXT:
				wp_send_json( $this->run_next() );
				return true; // Unreachable: wp_send_json exits.

			default:
				return false;
		}
	}
}
