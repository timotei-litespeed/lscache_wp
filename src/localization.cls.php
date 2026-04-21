<?php
/**
 * The localization class.
 *
 * @since   3.3
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * Localization - serve external resources locally.
 *
 * @since 3.3
 */
class Localization extends Base {

	const LOG_TAG = '🛍️';

	/**
	 * Init optimizer
	 *
	 * @since  3.0
	 * @access protected
	 */
	public function init() {
		add_filter( 'litespeed_buffer_finalize', [ $this, 'finalize' ], 23 ); // After page optm
	}

	/**
	 * Localize Resources
	 *
	 * @since  3.3
	 *
	 * @param string $uri Base64-encoded URL.
	 */
	public function serve_static( $uri ) {
		global $wp_filesystem;
		if ( !$wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $this->conf( self::O_OPTM_LOCALIZE ) ) {
			exit( 'Not supported' );
		}

		// Decode url
		$url = base64_decode( $uri ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		
		// Check if url is in localize urls list
		$domains = $this->conf( self::O_OPTM_LOCALIZE_DOMAINS );
		$match   = false;
		foreach ( $domains as $v ) {
			$domain = $v;
			if ( ! $v || 0 === strpos( $v, '#' ) ) {
				continue;
			}

			// Try to parse space split value
			if ( strpos( $v, ' ' ) ) {
				$v = explode( ' ', $v );
				if ( ! empty( $v[1] ) ) {
					$domain = $v[1];
				}
			}

			if ( 0 !== strpos( $domain, 'https://' ) ) {
				continue;
			}
			if ( $v !== $domain ) {
				continue;
			}

			$match = true;
			break;
		}
		if ( ! $match ) {
			exit( 'Not in domains list.' );
		}

		// Generate localres folder if not exist
		$this->_maybe_mk_cache_folder( 'localres' );
		self::debug( 'localize [url] ' . $url );

		// Check if file is already saved. If yes, serve it.
		$folder        = $this->_realpath( false );
		$localized_ext = $this->search_file_extension( $folder, $url );
		$match_file    = $this->_realpath( $url, $localized_ext );

		if ( file_exists( $match_file ) ) {
			header( 'Content-Type: ' . $this->get_file_type( $localized_ext ) );
			wp_safe_redirect( $this->_rewrite( $url, $localized_ext ) );
			exit();
		}
		
		// Save url to file
		$tmp_file = $this->_realpath( $url, 'tmp' );
		$response = $this->save_url_to_path( $url, $tmp_file );

		// Parse response data
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			self::debug( 'failed to get: ' . $error_message );
			wp_safe_redirect( $url );
			exit();
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$file_ext     = $this->get_file_ext( $content_type );

		// Unknown content-type: don't poison the cache with an extensionless file.
		if ( ! $file_ext ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			self::debug( 'unknown content-type: ' . $content_type . ' [url] ' . $url );
			wp_safe_redirect( $url );
			exit();
		}

		// Process specific file content
		if ( file_exists( $tmp_file ) ) {
			// CSS - look into file and localize inner font-face
			if ( 'css' === $file_ext ) {
				$body     = file_get_contents( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$new_body = $this->process_fontface( $body );
				$new_body = $this->cls('Optimizer')->optm_font_face( $new_body );

				$wp_filesystem->put_contents( $tmp_file, $new_body );
			}
		}

		$file_w_extension = $this->_realpath( $url, $file_ext );
		$wp_filesystem->move( $tmp_file, $file_w_extension, true );

		header( 'Content-Type: ' . $content_type );

		$url = $this->_rewrite( $url, $file_ext );
		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Process inner font files from main resource url
	 *
	 * @since 7.9
	 * 
	 * @param string $content Content to process.
	 * @return string Content updated with localized link
	 */
	public function process_fontface( $content ) {
		global $wp_filesystem;

		if ( empty( $content ) ) {
			return $content;
		}

		// Test if there is any url() in content, if not return content as is.
		if ( ! preg_match_all( '/url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)/i', $content, $matches ) ) {
			return $content;
		}

		$replacements = null;

		// Get font-face declarations. Support for multiple src +  multiple links in src
		preg_match_all('/@font-face\s*\{([^}]+)\}/i', $content, $font_blocks);
		if ( 0 < count( $font_blocks[1] ) ) {
			foreach ( $font_blocks[1] as $block ) {
				// Get fonts url's
				preg_match_all('/src\s*:\s*url\(([^)]+)\)/', $block, $matches);
				if ( 0 < count( $matches[1] ) ) {
					// Change all fonts
					foreach ( $matches[1] as $i => $match ) {
						// Save link to file with no extension
						$folder     = $this->_realpath( false );
						$match_name = $this->_realpath( $match );
						$match_url  = $this->_rewrite( $match );
						// Test if file is already saved.
						$localized_ext = $this->search_file_extension( $folder, $match );

						if ( $localized_ext ) {
							$replacements[ $match ] = $match_url . '.' . $localized_ext;
						} else {
							$response = $this->save_url_to_path( $match, $match_name );
							if ( is_wp_error( $response ) ) {
								if ( file_exists( $match_name ) ) {
									wp_delete_file( $match_name );
								}
								self::debug( 'failed to fetch inner font: ' . $response->get_error_message() . ' [url] ' . $match );
								continue;
							}
							$content_type = wp_remote_retrieve_header( $response, 'content-type' );
							$file_type    = $this->get_file_ext( $content_type ); // Get file extension from header(not all links has extension)
							if ( ! $file_type ) {
								if ( file_exists( $match_name ) ) {
									wp_delete_file( $match_name );
								}
								self::debug( 'unknown content-type for inner font: ' . $content_type . ' [url] ' . $match );
								continue;
							}
							$path_w_extension = $match_name . '.' . $file_type;
							$url_w_extension  = $match_url . '.' . $file_type;

							// Rename the file with no extension link to file with extension
							file_exists( $match_name ) && $wp_filesystem->move( $match_name, $path_w_extension, true );
							// Add to replacement list
							$replacements[ $match ] = $url_w_extension;
						}
					}
				}
			}
		}

		// Do all replacements
		if ( ! empty( $replacements ) ) {
			$content = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
		}
		
		return $content;
	}

	/**
	 * Get file extension from filename.
	 *
	 * @since 7.9
	 * @access public
	 * 
	 * @param string $dir Directory to traverse.
	 * @param string $file File name.
	 * @return bool|string
	 */
	public function search_file_extension( $dir, $file ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \DirectoryIterator( $dir );
		$name     = md5( $file );

		foreach ( $iterator as $fileinfo ) {
			if ( $fileinfo->isDot() || $fileinfo->isDir() ) {
				continue;
			}
			$ext = $fileinfo->getExtension();
			// Skip in-flight tmp files so a concurrent download is not served as the final asset.
			if ( 'tmp' === $ext ) {
				continue;
			}
			if ( strstr( $fileinfo->getFilename(), $name ) ) {
				return $ext;
			}
		}

		return false;
	}

	/**
	 * Get the public URL of a localized resource.
	 *
	 * @since 4.5
	 *
	 * @param string|false $url Original external URL. False to generate folder path.
	 * @param string       $type File type.
	 * @return string Rewritten local URL.
	 */
	private function _rewrite( $url, $type = '' ) {
		return $this->_localres_url() . $this->_filepath( $url, $type );
	}

	/**
	 * Generate realpath of the cache file
	 *
	 * @since  4.5
	 * @access private
	 *
	 * @param string|false $url Original external URL. False to generate folder path.
	 * @param string       $type File type.
	 * @return string Absolute file path.
	 */
	private function _realpath( $url, $type = '' ) {
		return $this->_localres_realpath() . $this->_filepath( $url, $type );
	}

	/**
	 * Generate folder url
	 *
	 * @since  7.9
	 * @access private
	 *
	 * @return string Absolute folder url.
	 */
	private function _localres_url() {
		return LITESPEED_STATIC_URL . '/localres/';
	}

	/**
	 * Generate folder realpath
	 *
	 * @since  7.9
	 * @access private
	 *
	 * @return string Absolute folder realpath.
	 */
	private function _localres_realpath() {
		return LITESPEED_STATIC_DIR . '/localres/';
	}

	/**
	 * Get file extension from content-type
	 *
	 * @since 7.9
	 * 
	 * @param string $content_type Resource content type.
	 * @return bool|string
	 */
	public function get_file_ext( $content_type ) {
		if ( str_contains( $content_type, 'text/css' ) ) {
			return 'css';
		} elseif ( str_contains( $content_type, 'application/javascript' ) || str_contains( $content_type, 'application/x-javascript' ) ) { 
			return 'js';
		} elseif ( str_contains( $content_type, 'font/woff2' ) ) {
			return 'woff2';
		} elseif ( str_contains( $content_type, 'font/woff' ) ) {
			return 'woff';
		} elseif ( str_contains( $content_type, 'font/otf' ) ) {
			return 'otf';
		} elseif ( str_contains( $content_type, 'font/ttf' ) ) {
			return 'ttf';
		}

		return false;
	}

	/**
	 * Get content-type from file extension
	 *
	 * @since 7.9
	 * 
	 * @param string $ext Resource extension.
	 * @return bool|string
	 */
	public function get_file_type( $ext ) {
		if ( 'css' === $ext ) {
			return 'text/css' ;
		} elseif ( 'js' === $ext ) {
			return 'application/javascript';
		} elseif ( 'woff' === $ext ) {
			return 'font/woff';
		} elseif ( 'woff2' === $ext ) {
			return 'font/woff2';
		} elseif ( 'otf' === $ext ) {
			return 'font/otf';
		} elseif ( 'ttf' === $ext ) {
			return 'font/ttf';
		}

		return false;
	}

	/**
	 * Get filepath
	 *
	 * @since 4.5
	 * @access private
	 *
	 * @param string $url Original external URL.
	 * @param string $type File type.
	 * @return string Relative file path.
	 */
	private function _filepath( $url, $type = '' ) {
		// Prepare data: type and filename
		$type     = ( !empty( $type ) ?  '.' . $type : '' );
		$filename = false !== $url ? md5($url) . $type : '';
		if ( is_multisite() ) {
			$filename = get_current_blog_id() . '/' . $filename;
		}

		return $filename;
	}

	/**
	 * Convert external resource URLs to local URLs in page content.
	 *
	 * @since 3.3
	 * @since 7.9 Refactored to support more resource types and handle Font Display Optimization case.
	 *
	 * @param string $content Page HTML content.
	 * @return string Modified content with localized URLs.
	 */
	public function finalize( $content ) {
		if ( is_admin() ) {
			return $content;
		}

		if ( ! $this->conf( self::O_OPTM_LOCALIZE ) ) {
			return $content;
		}

		$domains = $this->conf( self::O_OPTM_LOCALIZE_DOMAINS );
		if ( ! $domains ) {
			return $content;
		}

		$font_display_setting = $this->conf(self::O_OPTM_CSS_FONT_DISPLAY);
		foreach ( $domains as $v ) {
			if ( ! $v || 0 === strpos( $v, '#' ) ) {
				continue;
			}

			$domain = $v;
			// Try to parse space split value
			if ( strpos( $v, ' ' ) ) {
				$v = explode( ' ', $v );
				if ( ! empty( $v[1] ) ) {
					$domain = $v[1];
				}
			}

			if ( 0 !== strpos( $domain, 'https://' ) ) {
				continue;
			}

			// Strip display=swap appended earlier when Font Display Optimization is enabled
			if ( true === $font_display_setting ) {
				$content = str_replace(
					array( $domain . '&#038;display=swap', $domain . '&display=swap' ),
					$domain,
					$content
				);
			}

			$content = str_replace( $domain, LITESPEED_STATIC_URL . '/localres/' . base64_encode( $domain ), $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return $content;
	}
	
	/**
	 * Download a remote URL and stream it to a local file.
	 *
	 * @since 7.8
	 * 
	 * @param string $url Remote URL to fetch.
	 * @param string $file Destination file path.
	 * @return array|\WP_Error
	 */
	public function save_url_to_path( $url, $file ) {
		return wp_safe_remote_get(
			$url,
			[
				'timeout' => 180,
				'stream' => true,
				'filename' => $file,
			]
		);
	}

	/**
	 * Delete all localization files from folder.
	 *
	 * @since 7.9
	 * @access public
	 * 
	 * @throws \Exception When folder is not found, not a folder or not writable.
	 * @return void
	 */
	public function clear_resources() {
		global $wp_filesystem;

		// On update need to test if $wp_filesystem is initialized, if not initialize it.
		if ( !$wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$localres_folder = LITESPEED_STATIC_DIR . '/localres';
		if ( is_multisite() && !is_network_admin() ) {
			$localres_folder .= '/' . get_current_blog_id();
		}
		self::debug( 'Localisation folder: ' . $localres_folder );
		
		try {
			if ( ! $wp_filesystem->exists( $localres_folder ) ) {
				throw new \Exception( 'path not found' );
			}
			if ( ! $wp_filesystem->is_dir( $localres_folder ) ) {
				throw new \Exception( 'path is not a folder'  );
			}
			if ( ! $wp_filesystem->is_writable( $localres_folder ) ) {
				throw new \Exception( 'path not writable' );
			}

			$this->_clear_folder( $localres_folder );
			self::debug( 'Localisation folder cleared: ' . $localres_folder );
		} catch ( \Exception $e ) {
			self::debug( 'Localisation clear error: ' . $e->getMessage() . '. Folder: ' . $localres_folder );
		}
	}

	/**
	 * Recursively delete all files and subdirectories within a specified directory.
	 *
	 * @since 7.9
	 * @access private
	 *
	 * @param string $dir Directory to traverse.
	 * @return void
	 */
	private function _clear_folder( $dir ) {
		global $wp_filesystem;

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $fileinfo ) {
			if ( $fileinfo->isDir() ) {
				$wp_filesystem->rmdir( $fileinfo->getRealPath() );
			} else {
				$wp_filesystem->delete( $fileinfo->getRealPath() );
			}
		}
	}
}
