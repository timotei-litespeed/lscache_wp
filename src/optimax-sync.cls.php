<?php
/**
 * Keeps an OptimaX build in step with small edits of its page.
 *
 * @since   8.0
 * @package LiteSpeed
 */

namespace LiteSpeed;

defined( 'WPINC' ) || exit();

/**
 * Fingerprint of a rendered page, and the text patch of a build.
 *
 * A fingerprint splits a page into its design (tags, their attributes, stylesheets
 * and scripts) and its content (text, link targets, images, meta descriptions).
 * Two renders with the same design differ only in content, which patch() writes
 * into the stored OptimaX HTML instead of building the page again.
 *
 * Works on the raw HTML: no DOM, so the offsets it patches are the bytes on disk.
 *
 * @since 8.0
 */
class Optimax_Sync {

	const TYPE_TEXT = 't';
	const TYPE_HREF = 'h';
	const TYPE_IMG  = 'i';
	const TYPE_META = 'm';

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

			$line = $name . self::design_attrs( $attrs, $skip );
			if ( isset( $tok['inner'] ) ) {
				if ( 'title' === $name ) {
					$text = self::norm_text( $tok['inner'] );
					if ( '' !== $text ) {
						$line   .= ' T';
						$items[] = [ self::TYPE_TEXT, $text ];
					}
				} elseif ( 'style' === $name ) {
					$line .= ' ' . md5( self::mask( $tok['inner'] ) );
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
	 * Write the content changes between two renders into the stored OptimaX HTML.
	 *
	 * Each changed item is found in the OptimaX HTML by its old value, as the same
	 * occurrence it was in the old render (the 2nd "Read more" stays the 2nd). When
	 * the OptimaX HTML holds that value a different number of times, the item cannot
	 * be placed and nothing is patched. A meta tag OptimaX dropped is skipped: it is
	 * not on the page.
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
		if ( count( $old ) !== count( $new ) ) {
			return false;
		}

		$tokens = self::tokenize( $html );
		if ( null === $tokens ) {
			return false;
		}

		// Where each value sits in the OptimaX HTML, in page order.
		$found = [];
		foreach ( $tokens as $tok ) {
			if ( 'text' === $tok['kind'] ) {
				$found[ self::TYPE_TEXT ][ self::norm_text( $tok['raw'] ) ][] = $tok;
				continue;
			}
			if ( 'tag' !== $tok['kind'] || '/' === $tok['name'][0] ) {
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
				$found[ self::TYPE_IMG ][ self::img_key( $attrs ) ][] = $tok;
			} elseif ( 'a' === $tok['name'] && isset( $attrs['href'] ) && ! self::is_anchor( $attrs['href'] ) ) {
				$found[ self::TYPE_HREF ][ $attrs['href'] ][] = $tok;
			} elseif ( 'meta' === $tok['name'] && isset( $attrs['content'] ) && self::meta_key( $attrs ) ) {
				$found[ self::TYPE_META ][ self::meta_key( $attrs ) ][] = $tok;
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
				if ( self::TYPE_META === $type ) {
					continue;
				}
				return false;
			}
			$tok = $cands[ $nth[ $i ] ];

			switch ( $type ) {
				case self::TYPE_TEXT:
					preg_match( '~^(\s*).*?(\s*)$~s', $tok['raw'], $m );
					$repl = $m[1] . self::esc_text( $new[ $i ][1] ) . $m[2];
					break;
				case self::TYPE_HREF:
					$repl = self::set_attr( $tok['raw'], 'href', $new[ $i ][1] );
					break;
				case self::TYPE_META:
					$repl = self::set_attr( $tok['raw'], 'content', $new[ $i ][1] );
					break;
				default:
					$repl = call_user_func( $img_tag, $new[ $i ][1] );
			}
			if ( ! is_string( $repl ) || isset( $edits[ $tok['offset'] ] ) ) {
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
		$re = '~(\s' . preg_quote( $name, '~' ) . '\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)~i';
		if ( ! preg_match( $re, $tag, $m, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		$quoted = '"' . str_replace( [ '&', '"', "\xc2\xa0" ], [ '&amp;', '&quot;', '&nbsp;' ], $val ) . '"';

		return substr_replace( $tag, $quoted, $m[2][1], strlen( $m[2][0] ) );
	}

	/**
	 * The URL an image is found by: its first real or lazy URL that is not inline data.
	 *
	 * Without the `.webp`/`.avif` OptimaX appends, or the render already carried.
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
			return preg_replace( '~\.(webp|avif)(?=[?#]|$)~i', '', trim( $attrs[ $name ] ) );
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
			if ( 'value' !== $name && false === strpos( $name, 'nonce' ) && ! in_array( $name, self::ID_ATTRS, true ) ) {
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
	 * Whether two items hold the same content.
	 *
	 * An image is compared as its design reads its attributes: an `<img>` numbered per
	 * render is the same image.
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
		if ( self::TYPE_IMG !== $a[0] || self::TYPE_IMG !== $b[0] ) {
			return false;
		}
		return self::design_attrs( self::attrs( $a[1] ), [] ) === self::design_attrs( self::attrs( $b[1] ), [] );
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
		return isset( $item[2] ) ? $item[2] : $item[1];
	}
}
