<?php
/**
 * Tells whether a page changed since its OptimaX build.
 *
 * @since   8.0
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * Fingerprint of a rendered page.
 *
 * A fingerprint splits a page into its design (tags, their attributes, stylesheets
 * and scripts) and its content (text, link targets, images, meta descriptions,
 * data-* and inline style values, JSON-LD prices). A change of either goes to
 * QUIC.cloud, which carries it into the build (a sync) or builds the page again.
 *
 * Works on the raw HTML, without a DOM.
 *
 * @since 8.0
 */
class Optimax_Sync {

	/**
	 * A converted copy's suffix on a convertible original: `.jpg.webp`, `.png.avif`.
	 *
	 * @var string
	 */
	const NEXTGEN_SUFFIX = '\.(jpe?g|png|gif)\.(webp|avif)';

	const TYPE_TEXT = 't';
	const TYPE_HREF = 'h';
	const TYPE_IMG  = 'i';
	const TYPE_META = 'm';
	const TYPE_ATTR = 'd';
	const TYPE_JSON = 'j';

	/**
	 * Fingerprint format. A stored fingerprint of another format is recorded again, never compared.
	 *
	 * 8: a stylesheet `<link>` counts by the local file it loads (sheet_id()), not its URL.
	 * 9: LSCWP's own `litespeed-dummy.css` placeholder is left out (is_dummy_css()).
	 * 10: the visitor's OS class on `<body>` (UA_BODY_CLASSES) is left out.
	 * 11: a `<link rel=preload as=style>` counts by the local file it loads, as a stylesheet does.
	 */
	const VERSION = 11;

	/**
	 * A price in JSON-LD (schema.org Offer / AggregateOffer): key, then its raw JSON value.
	 */
	const RE_JSON_PRICE = '~"(price|lowPrice|highPrice)"\s*:\s*("(?:[^"\\\\]|\\\\.)*"|-?[0-9][0-9.eE+-]*)~';

	/**
	 * Why the last patch() could not place a change, for the debug log.
	 *
	 * @var string
	 */
	public static $why = '';

	/**
	 * One token: a comment, a declaration, a raw-text element with its content, or a tag.
	 *
	 * Group 1/2: raw-text element name and its content. Quoted attribute values may hold `>`.
	 */
	const RE_TOKEN = '~<!--.*?-->|<!\[CDATA\[.*?\]\]>|<![^>]*>|<\?.*?\?>|<(script|style|noscript|template|textarea|title)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(.*?)</\1\s*>|</?[a-zA-Z][^\s/>]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~is';

	/**
	 * One attribute of a tag: name, then its value quoted or bare.
	 */
	const RE_ATTR = '~([^\s"\'<>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~';

	/**
	 * Attributes an `<img>` may carry its URL in, real or lazy.
	 */
	const IMG_URL_ATTRS = [ 'src', 'data-src', 'data-lazy-src', 'data-original' ];

	/**
	 * Attributes holding an element id, or pointing at one, whose value is left out of the design.
	 *
	 * Themes number their elements per render (`uniqid()`, `rand()`), so the same
	 * page would read as a new design on every render.
	 */
	const ID_ATTRS = [ 'id', 'for', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns', 'headers', 'form', 'list' ];

	/**
	 * `<body>` classes a theme derives from the visitor's user agent, not from the page.
	 *
	 * Divi adds `osx`, `linux` or `windows` (functions.php, et_divi_theme_body_class), so
	 * a render for QUIC.cloud's bot, an iPhone and an Android phone each differ from the
	 * build's render on this line alone.
	 */
	const UA_BODY_CLASSES = [ 'osx', 'linux', 'windows' ];

	/**
	 * The fingerprint of a rendered page.
	 *
	 * `lines` hashes each design line, so a changed design can name the first line
	 * that moved. Attribute values holding a nonce, and every `value`, are left out
	 * of the design: they change between renders of the same page.
	 *
	 * @since 8.0
	 *
	 * @param string $html Page HTML.
	 * @return array|null `design`, `lines`, `items`; null when the HTML cannot be read.
	 */
	public static function fingerprint( $html ) {
		$tokens = self::tokenize( $html );
		if ( null === $tokens ) {
			return null;
		}

		// The classes the page uses: a `<style>` rule naming another class styles nothing here.
		$classes = [];
		foreach ( $tokens as $tok ) {
			if ( 'tag' === $tok['kind'] && false !== stripos( $tok['raw'], 'class' ) ) {
				$attrs = self::attrs( $tok['raw'] );
				if ( isset( $attrs['class'] ) ) {
					foreach ( preg_split( '~\s+~', $attrs['class'], -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
						$classes[ $class ] = true;
					}
				}
			}
		}

		$skel  = [];
		$items = [];
		foreach ( $tokens as $tok ) {
			if ( 'text' === $tok['kind'] ) {
				$text = self::norm_text( $tok['raw'] );
				if ( '' !== $text ) {
					$skel[]  = 'T';
					$items[] = [ self::TYPE_TEXT, $text ];
				}
				continue;
			}
			if ( 'tag' !== $tok['kind'] ) {
				continue;
			}

			$name = $tok['name'];
			if ( '/' === $name[0] ) {
				$skel[] = $name;
				continue;
			}

			$attrs = self::attrs( $tok['raw'] );
			if ( 'img' === $name ) {
				$skel[]  = 'img';
				$items[] = [ self::TYPE_IMG, $tok['raw'], self::img_key( $attrs ) ];
				continue;
			}
			if ( 'link' === $name && self::is_dummy_css( $attrs ) ) {
				continue;
			}

			if ( 'body' === $name && isset( $attrs['class'] ) ) {
				$attrs['class'] = implode( ' ', array_diff( preg_split( '~\s+~', trim( $attrs['class'] ), -1, PREG_SPLIT_NO_EMPTY ), self::UA_BODY_CLASSES ) );
			}

			$skip = [];
			if ( 'a' === $name && isset( $attrs['href'] ) ) {
				// An in-page anchor names an element id: neither design nor content.
				$skip[] = 'href';
				if ( ! self::is_anchor( $attrs['href'] ) ) {
					$items[] = [ self::TYPE_HREF, $attrs['href'] ];
				}
			} elseif ( 'meta' === $name && isset( $attrs['content'] ) && self::meta_key( $attrs ) ) {
				$skip[]  = 'content';
				$items[] = [ self::TYPE_META, $attrs['content'], self::meta_key( $attrs ) ];
			}
			// data-* and style values are content: page builders rewrite them without changing the page.
			foreach ( $attrs as $attr => $val ) {
				if ( self::is_content_attr( $attr ) ) {
					$items[] = [ self::TYPE_ATTR, $val, $attr ];
				}
			}

			// A stylesheet counts by what it loads: a cache-busting `?ver=` bump over the
			// same local file (Elementor rewrites its post CSS on every save, Divi bumps the
			// `?ver=` of its preloaded et-cache CSS) is not a new design.
			$sheet = '';
			if ( 'link' === $name && isset( $attrs['href'] ) && ( self::is_stylesheet( $attrs ) || self::is_style_preload( $attrs ) ) ) {
				$skip[] = 'href';
				$sheet  = ' href=' . self::sheet_id( $attrs['href'] );
			}

			$line = $name . self::design_attrs( $attrs, $skip ) . $sheet;
			if ( isset( $tok['inner'] ) ) {
				if ( 'title' === $name ) {
					$text = self::norm_text( $tok['inner'] );
					if ( '' !== $text ) {
						$line   .= ' T';
						$items[] = [ self::TYPE_TEXT, $text ];
					}
				} elseif ( 'style' === $name ) {
					$line .= ' ' . md5( self::mask( self::used_css( $tok['inner'], $classes ) ) );
				} elseif ( self::is_json_ld( $name, $attrs ) ) {
					// Structured data repeats the price shown on the page: Google reads it from here.
					if ( preg_match_all( self::RE_JSON_PRICE, $tok['inner'], $prices, PREG_SET_ORDER ) ) {
						foreach ( $prices as $price ) {
							$items[] = [ self::TYPE_JSON, $price[2], $price[1] ];
						}
					}
				}
			}
			$skel[] = $line;
		}

		return [
			'design' => md5( implode( "\n", $skel ) ),
			'lines'  => array_map(
				function ( $line ) {
					return substr( md5( $line ), 0, 8 );
				},
				$skel
			),
			'skel'   => $skel,
			'items'  => $items,
		];
	}

	/**
	 * The first content item that changed between two renders of the same design.
	 *
	 * Per-render values do not count (same()): an image or a data-* value that
	 * differs only in its element ids or `uniqid()` tokens is the same, and so is
	 * an image that differs only in the next-gen copy it links.
	 *
	 * @since 8.0
	 *
	 * @param array $old Items of the render the build matches.
	 * @param array $new Items of this render.
	 * @return string|null The change, for the debug log; null when the content is the same.
	 */
	public static function changed_item( $old, $new ) {
		if ( count( $old ) !== count( $new ) ) {
			return count( $old ) . ' items, now ' . count( $new );
		}
		foreach ( $old as $i => $item ) {
			if ( ! self::same( $item, $new[ $i ] ) ) {
				return $item[0] . ' ' . substr( (string) $item[1], 0, 80 ) . ' => ' . substr( (string) $new[ $i ][1], 0, 80 );
			}
		}
		return null;
	}

	/**
	 * A signature of a render's content, in the form same() compares.
	 *
	 * Two renders showing the same change sign the same, even when their per-render
	 * values differ, so the change can be confirmed by the second one. Keep it in
	 * step with same().
	 *
	 * Serialized, not JSON-encoded: invalid UTF-8 in a text would fail the encode,
	 * and every failed encode would then sign the same.
	 *
	 * @since 8.0
	 *
	 * @param array $items Items of a render.
	 * @return string
	 */
	public static function content_sig( $items ) {
		$norm = [];
		foreach ( $items as $item ) {
			if ( self::TYPE_ATTR === $item[0] ) {
				$item = [ $item[0], self::attr_sig( $item[1] ), $item[2] ];
			} elseif ( self::TYPE_IMG === $item[0] ) {
				$item = [ $item[0], self::img_sig( $item[1] ) ];
			}
			$norm[] = $item;
		}
		return md5( serialize( $norm ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashed, never unserialized.
	}

	/**
	 * Write the content changes between two renders into the stored OptimaX HTML.
	 *
	 * Each changed item is found in the OptimaX HTML by its old value, as the same
	 * occurrence it was in the old render (the 2nd "Read more" stays the 2nd). When
	 * the OptimaX HTML holds that value a different number of times, the item cannot
	 * be placed and nothing is patched. A meta tag or JSON-LD price OptimaX dropped
	 * is skipped: it is not on the page.
	 *
	 * @since 8.0
	 *
	 * @param string   $html    Stored OptimaX HTML.
	 * @param array    $old     Items of the render the build matches.
	 * @param array    $new     Items of this render; same design, so same length and types.
	 * @param callable $img_tag Maps a fresh `<img>` tag to the one to store (next-gen links).
	 * @return string|false Patched HTML, or false when a change cannot be placed.
	 */
	public static function patch( $html, $old, $new, $img_tag ) {
		self::$why = '';
		if ( count( $old ) !== count( $new ) ) {
			return false;
		}

		$tokens = self::tokenize( $html );
		if ( null === $tokens ) {
			return false;
		}

		// Where each value sits in the OptimaX HTML, in page order.
		$found      = [];
		$preloaded  = [];
		$in_picture = 0;
		foreach ( $tokens as $tok ) {
			if ( 'text' === $tok['kind'] ) {
				$found[ self::TYPE_TEXT ][ self::norm_text( $tok['raw'] ) ][] = $tok;
				continue;
			}
			if ( 'tag' !== $tok['kind'] ) {
				continue;
			}
			if ( 'picture' === $tok['name'] ) {
				++$in_picture;
			} elseif ( '/picture' === $tok['name'] ) {
				$in_picture = max( 0, $in_picture - 1 );
			}
			if ( '/' === $tok['name'][0] ) {
				continue;
			}
			if ( 'title' === $tok['name'] && isset( $tok['inner'] ) ) {
				$inner = [
					'kind'   => 'text',
					'raw'    => $tok['inner'],
					'offset' => $tok['inner_offset'],
				];

				$found[ self::TYPE_TEXT ][ self::norm_text( $tok['inner'] ) ][] = $inner;
				continue;
			}

			$attrs = self::attrs( $tok['raw'] );
			if ( 'img' === $tok['name'] ) {
				$tok['in_picture'] = $in_picture > 0;

				$found[ self::TYPE_IMG ][ self::img_key( $attrs ) ][] = $tok;
				continue;
			}
			if ( 'link' === $tok['name'] && isset( $attrs['rel'], $attrs['as'] ) && 'image' === strtolower( trim( $attrs['as'] ) ) && false !== stripos( $attrs['rel'], 'preload' ) ) {
				foreach ( [ 'href', 'imagesrcset' ] as $name ) {
					if ( ! empty( $attrs[ $name ] ) ) {
						foreach ( self::img_urls( $attrs[ $name ] ) as $url ) {
							$preloaded[ self::url_path( $url ) ] = true;
						}
					}
				}
			}
			if ( isset( $tok['inner'] ) && self::is_json_ld( $tok['name'], $attrs ) ) {
				foreach ( self::json_prices( $tok['inner'], $tok['inner_offset'] ) as $price ) {
					$found[ self::TYPE_JSON ][ $price['key'] ][] = $price;
				}
				continue;
			}
			if ( 'a' === $tok['name'] && isset( $attrs['href'] ) && ! self::is_anchor( $attrs['href'] ) ) {
				$found[ self::TYPE_HREF ][ $attrs['href'] ][] = $tok;
			} elseif ( 'meta' === $tok['name'] && isset( $attrs['content'] ) && self::meta_key( $attrs ) ) {
				$found[ self::TYPE_META ][ self::meta_key( $attrs ) ][] = $tok;
			}
			foreach ( $attrs as $attr => $val ) {
				if ( self::is_content_attr( $attr ) ) {
					$found[ self::TYPE_ATTR ][ self::locate_key( [ self::TYPE_ATTR, $val, $attr ] ) ][] = $tok;
				}
			}
		}

		// Where each value sits in the old render.
		$seen = [];
		$nth  = [];
		foreach ( $old as $i => $item ) {
			$key       = self::locate_key( $item );
			$nth[ $i ] = isset( $seen[ $item[0] ][ $key ] ) ? $seen[ $item[0] ][ $key ] : 0;

			$seen[ $item[0] ][ $key ] = $nth[ $i ] + 1;
		}

		$edits = [];
		foreach ( $old as $i => $item ) {
			if ( self::same( $item, $new[ $i ] ) ) {
				continue;
			}
			if ( $item[0] !== $new[ $i ][0] ) {
				return false;
			}

			$type  = $item[0];
			$key   = self::locate_key( $item );
			$cands = isset( $found[ $type ][ $key ] ) ? $found[ $type ][ $key ] : [];
			if ( count( $cands ) !== $seen[ $type ][ $key ] ) {
				if ( self::TYPE_META === $type || self::TYPE_JSON === $type ) {
					continue;
				}
				self::$why = $type . ' ' . substr( $key, 0, 120 ) . ': ' . count( $cands ) . ' in OptimaX HTML, ' . $seen[ $type ][ $key ] . ' in the page';
				if ( self::TYPE_ATTR === $type ) {
					self::$why .= self::diff_note( $item[1], $new[ $i ][1] );
				}
				return false;
			}
			$tok = $cands[ $nth[ $i ] ];

			// A tag may take several attribute edits; text and images replace it whole.
			$edited = isset( $edits[ $tok['offset'] ] );
			$base   = $edited ? $edits[ $tok['offset'] ][1] : $tok['raw'];
			switch ( $type ) {
				case self::TYPE_TEXT:
					preg_match( '~^(\s*).*?(\s*)$~s', $tok['raw'], $m );
					$repl = $edited ? false : $m[1] . self::esc_text( $new[ $i ][1] ) . $m[2];
					break;
				case self::TYPE_HREF:
					$repl = self::set_attr( $base, 'href', $new[ $i ][1] );
					break;
				case self::TYPE_META:
					$repl = self::set_attr( $base, 'content', $new[ $i ][1] );
					break;
				case self::TYPE_ATTR:
					$repl = self::set_attr( $base, $item[2], $new[ $i ][1] );
					break;
				case self::TYPE_JSON:
					// The raw JSON value, quotes included: a number stays a number.
					$repl = $edited ? false : $new[ $i ][1];
					break;
				default:
					$repl = $edited ? false : self::patch_img( $tok['raw'], call_user_func( $img_tag, $new[ $i ][1] ), $item[1], $new[ $i ][1], $preloaded, ! empty( $tok['in_picture'] ) );
			}
			if ( ! is_string( $repl ) ) {
				return false;
			}
			$edits[ $tok['offset'] ] = [ strlen( $tok['raw'] ), $repl ];
		}

		krsort( $edits );
		foreach ( $edits as $offset => $edit ) {
			$html = substr_replace( $html, $edit[1], $offset, $edit[0] );
		}

		return $html;
	}

	/**
	 * Where two attribute values first differ, for the debug log (instrumentation only).
	 *
	 * @since 8.0
	 *
	 * @param string $old Value in the render the build matches.
	 * @param string $new Value in this render.
	 * @return string md5 of both, the offset of the first differing byte and 40 bytes around it in each.
	 */
	private static function diff_note( $old, $new ) {
		$len = min( strlen( $old ), strlen( $new ) );
		$at  = 0;
		while ( $at < $len && $old[ $at ] === $new[ $at ] ) {
			++$at;
		}
		$from = max( 0, $at - 20 );
		$show = function ( $val ) use ( $from ) {
			return preg_replace( '~[^\x20-\x7e]~', '?', self::mask( substr( $val, $from, 40 ) ) );
		};

		return ' [old md5=' . md5( $old ) . ' new md5=' . md5( $new ) . ' differ at byte ' . $at . ' of ' . strlen( $old ) . '/' . strlen( $new )
			. ' old="' . $show( $old ) . '" new="' . $show( $new ) . '"]';
	}

	/**
	 * The page-builder stylesheets whose change made a design line differ (instrumentation only).
	 *
	 * Elementor's `post-*.css` and Divi's `et-cache` files are rewritten by the builder.
	 * The fingerprint keeps only the hash of the old line, so the old id is not known:
	 * the new id, the file's size and mtime are named, to tell a real edit (mtime after
	 * the build) from a regenerated identical file.
	 *
	 * @since 8.0
	 *
	 * @param array $old_lines Line hashes of the stored fingerprint.
	 * @param array $now       fingerprint() of this render.
	 * @return array One note per such stylesheet, at most five.
	 */
	public static function changed_sheets( $old_lines, $now ) {
		$notes = [];
		foreach ( $now['skel'] as $i => $line ) {
			if ( isset( $old_lines[ $i ] ) && $old_lines[ $i ] === $now['lines'][ $i ] ) {
				continue;
			}
			if ( ! preg_match( '~ href=(\S*(?:/elementor/css/post-|/et-cache/)\S*)#([0-9a-f]{32})$~', $line, $m ) ) {
				continue;
			}
			$file    = Utility::is_internal_file( $m[1] );
			$notes[] = 'line ' . $i . ' ' . $m[1] . ' old line hash ' . ( isset( $old_lines[ $i ] ) ? $old_lines[ $i ] : 'none' ) . ' new id ' . $m[2]
				. ( $file ? ' size ' . (int) @filesize( $file[0] ) . ' mtime ' . (int) @filemtime( $file[0] ) : ' file not found' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( count( $notes ) >= 5 ) {
				break;
			}
		}

		return $notes;
	}

	/**
	 * OptimaX's own `<img>` with this render's image URLs.
	 *
	 * Only the URL attributes change (`src`, `srcset`, `data-src`, ...): lazy-load,
	 * sizes, width/height and the rest are OptimaX's and stay. Refused (false, with
	 * `$why`) where the URLs alone are not the whole story: inside `<picture>`
	 * (`<source>` tags link the image too), the image OptimaX preloads or marks
	 * `fetchpriority=high` (the LCP image), or an OptimaX `srcset` the render has no
	 * counterpart for (it would keep linking the old image).
	 *
	 * @since 8.0
	 *
	 * @param string $ox         OptimaX HTML's `<img>` tag.
	 * @param string $fresh      This render's `<img>` tag, next-gen links applied.
	 * @param string $old_tag    The `<img>` tag in the render the build matches.
	 * @param string $new_tag    The `<img>` tag in this render.
	 * @param array  $preloaded  Paths OptimaX preloads as images, as keys.
	 * @param bool   $in_picture Whether the tag sits inside `<picture>`.
	 * @return string|false
	 */
	private static function patch_img( $ox, $fresh, $old_tag, $new_tag, $preloaded, $in_picture ) {
		$attrs = self::attrs( $ox );
		if ( $in_picture ) {
			self::$why = 'img inside <picture>';
			return false;
		}
		if ( isset( $attrs['fetchpriority'] ) && 'high' === strtolower( trim( $attrs['fetchpriority'] ) ) ) {
			self::$why = 'img with fetchpriority=high';
			return false;
		}

		// Any other attribute that changed (alt, width/height, class) is not carried by a URL swap.
		$url_attrs = array_merge( self::IMG_URL_ATTRS, [ 'srcset', 'data-srcset' ] );
		$was       = self::attrs( $old_tag );
		$is        = self::attrs( $new_tag );
		foreach ( array_unique( array_merge( array_keys( $was ), array_keys( $is ) ) ) as $name ) {
			if ( in_array( $name, $url_attrs, true ) ) {
				continue;
			}
			$a = isset( $was[ $name ] ) ? $was[ $name ] : null;
			$b = isset( $is[ $name ] ) ? $is[ $name ] : null;
			// An id-like attribute differs on every render: only its presence counts.
			$differs = in_array( $name, self::ID_ATTRS, true ) ? ( null === $a ) !== ( null === $b ) : ( null === $a || null === $b || self::mask( $a ) !== self::mask( $b ) );
			if ( $differs ) {
				self::$why = 'img changed besides its URLs: ' . $name;
				return false;
			}
		}

		$new = self::attrs( $fresh );
		foreach ( array_merge( self::IMG_URL_ATTRS, [ 'srcset', 'data-srcset' ] ) as $name ) {
			if ( empty( $attrs[ $name ] ) || 0 === strpos( $attrs[ $name ], 'data:' ) ) {
				continue;
			}
			foreach ( self::img_urls( $attrs[ $name ] ) as $url ) {
				if ( isset( $preloaded[ self::url_path( $url ) ] ) ) {
					self::$why = 'img is preloaded';
					return false;
				}
			}
			if ( empty( $new[ $name ] ) || 0 === strpos( $new[ $name ], 'data:' ) ) {
				self::$why = 'img has ' . $name . ' in OptimaX HTML only';
				return false;
			}
			$ox = self::set_attr( $ox, $name, $new[ $name ] );
			if ( false === $ox ) {
				return false;
			}
		}

		return $ox;
	}

	/**
	 * The URLs in an image attribute: the value itself, or each candidate of a srcset.
	 *
	 * @since 8.0
	 *
	 * @param string $val Attribute value.
	 * @return array
	 */
	private static function img_urls( $val ) {
		$urls = [];
		foreach ( explode( ',', $val ) as $candidate ) {
			$parts = preg_split( '~\s+~', trim( $candidate ) );
			if ( '' !== $parts[0] ) {
				$urls[] = $parts[0];
			}
		}
		return $urls;
	}

	/**
	 * A URL's path, with the next-gen suffix OptimaX appends left off.
	 *
	 * @since 8.0
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_path( $url ) {
		return preg_replace( '~\.(webp|avif)$~i', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/**
	 * Split HTML into tokens, with their byte offsets.
	 *
	 * @since 8.0
	 *
	 * @param string $html HTML.
	 * @return array|null List of `kind` (text|tag|other), `raw`, `offset`, and for a tag `name`
	 *                    (`/name` when closing) plus `inner`/`inner_offset` for a raw-text element.
	 *                    Null when the pattern gives up on the input.
	 */
	public static function tokenize( $html ) {
		$html = (string) $html;
		if ( false === preg_match_all( self::RE_TOKEN, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tokens = [];
		$pos    = 0;
		foreach ( $matches as $m ) {
			$raw    = $m[0][0];
			$offset = $m[0][1];
			if ( $offset > $pos ) {
				$tokens[] = [
					'kind'   => 'text',
					'raw'    => substr( $html, $pos, $offset - $pos ),
					'offset' => $pos,
				];
			}
			$pos = $offset + strlen( $raw );

			if ( ! preg_match( '~^<(/?[a-zA-Z][^\s/>]*)~', $raw, $name ) ) {
				$tokens[] = [
					'kind'   => 'other',
					'raw'    => $raw,
					'offset' => $offset,
				];
				continue;
			}

			$tok = [
				'kind'   => 'tag',
				'raw'    => $raw,
				'offset' => $offset,
				'name'   => strtolower( $name[1] ),
			];
			if ( isset( $m[2] ) && '' !== $m[1][0] ) {
				// A raw-text element: keep its open tag as the token, its content apart.
				$tok['raw']          = substr( $raw, 0, $m[2][1] - $offset );
				$tok['inner']        = $m[2][0];
				$tok['inner_offset'] = $m[2][1];
			}
			$tokens[] = $tok;
		}
		if ( $pos < strlen( $html ) ) {
			$tokens[] = [
				'kind'   => 'text',
				'raw'    => substr( $html, $pos ),
				'offset' => $pos,
			];
		}

		return $tokens;
	}

	/**
	 * A tag's attributes, names lowercased, values decoded. The first of a repeated name wins, as in browsers.
	 *
	 * @since 8.0
	 *
	 * @param string $tag Open tag.
	 * @return array
	 */
	public static function attrs( $tag ) {
		$tag = preg_replace( '~^<[^\s/>]+|/?>$~', '', $tag );
		preg_match_all( self::RE_ATTR, (string) $tag, $matches, PREG_SET_ORDER );

		$attrs = [];
		foreach ( $matches as $m ) {
			$name = strtolower( $m[1] );
			if ( isset( $attrs[ $name ] ) ) {
				continue;
			}
			$val = isset( $m[2] ) ? $m[2] : '';
			if ( '' !== $val && ( '"' === $val[0] || "'" === $val[0] ) ) {
				$val = substr( $val, 1, -1 );
			}
			$attrs[ $name ] = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $attrs;
	}

	/**
	 * Text as both renders and OptimaX's serializer agree on it.
	 *
	 * OptimaX re-serializes the page (entities become characters) and collapses its
	 * whitespace, so text is compared decoded, with runs of whitespace as one space.
	 *
	 * @since 8.0
	 *
	 * @param string $raw Raw text.
	 * @return string
	 */
	public static function norm_text( $raw ) {
		$text = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '~[ \t\n\r\f]+~', ' ', $text ), " \t\n\r\f" );
	}

	/**
	 * Text escaped as OptimaX's serializer writes it.
	 *
	 * @since 8.0
	 *
	 * @param string $text Decoded text.
	 * @return string
	 */
	public static function esc_text( $text ) {
		return str_replace( "\xc2\xa0", '&nbsp;', htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' ) );
	}

	/**
	 * A tag with one attribute's value replaced.
	 *
	 * @since 8.0
	 *
	 * @param string $tag  Open tag.
	 * @param string $name Attribute name.
	 * @param string $val  Decoded value.
	 * @return string|false False when the tag has no such attribute.
	 */
	public static function set_attr( $tag, $name, $val ) {
		// Walk the real attributes, as attrs() reads them: `src=` inside another attribute's quoted value is no attribute.
		if ( ! preg_match( '~^<[^\s/>]+~', $tag, $start ) ) {
			return false;
		}
		preg_match_all( self::RE_ATTR, $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, strlen( $start[0] ) );
		foreach ( $matches as $m ) {
			if ( strtolower( $m[1][0] ) !== strtolower( $name ) ) {
				continue;
			}
			if ( ! isset( $m[2] ) || $m[2][1] < 0 ) {
				return false;
			}
			$quoted = '"' . str_replace( [ '&', '"', "\xc2\xa0" ], [ '&amp;', '&quot;', '&nbsp;' ], $val ) . '"';

			return substr_replace( $tag, $quoted, $m[2][1], strlen( $m[2][0] ) );
		}

		return false;
	}

	/**
	 * The URL an image is found by: its first real or lazy URL that is not inline data.
	 *
	 * Without the `.webp`/`.avif` OptimaX appends, or the render already carried, and
	 * without its query: a `?ver=` cache-buster (LiteSpeed's cached avatars) changes
	 * while the image stays where it is.
	 *
	 * @since 8.0
	 *
	 * @param array $attrs attrs() of the `<img>`.
	 * @return string
	 */
	public static function img_key( $attrs ) {
		foreach ( self::IMG_URL_ATTRS as $name ) {
			if ( empty( $attrs[ $name ] ) || 0 === strpos( $attrs[ $name ], 'data:' ) ) {
				continue;
			}
			return preg_replace( '~\.(webp|avif)$~i', '', preg_replace( '~[?#].*$~s', '', trim( $attrs[ $name ] ) ) );
		}

		return '';
	}

	/**
	 * What tells one meta tag from another: its name, property or itemprop.
	 *
	 * @since 8.0
	 *
	 * @param array $attrs attrs() of the `<meta>`.
	 * @return string '' for a meta tag without one (charset, http-equiv).
	 */
	public static function meta_key( $attrs ) {
		foreach ( [ 'name', 'property', 'itemprop' ] as $name ) {
			if ( ! empty( $attrs[ $name ] ) ) {
				return $name . '=' . strtolower( $attrs[ $name ] );
			}
		}
		return '';
	}

	/**
	 * The design part of a tag's attributes.
	 *
	 * A data-* or style value is content (an item), so only its name is design.
	 *
	 * @since 8.0
	 *
	 * @param array $attrs attrs().
	 * @param array $skip  Names whose value is content, not design.
	 * @return string
	 */
	private static function design_attrs( $attrs, $skip ) {
		$out = '';
		foreach ( $attrs as $name => $val ) {
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			$out .= ' ' . $name;
			if ( 'value' !== $name && false === strpos( $name, 'nonce' ) && ! in_array( $name, self::ID_ATTRS, true ) && ! self::is_content_attr( $name ) ) {
				$out .= '=' . self::mask( $val );
			}
		}
		return $out;
	}

	/**
	 * Mask `uniqid()` tokens (13 hex digits), which change on every render.
	 *
	 * @since 8.0
	 *
	 * @param string $val Attribute value or inline CSS.
	 * @return string
	 */
	private static function mask( $val ) {
		return preg_replace( '~(?<![0-9a-f])[0-9a-f]{13}(?![0-9a-f])~i', '#', $val );
	}

	/**
	 * A data-* or style value as compared: `uniqid()` tokens masked, and JSON by what it sets.
	 *
	 * Widget settings in a data-* attribute come as JSON whose unset keys a theme
	 * writes on some renders and leaves out on others: Woodmart's carousel adds its
	 * `_tablet` / `_mobile` keys as null, "" or an empty size control on one render
	 * and not the next, and every such render read as a content change that was
	 * patched again (test2, 2026-11-06). A JSON object or array is compared with its
	 * unset values dropped and its keys in one order. Any other value, or JSON that
	 * does not decode, is compared as text.
	 *
	 * @since 8.0
	 *
	 * @param string $val Attribute value.
	 * @return string
	 */
	private static function attr_sig( $val ) {
		$val  = (string) $val;
		$head = ltrim( $val );
		if ( '' !== $head && ( '{' === $head[0] || '[' === $head[0] ) ) {
			$data = json_decode( $val, true );
			if ( is_array( $data ) ) {
				$val = (string) wp_json_encode( self::json_set( $data ) );
			}
		}
		return self::mask( $val );
	}

	/**
	 * JSON settings without their unset values, object keys sorted.
	 *
	 * Unset: null, "", an empty array or object, and an object that holds nothing
	 * but a `unit` (an empty size control, `{"unit":"px","size":"","sizes":[]}`).
	 * A list keeps its length: only an empty one is unset. 0 and false are values.
	 *
	 * @since 8.0
	 *
	 * @param mixed $data Decoded JSON.
	 * @return mixed Null when nothing is set.
	 */
	private static function json_set( $data ) {
		if ( ! is_array( $data ) ) {
			return null === $data || '' === $data ? null : $data;
		}
		if ( ! $data ) {
			return null;
		}
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return array_map( [ __CLASS__, 'json_set' ], $data );
		}

		$out = [];
		foreach ( $data as $key => $value ) {
			$value = self::json_set( $value );
			if ( null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		if ( ! $out || [ 'unit' ] === array_keys( $out ) ) {
			return null;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The part of a `<style>` block that styles this page, comments and whitespace left out.
	 *
	 * A rule whose every selector names a class no element has styles nothing, and
	 * generators put such rules in the page: Tailwind (Atomic Wind) emits a rule for
	 * every word in the page text that is also a class name, so a text edit changes
	 * the CSS. At-rules (`@media`, `@supports`, `@layer`, `@container`) keep only
	 * their used rules; other at-rules (`@font-face`, `@keyframes`) are kept whole.
	 * Nested rules (CSS nesting) are filtered the same way.
	 *
	 * @since 8.0
	 *
	 * @param string $css     CSS.
	 * @param array  $classes Classes the page uses, as keys.
	 * @return string
	 */
	private static function used_css( $css, $classes ) {
		$css = preg_replace( '~/\*.*?\*/~s', '', (string) $css );
		$len = strlen( $css );
		$out = '';
		$pos = 0;
		while ( $pos < $len ) {
			$open = strpos( $css, '{', $pos );
			if ( false === $open ) {
				$out .= self::squeeze_css( substr( $css, $pos ) );
				break;
			}

			$depth = 0;
			for ( $end = $open; $end < $len; $end++ ) {
				if ( '{' === $css[ $end ] ) {
					++$depth;
				} elseif ( '}' === $css[ $end ] && 0 === --$depth ) {
					break;
				}
			}

			// Declarations before a nested rule belong to the enclosing rule.
			$prelude = substr( $css, $pos, $open - $pos );
			$cut     = strrpos( $prelude, ';' );
			if ( false !== $cut ) {
				$out    .= self::squeeze_css( substr( $prelude, 0, $cut + 1 ) );
				$prelude = substr( $prelude, $cut + 1 );
			}
			$prelude = trim( $prelude );
			$body    = substr( $css, $open + 1, max( 0, $end - $open - 1 ) );

			if ( '@' === substr( $prelude, 0, 1 ) ) {
				$inner = preg_match( '~^@(media|supports|layer|container|scope)\b~i', $prelude ) ? self::used_css( $body, $classes ) : self::squeeze_css( $body );
				if ( '' !== $inner ) {
					$out .= self::squeeze_css( $prelude ) . '{' . rtrim( $inner, ';' ) . '}';
				}
			} elseif ( self::selector_used( $prelude, $classes ) ) {
				$out .= self::squeeze_css( $prelude ) . '{' . rtrim( self::used_css( $body, $classes ), ';' ) . '}';
			}
			$pos = $end + 1;
		}

		return $out;
	}

	/**
	 * Whether one of a rule's selectors names only classes the page uses.
	 *
	 * Ids are not checked: themes number them per render.
	 *
	 * @since 8.0
	 *
	 * @param string $prelude Selector list.
	 * @param array  $classes Classes the page uses, as keys.
	 * @return bool
	 */
	private static function selector_used( $prelude, $classes ) {
		foreach ( explode( ',', $prelude ) as $selector ) {
			preg_match_all( '~\.((?:\\\\.|[\w-])+)~', $selector, $m );
			$used = true;
			foreach ( $m[1] as $class ) {
				if ( ! isset( $classes[ stripslashes( $class ) ] ) ) {
					$used = false;
					break;
				}
			}
			if ( $used ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CSS with whitespace collapsed: minified and unminified copies compare equal.
	 *
	 * @since 8.0
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function squeeze_css( $css ) {
		return trim( preg_replace( [ '~\s+~', '~\s*([{}:;,>])\s*~', '~;}~' ], [ ' ', '$1', '}' ], $css ) );
	}

	/**
	 * Whether a raw-text element is JSON-LD structured data.
	 *
	 * @since 8.0
	 *
	 * @param string $name  Tag name.
	 * @param array  $attrs attrs() of the tag.
	 * @return bool
	 */
	private static function is_json_ld( $name, $attrs ) {
		return 'script' === $name && isset( $attrs['type'] ) && 'application/ld+json' === strtolower( trim( $attrs['type'] ) );
	}

	/**
	 * This render, with each stylesheet link that loads the same file as in the build's
	 * render written as it was there.
	 *
	 * The build links the old URL. QUIC.cloud refuses a changed `<link>` as a new
	 * resource, so a sync must not show a `?ver=` bump over an unchanged file. Only a
	 * link that differs in nothing but its href is rewritten: any other change of the
	 * tag still reaches QUIC.cloud.
	 *
	 * @since 8.0
	 *
	 * @param string $base The render the build matches.
	 * @param string $now  This render.
	 * @return string
	 */
	public static function align_stylesheets( $base, $now ) {
		$was = [];
		foreach ( self::stylesheet_tags( $base ) as $raw => $id ) {
			$was[ $id ][] = $raw;
		}
		if ( ! $was ) {
			return $now;
		}

		$swap = [];
		foreach ( self::stylesheet_tags( $now ) as $raw => $id ) {
			if ( empty( $was[ $id ] ) || in_array( $raw, $was[ $id ], true ) ) {
				continue;
			}
			foreach ( $was[ $id ] as $old ) {
				if ( self::without_href( $old ) === self::without_href( $raw ) ) {
					$swap[ $raw ] = $old;
					break;
				}
			}
		}

		return $swap ? strtr( $now, $swap ) : $now;
	}

	/**
	 * This render, with each `<img>` that differs from the build's render only by a
	 * next-gen copy it is proven to have (`a.jpg` vs `a.jpg.avif`) written as it was there.
	 *
	 * Image Optimization links the `.webp`/`.avif` copy once a file is converted, so a
	 * render can show it where the build's render showed the original. QUIC.cloud
	 * refuses a changed image as a new resource. Images are paired by position, and a
	 * pair is aligned only when every attribute is the same, each differing URL or
	 * srcset candidate (same count, order and descriptors) is its base URL plus the
	 * suffix, and that copy exists beside the original. Anything else, an image in a
	 * `<picture>`, or another image count, is left for QUIC.cloud to refuse.
	 *
	 * @since 8.0
	 *
	 * @param string $base The render the build matches.
	 * @param string $now  This render.
	 * @return string
	 */
	public static function align_images( $base, $now ) {
		$was = self::img_tags( $base );
		$is  = self::img_tags( $now );
		if ( ! $was || count( $was ) !== count( $is ) ) {
			return $now;
		}

		// A tag is rewritten wherever it appears, so only when every occurrence aligns to the same base tag.
		$want = [];
		foreach ( $is as $i => $tag ) {
			$old                   = $was[ $i ];
			$ok                    = $tag['raw'] !== $old['raw'] && ! $tag['picture'] && ! $old['picture'] && self::same_img( $old['raw'], $tag['raw'] );
			$want[ $tag['raw'] ][] = $ok ? $old['raw'] : false;
		}

		$swap = [];
		foreach ( $want as $raw => $olds ) {
			$olds = array_unique( $olds, SORT_REGULAR );
			if ( 1 === count( $olds ) && false !== $olds[0] ) {
				$swap[ $raw ] = $olds[0];
			}
		}

		return $swap ? strtr( $now, $swap ) : $now;
	}

	/**
	 * The `<img>` tags of a page in order, each marked when it sits in a `<picture>`.
	 *
	 * @since 8.0
	 *
	 * @param string $html Page HTML.
	 * @return array List of `raw` and `picture`.
	 */
	private static function img_tags( $html ) {
		$out     = [];
		$picture = 0;
		foreach ( (array) self::tokenize( $html ) as $tok ) {
			if ( 'tag' !== $tok['kind'] ) {
				continue;
			}
			if ( 'picture' === $tok['name'] ) {
				++$picture;
			} elseif ( '/picture' === $tok['name'] ) {
				$picture = max( 0, $picture - 1 );
			} elseif ( 'img' === $tok['name'] ) {
				$out[] = [
					'raw'     => $tok['raw'],
					'picture' => $picture > 0,
				];
			}
		}
		return $out;
	}

	/**
	 * Whether two `<img>` tags are the same image, one showing a proven next-gen copy where the other shows the original.
	 *
	 * Attributes are compared in order, repeats included: names and every value
	 * byte for byte, except the URL attributes and srcsets, whose differing URLs must
	 * each be next-gen copies (nextgen_copy()).
	 *
	 * @since 8.0
	 *
	 * @param string $base `<img>` in the build's render.
	 * @param string $now  `<img>` in this render.
	 * @return bool
	 */
	private static function same_img( $base, $now ) {
		$a = self::raw_attrs( $base );
		$b = self::raw_attrs( $now );
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		foreach ( $a as $i => $attr ) {
			list( $name, $raw ) = $attr;
			if ( $name !== $b[ $i ][0] ) {
				return false;
			}
			if ( $raw === $b[ $i ][1] ) {
				continue;
			}
			$was = html_entity_decode( self::unquote( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$is  = html_entity_decode( self::unquote( $b[ $i ][1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( in_array( $name, self::IMG_URL_ATTRS, true ) ) {
				if ( ! self::nextgen_copy( $was, $is ) ) {
					return false;
				}
			} elseif ( 'srcset' === $name || 'data-srcset' === $name ) {
				if ( ! self::same_srcset( $was, $is ) ) {
					return false;
				}
			} else {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether two srcsets list the same candidates, each URL the same or its proven next-gen copy.
	 *
	 * @since 8.0
	 *
	 * @param string $base srcset in the build's render.
	 * @param string $now  srcset in this render.
	 * @return bool
	 */
	private static function same_srcset( $base, $now ) {
		$a = explode( ',', $base );
		$b = explode( ',', $now );
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		foreach ( $a as $i => $candidate ) {
			$was = preg_split( '~\s+~', trim( $candidate ), 2 );
			$is  = preg_split( '~\s+~', trim( $b[ $i ] ), 2 );
			if ( ( isset( $was[1] ) ? $was[1] : '' ) !== ( isset( $is[1] ) ? $is[1] : '' ) ) {
				return false;
			}
			if ( $was[0] !== $is[0] && ! self::nextgen_copy( $was[0], $is[0] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a URL is another's next-gen copy that exists: `a.jpg?x=1` and `a.jpg.avif?x=1`
	 * with `a.jpg.avif` beside `a.jpg` on disk.
	 *
	 * The suffix alone proves nothing, so the files are checked as Media::replace_webp()
	 * does when it links the copy, filters included.
	 *
	 * @since 8.0
	 *
	 * @param string $base The original's URL.
	 * @param string $now  The URL in this render.
	 * @return bool
	 */
	private static function nextgen_copy( $base, $now ) {
		if ( $base === $now || self::nextgen_neutral( $now ) !== $base ) {
			return false;
		}
		if ( ! preg_match( '~\.(webp|avif)$~i', substr( $now, 0, strcspn( $now, '?#' ) ), $m ) ) {
			return false;
		}

		$ori = apply_filters( 'litespeed_media_check_ori', Utility::is_internal_file( $base ), $base );
		return $ori && apply_filters( 'litespeed_media_check_webp', Utility::is_internal_file( $base, $m[1] ), $base );
	}

	/**
	 * A tag's attributes in order, repeats included, each as name and raw value (quotes kept).
	 *
	 * @since 8.0
	 *
	 * @param string $tag Open tag.
	 * @return array List of [ lowercased name, raw value ].
	 */
	private static function raw_attrs( $tag ) {
		$tag = preg_replace( '~^<[^\s/>]+|/?>$~', '', $tag );
		preg_match_all( self::RE_ATTR, (string) $tag, $matches, PREG_SET_ORDER );

		$out = [];
		foreach ( $matches as $m ) {
			$out[] = [ strtolower( $m[1] ), isset( $m[2] ) ? $m[2] : '' ];
		}
		return $out;
	}

	/**
	 * An attribute's raw value without its quotes.
	 *
	 * @since 8.0
	 *
	 * @param string $val Raw value.
	 * @return string
	 */
	private static function unquote( $val ) {
		if ( '' !== $val && ( '"' === $val[0] || "'" === $val[0] ) ) {
			return substr( $val, 1, -1 );
		}
		return $val;
	}

	/**
	 * The stylesheet `<link>` tags of a page, each with what it loads (sheet_id()).
	 *
	 * @since 8.0
	 *
	 * @param string $html Page HTML.
	 * @return array Raw tag => sheet id.
	 */
	private static function stylesheet_tags( $html ) {
		$out = [];
		foreach ( (array) self::tokenize( $html ) as $tok ) {
			if ( 'tag' !== $tok['kind'] || 'link' !== $tok['name'] ) {
				continue;
			}
			$attrs = self::attrs( $tok['raw'] );
			if ( isset( $attrs['href'] ) && self::is_stylesheet( $attrs ) ) {
				$out[ $tok['raw'] ] = self::sheet_id( $attrs['href'] );
			}
		}
		return $out;
	}

	/**
	 * A tag's attributes without its href, to tell whether two tags differ only there.
	 *
	 * @since 8.0
	 *
	 * @param string $raw Raw tag.
	 * @return string
	 */
	private static function without_href( $raw ) {
		$attrs = self::attrs( $raw );
		unset( $attrs['href'] );
		return (string) wp_json_encode( $attrs );
	}

	/**
	 * Whether a `<link>` is LSCWP's `litespeed-dummy.css`: a placeholder Page Optimization
	 * enqueues to mark where its CSS goes and drops again, so it is no part of the page's
	 * design. Its `?ver=` follows WordPress updates, which made it read as a design change.
	 *
	 * @since 8.0
	 *
	 * @param array $attrs Decoded attributes.
	 * @return bool
	 */
	private static function is_dummy_css( $attrs ) {
		return isset( $attrs['href'] ) && self::is_stylesheet( $attrs ) && '/assets/css/litespeed-dummy.css' === substr( (string) wp_parse_url( $attrs['href'], PHP_URL_PATH ), -31 );
	}

	/**
	 * Whether a `<link>` loads a stylesheet.
	 *
	 * @since 8.0
	 *
	 * @param array $attrs Decoded attributes.
	 * @return bool
	 */
	private static function is_stylesheet( $attrs ) {
		return isset( $attrs['rel'] ) && in_array( 'stylesheet', preg_split( '~\s+~', strtolower( $attrs['rel'] ), -1, PREG_SPLIT_NO_EMPTY ), true );
	}

	/**
	 * Whether a `<link>` preloads a stylesheet: `rel=preload as=style`.
	 *
	 * @since 8.0
	 *
	 * @param array $attrs Decoded attributes.
	 * @return bool
	 */
	private static function is_style_preload( $attrs ) {
		return isset( $attrs['rel'], $attrs['as'] )
			&& in_array( 'preload', preg_split( '~\s+~', strtolower( $attrs['rel'] ), -1, PREG_SPLIT_NO_EMPTY ), true )
			&& 'style' === strtolower( trim( $attrs['as'] ) );
	}

	/**
	 * What a stylesheet link loads: its local file, by path and content, when the URL
	 * resolves to one; otherwise the URL itself.
	 *
	 * The local file is read first, so the same CSS behind a new `?ver=` reads the
	 * same, and new CSS behind the same URL reads as new. Hashed once per request.
	 *
	 * @since 8.0
	 *
	 * @param string $href Decoded href.
	 * @return string
	 */
	public static function sheet_id( $href ) {
		static $hashed = [];

		// A stylesheet a script writes (`style.php?skin=dark`) is told apart by its URL:
		// the PHP file behind it is the same for every output.
		$file = Utility::is_internal_file( $href );
		if ( ! $file || 'css' !== strtolower( pathinfo( $file[0], PATHINFO_EXTENSION ) ) ) {
			return self::mask( $href );
		}

		$key = $file[0];
		if ( ! isset( $hashed[ $key ] ) ) {
			$md5             = md5_file( $file[0] );
			$hashed[ $key ] = false === $md5 ? '' : $md5;
		}
		if ( '' === $hashed[ $key ] ) {
			return self::mask( $href );
		}

		return wp_parse_url( $href, PHP_URL_PATH ) . '#' . $hashed[ $key ];
	}

	/**
	 * A key for a render: equal keys mean equal fingerprints (a probe, nothing skips on it yet).
	 *
	 * The render with its `uniqid()` tokens masked, plus the path, change time and size of
	 * every local stylesheet (or stylesheet preload) it links, and the fingerprint format.
	 * Cheaper than fingerprint(): no tokenizer, no stylesheet hashing.
	 *
	 * @since 8.0
	 *
	 * @param string $html Page HTML.
	 * @return string md5.
	 */
	public static function render_key( $html ) {
		$files = '';
		if ( preg_match_all( '~<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $html, $links ) ) {
			foreach ( $links[0] as $link ) {
				$attrs = self::attrs( $link );
				if ( ! isset( $attrs['href'] ) || ! ( self::is_stylesheet( $attrs ) || self::is_style_preload( $attrs ) ) ) {
					continue;
				}
				$file = Utility::is_internal_file( $attrs['href'] );
				if ( $file ) {
					$files .= '|' . wp_parse_url( $attrs['href'], PHP_URL_PATH ) . ':' . (int) @filectime( $file[0] ) . ':' . (int) @filesize( $file[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		return md5( self::mask( $html ) . $files . '|v' . self::VERSION );
	}

	/**
	 * The prices in a JSON-LD block, in order, each with its byte offset in the page.
	 *
	 * Read as text, not decoded: the patch rewrites the value where it sits, so the
	 * rest of the block (and how OptimaX minified it) stays as it is.
	 *
	 * @since 8.0
	 *
	 * @param string $json   Script content.
	 * @param int    $offset Byte offset of the content in the page.
	 * @return array List of `key`, `raw` (the JSON value), `offset`.
	 */
	private static function json_prices( $json, $offset ) {
		$out = [];
		if ( preg_match_all( self::RE_JSON_PRICE, (string) $json, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$out[] = [
					'key'    => $m[1][0],
					'raw'    => $m[2][0],
					'offset' => $offset + $m[2][1],
				];
			}
		}
		return $out;
	}

	/**
	 * Whether a link points into the page: `#id`.
	 *
	 * @since 8.0
	 *
	 * @param string $href Decoded href.
	 * @return bool
	 */
	private static function is_anchor( $href ) {
		return '#' === substr( ltrim( $href ), 0, 1 );
	}

	/**
	 * Whether an attribute value is content: data-* (widget configuration) or an inline style.
	 *
	 * Page builders rewrite both on a save without changing the page: Elementor re-saves
	 * every widget's settings, and Woodmart renders a grid's gap variables only once its
	 * element cache is rebuilt. Nonces are neither design nor content.
	 *
	 * @since 8.0
	 *
	 * @param string $name Lowercased attribute name.
	 * @return bool
	 */
	private static function is_content_attr( $name ) {
		return ( 0 === strpos( $name, 'data-' ) || 'style' === $name ) && false === strpos( $name, 'nonce' );
	}

	/**
	 * Whether two items hold the same content.
	 *
	 * Per-render values do not count: an `<img>` or a data-* value that differs only
	 * in its element ids or `uniqid()` tokens is the same, and so is an `<img>` that
	 * differs only in the next-gen copy it links (img_sig()), and JSON settings that
	 * differ only in unset keys or key order (attr_sig()). content_sig() signs items
	 * in the same form: keep the two in step.
	 *
	 * @since 8.0
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( $a === $b ) {
			return true;
		}
		if ( $a[0] !== $b[0] ) {
			return false;
		}
		if ( self::TYPE_ATTR === $a[0] ) {
			return $a[2] === $b[2] && self::attr_sig( $a[1] ) === self::attr_sig( $b[1] );
		}
		if ( self::TYPE_IMG === $a[0] ) {
			return self::img_sig( $a[1] ) === self::img_sig( $b[1] );
		}
		return false;
	}

	/**
	 * An `<img>` as compared: every attribute, without per-render values, its URLs next-gen neutral.
	 *
	 * @since 8.0
	 *
	 * @param string $tag `<img>` tag.
	 * @return string
	 */
	private static function img_sig( $tag ) {
		$out = '';
		foreach ( self::attrs( $tag ) as $name => $val ) {
			if ( in_array( $name, self::IMG_URL_ATTRS, true ) ) {
				$val = self::nextgen_neutral( $val );
			} elseif ( 'srcset' === $name || 'data-srcset' === $name ) {
				$val = self::nextgen_neutral_set( $val );
			}
			$out .= ' ' . $name . ( in_array( $name, self::ID_ATTRS, true ) ? '' : '=' . self::mask( $val ) );
		}
		return $out;
	}

	/**
	 * An image URL without the next-gen suffix LiteSpeed's Image Optimization adds: `a.jpg.avif` is `a.jpg`.
	 *
	 * Once a file is converted, `Media::webp_srcset` rewrites the render's srcset to
	 * the `.webp`/`.avif` copy, and the build already links next-gen files, so the
	 * suffix is no change of the page. The pattern mirrors `Media::replace_webp`
	 * (media.cls.php:1530-1536): only a convertible original (jpg, jpeg, png, gif)
	 * gets a suffix, so an original `.webp` or `.avif` upload is left as is; a file
	 * named like a converted copy (`x.jpg.webp`) counts as one. Only the path is
	 * rewritten; a query or fragment is kept.
	 *
	 * `<source srcset>` and `<link imagesrcset>` are not made neutral (deferred): a
	 * next-gen rewrite there still reads as a design change.
	 *
	 * @since 8.0
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function nextgen_neutral( $url ) {
		$cut = strcspn( $url, '?#' );
		return preg_replace( '~' . self::NEXTGEN_SUFFIX . '$~i', '.$1', substr( $url, 0, $cut ) ) . substr( $url, $cut );
	}

	/**
	 * Text (HTML or CSS) with every converted copy's link put back to its original.
	 *
	 * For a result that links next-gen files for a version that has none, e.g. a
	 * service that predates "no next-gen format": `a.jpg.webp` becomes `a.jpg`. Same
	 * pattern as nextgen_neutral(), so an original `.webp` upload is left alone.
	 *
	 * @since 8.0
	 *
	 * @param string $text HTML or CSS.
	 * @return string
	 */
	public static function drop_nextgen_links( $text ) {
		return preg_replace( '~' . self::NEXTGEN_SUFFIX . '(?![A-Za-z0-9])~i', '.$1', (string) $text );
	}

	/**
	 * A srcset with every candidate's URL made next-gen neutral (nextgen_neutral()).
	 *
	 * Candidates are trimmed and rejoined with `, `; both sides of a compare go
	 * through here, so whitespace and separator differences cancel out. A comma
	 * inside a URL is rare; it can only split or merge candidates differently, so
	 * such a srcset may read as a change, or (`?x=1,2` vs `?x=1, 2`) as none.
	 *
	 * @since 8.0
	 *
	 * @param string $srcset srcset value.
	 * @return string
	 */
	private static function nextgen_neutral_set( $srcset ) {
		$out = [];
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '~\s+~', trim( $candidate ), 2 );
			$out[] = self::nextgen_neutral( $parts[0] ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
		}
		return implode( ', ', $out );
	}

	/**
	 * The value an item is found by in the OptimaX HTML.
	 *
	 * @since 8.0
	 *
	 * @param array $item An item.
	 * @return string
	 */
	private static function locate_key( $item ) {
		if ( self::TYPE_ATTR === $item[0] ) {
			return $item[2] . '=' . self::attr_norm( $item[2], $item[1] );
		}
		return isset( $item[2] ) ? $item[2] : $item[1];
	}

	/**
	 * An attribute value as compared: `uniqid()` tokens masked, an inline style as
	 * OptimaX's minifier writes it (no whitespace around `:` and `;`, no last `;`).
	 *
	 * @since 8.0
	 *
	 * @param string $name Attribute name.
	 * @param string $val  Decoded value.
	 * @return string
	 */
	private static function attr_norm( $name, $val ) {
		$val = self::mask( $val );
		if ( 'style' === $name ) {
			$val = rtrim( preg_replace( '~\s*([:;])\s*~', '$1', trim( $val ) ), ';' );
		}
		return $val;
	}
}
