<?php

/**
 * The class to processing image.
 *
 * @since 		6.3
 * @package    	LiteSpeed
 * @subpackage 	LiteSpeed/src
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */

namespace LiteSpeed;

defined('WPINC') || exit();

class Img_Resize extends Base
{
	const LOG_TAG = '📐';

	const TYPE_NEXT = 'next';
	const TYPE_START = 'start';
	const TYPE_RECALCULATE = 'recalculate';
	const TYPE_DELETE_BK = 'delete_bk';
	const TYPE_IMAGE = 'image';
	const TYPE_RESTORE_BK = 'restore_bk';
	const TYPE_RESET = 'reset';

	const DB_PREFIX = 'litespeed-resize';
	const DB_DATA = 'data';
	
	const BK_ADD = '_res_bk';

	const RES_BK = 'has_bk';
	const RES_ORIGINAL_SIZE = 'orig_size';
	const RES_NEW_SIZE = 'new_size';

	const S_CURRENT_POST = 'current_post';
	const S_CURRENT = 'current';
	const S_TOTAL = 'total';

	private $mime_images = array( 'image/jpeg', 'image/png', 'image/gif' );
	
	protected $_summary;
	
	/**
	 * Init
	 *
	 * @return void
	 */
	public function init()
	{
		Debug2::debug2('[Img Processing] init');

		$this->_summary = self::get_summary();
		if ( !isset( $this->_summary[self::S_CURRENT_POST] ) ) {
			$this->_summary[self::S_CURRENT_POST] = 0;
			self::save_summary();
		}
		if ( !isset( $this->_summary[self::S_CURRENT] ) ) {
			$this->_summary[self::S_CURRENT] = 0;
			self::save_summary();
		}
		if ( !isset( $this->_summary[self::S_TOTAL] ) ) {
			$this->_summary[self::S_TOTAL] = 0;
			self::save_summary();
		}

		// Hooks
	    $this->add_hooks();
	}

	/**
	 * Add custom hooks.
	 *
	 * @return void
	 */
	public function add_hooks(){
		// If image resize optimization is ON, do resize.
		if($this->conf( self::O_IMG_OPTM_RESIZE )){
			if ( version_compare( get_bloginfo( 'version' ), '5.3.0', '>=' ) ) {
				// Ensure WP will not resize the image before we do. WP will do this by default in >= 5.3.0.
				add_filter( 'big_image_size_threshold', array( $this, 'set_big_image_size_threshold'), 10, 1 );
			}
			
			// Wordpress default upload filter.
			add_filter( 'wp_handle_upload', array( $this, 'wp_resize_upload_image' ));
			// TODO: find better filter?!
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'wp_resize_generate_metadata' ), 10, 2);
	
			// Some plugins will need custom upload adjustment. Add here.
		}
	}

	// Hooks START here.
	/**
	 * WP Resize hook.
	 *
	 * @param array $params File with path. Expected keys: type(mime type format), url, file(path to file)
	 * @return array
	 */
	public function wp_resize_upload_image($params){
		$this->resize_image_func( $params );

		return $params;
	}
	
	/**
	 * Set big image size threshold in WP > 5.3.0. Stop Wordpress resize in media.
	 *
	 * @param  mixed $size Current size.
	 * @return int
	 */
	public function set_big_image_size_threshold( $size ){
		$resize_size = $this->get_formatted_resize_size();

		return max(
			(int) $resize_size[0],
			(int) $resize_size[1],
			(int) $size
		) + 1;
	}
	// Hooks END here.
		
	/**
	 * WP generate attachment metadata hook.
	 *
	 * @param  array $meta Meta data to save.
	 * @param  int|null $id Post id.
	 * @return array Meta data.
	 */
	public function wp_resize_generate_metadata( $meta, $id = null ){
		// Get file info from post id.
		$params = $this->prepare_parameters_from_id( $id );

		try{
			if(
				$params['file'] && 
				in_array( $params['type'], $this->mime_images, true )
			){
				$this->generate_post_meta( $params, $id );
			}
		}
		catch( \Exception $e ){
			return $meta;
		}

		return $meta;
	}
	
	/**
	 * Update summary with necessary data. Calculate totals, how many have data, and (if needed) will save the next image ID to convert.
	 *
	 * @param  bool $go_to_next
	 * @return void
	 */
	public function update_summary_data($go_to_next = true){
		global $wpdb;
		$meta_name_data = $this->get_meta_name();

		// Prepare SQL: select is attachment
		$select_attachment = array(
			'sql' => 'FROM %i WHERE post_type = %s AND %i LIKE %s',
			'vars' => array(
				$wpdb->posts,
				'attachment',
				'post_mime_type',
				'%image/%'
			),
		);
		$select_is_attachment = $wpdb->prepare(
			'SELECT ID ' . $select_attachment['sql'], 
			$select_attachment['vars']
		);
		$select_count_is_attachment = $wpdb->prepare(
			'SELECT count(1) ' . $select_attachment['sql'], 
			$select_attachment['vars']
		);

		// Prepare SQL: select posts with resize meta
		$select_with_meta_resize = $wpdb->prepare(
			'SELECT b.%i FROM %i AS b WHERE b.%i = %s AND b.%i = a.%i',
			array(
				'meta_id',
				$wpdb->postmeta,
				'meta_key',
				$meta_name_data,
				'post_id',
				'post_id',
			)
		);

		if($go_to_next){
			// Next image will become Current image.
			$sql = "SELECT a.%i FROM %i AS a WHERE a.%i IN (" . $select_is_attachment . ") AND NOT EXISTS (" . $select_with_meta_resize . ") ORDER BY a.%i ASC LIMIT 1";
			$prepare_sql = $wpdb->prepare(
				$sql,
				array(
					'post_id',
					$wpdb->postmeta,
					'post_id',
					'post_id'
				)
			);
			$current_image = $wpdb->get_var( $prepare_sql );
			if($current_image){
				$this->_summary[self::S_CURRENT_POST] = $current_image;
			}
		}

		// Get total images from media.
		$total_posts = $wpdb->get_var( $select_count_is_attachment );
		if($total_posts){
			$this->_summary[self::S_TOTAL] = $total_posts;
		}

		// Get how many are done.
		$prepare_sql = $wpdb->prepare(
			"SELECT COUNT(a.%i) FROM %i AS a WHERE %i = %s",
			array(
				'post_id',
				$wpdb->postmeta,
				'meta_key',
				$meta_name_data
			)
		);
		$current_posts = $wpdb->get_var( $prepare_sql );
		if($current_posts){
			$this->_summary[self::S_CURRENT] = $current_posts;
		}

		self::save_summary();
	}
	
	/**
	 * Get meta name.
	 *
	 * @return string
	 */
	public static function get_meta_name(){
		return self::DB_PREFIX . '-' . self::DB_DATA;
	}
	
	/**
	 * Get backup gallery path.
	 *
	 * @param  mixed $backup_path Backup path.
	 * @return void
	 */
	private function get_backup_gallery_path($backup_path){
		$upload_dir = wp_upload_dir();
		
		return str_replace( $upload_dir['basedir'], '', $backup_path );
	}
	
	/**
	 * Create backup code.
	 *
	 * @param  mixed $params File paramters.
	 * @return void
	 */
	public function do_backup_funct( $params ){
		$path_info = pathinfo($params['file']);
		$backup_path = $this->get_backup_path_from_file( $params['file'], $path_info );
		$return_val = $this->get_backup_gallery_path($backup_path);

		// Check if backup was done.
		if ( !is_file( $backup_path ) ){
			// Cannot make backup.
			if( !copy( $params['file'], $backup_path ) ) {
				self::debug('[Image Resize] Cannot make backup to file: ' . $params['file'] );
				$return_val = 0;
			}
		}
		else {
			self::debug('[Image Resize] Backup exists for file: ' . $params['file'] );
		}

		return $return_val;
	}

	/**
	 * Resize functionality: on upload and custom attachement id.
	 *
	 * @param array $params File with path. Expected keys: type(mime type format), url, file(path to file)
	 * @param bool|int|string $post_id Attachment id.
	 * @return void
	 */
	public function resize_image_func( $params, $post_id = false ){
		// Return if the file is not an image.
		if ( ! in_array( $params['type'], $this->mime_images, true ) ) {
			return false;
		}

		try{
			if( ! $params['file'] ){
				throw( new \Exception( 'No image sent to resize.' ) );
			}
			$path_info = pathinfo($params['file']);
			$original_size = filesize( $params['file'] );
			$meta_add = array(
				self::RES_ORIGINAL_SIZE => $original_size,
				self::RES_NEW_SIZE => $original_size,
				self::RES_BK => 0
			);

			if($post_id){
				if( metadata_exists( 'post', $post_id, $this->get_meta_name() ) ){
					$meta_add = get_post_meta( $post_id, $this->get_meta_name(), true );
				}
			}
			
			// Get resize size.
			$resize_size = $this->get_formatted_resize_size();

			// Image editor for current image.
			$editor = wp_get_image_editor( $params['file'] );
			if ( is_wp_error( $editor ) ) {
				throw( new \Exception( 'Editor cannot be created. ' . $editor->get_error_message() ) );
			}
			$current_sizes = $editor->get_size();

			// Do resize if needed.
			if(
				$current_sizes['width'] > $resize_size[0] ||
				$current_sizes['height'] > $resize_size[1]
			){
				$do_backup = apply_filters( 'litespeed_img_resize_original_backup', !$this->conf( self::O_IMG_OPTM_STOP_BK ) );
				
				$backup = $do_backup ? $this->do_backup_funct( $params, $meta_add ) : null;
				if( $backup ){
					$meta_add[Img_Resize::RES_BK] = $backup;
				}
				else{
					$backup_path = $this->get_backup_path_from_file( $params['file'], $path_info );

					if( is_file( $backup_path ) ){
						$meta_add[Img_Resize::RES_BK] = $this->get_backup_gallery_path( $backup_path );
					}
				}

				// Add crop image data.
				$resize_crop = apply_filters( 'litespeed_img_resize_crop', false ); // Possible values: see https://developer.wordpress.org/reference/classes/wp_image_editor/resize/

				// Prepare what to do.
				$editor->resize( $resize_size[0], $resize_size[1], $resize_crop );
				$editor->set_quality( 100 );

				// Save resized image.
				$saved = $editor->save( $params['file'] );
				if ( is_wp_error( $saved ) ) {
					throw( new \Exception( 'Error resizing: ' . $saved->get_error_message() ) );
				}

				// Done.
				self::debug( '[Image Resize] Done: ' . $params['url'] );
				$meta_add[ self::RES_NEW_SIZE ] = filesize( $params['file'] );
			}

			if(!$post_id){
				// Save status of image upload. Temp to send data for meta.
				file::save( $path_info['dirname'] . '/' . $path_info['filename'] . '.lsc', json_encode( $meta_add ) );
			}
			else{
				// If meta do not exist, add it
				if( !metadata_exists( 'post', $post_id, $this->get_meta_name() ) ){
					add_post_meta( $post_id, $this->get_meta_name(), $meta_add );
				}
				// else update meta.
				else{
					update_post_meta( $post_id, $this->get_meta_name(), $meta_add );
				}
			}

			return true;
		}
		catch(\Exception $e){
			self::debug( '[Image Resize] Cannot resize file ' . $params['url'] . ': ' . $e );

			return false;
		}
	}
		
	/**
	 * Get resize style(by width or by height). Possible values: 0 - keep width ; 1 - keep height.
	 * Used in get_formatted_resize_size().
	 *
	 * @return void
	 */
	public function resize_style(){
		return apply_filters( 'litespeed_img_resize_style', 0 );
	}
	
	/**
	 * Ensure the resize from settings is formatted correctly and return it.
	 *
	 * @return array
	 */
	public function get_formatted_resize_size(){
		// Get sizes.
		$resize_size = $this->conf( self::O_IMG_OPTM_RESIZE_SIZE );
		$resize = explode( 'x', $resize_size );

		// Ensure correct size format. Null is used in resize function to get auto value for the missing size.
		if( strstr( $resize_size, 'x' ) === false ){
			$resize_style = $this->resize_style();

			// If resize to keep width.
			if( $resize_style === 0 ) $resize = [$resize_size, null];
			// If resize to keep height.
			if( $resize_style === 1 ) $resize = [null, $resize_size];
		}
		else{
			foreach( $resize as &$res ){
				if( $res === '' ) $res = null;
			}
		}

		return $resize;
	}
	
	/**
	 * Generate post metas from parameters. Used when uploading + generating attachment metadata.
	 *
	 * @param  array $params Image data.
	 * @param  mixed $id Post id.
	 * @return void
	 */
	public function generate_post_meta($params, $id){
		$meta_name_data = $this->get_meta_name();
		$post_meta_attachment = get_post_meta($id, $meta_name_data, true);

		// Create metas if do not exist.
		if( !$post_meta_attachment ){
			$path_info = pathinfo($params['file']);
			$lsc_file  = $path_info['dirname'] . '/' . $path_info['filename'] . '.lsc';

			if( is_file( $lsc_file ) ){
				$data = file::read($lsc_file);
				$data = json_decode($data, true);
				
				// Add data meta.
				add_post_meta($id, $meta_name_data, array(
					self::RES_ORIGINAL_SIZE => $data[self::RES_ORIGINAL_SIZE] ? $data[self::RES_ORIGINAL_SIZE] : 0,
					self::RES_NEW_SIZE => $data[self::RES_NEW_SIZE] ? $data[self::RES_NEW_SIZE] : 0,
					self::RES_BK   => $data[self::RES_BK],
				));

				// Delete file.
				unlink( $lsc_file );
			}
		}
	}
	
	/**
	 * Get image backup name from image path.
	 *
	 * @param  string $file_path File with path.
	 * @param  array|null $path_info Path info of file.
	 * @return string
	 */
	private function get_backup_name_from_file($file_path, $path_info = null){
		// If null sent, get pathinfo from file path.
		!$path_info && $path_info = pathinfo($file_path);

		return $path_info['filename'] . self::BK_ADD . '.' . $path_info['extension'];
	}

	/**
	 * Get image backup path from image path.
	 *
	 * @param  string $file_path File with path.
	 * @param  array|null $path_info Path info of file.
	 * @return string
	 */
	private function get_backup_path_from_file($file_path, $path_info = null){
		// If null sent, get pathinfo from file path.
		!$path_info && $path_info = pathinfo($file_path);

		$backup_name = $this->get_backup_name_from_file( $file_path, $path_info );
		
		// Return backup file path.
		return $path_info['dirname'] . '/' . $backup_name;
	}
	
	/**
	 * Prepare parameters for resize from attachment id.
	 *
	 * @param  string|int $id Attachment id.
	 * @return array
	 */
	private function prepare_parameters_from_id( $id ){
		$params = array(
			'file' => null,
			'url' => null,
			'type' => null
		);
		$metas = wp_get_attachment_metadata( $id );

		if(isset($metas['file'])){
			$upload_dir = wp_upload_dir();
			$file_path = $upload_dir['basedir'] . '/' . $metas['file'];

			if( $file_path ){
				$url = wp_get_attachment_image_url( $id, 'full' );
				$type = wp_get_image_mime( $file_path );

				$params['file'] = $file_path;
				$params['url']  = $url ? $url : null;
				$params['type'] = $type ? $type : null;
			}
		}

		return $params;
	}

	/**
	 * Convert size to units.
	 * https://stackoverflow.com/a/11860664
	 *
	 * @return string
	 */
	public function filesize_formatted($size)
	{
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$power = $size > 0 ? floor(log($size, 1024)) : 0;

		return number_format($size / pow(1024, $power), 2, '.', ',') . '' . $units[$power];
	}
	
	/**
	 * Restore original from backup.
	 *
	 * @param array $id Post id to restore from backup.
	 * @param array $update_meta Update meta data.
	 * @return void
	 */
	private function restore_funct( $id, $update_meta = true ){
		$return_val = false;
		$meta_name_data = $this->get_meta_name();
		$meta = get_post_meta( $id, $meta_name_data, true );

		if( $meta ){
			try{
				$upload_dir = wp_upload_dir();
				$bk_path = $upload_dir['basedir'] . $meta[self::RES_BK];
				$file_path = get_attached_file(  $id );
				
				// Copy backup over current file.
				if( !copy($bk_path, $file_path ) ){
					$return_val = false;
				}
				else{
					$return_val = true;
					if( $update_meta ){
						$meta[self::RES_NEW_SIZE] = $meta[self::RES_ORIGINAL_SIZE];
						update_post_meta( $id, $meta_name_data, $meta );
					}
				}
			}
			catch( \Exception $e ){
				$return_val = false;
			}
		}

		return $return_val;
	}
	
	/**
	 * Generate/Regenerate attachment metadata.
	 *
	 * @param int $post_id Post id to generate attachment metadata.
	 * @return void
	 */
	private function generate_attachment_metadata( $post_id ){
		if (!$post_id) {
			return;
		}

		$params = $this->prepare_parameters_from_id($post_id);

		if(
			$params['file'] && 
			in_array( $params['type'], $this->mime_images, true )
		){
			$this->generate_post_meta($params, $post_id);
		}
	}
	
	// ACTIONS Start here.
	/**
	 * Resize image from media optimize dashboard button.
	 *
	 * @param  mixed $update_summary_first
	 * @return void
	 */
	public function resize_next($update_summary_first = false){
		if( $this->conf( self::O_IMG_OPTM_RESIZE ) ){
			// Do summary update before resize + go to next. Eg: for first run.
			$update_summary_first && $this->update_summary_data();

			// Get summary.
			$summary = $this->get_summary();

			if( $summary['current'] <= $summary['total'] ){
				$this->resize_image_by_id( $summary[self::S_CURRENT_POST] );
			}
		}
		else{
			$msg = __( 'Image resize is turned off.', 'litespeed-cache' );
			Admin_Display::error( $msg );
		}
	}
	
	/**
	 * Recalculate resize summary.
	 *
	 * @return void
	 */
	public function recalculate_summary(){
		$this->update_summary_data( true );
	}
	
	/**
	 * Delete all images backups.
	 *
	 * @return void
	 */
	private function delete_all_backups(){
		global $wpdb;
		$meta_name_data = $this->get_meta_name();
		$errors = 0;
		$dones = 0;
		
		// Select images with Resize meta data.
		$select_with_meta_resize = $wpdb->prepare(
			'SELECT %i, %i, %i FROM %i WHERE %i = %s',
			array(
				'meta_id',
				'post_id',
				'meta_value',
				$wpdb->postmeta,
				'meta_key',
				$meta_name_data
			)
		);
		$attachments = $wpdb->get_results($select_with_meta_resize);

		if( $attachments && count( $attachments ) > 0 ){
			try{
				$upload_dir = wp_upload_dir();
				foreach( $attachments as $attachment ){
					if( $attachment->meta_value ){
						$meta = unserialize( $attachment->meta_value );
						if($meta[self::RES_BK]){
							$path = $upload_dir['basedir'] . $meta[self::RES_BK];
							if( !unlink($path) ){
								$errors++;
								self::debug( '[Image Resize] Cannot delete backup image: ' . $path );
							}

							$meta[self::RES_BK] = 0;
							update_post_meta( $attachment->post_id, $meta_name_data, $meta );
							$dones++;
						}
					}
				}

				if($dones > 0){
					$msg = sprintf(
						__('Backup images(%s) have been deleted.', 'litespeed-cache'),
						$dones
					);
					if($errors){
						$msg .= ' ' . sprintf(
							__('There were some errors(%s). You can check debug log to see the errors.', 'litespeed-cache'),
							$errors
						);
						Admin_Display::error($msg);
					}
					else{
						Admin_Display::success($msg);
					}
				}
				else{
					$msg = __('No backup images found.', 'litespeed-cache');
					Admin_Display::info($msg);
				}
			}
			catch( \Exception $e ){
				self::debug( '[Image Resize] Cannot delete all backup images: ' . $e );
				
				$msg = __('Error deleting backups.', 'litespeed-cache');
				Admin_Display::error($msg);
			}
		}
		else{
			$msg = __('No images found.', 'litespeed-cache');
			Admin_Display::info($msg);
		}
	}
	
	/**
	 * Resize image by attachemnt id.
	 *
	 * @param  mixed $post_id Post id.
	 * @return void
	 */
	public function resize_image_by_id( $post_id ){
		if (!$post_id) {
			$msg = __('No attachment sent.', 'litespeed-cache');
			Admin_Display::error($msg);
			return;
		}

		// Get summary.
		$summary = $this->get_summary();
		$params = $this->prepare_parameters_from_id( $post_id );
		$result = $this->resize_image_func( $params, $post_id );

		// Image has done resizing.
		if($result){
			$this->generate_attachment_metadata( $summary[self::S_CURRENT_POST] );
		
			$msg = sprintf(
				__( 'Done resizing image #%s.', 'litespeed-cache' ),
				$post_id
			);
			Admin_Display::success( $msg );
		}
		// Error on resizing image.
		else{
			add_post_meta(
				$summary[self::S_CURRENT_POST], 
				$this->get_meta_name(),
				array(
					self::RES_NEW_SIZE => 0,
					self::RES_ORIGINAL_SIZE => 0,
					self::RES_BK   => 0,
				)
			);
			$msg = sprintf(
				__( 'Cannot resize image #%s. Skipping this image.', 'litespeed-cache' ),
				$summary[self::S_CURRENT_POST]
			);
			Admin_Display::error( $msg );
		}
		$this->update_summary_data();
	}
	
	/**
	 * Restore image from backup.
	 *
	 * @param int $post_id Post id to restore from backup.
	 * @param bool $update_meta Update or not meta data.
	 * @return void
	 */
	private function restore_from_bk( $post_id, $update_meta = true ){
		if (!$post_id) {
			$msg = __('No attachment sent.', 'litespeed-cache');
			Admin_Display::error($msg);
			return;
		}

		$result = $this->restore_funct($post_id, $update_meta);
		if( $result ){
			self::debug( '[Image Resize] Image #' . $post_id . ' restored from backup.' );
				
			$msg = __('Image restored from backup.', 'litespeed-cache');
			Admin_Display::success($msg);
		}
		else{
			self::debug( '[Image Resize] Error restore backup for #' . $post_id . '.' );
				
			$msg = __('Error restoring backup.', 'litespeed-cache');
			Admin_Display::error($msg);
		}
	}

	/**
	 * Delete resize data: delete meta + restore backup + delete backup.
	 *
	 * @param int $post_id Post id to reset.
	 * @param int $delete_backup Delete backup.
	 * @access public
	 */
	public function reset_row($post_id, $delete_backup = true)
	{
		if (!$post_id) {
			return;
		}

		self::debug('_reset_row [pid] ' . $post_id);
		$error = false;

		$meta = get_post_meta($post_id, $this->get_meta_name(), true);
		if($meta[self::RES_BK]){
			// Restore image from backup.
			$this->restore_funct($post_id, false);

			if( $delete_backup ){
				// Delete backup file.
				$upload_dir = wp_upload_dir();
				$backup_path = $upload_dir['basedir'] . $meta[self::RES_BK];
			}

			if(!unlink($backup_path)){
				$error = true;
			}
		}

		// Delete post meta.
		delete_post_meta($post_id, $this->get_meta_name());

		// Show admin correct message.
		if(!$error){
			$msg = __('Image resize reset.', 'litespeed-cache');
			Admin_Display::success($msg);
		}
		else{
			$msg = __('Cannot reset resize data.', 'litespeed-cache');
			Admin_Display::error($msg);
		}
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @access public
	 */
	public function handler()
	{
		$type = Router::verify_type();

		switch ($type) {
			case self::TYPE_NEXT:
				// Resize next.
				$this->resize_next();
				break;
			case self::TYPE_START:
				// Start resizing(on new sites).
				$this->resize_next(true);
				break;
			case self::TYPE_RECALCULATE:
				// Recalculate summary.
				$this->recalculate_summary();
				break;
			case self::TYPE_DELETE_BK:
				// Delete all backups.
				$this->delete_all_backups();
				break;
			case self::TYPE_IMAGE:
				// Resize image by id.
				$id = $_GET['id'] ? $_GET['id'] : null;
				$this->resize_image_by_id( $id );
				break;
			case self::TYPE_RESTORE_BK:
				// Restore a image backup.
				$id = $_GET['id'] ? $_GET['id'] : null;
				$this->restore_from_bk( $id );
				break;
			case self::TYPE_RESET:
				// Reset image data(delete meta + restore backup + delete backup). If backup is found.
				$id = $_GET['id'] ? $_GET['id'] : null;
				$this->reset_row( $id, false );
				break;
			default:
				break;
		}

		Admin::redirect();
	}
}
