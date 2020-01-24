<?php
namespace LiteSpeed ;
defined( 'WPINC' ) || exit ;

$this->form_action() ;
?>


<h3 class="litespeed-title-short">
	<?php echo __('Debug Settings', 'litespeed-cache'); ?>
	<?php $this->learn_more( 'https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:debug', false, 'litespeed-learn-more' ) ; ?>
</h3>

<table class="wp-list-table striped litespeed-table"><tbody>
	<tr>
		<th>
			<?php $id = Base::O_DEBUG_DISABLE_ALL ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_switch( $id ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'This will disable LSCache and all optimization features for debug purpose.', 'litespeed-cache' ) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<div class="litespeed-switch">
				<?php $this->build_radio(
					$id,
					Base::VAL_OFF,
					__( 'OFF', 'litespeed-cache' )
				) ; ?>

				<?php $this->build_radio(
					$id,
					Base::VAL_ON,
					__( 'ON', 'litespeed-cache' )
				) ; ?>

				<?php $this->build_radio(
					$id,
					Base::VAL_ON2,
					__( 'Admin IP only', 'litespeed-cache' )
				) ; ?>
			</div>
			<div class="litespeed-desc">
				<?php echo __( 'Outputs to WordPress debug log.', 'litespeed-cache' ) ; ?>
				<?php echo __( 'This should be set to off once everything is working to prevent filling the disk.', 'litespeed-cache' ) ; ?>
				<?php echo __( 'The Admin IP option will only output log messages on requests from admin IPs.', 'litespeed-cache' ) ; ?>
				<?php echo sprintf( __( 'The logs will be outputted to %s.', 'litespeed-cache' ), '<code>wp-content/debug.log</code>' ) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG_IPS ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_textarea( $id, 30 ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'Allows listed IPs (one per line) to perform certain actions from their browsers.', 'litespeed-cache' ) ; ?>
				<?php echo __( 'Your IP', 'litespeed-cache' ) ; ?>: <code><?php echo Router::get_ip() ; ?></code>
				<?php $this->_validate_ip( $id ) ; ?>
				<br />
				<?php $this->learn_more(
					'https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:information:admin-ip-commands',
					__( 'More information about the available commands can be found here.', 'litespeed-cache' )
				) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG_LEVEL ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<div class="litespeed-switch">
				<?php $this->build_radio(
					$id,
					Base::VAL_OFF,
					__( 'Basic', 'litespeed-cache' )
				) ; ?>

				<?php $this->build_radio(
					$id,
					Base::VAL_ON,
					__( 'Advanced', 'litespeed-cache' )
				) ; ?>
			</div>
			<div class="litespeed-desc">
				<?php echo __( 'Advanced level will log more details.', 'litespeed-cache' ) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG_FILESIZE ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_input( $id, 'litespeed-input-short' ) ; ?> <?php echo __( 'MB', 'litespeed-cache' ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'Specify the maximum size of the log file.', 'litespeed-cache' ) ; ?>
				<?php $this->recommended( $id ) ; ?>
				<?php $this->_validate_ttl( $id, 3, 3000 ) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG_COOKIE ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_switch( $id ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'Log request cookie values.', 'litespeed-cache' ) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_MEDIA_LAZY_EXC ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_switch( $id ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'Shorten query strings in the debug log to improve readability.', 'litespeed-cache' ) ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG_INC ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_textarea( $id ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'Only log listed pages.', 'litespeed-cache' ) ; ?>
				<?php $this->_uri_usage_example() ; ?>
			</div>
		</td>
	</tr>

	<tr>
		<th>
			<?php $id = Base::O_DEBUG_EXC ; ?>
			<?php $this->title( $id ) ; ?>
		</th>
		<td>
			<?php $this->build_textarea( $id ) ; ?>
			<div class="litespeed-desc">
				<?php echo __( 'Prevent any debug log of listed pages.', 'litespeed-cache' ) ; ?>
				<?php $this->_uri_usage_example() ; ?>
			</div>
		</td>
	</tr>

</tbody></table>

<?php

$this->form_end() ;







