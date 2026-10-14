<?php
/**
 * LiteSpeed Cache QUIC.cloud Queue CLI.
 *
 * @package LiteSpeed\CLI
 * @since 8.1
 */

namespace LiteSpeed\CLI;

defined( 'WPINC' ) || exit();

use LiteSpeed\Base;
use LiteSpeed\Queue_Box;
use LiteSpeed\Utility;
use WP_CLI;

/**
 * Manage the QUIC.cloud service queues (UCSS, CCSS, VPI, LQIP).
 */
class Queue extends Base {

	/**
	 * Validate the svc argument.
	 *
	 * @param array $args Positional args.
	 * @return string|false
	 */
	private function _svc( $args ) {
		$svc = ! empty( $args[0] ) ? strtolower( (string) $args[0] ) : '';
		if ( ! Queue_Box::is_svc( $svc ) ) {
			WP_CLI::error( 'Unknown service. Use one of: ' . implode( '|', array_keys( Queue_Box::svcs() ) ) );
			return false;
		}
		return $svc;
	}

	/**
	 * List the items of one queue.
	 *
	 * ## OPTIONS
	 *
	 * <svc>
	 * : ucss|ccss|vpi|lqip
	 *
	 * [--search=<text>]
	 * : Only URLs containing this text (case-insensitive).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue list ucss
	 *     $ wp litespeed-queue list lqip --search=hero
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function list( $args, $assoc_args = [] ) {
		$svc = $this->_svc( $args );
		if ( ! $svc ) {
			return;
		}

		$qs     = ! empty( $assoc_args['search'] ) ? (string) $assoc_args['search'] : '';
		$rows   = Queue_Box::items( $svc, $qs );
		$groups = Queue_Box::group( $rows );

		if ( ! $groups ) {
			WP_CLI::line( 'Queue is empty.' );
			return;
		}

		$list = [];
		foreach ( $groups as $group ) {
			foreach ( $group['versions'] as $row ) {
				list( , $state_txt ) = Queue_Box::state_text( $row );
				$list[]              = [
					'hash'    => $row['hash'],
					'url'     => $row['url'],
					'version' => Queue_Box::version_label( $row ),
					'status'  => $state_txt . ( $row['err'] ? ' (' . $row['err'] . ')' : '' ),
					'tries'   => $row['tries'],
					'added'   => $row['added'] ? Utility::readable_time( $row['added'] ) : '-',
				];
			}
		}

		WP_CLI\Utils\format_items( 'table', $list, [ 'hash', 'url', 'version', 'status', 'tries', 'added' ] );
		$this->_print_info( Queue_Box::info( $svc ) );
	}

	/**
	 * Run the next runnable item of a queue, or all of them.
	 *
	 * ## OPTIONS
	 *
	 * <svc>
	 * : ucss|ccss|vpi|lqip
	 *
	 * [--all]
	 * : Keep running until the queue is empty (sleeps when QUIC.cloud asks to wait).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue run ccss
	 *     $ wp litespeed-queue run ucss --all
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function run( $args, $assoc_args = [] ) {
		$svc = $this->_svc( $args );
		if ( ! $svc ) {
			return;
		}
		$all      = ! empty( $assoc_args['all'] );
		$instance = Queue_Box::instance( $svc );

		do {
			$res = $instance->run_next();
			if ( ! empty( $res['last'] ) ) {
				$line = $res['last']['url'] . ' — ' . $res['last']['msg'];
				if ( 'failed' === $res['last']['status'] ) {
					WP_CLI::warning( $line );
				} else {
					WP_CLI::line( $line );
				}
			}
			if ( $res['stop'] ) {
				WP_CLI::error( $res['msg'], false );
				break;
			}
			if ( $res['done'] ) {
				WP_CLI::success( $res['msg'] ? $res['msg'] : 'Done.' );
				break;
			}
			if ( ! $all ) {
				WP_CLI::line( 'Remaining runnable: ' . (int) $res['remaining'] );
				break;
			}
			if ( $res['wait'] > 0 ) {
				WP_CLI::line( 'Waiting ' . (int) $res['wait'] . 's as requested by QUIC.cloud...' );
				sleep( min( 600, (int) $res['wait'] ) );
			}
		} while ( $all );
	}

	/**
	 * Clear items of a queue.
	 *
	 * ## OPTIONS
	 *
	 * <svc>
	 * : ucss|ccss|vpi|lqip
	 *
	 * [--failed]
	 * : Only failed / not-retried items.
	 *
	 * [--waiting]
	 * : Only items still waiting.
	 *
	 * [--all]
	 * : Everything except items QUIC.cloud is still building (add --force to drop those too).
	 *
	 * [--force]
	 * : With --all: also drop items currently building on QUIC.cloud.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue clear ucss --failed
	 *     $ wp litespeed-queue clear vpi --all --force
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function clear( $args, $assoc_args = [] ) {
		$svc = $this->_svc( $args );
		if ( ! $svc ) {
			return;
		}
		if ( ! empty( $assoc_args['failed'] ) ) {
			$which = 'failed';
		} elseif ( ! empty( $assoc_args['waiting'] ) ) {
			$which = 'waiting';
		} elseif ( ! empty( $assoc_args['all'] ) ) {
			$which = 'all';
		} else {
			WP_CLI::error( 'Pass one of --failed, --waiting or --all.' );
			return;
		}
		Queue_Box::instance( $svc )->clear_items( $which, ! empty( $assoc_args['force'] ) );
	}

	/**
	 * Retry failed items: clear their failure fields and run them now.
	 *
	 * ## OPTIONS
	 *
	 * <svc>
	 * : ucss|ccss|vpi|lqip
	 *
	 * [<hash>]
	 * : Item hash from `list`.
	 *
	 * [--all]
	 * : Reset every failed item and run the queue until empty.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue retry ucss 0cc175b9c0f1b6a831c399e269772661
	 *     $ wp litespeed-queue retry ccss --all
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function retry( $args, $assoc_args = [] ) {
		$svc = $this->_svc( $args );
		if ( ! $svc ) {
			return;
		}
		$instance = Queue_Box::instance( $svc );

		if ( ! empty( $assoc_args['all'] ) ) {
			$reset = 0;
			foreach ( Queue_Box::items( $svc ) as $row ) {
				if ( 'failed' === $row['state'] || 'dead' === $row['state'] ) {
					$instance->retry_item( $row['hash'] );
					++$reset;
				}
			}
			WP_CLI::success( 'Retried ' . $reset . ' failed item(s).' );
			return;
		}

		if ( empty( $args[1] ) ) {
			WP_CLI::error( 'Pass an item hash (see `list`) or --all.' );
			return;
		}
		$instance->retry_item( (string) $args[1] );
	}

	/**
	 * Pause the cron for one queue.
	 *
	 * ## OPTIONS
	 *
	 * <svc>
	 * : ucss|ccss|vpi|lqip
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue pause ucss
	 *
	 * @param array $args Positional args.
	 */
	public function pause( $args ) {
		$svc = $this->_svc( $args );
		if ( ! $svc ) {
			return;
		}
		Queue_Box::instance( $svc )->pause();
	}

	/**
	 * Resume the cron for one queue.
	 *
	 * ## OPTIONS
	 *
	 * <svc>
	 * : ucss|ccss|vpi|lqip
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue resume ucss
	 *
	 * @param array $args Positional args.
	 */
	public function resume( $args ) {
		$svc = $this->_svc( $args );
		if ( ! $svc ) {
			return;
		}
		Queue_Box::instance( $svc )->resume();
	}

	/**
	 * Summary of all queues.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp litespeed-queue status
	 */
	public function status() {
		$list = [];
		foreach ( array_keys( Queue_Box::svcs() ) as $svc ) {
			$info   = Queue_Box::info( $svc );
			$list[] = [
				'service'  => $info['title'],
				'waiting'  => $info['counts']['waiting'],
				'building' => $info['counts']['sent'],
				'failed'   => $info['counts']['failed'],
				'paused'   => $info['paused'] ? 'yes' : 'no',
				'next'     => $info['next_line'],
				'error'    => $info['err_recent'] ? $info['err_recent'] : '-',
			];
		}
		WP_CLI\Utils\format_items( 'table', $list, [ 'service', 'waiting', 'building', 'failed', 'paused', 'next', 'error' ] );
	}

	/**
	 * Print the queue state lines below a list.
	 *
	 * @param array $info Queue_Box::info().
	 * @return void
	 */
	private function _print_info( $info ) {
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '%s: %d waiting, %d building, %d failed (%d not retried). %s', $info['title'], $info['counts']['waiting'], $info['counts']['sent'], $info['counts']['failed'], $info['counts']['dead'], $info['next_line'] ) );
		if ( $info['err_recent'] ) {
			WP_CLI::line( 'Last error: ' . $info['err_recent'] );
		}
	}
}
