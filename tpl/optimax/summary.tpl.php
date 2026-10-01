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
$ox_paused      = Optimax::is_paused();
$ox_cron        = (bool) $this->conf( Base::O_OPTIMAX_CRON );
$wp_cron_off    = Task::wp_cron_off();
$pages          = $this->cls( 'Optimax_Pages' );
$pages_ready    = $pages->ensure_ready();
$page_list      = $pages_ready ? $pages->all() : [];
$max_links      = Optimax_Pages::max_links();
$home           = untrailingslashit( home_url() );
// Action links start from the bare page; build_url() would otherwise copy the current URL's query args.
$ox_page        = 'admin.php?page=litespeed-optimax';
$mobile_on      = (bool) $this->conf( Base::O_CACHE_MOBILE );
$nextgen_on     = (bool) $this->conf( Base::O_IMG_OPTM_WEBP );
$nextgen_title  = $this->cls( 'Media' )->next_gen_image_title();
$status_labels  = [
	Optimax_Pages::STATUS_WORKING => __( 'Working on it', 'litespeed-cache' ),
	Optimax_Pages::STATUS_QUEUED  => __( 'In queue', 'litespeed-cache' ),
	Optimax_Pages::STATUS_IN_USE  => __( 'In Use', 'litespeed-cache' ),
	Optimax_Pages::STATUS_REFRESH => __( 'Needs refresh', 'litespeed-cache' ),
];
// A page shown by its path under home; a URL from an older site address keeps its full form, marked.
$page_label = function ( $url ) use ( $home ) {
	if ( 0 === strpos( $url, $home ) ) {
		$path = substr( $url, strlen( $home ) );
		return [ '' === $path ? '/' : $path, false ];
	}
	return [ $url, true ];
};
?>
<div class="litespeed-flex-container litespeed-column-with-boxes">
	<div class="litespeed-width-7-10 litespeed-column-left">

		<?php if ( ! $pages_ready ) : ?>
			<div class="litespeed-callout notice notice-error inline">
				<h4><?php esc_html_e( 'The OptimaX page list is not ready yet.', 'litespeed-cache' ); ?></h4>
				<p><?php esc_html_e( 'The database update could not add its column. Reload this page; if this stays, check the database user can ALTER tables.', 'litespeed-cache' ); ?></p>
			</div>
		<?php endif; ?>

		<h3 class="litespeed-title-short"><?php esc_html_e( 'OptimaX Pages', 'litespeed-cache' ); ?></h3>

		<?php if ( ! $ox_on ) : ?>
			<div class="litespeed-callout notice notice-error inline">
				<h4><?php esc_html_e( 'OptimaX is disabled', 'litespeed-cache' ); ?></h4>
				<p>
					<?php
					printf(
						/* translators: %s: link to the OptimaX Settings tab */
						esc_html__( 'Turn on OptimaX in the %s, then add the pages it should optimize.', 'litespeed-cache' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=litespeed-optimax#settings' ) ) . '">' . esc_html__( 'OptimaX Settings tab', 'litespeed-cache' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php elseif ( $ox_paused ) : ?>
			<div class="litespeed-callout notice notice-warning inline">
				<h4><?php esc_html_e( 'OptimaX is paused', 'litespeed-cache' ); ?></h4>
				<p><?php esc_html_e( 'Turn on Next-Gen Image Format (WebP or AVIF) in Image Optimization to resume OptimaX.', 'litespeed-cache' ); ?></p>
			</div>
		<?php else : ?>
		<?php if ( ! empty( $queue ) ) : ?>
			<p>
				<?php if ( $queue_waiting ) : ?>
					<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_CLEAR_Q ) ); ?>" class="button litespeed-btn-warning" data-litespeed-cfm="<?php esc_attr_e( 'Remove the pages waiting in the OptimaX queue? Pages QUIC.cloud is already optimizing are kept.', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear queue', 'litespeed-cache' ); ?></a>
				<?php endif; ?>
				<?php if ( $ox_service_hot ) : ?>
					<button class="button button-secondary" disabled><?php printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), 'OptimaX' ); ?> - <?php printf( esc_html__( 'Available after %d second(s)', 'litespeed-cache' ), esc_html( $ox_service_hot ) ); ?></button>
				<?php else : ?>
					<a href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_GEN ) ); ?>" class="button litespeed-btn-success"><?php printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), 'OptimaX' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( ! $page_list ) : ?>
			<p><?php esc_html_e( 'No pages yet. Add one under Manage Pages below.', 'litespeed-cache' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'litespeed-cache' ); ?></th>
						<th><?php esc_html_e( 'Version', 'litespeed-cache' ); ?></th>
						<th><?php esc_html_e( 'Status', 'litespeed-cache' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'litespeed-cache' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $page_list as $page_row ) : ?>
						<?php
						list( $page_text, $page_foreign ) = $page_label( $page_row['url'] );
						$versions                         = $pages->versions( $page_row );
						?>
						<?php if ( ! $versions ) : ?>
							<tr>
								<td>
									<code><?php echo esc_html( $page_text ); ?></code>
									<a href="<?php echo esc_url( $page_row['url'] ); ?>" class="litespeed-link-with-icon" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open in a new tab', 'litespeed-cache' ); ?>"><span class="dashicons dashicons-external"></span></a>
								</td>
								<td>—</td>
								<td><?php esc_html_e( 'Waiting for first visit', 'litespeed-cache' ); ?></td>
								<td>—</td>
							</tr>
						<?php endif; ?>
						<?php foreach ( $versions as $i => $ver ) : ?>
							<?php
							$parts = [];
							if ( $mobile_on ) {
								$parts[] = $ver['groups']['mobile'] ? '📱 ' . __( 'Mobile', 'litespeed-cache' ) : __( 'Desktop', 'litespeed-cache' );
							}
							if ( $nextgen_on && $ver['groups']['nextgen'] ) {
								$parts[] = $nextgen_title;
							}
							$run_args  = $ver['q_k'] ? [ 'q_k' => $ver['q_k'] ] : [ 'fid' => $ver['file_id'] ];
							$run_label = Optimax_Pages::STATUS_IN_USE === $ver['status'] ? __( 'Refresh', 'litespeed-cache' ) : __( 'Run OX', 'litespeed-cache' );
							?>
							<tr>
								<td>
									<?php if ( 0 === $i ) : ?>
										<code><?php echo esc_html( $page_text ); ?></code>
										<a href="<?php echo esc_url( $page_row['url'] ); ?>" class="litespeed-link-with-icon" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open in a new tab', 'litespeed-cache' ); ?>"><span class="dashicons dashicons-external"></span></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $parts ? implode( ' · ', $parts ) : '—' ); ?></td>
								<td><?php echo esc_html( isset( $status_labels[ $ver['status'] ] ) ? $status_labels[ $ver['status'] ] : '—' ); ?></td>
								<td>
									<?php if ( Optimax_Pages::STATUS_WORKING === $ver['status'] ) : ?>
										—
									<?php else : ?>
										<?php if ( $ox_service_hot ) : ?>
											<button class="button button-small" disabled><?php echo esc_html( $run_label ); ?></button>
										<?php else : ?>
											<a class="button button-small" href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_VER_RUN, false, $ox_page, $run_args ) ); ?>"><?php echo esc_html( $run_label ); ?></a>
										<?php endif; ?>
										<?php if ( $ver['q_k'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_VER_DEQUEUE, false, $ox_page, [ 'q_k' => $ver['q_k'] ] ) ); ?>"><?php esc_html_e( 'Remove from queue', 'litespeed-cache' ); ?></a>
										<?php elseif ( $ver['file_id'] && Optimax_Pages::STATUS_IN_USE !== $ver['status'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_VER_QUEUE, false, $ox_page, [ 'fid' => $ver['file_id'] ] ) ); ?>"><?php esc_html_e( 'Queue', 'litespeed-cache' ); ?></a>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3 class="litespeed-title-short">
			<?php esc_html_e( 'Manage Pages', 'litespeed-cache' ); ?>
			<span class="litespeed-desc">
				<?php
				$page_count = count( $page_list );
				echo esc_html( $max_links ? sprintf( __( '%1$d of %2$d pages', 'litespeed-cache' ), $page_count, $max_links ) : sprintf( _n( '%d page', '%d pages', $page_count, 'litespeed-cache' ), $page_count ) );
				?>
			</span>
		</h3>

		<?php if ( $pages_ready ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=litespeed-optimax' ) ); ?>" class="litespeed-margin-bottom10">
				<input type="hidden" name="<?php echo esc_attr( Router::ACTION ); ?>" value="<?php echo esc_attr( Router::ACTION_OPTIMAX ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( Router::TYPE ); ?>" value="<?php echo esc_attr( Optimax::TYPE_PAGE_ADD ); ?>" />
				<?php wp_nonce_field( Router::ACTION_OPTIMAX, Router::NONCE ); ?>
				<input type="text" name="ox_page" class="regular-text" placeholder="/sample-page/" required />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add', 'litespeed-cache' ); ?></button>
				<span class="litespeed-desc"><?php esc_html_e( 'A path on this site, or a full URL copied from the browser.', 'litespeed-cache' ); ?></span>
			</form>

			<?php if ( $page_list ) : ?>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'litespeed-cache' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'litespeed-cache' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $page_list as $page_row ) : ?>
							<?php list( $page_text, $page_foreign ) = $page_label( $page_row['url'] ); ?>
							<tr>
								<td>
									<code><?php echo esc_html( $page_text ); ?></code>
									<?php if ( $page_foreign ) : ?>
										<span class="litespeed-warning"><?php esc_html_e( '(not on this site\'s current address)', 'litespeed-cache' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( Optimax_Pages::is_home( $page_row['url'] ) ) : ?>
										<span class="litespeed-desc"><?php esc_html_e( 'Default', 'litespeed-cache' ); ?></span>
									<?php else : ?>
										<a class="litespeed-danger" href="<?php echo esc_url( Utility::build_url( Router::ACTION_OPTIMAX, Optimax::TYPE_PAGE_DEL, false, $ox_page, [ 'id' => (int) $page_row['id'] ] ) ); ?>" data-litespeed-cfm="<?php esc_attr_e( 'Remove this page and delete all its OptimaX builds?', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Remove', 'litespeed-cache' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<?php endif; ?>
	</div>

	<div class="litespeed-width-3-10 litespeed-column-right">
		<div class="postbox litespeed-postbox">
			<div class="inside">
				<h3 class="litespeed-title"><?php esc_html_e( 'OptimaX Status', 'litespeed-cache' ); ?></h3>

				<p>
					<?php if ( $ox_on ) : ?>
						<span class="<?php echo esc_attr( $ox_paused ? 'litespeed-label-warning' : 'litespeed-label-success' ); ?> litespeed-label-dashboard"><?php esc_html_e( 'ON', 'litespeed-cache' ); ?></span>
					<?php else : ?>
						<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'OFF', 'litespeed-cache' ); ?></span>
					<?php endif; ?>
					OptimaX
					<?php if ( $ox_paused ) : ?>
						 <span class="litespeed-warning">- <?php esc_html_e( 'paused', 'litespeed-cache' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<?php if ( $ox_cron ) : ?>
						<span class="<?php echo esc_attr( $ox_paused || $wp_cron_off ? 'litespeed-label-warning' : 'litespeed-label-success' ); ?> litespeed-label-dashboard"><?php esc_html_e( 'ON', 'litespeed-cache' ); ?></span>
					<?php else : ?>
						<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'OFF', 'litespeed-cache' ); ?></span>
					<?php endif; ?>
					<?php esc_html_e( 'OptimaX Cron', 'litespeed-cache' ); ?>
					<?php if ( $ox_cron && $ox_paused ) : ?>
						 <span class="litespeed-warning">- <?php esc_html_e( 'paused', 'litespeed-cache' ); ?></span>
					<?php elseif ( $ox_cron && $wp_cron_off ) : ?>
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
