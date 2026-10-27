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
	 */
	const VERSION = 9;

	/**
	 * A price in JSON-LD (schema.org Offer / AggregateOffer): key, then its raw JSON value.
	 */
	const RE_JSON_PRICE = '~"(price|lowPrice|highPrice)"\s*:\s*("(?:[^"\\\\]|\\\\.)*"|-?[0-9][0-9.eE+-]*)~';

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
			// same local file (Elementor rewrites its post CSS on every save) is not a new design.
			$sheet = '';
			if ( 'link' === $name && isset( $attrs['href'] ) && self::is_stylesheet( $attrs ) ) {
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
				$item = [ $item[0], self::mask( $item[1] ), $item[2] ];
			} elseif ( self::TYPE_IMG === $item[0] ) {
				$item = [ $item[0], self::img_sig( $item[1] ) ];
			}
			$norm[] = $item;
		}
		return md5( serialize( $norm ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashed, never unserialized.
	}

	/**
	 * Split HTML into tokens.
	 *
	 * @since 8.0
	 *
	 * @param string $html HTML.
	 * @return array|null List of `kind` (text|tag|other), `raw`, and for a tag `name` (`/name`
	 *                    when closing) plus `inner` for a raw-text element.
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
					'kind' => 'text',
					'raw'  => substr( $html, $pos, $offset - $pos ),
				];
			}
			$pos = $offset + strlen( $raw );

			if ( ! preg_match( '~^<(/?[a-zA-Z][^\s/>]*)~', $raw, $name ) ) {
				$tokens[] = [
					'kind' => 'other',
					'raw'  => $raw,
				];
				continue;
			}

			$tok = [
				'kind' => 'tag',
				'raw'  => $raw,
				'name' => strtolower( $name[1] ),
			];
			if ( isset( $m[2] ) && '' !== $m[1][0] ) {
				// A raw-text element: keep its open tag as the token, its content apart.
				$tok['raw']   = substr( $raw, 0, $m[2][1] - $offset );
				$tok['inner'] = $m[2][0];
			}
			$tokens[] = $tok;
		}
		if ( $pos < strlen( $html ) ) {
			$tokens[] = [
				'kind' => 'text',
				'raw'  => substr( $html, $pos ),
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
	 * differs only in the next-gen copy it links (img_sig()). content_sig() signs
	 * items in the same form: keep the two in step.
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
			return $a[2] === $b[2] && self::mask( $a[1] ) === self::mask( $b[1] );
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
		return preg_replace( '~\.(jpe?g|png|gif)\.(webp|avif)$~i', '.$1', substr( $url, 0, $cut ) ) . substr( $url, $cut );
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

}
