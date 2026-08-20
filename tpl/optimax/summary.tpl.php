<?php
/**
 * LiteSpeed Cache OptimaX Summary
 *
 * Manages the OX summary interface for LiteSpeed Cache.
 *
 * @package LiteSpeed
 * @since 8.0
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit;

$summary        = Optimax::get_summary();
$closest_server = Cloud::get_summary( 'server.' . Cloud::SVC_OPTIMAX );
$queue          = $this->load_queue( 'optimax' );
$ox_service_hot = $this->cls( 'Cloud' )->service_hot( Cloud::SVC_OPTIMAX );
$next_gen       = '<code class="litespeed-success">' . $this->cls( 'Media' )->next_gen_image_title() . '</code>';
?>
<div class="litespeed-flex-container litespeed-column-with-boxes">
	<div class="litespeed-width-7-10 litespeed-column-left">

		<h3 class="litespeed-title-short">
			<?php esc_html_e( 'OptimaX Queue', 'litespeed-cache' ); ?>
		</h3>

		<?php if ( ! $this->conf( Base::O_OPTIMAX ) ) : ?>
			<div class="litespeed-callout notice notice-error inline">
				<h4><?php esc_html_e( 'OptimaX is disabled', 'litespeed-cache' ); ?></h4>
				<p><?php esc_html_e( 'Turn on OptimaX in the OptimaX Settings tab to start queueing pages.', 'litespeed-cache' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $queue ) ) : ?>
			<div class="litespeed-callout notice notice-info inline">
				<h4><?php esc_html_e( 'The OptimaX queue is empty.', 'litespeed-cache' ); ?></h4>
				<p><?php esc_html_e( 'Visit a page on the frontend to add it to the queue.', 'litespeed-cache' ); ?></p>
			</div>
		<?php else : ?>
			<div class="litespeed-callout notice notice-warning inline">
				<h4>
					<?php printf( esc_html__( 'URL list in %s queue waiting for cron', 'litespeed-cache' ), 'OptimaX' ); ?> ( <?php echo esc_html( count( $queue ) ); ?> )
					<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_CLEAR_Q ) ); ?>" class="button litespeed-btn-warning litespeed-right" data-litespeed-cfm="<?php esc_attr_e( 'Are you sure you want to clear the OptimaX queue?', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear', 'litespeed-cache' ); ?></a>
				</h4>
				<p>
					<?php
					$i = 0;
					foreach ( $queue as $queue_key => $queue_val ) :
						if ( $i++ > 20 ) :
							echo '...';
							break;
						endif;
						if ( ! is_array( $queue_val ) ) {
							continue;
						}
						if ( ! empty( $queue_val['_status'] ) ) {
							echo '<span class="litespeed-success">';
						}
						echo esc_html( isset( $queue_val['url'] ) ? $queue_val['url'] : '' );
						if ( ! empty( $queue_val['_status'] ) ) {
							echo '</span>';
						}
						$pos = strpos( $queue_key, ' ' );
						if ( $pos ) {
							echo ' (' . esc_html__( 'Vary Group', 'litespeed-cache' ) . ':' . esc_html( substr( $queue_key, 0, $pos ) ) . ')';
						}
						if ( ! empty( $queue_val['is_mobile'] ) ) {
							echo ' <span data-balloon-pos="up" aria-label="mobile">📱</span>';
						}
						if ( ! empty( $queue_val['is_nextgen'] ) ) {
							echo ' ' . wp_kses_post( $next_gen );
						}
						if ( $ox_service_hot ) {
							echo ' <button class="button button-small" disabled>' . esc_html__( 'Run', 'litespeed-cache' ) . '</button>';
						} else {
							$run_url = Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_GEN_ITEM, false, null, array( 'q_k' => $queue_key ) );
							echo ' <a href="' . esc_url( $run_url ) . '" class="button button-small litespeed-btn-success">' . esc_html__( 'Run', 'litespeed-cache' ) . '</a>';
						}
						echo '<br />';
					endforeach;
					?>
				</p>
			</div>

			<?php if ( $ox_service_hot ) : ?>
				<button class="button button-secondary" disabled>
					<?php printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), 'OptimaX' ); ?>
					- <?php printf( esc_html__( 'Available after %d second(s)', 'litespeed-cache' ), esc_html( $ox_service_hot ) ); ?>
				</button>
			<?php else : ?>
				<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_GEN ) ); ?>" class="button litespeed-btn-success">
					<?php printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), 'OptimaX' ); ?>
				</a>
			<?php endif; ?>

			<?php Doc::queue_issues(); ?>
		<?php endif; ?>
	</div>

	<div class="litespeed-width-3-10 litespeed-column-right">
		<div class="postbox litespeed-postbox">
			<div class="inside">
				<h3 class="litespeed-title"><?php esc_html_e( 'OptimaX Status', 'litespeed-cache' ); ?></h3>

				<p>
					<?php esc_html_e( 'Queued URLs', 'litespeed-cache' ); ?>:
					<code><?php echo esc_html( count( $queue ) ); ?></code>
				</p>
				<p>
					<?php if ( $this->conf( Base::O_OPTIMAX_CRON ) ) : ?>
						<span class="litespeed-label-success litespeed-label-dashboard"><?php esc_html_e( 'ON', 'litespeed-cache' ); ?></span>
					<?php else : ?>
						<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'OFF', 'litespeed-cache' ); ?></span>
					<?php endif; ?>
					<?php esc_html_e( 'OptimaX Cron', 'litespeed-cache' ); ?>
				</p>
				<?php if ( ! empty( $summary['last_request_optimax'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Last Request', 'litespeed-cache' ); ?>:
						<code><?php echo esc_html( Utility::readable_time( $summary['last_request_optimax'] ) ); ?></code>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $summary['last_took_ms_optimax'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Last Request Cost', 'litespeed-cache' ); ?>:
						<code><?php echo esc_html( number_format( $summary['last_took_ms_optimax'] / 1000, 2 ) ); ?>s</code>
					</p>
				<?php elseif ( ! empty( $summary['last_spent_optimax'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Last Request Cost', 'litespeed-cache' ); ?>:
						<code><?php echo esc_html( $summary['last_spent_optimax'] ); ?>s</code>
					</p>
				<?php endif; ?>
				<?php if ( $closest_server ) : ?>
					<p>
						<?php esc_html_e( 'Cloud Server', 'litespeed-cache' ); ?>:
						<a class='litespeed-redetect' href="<?php echo esc_url( Utility::build_url( Router::ACTION_CLOUD, Cloud::TYPE_REDETECT_CLOUD, false, null, array( 'svc' => Cloud::SVC_OPTIMAX ) ) ); ?>" data-balloon-pos="up" data-balloon-break aria-label="<?php printf( esc_attr__( 'Current closest Cloud server is %s. Click to redetect.', 'litespeed-cache' ), esc_attr( $closest_server ) ); ?>"><i class='litespeed-quic-icon'></i> <?php esc_html_e( 'Redetect', 'litespeed-cache' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
