<?php
/**
 * LiteSpeed Cache Compatibility
 *
 * Renders the Compatibility interface for LiteSpeed Cache, allowing users to apply compatibility fixes with theme and plugins.
 *
 * @package LiteSpeed
 * @since 7.5.0
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit;

$compatility = $this->cls( 'Compatibility' );
$list        = $compatility->getList();
?>

<h3 class="litespeed-title">
	<?php esc_html_e( 'Compatibility', 'litespeed-cache' ); ?>
	<?php Doc::learn_more( 'https://docs.litespeedtech.com/lscache/lscwp/toolbox/#compatibility' ); ?>
</h3>

<?php if ( 0 < count( $list ) ) : ?>
	<div>
		<?php foreach ( $list as $compatibility_key => $l ) : ?>
			<div>
				<h3 style="margin-bottom: 10px;"><?php esc_html_e( $l[ 'title' ] ); ?></h3>
				<?php if ( isset( $l[ 'text' ] ) && $l[ 'text' ] ) : ?>
					<div style="margin-bottom: 10px; margin-left: 10px;"><?php esc_html_e( $l[ 'text' ] ); ?></div>
				<?php endif; ?>
				<?php if ( isset( $l[ 'functions' ] ) && 0 < count( $l[ 'functions' ] ) ) : ?>
					<table class="compatibility_table">
						<?php foreach ( $l[ 'functions' ] as $func_key => $func ) : ?>
							<?php
								$comp_can_show    = $compatility->canShowFunction( $compatibility_key, $func_key );
								$comp_button_link = $comp_can_show ? $compatility->getLink( $compatibility_key, $func_key ) : '#';
								if ( $comp_button_link ) :
									$comp_button_id = $compatibility_key . '-' . $func_key;
									$comp_class_add = !$comp_can_show ? ' disabled' : '';
									$comp_title     = isset( $func[ 'info' ] ) ? "  aria-label=\"" . esc_html( $func[ 'info' ] ) . "\" data-balloon-pos=\"down\"" : '';
							?>
								<tr>
									<td class="td_button">
										<a 
											id="<?php echo esc_html( $comp_button_id ); ?>"
											href="<?php echo esc_url( $comp_button_link ); ?>"
											class="button litespeed-btn-danger btn-compatibility-apply<?php esc_html_e( $comp_class_add ); ?>"
										>
											<?php esc_html_e( $func[ 'text' ] ); ?>
										</a>
									</td>
									<td class="td_text">
										<?php if( isset( $func[ 'info' ] ) ): ?>
											<?php echo $func[ 'info' ]; ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
<?php else : ?>
	<?php esc_html_e('No compatibilities functions found.', 'litespeed-cache'); ?>
<?php endif; ?>

<style>
	.compatibility_table tr td.td_button{ text-align: center; }
	.compatibility_table tr td.td_button .button{ width: 100%; }
	.compatibility_table tr td.td_text{ width: 90%; }

	@media all and ( max-width: 600px ){
		.compatibility_table tr{ display: block; margin-bottom: 15px; text-align: left; }
		.compatibility_table tr td{ padding: 5px; }
		.compatibility_table tr td.td_button{ text-align: left; }
		.compatibility_table tr td.td_button .button{ width: auto; }
		.compatibility_table tr td.td_text,
		.compatibility_table tr td.td_button{ width: 100%; display: block; }
	}
</style>