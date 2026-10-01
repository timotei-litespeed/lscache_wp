<?php
/**
 * LiteSpeed Cache OptimaX Settings
 *
 * Manages OptimaX settings for LiteSpeed Cache.
 *
 * @package LiteSpeed
 * @since 8.0
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit;

$closest_server = Cloud::get_summary( 'server.' . Cloud::SVC_OPTIMAX );

$this->form_action();
?>

<h3 class="litespeed-title-short">
	<?php esc_html_e( 'OptimaX Settings', 'litespeed-cache' ); ?>
	<?php Doc::learn_more( 'https://docs.litespeedtech.com/lscache/lscwp/imageopt/#image-optimization-settings-tab' ); ?>
</h3>

<table class="wp-list-table striped litespeed-table">
	<tbody>

		<tr>
			<th>
				<?php $option_id = Base::O_OPTIMAX; ?>
				<?php $this->title( $option_id ); ?>
			</th>
			<td>
				<?php $this->build_switch( $option_id ); ?>
				<div class="litespeed-desc">
					<?php esc_html_e( 'Turn on OptimaX.', 'litespeed-cache' ); ?>
				</div>

				<?php if ( Optimax::is_paused() ) : ?>
					<div class="litespeed-danger litespeed-text-bold">
						⚠️ <?php echo esc_html( Optimax::paused_msg() ); ?>
					</div>
				<?php endif; ?>
				
				<?php if ( $closest_server ) : ?>
					<a class="litespeed-redetect" href="<?php echo esc_url( Utility::build_url( Router::ACTION_CLOUD, Cloud::TYPE_REDETECT_CLOUD, false, null, array( 'svc' => Cloud::SVC_OPTIMAX ) ) ); ?>" data-balloon-pos="up" data-balloon-break aria-label="<?php printf( esc_html__( 'Current closest Cloud server is %s. Click to redetect.', 'litespeed-cache' ), esc_html( $closest_server ) ); ?>" data-litespeed-cfm="<?php esc_html_e( 'Are you sure you want to redetect the closest cloud server for this service?', 'litespeed-cache' ); ?>"><i class="litespeed-quic-icon"></i> <?php esc_html_e( 'Redetect', 'litespeed-cache' ); ?></a>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th>
				<?php $option_id = Base::O_OPTIMAX_CRON; ?>
				<?php $this->title( $option_id ); ?>
			</th>
			<td>
				<?php $this->build_switch( $option_id ); ?>
				<div class="litespeed-desc">
					<?php esc_html_e( 'Automatically process the OptimaX queue via cron job, one URL per run. When off, run the queue manually from the OptimaX Summary tab.', 'litespeed-cache' ); ?>
					<?php Task::cron_off_notice(); ?>
				</div>
			</td>
		</tr>

	</tbody>
</table>

<?php
$this->form_end();
