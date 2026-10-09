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
$queue_waiting  = count( array_filter( $queue, [ 'LiteSpeed\Optimax_Pages', 'is_waiting' ] ) );
$ox_service_hot = $this->cls( 'Cloud' )->service_hot( Cloud::SVC_OPTIMAX );
$ox_on          = (bool) $this->conf( Base::O_OPTIMAX );
$ox_cron        = (bool) $this->conf( Base::O_OPTIMAX_CRON );
$wp_cron_off    = Task::wp_cron_off();
$ox_stats       = $this->cls( 'Optimax_Pages' )->stats();
$nextgen_title  = $this->cls( 'Media' )->next_gen_image_title();
?>
<div class="litespeed-flex-container litespeed-column-with-boxes">
	<div class="litespeed-width-7-10 litespeed-column-left">

		<h3 class="litespeed-title-short"><?php esc_html_e( 'OptimaX', 'litespeed-cache' ); ?></h3>

		<div class="litespeed-callout notice notice-warning inline">
			<h4><?php esc_html_e( 'OptimaX can make mistakes', 'litespeed-cache' ); ?></h4>
			<p>
				<?php
				printf(
					/* translators: 1: link to the support forum, 2: link to submit a ticket */
					esc_html__( 'Please test each page after OptimaX runs on it. If you find errors, contact us on the %1$s or %2$s.', 'litespeed-cache' ),
					'<a href="https://wordpress.org/support/plugin/litespeed-cache/" rel="noopener noreferrer" target="_blank">' . esc_html__( 'support forum', 'litespeed-cache' ) . '</a>',
					'<a href="https://store.litespeedtech.com/store/submitticket.php" rel="noopener noreferrer" target="_blank">' . esc_html__( 'submit a ticket', 'litespeed-cache' ) . '</a>'
				);
				?>
			</p>
		</div>

		<?php if ( ! $ox_on ) : ?>
			<div class="litespeed-callout notice notice-error inline">
				<h4><?php esc_html_e( 'OptimaX is disabled', 'litespeed-cache' ); ?></h4>
				<p>
					<?php
					printf(
						/* translators: %s: link to the OptimaX Settings tab */
						esc_html__( 'Turn on OptimaX in the %s.', 'litespeed-cache' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=litespeed-optimax#settings' ) ) . '">' . esc_html__( 'OptimaX Settings tab', 'litespeed-cache' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<p>
				<?php esc_html_e( 'Pages optimized', 'litespeed-cache' ); ?>: <code><?php echo esc_html( $ox_stats['pages'] ); ?></code>
				<?php /* translators: %d: number of page versions (desktop, mobile, next-gen) */ ?>
				<span class="litespeed-desc"><?php echo esc_html( sprintf( _n( '(%d version)', '(%d versions)', $ox_stats['versions'], 'litespeed-cache' ), $ox_stats['versions'] ) ); ?></span>
			</p>
			<p>
				<?php esc_html_e( 'Text updated without a rebuild', 'litespeed-cache' ); ?>: <code><?php echo esc_html( empty( $summary['ox_patches'] ) ? 0 : (int) $summary['ox_patches'] ); ?></code>
			</p>
			<p>
				<?php esc_html_e( 'Pages to rebuild after a design change', 'litespeed-cache' ); ?>: <code><?php echo esc_html( $ox_stats['refresh'] ); ?></code>
				<?php if ( $ox_stats['refresh'] ) : ?>
					<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_REBUILD ) ); ?>" class="button button-small litespeed-left10"><?php esc_html_e( 'Rebuild now', 'litespeed-cache' ); ?></a>
					<br /><span class="litespeed-desc"><?php esc_html_e( 'Each is also queued again on its next visit. Visitors get it without OptimaX until it is rebuilt.', 'litespeed-cache' ); ?></span>
				<?php endif; ?>
			</p>

			<?php $ox_optimized = $ox_stats['pages'] ? $this->cls( 'Optimax_Pages' )->optimized( 50 ) : []; ?>
			<?php if ( $ox_optimized ) : ?>
				<div class="litespeed-callout notice notice-success inline">
					<h4><?php esc_html_e( 'Optimized pages', 'litespeed-cache' ); ?> ( <?php echo esc_html( $ox_stats['pages'] ); ?> )</h4>
					<p>
						<?php foreach ( $ox_optimized as $ox_url => $ox_versions ) : ?>
							<a href="<?php echo esc_url( $ox_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ox_url ); ?></a>
							<?php
							$ox_groups = [];
							foreach ( $ox_versions as $ox_ver ) {
								$ox_groups[] = ( $ox_ver['mobile'] ? '📱' : '🖥️' ) . ( $ox_ver['nextgen'] ? ' ' . $nextgen_title : '' );
							}
							echo ' <span class="litespeed-desc">(' . esc_html( implode( ', ', $ox_groups ) ) . ')</span>';
							?>
							<br />
						<?php endforeach; ?>
						<?php if ( $ox_stats['pages'] > count( $ox_optimized ) ) : ?>
							...
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $queue ) ) : ?>
				<div class="litespeed-callout notice notice-warning inline">
					<h4>
						<?php printf( esc_html__( 'URL list in %s queue waiting for cron', 'litespeed-cache' ), 'OptimaX' ); ?> ( <?php echo esc_html( count( $queue ) ); ?> )
						<?php if ( $queue_waiting ) : ?>
							<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_CLEAR_Q ) ); ?>" class="button litespeed-btn-warning litespeed-right" data-litespeed-cfm="<?php esc_attr_e( 'Remove the pages waiting in the OptimaX queue? Pages QUIC.cloud is already optimizing are kept.', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear', 'litespeed-cache' ); ?></a>
						<?php endif; ?>
					</h4>
					<p>
						<?php
						$i = 0;
						foreach ( $queue as $queue_val ) :
							if ( $i++ > 20 ) :
								echo '...';
								break;
							endif;
							if ( ! is_array( $queue_val ) || empty( $queue_val['url'] ) ) {
								continue;
							}
							// Sent to QUIC.cloud: highlighted.
							$sent = ! Optimax_Pages::is_waiting( $queue_val );
							echo $sent ? '<span class="litespeed-success">' : '';
							echo esc_html( $queue_val['url'] );
							echo $sent ? '</span>' : '';
							if ( ! empty( $queue_val['is_mobile'] ) || ! empty( $queue_val['is_nextgen'] ) ) {
								echo ' (' . esc_html__( 'Vary Group', 'litespeed-cache' ) . ':';
								if ( ! empty( $queue_val['is_mobile'] ) ) {
									echo ' <span data-balloon-pos="up" aria-label="mobile">📱</span>';
								}
								if ( ! empty( $queue_val['is_nextgen'] ) ) {
									echo ' <code>' . esc_html( $nextgen_title ) . '</code>';
								}
								echo ')';
							}
							if ( Optimax::retries_stopped( $queue_val ) ) {
								echo ' <span class="litespeed-danger">' . esc_html( sprintf( __( 'Failed %d times, not retried', 'litespeed-cache' ), Optimax::MAX_TRIES ) ) . '</span>';
							} elseif ( ! empty( $queue_val['_status'] ) && 'failed' === $queue_val['_status'] ) {
								echo ' <span class="litespeed-danger">' . esc_html__( 'Failed, will retry', 'litespeed-cache' ) . '</span>';
							}
							echo '<br />';
						endforeach;
						?>
					</p>
				</div>
				<p>
					<?php if ( $ox_service_hot ) : ?>
						<button class="button button-secondary" disabled><?php printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), 'OptimaX' ); ?> - <?php printf( esc_html__( 'Available after %d second(s)', 'litespeed-cache' ), esc_html( $ox_service_hot ) ); ?></button>
					<?php else : ?>
						<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_GEN ) ); ?>" class="button litespeed-btn-success"><?php printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), 'OptimaX' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="litespeed-width-3-10 litespeed-column-right">
		<div class="postbox litespeed-postbox">
			<div class="inside">
				<h3 class="litespeed-title"><?php esc_html_e( 'OptimaX Status', 'litespeed-cache' ); ?></h3>

				<p>
					<?php if ( $ox_on ) : ?>
						<span class="litespeed-label-success litespeed-label-dashboard"><?php esc_html_e( 'ON', 'litespeed-cache' ); ?></span>
					<?php else : ?>
						<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'OFF', 'litespeed-cache' ); ?></span>
					<?php endif; ?>
					OptimaX
				</p>
				<p>
					<?php if ( $ox_cron ) : ?>
						<span class="<?php echo esc_attr( $wp_cron_off ? 'litespeed-label-warning' : 'litespeed-label-success' ); ?> litespeed-label-dashboard"><?php esc_html_e( 'ON', 'litespeed-cache' ); ?></span>
					<?php else : ?>
						<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'OFF', 'litespeed-cache' ); ?></span>
					<?php endif; ?>
					<?php esc_html_e( 'OptimaX Cron', 'litespeed-cache' ); ?>
					<?php if ( $ox_cron && $wp_cron_off ) : ?>
						<span class="litespeed-warning"> - <?php esc_html_e( 'WP Cron is not running', 'litespeed-cache' ); ?></span>
						<?php if ( $ox_on ) : ?>
							<br /><br />
							<?php Task::run_cron_btn( [ Base::O_OPTIMAX_CRON, Base::O_OPTIMAX ] ); ?>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<p>
					<?php esc_html_e( 'Queued URLs', 'litespeed-cache' ); ?>:
					<code><?php echo esc_html( count( $queue ) ); ?></code>
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
