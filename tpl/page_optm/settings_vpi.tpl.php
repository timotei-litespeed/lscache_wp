<?php
/**
 * LiteSpeed Cache Viewport Images Settings
 *
 * Renders the Viewport Images settings interface for LiteSpeed Cache, allowing configuration of viewport image detection and exclusions.
 *
 * @package LiteSpeed
 * @since 1.0.0
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit;

$summary        = VPI::get_summary();
$closest_server = Cloud::get_summary( 'server.' . Cloud::SVC_VPI );
?>

<h3 class="litespeed-title-short">
	<?php esc_html_e( 'Viewport Images', 'litespeed-cache' ); ?>
	<?php Doc::learn_more( 'https://docs.litespeedtech.com/lscache/lscwp/pageopt/#vpi' ); ?>
</h3>

<table class="wp-list-table striped litespeed-table">
	<tbody>
		<tr>
			<th>
				<?php $option_id = Base::O_MEDIA_VPI; ?>
				<?php $this->title( $option_id ); ?>
			</th>
			<td>
				<?php $this->build_switch( $option_id ); ?>
				<div class="litespeed-desc">
					<?php esc_html_e( 'When you use Lazy Load, it will delay the loading of all images on a page.', 'litespeed-cache' ); ?>
					<br /><?php esc_html_e( 'The Viewport Images service detects which images appear above the fold, and excludes them from lazy load.', 'litespeed-cache' ); ?>
					<br /><?php esc_html_e( "This enables the page's initial screenful of imagery to be fully displayed without delay.", 'litespeed-cache' ); ?>

					<?php if ( ! $this->conf( Base::O_MEDIA_LAZY ) ) : ?>
						<br />
						<font class="litespeed-warning litespeed-left10">
							⚠️ <?php esc_html_e( 'Notice', 'litespeed-cache' ); ?>: <?php printf( esc_html__( '%s must be turned ON for this setting to work.', 'litespeed-cache' ), '<code>' . esc_html( Lang::title( Base::O_MEDIA_LAZY ) ) . '</code>' ); ?>
						</font>
					<?php endif; ?>
				</div>

				<div class="litespeed-desc litespeed-left20">
					<?php if ( $summary ) : ?>
						<?php if ( ! empty( $summary['last_request'] ) ) : ?>
							<p>
								<?php echo esc_html__( 'Last generated', 'litespeed-cache' ) . ': <code>' . esc_html( Utility::readable_time( $summary['last_request'] ) ) . '</code>'; ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $closest_server ) : ?>
						<a class='litespeed-redetect' href="<?php echo esc_url( Utility::build_url( Router::ACTION_CLOUD, Cloud::TYPE_REDETECT_CLOUD, false, null, array( 'svc' => Cloud::SVC_VPI ) ) ); ?>" data-balloon-pos="up" data-balloon-break aria-label='<?php printf( esc_html__( 'Current closest Cloud server is %s. Click to redetect.', 'litespeed-cache' ), esc_html( $closest_server ) ); ?>' data-litespeed-cfm="<?php esc_html_e( 'Are you sure you want to redetect the closest cloud server for this service?', 'litespeed-cache' ); ?>"><i class='litespeed-quic-icon'></i> <?php esc_html_e( 'Redetect', 'litespeed-cache' ); ?></a>
					<?php endif; ?>

					<?php Queue_Box::render( 'vpi' ); ?>
				</div>
			</td>
		</tr>

		<tr>
			<th>
				<?php $option_id = Base::O_MEDIA_VPI_CRON; ?>
				<?php $this->title( $option_id ); ?>
			</th>
			<td>
				<?php $this->build_switch( $option_id ); ?>
				<div class="litespeed-desc">
					<?php esc_html_e( 'Enable Viewport Images auto generation cron.', 'litespeed-cache' ); ?>
				</div>
			</td>
		</tr>
	</tbody>
</table>
