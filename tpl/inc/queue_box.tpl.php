<?php
/**
 * Shared QUIC.cloud queue box (UCSS / CCSS / VPI / LQIP).
 *
 * Rendered by Queue_Box::render( $svc ); expects `$qb` (see Queue_Box::render()).
 *
 * @package LiteSpeed
 * @since 8.1
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit;

if ( empty( $qb ) || ! is_array( $qb ) ) {
	return;
}

$qb_info   = $qb['info'];
$qb_counts = $qb['counts'];
$qb_empty  = empty( $qb_counts['total'] );
?>
<div class="litespeed-callout notice notice-<?php echo $qb_empty ? 'info' : 'warning'; ?> inline litespeed-queue-box" id="<?php echo esc_attr( $qb['box_id'] ); ?>">
	<h4>
		<?php /* translators: %s: service name */ printf( esc_html__( '%s queue', 'litespeed-cache' ), esc_html( $qb['title'] ) ); ?>:
		<span class="litespeed-queue-counts">
			<span class="litespeed-info"><?php /* translators: %d: count */ printf( esc_html__( '%d waiting', 'litespeed-cache' ), (int) $qb_counts['waiting'] ); ?></span> ·
			<span class="litespeed-success"><?php /* translators: %d: count */ printf( esc_html__( '%d building', 'litespeed-cache' ), (int) $qb_counts['sent'] ); ?></span> ·
			<span class="<?php echo $qb_counts['failed'] ? 'litespeed-danger' : ''; ?>"><?php /* translators: %d: count */ printf( esc_html__( '%d failed', 'litespeed-cache' ), (int) $qb_counts['failed'] ); ?></span>
		</span>
		<?php if ( ! $qb_empty ) : ?>
			<small class="litespeed-desc litespeed-left10">
				<?php /* translators: 1: version count, 2: URL count */ printf( esc_html__( '%1$d version(s) of %2$d URL(s)', 'litespeed-cache' ), (int) $qb_counts['total'], (int) $qb_info['urls'] ); ?>
			</small>
		<?php endif; ?>

		<span class="litespeed-right litespeed-queue-tools">
			<?php if ( $qb_info['paused'] ) : ?>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'resume' ) ); ?>" class="button button-small litespeed-btn-success"><?php esc_html_e( 'Resume', 'litespeed-cache' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'pause' ) ); ?>" class="button button-small"><?php esc_html_e( 'Pause', 'litespeed-cache' ); ?></a>
			<?php endif; ?>
			<?php if ( $qb_counts['failed'] ) : ?>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'clear_failed' ) ); ?>" class="button button-small litespeed-btn-warning" data-litespeed-cfm="<?php esc_attr_e( 'Are you sure?', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear failed', 'litespeed-cache' ); ?></a>
			<?php endif; ?>
			<?php if ( $qb_counts['waiting'] ) : ?>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'clear_waiting' ) ); ?>" class="button button-small litespeed-btn-warning" data-litespeed-cfm="<?php esc_attr_e( 'Are you sure?', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear waiting', 'litespeed-cache' ); ?></a>
			<?php endif; ?>
			<?php if ( ! $qb_empty ) : ?>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'clear_all' ) ); ?>" class="button button-small litespeed-btn-danger" data-litespeed-cfm="<?php esc_attr_e( 'Clear the whole queue? Items QUIC.cloud is still building are kept.', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear all', 'litespeed-cache' ); ?></a>
			<?php endif; ?>
			<?php if ( $qb_counts['sent'] ) : ?>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'clear_all', [ 'force' => 1 ] ) ); ?>" class="button button-small litespeed-btn-danger" data-litespeed-cfm="<?php esc_attr_e( 'Also drop items QUIC.cloud is still building? Their builds will be wasted.', 'litespeed-cache' ); ?>"><?php esc_html_e( 'Clear all incl. building', 'litespeed-cache' ); ?></a>
			<?php endif; ?>
		</span>
	</h4>

	<p class="litespeed-queue-state">
		<?php if ( $qb_info['paused'] ) : ?>
			<span class="litespeed-label-warning litespeed-label-dashboard"><?php /* translators: %s: HH:MM */ printf( esc_html__( 'Paused since %s', 'litespeed-cache' ), esc_html( Queue_Box::fmt_time( $qb_info['paused'] ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( $qb_info['next_run_after'] ) : ?>
			<span class="litespeed-label-warning litespeed-label-dashboard"><?php /* translators: %s: HH:MM */ printf( esc_html__( 'Waiting for QUIC.cloud until %s', 'litespeed-cache' ), esc_html( Queue_Box::fmt_time( $qb_info['next_run_after'] ) ) ); ?></span>
		<?php endif; ?>
		<?php if ( ! $qb_info['cron_on'] ) : ?>
			<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'Cron off', 'litespeed-cache' ); ?></span>
		<?php endif; ?>
		<?php if ( $qb_info['wpcron_off'] ) : ?>
			<span class="litespeed-label-danger litespeed-label-dashboard"><?php esc_html_e( 'WP-Cron disabled', 'litespeed-cache' ); ?></span>
		<?php endif; ?>
		<?php if ( $qb_info['hot'] ) : ?>
			<span class="litespeed-label-info litespeed-label-dashboard"><?php /* translators: %d: seconds */ printf( esc_html__( 'Cooling down: %d s', 'litespeed-cache' ), (int) $qb_info['hot'] ); ?></span>
		<?php endif; ?>
		<?php if ( $qb_info['err_recent'] ) : ?>
			<span class="litespeed-danger" data-balloon-pos="up" aria-label="<?php /* translators: %s: HH:MM */ printf( esc_attr__( 'Last error at %s', 'litespeed-cache' ), esc_attr( Queue_Box::fmt_time( $qb_info['last_err_at'] ) ) ); ?>">⚠️ <?php echo esc_html( $qb_info['err_recent'] ); ?></span>
		<?php endif; ?>
	</p>

	<div class="litespeed-queue-progress" style="display:none">
		<div class="litespeed-progress litespeed-queue-progress-bar"><div class="litespeed-progress-bar" style="width:0%"></div></div>
		<div class="litespeed-queue-log"></div>
	</div>

	<?php if ( $qb_empty ) : ?>
		<p><?php esc_html_e( 'The queue is empty.', 'litespeed-cache' ); ?></p>
	<?php else : ?>
		<div class="litespeed-queue-nav">
			<input type="text" class="litespeed-input-short2" value="<?php echo esc_attr( $qb['qs'] ); ?>" placeholder="<?php esc_attr_e( 'Search URL', 'litespeed-cache' ); ?>" data-litespeed-qs="<?php echo esc_url( $qb['base_url'] ); ?>" data-litespeed-qs-hash="<?php echo esc_attr( $qb['anchor'] ); ?>" />
			<a href="javascript:;" class="button button-small" data-litespeed-qs-btn><?php esc_html_e( 'Search', 'litespeed-cache' ); ?></a>
			<?php if ( '' !== $qb['qs'] ) : ?>
				<a href="<?php echo esc_url( $qb['base_url'] . '#' . $qb['anchor'] ); ?>" class="button button-small"><?php esc_html_e( 'Clear search', 'litespeed-cache' ); ?></a>
				<span class="litespeed-desc"><?php /* translators: %d: count */ printf( esc_html__( '%d match(es)', 'litespeed-cache' ), (int) $qb['matched'] ); ?></span>
			<?php endif; ?>

			<?php if ( $qb['pages'] > 1 ) : ?>
				<span class="litespeed-right">
					<?php if ( $qb['page'] > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'qpage' => $qb['page'] - 1, 'qs' => $qb['qs'] ], $qb['base_url'] ) . '#' . $qb['anchor'] ); ?>" class="button button-small">&laquo; <?php esc_html_e( 'Prev', 'litespeed-cache' ); ?></a>
					<?php endif; ?>
					<span class="litespeed-desc"><?php /* translators: 1: current page, 2: total pages */ printf( esc_html__( 'Page %1$d of %2$d', 'litespeed-cache' ), (int) $qb['page'], (int) $qb['pages'] ); ?></span>
					<?php if ( $qb['page'] < $qb['pages'] ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'qpage' => $qb['page'] + 1, 'qs' => $qb['qs'] ], $qb['base_url'] ) . '#' . $qb['anchor'] ); ?>" class="button button-small"><?php esc_html_e( 'Next', 'litespeed-cache' ); ?> &raquo;</a>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>

		<div class="litespeed-queue-list">
			<?php foreach ( $qb['groups'] as $qb_group ) : ?>
				<div class="litespeed-queue-row">
					<div class="litespeed-queue-url"><?php echo esc_html( $qb_group['url'] ); ?></div>
					<?php foreach ( $qb_group['versions'] as $qb_row ) : ?>
						<?php list( $qb_mod, $qb_state_txt ) = Queue_Box::state_text( $qb_row ); ?>
						<div class="litespeed-queue-ver">
							<span class="litespeed-queue-dot litespeed-queue-dot-<?php echo esc_attr( $qb_mod ); ?>"></span>
							<span class="litespeed-queue-ver-label"><?php echo esc_html( Queue_Box::version_label( $qb_row ) ); ?></span>
							<span class="litespeed-<?php echo esc_attr( $qb_mod ); ?>"><?php echo esc_html( $qb_state_txt ); ?></span>
							<?php if ( $qb_row['err'] ) : ?>
								<code class="litespeed-queue-err" title="<?php echo esc_attr( $qb_row['err'] ); ?>"><?php echo esc_html( $qb_row['err'] ); ?></code>
							<?php endif; ?>
							<?php if ( $qb_row['added'] ) : ?>
								<span class="litespeed-desc" data-balloon-pos="up" aria-label="<?php echo esc_attr( Utility::readable_time( $qb_row['added'] ) ); ?>"><?php /* translators: %s: human time diff */ printf( esc_html__( 'added %s ago', 'litespeed-cache' ), esc_html( human_time_diff( $qb_row['added'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( $qb_row['prio'] ) : ?>
								<span class="litespeed-desc" data-balloon-pos="up" aria-label="<?php esc_attr_e( 'Runs before visitor-added items', 'litespeed-cache' ); ?>">★</span>
							<?php endif; ?>
							<span class="litespeed-queue-actions">
								<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'gen_item', [ 'q_k' => $qb_row['hash'] ] ) ); ?>"><?php esc_html_e( 'Run', 'litespeed-cache' ); ?></a>
								<?php if ( 'failed' === $qb_row['state'] || 'dead' === $qb_row['state'] ) : ?>
									· <a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'retry_item', [ 'q_k' => $qb_row['hash'] ] ) ); ?>"><?php esc_html_e( 'Retry', 'litespeed-cache' ); ?></a>
								<?php endif; ?>
								· <a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'rm_item', [ 'q_k' => $qb_row['hash'] ] ) ); ?>" class="litespeed-danger"><?php esc_html_e( 'Remove', 'litespeed-cache' ); ?></a>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $qb['groups'] ) : ?>
				<p class="litespeed-desc"><?php esc_html_e( 'No items match the search.', 'litespeed-cache' ); ?></p>
			<?php endif; ?>
		</div>

		<p class="litespeed-queue-run">
			<?php if ( $qb_info['hot'] ) : ?>
				<button class="button button-secondary" disabled>
					<?php /* translators: %s: service name */ printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), esc_html( $qb['title'] ) ); ?>
					- <?php /* translators: %d: seconds */ printf( esc_html__( 'Available after %d second(s)', 'litespeed-cache' ), (int) $qb_info['hot'] ); ?>
				</button>
			<?php else : ?>
				<a href="javascript:;" class="button litespeed-btn-success" data-litespeed-queue-run="<?php echo esc_url( $qb['ajax_run_next'] ); ?>"><?php esc_html_e( 'Run until empty', 'litespeed-cache' ); ?></a>
				<a href="javascript:;" class="button litespeed-btn-warning" data-litespeed-queue-stop style="display:none"><?php esc_html_e( 'Stop', 'litespeed-cache' ); ?></a>
				<a href="<?php echo esc_url( Queue_Box::url( $qb['svc'], 'gen' ) ); ?>" class="button button-secondary">
					<?php /* translators: %s: service name */ printf( esc_html__( 'Run %s Queue Manually', 'litespeed-cache' ), esc_html( $qb['title'] ) ); ?>
				</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<p class="litespeed-desc litespeed-queue-next">⏱ <?php echo esc_html( $qb_info['next_line'] ); ?></p>
	<?php if ( ! $qb_empty ) : ?>
		<?php Doc::queue_issues(); ?>
	<?php endif; ?>
</div>
