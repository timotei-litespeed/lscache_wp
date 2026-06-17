# LiteSpeed Cache — PHP 8.5 output-buffer-handler hardening

**Component:** `src/core.cls.php` — `LiteSpeed\Core::send_headers_force()` (the `ob_start()` handler)
**Targets PHP:** 8.5+ (harmless / no-op on older versions)
**Status:** implemented on `dev`, verified on PHP 8.3 / 8.4 / 8.5 CLI; **not yet smoke-tested in a live WP request**

---

## TL;DR

**The fix.** PHP 8.5 deprecated two behaviours of `ob_start()` output handlers, and LiteSpeed's
`send_headers_force()` is one. This change hardens that handler against both so they no longer surface
in the logs or break the page:

1. **Producing output** — a 3rd-party hook echoing while our buffer is flushed makes PHP blame our
   handler and log a deprecation. We can't capture that output (it's fatal to buffer inside a handler),
   so we suppress *only* that one misattributed notice.
2. **Non-string return** — a `litespeed_buffer_*` filter returning a non-string (`null`/array/object)
   poisons the response. We guard every filter so the buffer is always a string.

**What changed (`src/core.cls.php`):**

- Added `silence_ob_handler_deprecation()` — a narrowly-targeted `set_error_handler` registered on
  `shutdown` (priority 0), **gated to PHP ≥ 8.5**, that swallows only the `Producing output … send_headers_force`
  deprecation and passes every other error through.
- Hardened `send_headers_force()` — coerces input/return to a string and routes every `litespeed_buffer_*`
  filter through a new private `buffer_filter()` helper that drops a non-string return (logging it) and
  keeps the last good buffer.

**How to test (quickest path):** enable LSC Debug Log, paste the `functions.php` snippet from §3.1, then,
as a logged-in admin, visit:

- `/?ls_ob_test=echo` → page renders, **no** `Producing output…` deprecation in the log. *(case A)*
- `/?ls_ob_test=return` → page renders (not blank), **no** `Array to string conversion`, and a
  `⚠️ litespeed_buffer_after returned a non-string` line in the LSC debug log. *(case B)*

Full details, rationale, and a no-WordPress CLI probe follow below.

---

## 1. Background

LiteSpeed registers a single output-buffer chokepoint early in the request:

```php
ob_start( [ $this, 'send_headers_force' ] );   // src/core.cls.php
```

When WordPress flushes that buffer at shutdown (`wp_ob_end_flush_all()` in `wp-includes/functions.php`,
hooked on `shutdown` priority 1), PHP invokes `send_headers_force()` to produce the final response.

PHP 8.5 added **two** deprecations that affect any such handler:

| # | Deprecation | Trigger |
|---|---|---|
| A | `ob_end_flush(): Producing output from user output handler …send_headers_force is deprecated` | Any code **echoes/prints** while the handler runs (e.g. a 3rd-party hook on `litespeed_buffer_*`, or another plugin printing during shutdown). |
| B | `Returning non-string values from a user output handler is deprecated` | The handler **returns a non-string** (a `litespeed_buffer_*` filter returns `null` / array / object / bool). Also surfaces as `Array to string conversion` warnings. |

Refs:
- https://php.watch/versions/8.5/ob-handler-output-deprecated
- https://php.watch/versions/8.5/ob_handler-return-non-string

### What is fixed in the plugin

| Case | Fix | Notes |
|---|---|---|
| **A** — producing output | §2.1 — targeted, version-gated deprecation suppressor | PHP already discards the stray output (response is unaffected); the suppressor removes the misattributed **log notice**. |
| **B** — non-string return | §2.2 — string guard around every buffer filter | A genuine correctness issue: a bad filter could blank the page. Fixed at the source by guaranteeing a string. |

### Why case A can't be "captured"

You cannot capture stray output from inside the handler — opening a nested buffer there is fatal:

```
PHP Fatal error: ob_start(): Cannot use output buffering in output buffering display handlers
```

Confirmed on PHP 8.5.5. PHP unavoidably **discards** the stray output (only the handler's return value
reaches the client), so the *response* is already isolated; the only artefact is the log notice, which
§2.1 silences.

---

## 2. Changes made (`src/core.cls.php`)

### 2.1 Silence the misattributed "producing output" deprecation (case A)

Gated to **PHP ≥ 8.5** (`PHP_VERSION_ID >= 80500`) so older versions register nothing. The handler is
installed on `shutdown` priority 0 — right before WP's `wp_ob_end_flush_all()` (priority 1) and PHP's own
end-of-request flush invoke the buffer handler, and on top of any early-installed 3rd-party error handler.

Registration (in `init()`, immediately after `ob_start()`):

```php
ob_start( [ $this, 'send_headers_force' ] );
// Silence the PHP 8.5+ deprecation that misattributes 3rd-party stray output to our buffer handler. @see silence_ob_handler_deprecation()
if ( PHP_VERSION_ID >= 80500 ) {
	add_action( 'shutdown', [ $this, 'silence_ob_handler_deprecation' ], 0 );
}
add_action( 'shutdown', [ $this, 'send_headers' ], 0 );
```

Method:

```php
public function silence_ob_handler_deprecation() {
	$previous = set_error_handler(
		function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$previous ) {
			if (
				E_DEPRECATED === $errno
				&& false !== strpos( $errstr, 'Producing output from user output handler' )
				&& false !== strpos( $errstr, 'send_headers_force' )
			) {
				return true; // Swallow only this misattributed deprecation.
			}
			// Delegate everything else to keep other error handlers (Query Monitor, etc.) working.
			return null !== $previous ? call_user_func( $previous, $errno, $errstr, $errfile, $errline ) : false;
		}
	);
}
```

The match requires **both** the generic phrase **and** `send_headers_force`, so it can never swallow an
unrelated deprecation; every other error is delegated to the previously-registered handler.

**Why the `PHP_VERSION_ID >= 80500` gate**

- The deprecation message only exists in **PHP 8.5+**. On 8.4 and below there is nothing to suppress, so
  the gate avoids installing a global `set_error_handler` (and its tiny per-request cost / chaining
  surface) where it would be pure dead weight.
- It documents intent: this is a PHP-8.5-specific measure.
- In **PHP 9.0** case A is expected to escalate from a deprecation to a thrown `Error`, which an error
  handler cannot intercept — at that point the gate is the natural place to revisit/disable this.

### 2.2 Isolate the response from non-string returns (case B)

`send_headers_force()` coerces its input to a string on entry, guarantees a string on return, and runs
every 3rd-party filter through a guard that keeps the last known-good buffer if a callback returns a
non-string:

```php
public function send_headers_force( $buffer ) {
	// Isolate our response: the buffer must stay a string end to end.
	if ( ! is_string( $buffer ) ) {
		$buffer = '';
	}

	$this->check_is_html( $buffer );

	$buffer = $this->buffer_filter( 'litespeed_buffer_before', $buffer );
	// … ESI / Optimax / litespeed_buffer_finalize …
	$buffer = $this->buffer_filter( 'litespeed_buffer_finalize', $buffer );
	// …
	$buffer = $this->buffer_filter( 'litespeed_buffer_after', $buffer );

	Debug2::ended();

	// Final guard: never hand a non-string back to the output buffering layer.
	return is_string( $buffer ) ? $buffer : '';
}

/**
 * Apply a buffer filter hook but keep the response isolated from misbehaving 3rd party callbacks.
 */
private function buffer_filter( $hook, $buffer ) {
	$result = apply_filters( $hook, $buffer );
	if ( is_string( $result ) ) {
		return $result;
	}
	Debug2::debug( '[Core] ⚠️ `' . $hook . '` returned a non-string (' . gettype( $result ) . '); keeping prior buffer' );
	return $buffer;
}
```

Effect: a misbehaving plugin can no longer blank the page or raise `Array to string conversion` /
the "Returning non-string values…" deprecation. The offending hook is logged to the LSC debug log.

### What was deliberately **not** changed

The finalization was **not** moved out of the output handler into a manual `shutdown` flush. Doing so
would let us *capture* 3rd-party stray output instead of discarding it, but it would forfeit the
"catch plugins that force-flush the buffer early" purpose that `send_headers_force` exists for. Left as
a possible future option.

---

## 3. How to test

> Requires LSC **Debug Log** enabled (Toolbox → Debug Settings) so `Debug2::debug()` writes to
> `wp-content/litespeed/debug/*.log`. The WP `debug.log` (WP_DEBUG_LOG) shows the raw PHP deprecations.
> Always test as a logged-in admin with a query string so the response isn't served from cache.

### 3.1 In WordPress — drop into the active theme `functions.php`

```php
/**
 * TEMPORARY — verify the two LiteSpeed output-buffer fixes. Admin only. Remove after testing.
 *   /?ls_ob_test=echo     → stray echo inside the real handler  (tests the suppressor, case A)
 *   /?ls_ob_test=return   → non-string return from a filter     (tests the buffer guard, case B)
 */
add_action( 'init', function () {
	if ( empty( $_GET['ls_ob_test'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$mode = sanitize_key( wp_unslash( $_GET['ls_ob_test'] ) );

	if ( 'echo' === $mode ) {
		add_filter( 'litespeed_buffer_after', function ( $buffer ) {
			echo "\n<!-- ls_ob_test: stray echo inside handler -->\n";
			return $buffer;
		}, 10 );
		if ( class_exists( '\LiteSpeed\Debug2' ) ) {
			\LiteSpeed\Debug2::debug( '[ls_ob_test] echo trigger armed — expect NO "Producing output" deprecation with the fix.' );
		}
		return;
	}

	if ( 'return' === $mode ) {
		add_filter( 'litespeed_buffer_after', function ( $buffer ) {
			return array( 'oops' ); // with the guard: discarded + logged, page intact, no PHP warning
		}, 10 );
		if ( class_exists( '\LiteSpeed\Debug2' ) ) {
			\LiteSpeed\Debug2::debug( '[ls_ob_test] return trigger armed — expect a "⚠️ … returned a non-string" line and an intact page.' );
		}
		return;
	}
}, 99 );
```

**Expected (patched build, PHP 8.5):**

| URL | Page | PHP / WP debug.log | LSC debug log |
|---|---|---|---|
| `/?ls_ob_test=echo` | renders normally (stray text discarded by PHP) | **no** `Producing output from user output handler …send_headers_force` | — |
| `/?ls_ob_test=return` | renders normally (not blank, no literal `Array`) | **no** `Array to string conversion` | `⚠️ \`litespeed_buffer_after\` returned a non-string (array); keeping prior buffer` |

On an **unpatched** build the same URLs produce the deprecation / warning — that's the before/after.

> Both trigger via `litespeed_buffer_after`, which runs unconditionally inside `send_headers_force()`
> on every front-end HTML response (even for logged-in / no-optimize requests), so they reliably hit the
> real code path.

### 3.2 Standalone — version-agnostic CLI probe (no WordPress)

Detects what *this* PHP build does to an output handler that misbehaves. Reports
`NOT AFFECTED` (≤ 8.4), `AFFECTED (DEPRECATION)` (8.5.x), or `AFFECTED (FATAL)` (future 9.0).

```php
<?php
error_reporting( E_ALL ); ini_set( 'display_errors', '1' );

$bad = function ( $buffer ) { echo '<!-- stray -->'; return $buffer; };
$captured = null; $fatal = null;

set_error_handler( function ( $no, $str ) use ( &$captured ) {
	if ( false !== strpos( $str, 'Producing output from user output handler' ) ) { $captured = $str; return true; }
	return false;
} );

try {
	ob_start( $bad );
	echo 'body';
	ob_end_flush();
} catch ( \Throwable $e ) {
	$fatal = get_class( $e ) . ': ' . $e->getMessage();
	while ( ob_get_level() > 0 ) { ob_end_clean(); }
}
restore_error_handler();

if ( $fatal )        $v = 'AFFECTED (FATAL): ' . $fatal;
elseif ( $captured ) $v = 'AFFECTED (DEPRECATION): ' . $captured;
else                 $v = 'NOT AFFECTED';
echo "PHP " . PHP_VERSION . " => " . $v . "\n";
```

Run: `php probe.php`

---

## 4. Verification evidence (CLI)

| PHP | Producing-output (case A) | Suppressor | Non-string return guard (case B) |
|---|---|---|---|
| 8.3.13 | not emitted | not registered (gate) | page intact, no warning |
| 8.4.22 | not emitted | not registered (gate) | page intact, no warning |
| 8.5.5  | **emitted** (reproduced) | **silenced**; unrelated deprecations still logged | page intact, no warning |

- Nested `ob_start()` inside a handler → confirmed fatal (`Cannot use output buffering in output
  buffering display handlers`).
- Stray handler output → confirmed **discarded** by PHP (only the return value reaches output).
- Targeted error handler → silences only the matching message; a different `E_USER_DEPRECATED` still
  passes through.
- Filter returning `null` / array / object / int → guard keeps the real page; legitimate string return
  still applied.

---

## 5. Notes / follow-ups

- Hot path: the additions are a couple of `is_string()` checks per response plus one `set_error_handler`
  at shutdown on PHP 8.5+ — negligible cost.
- The suppressor is intentionally gated to `PHP_VERSION_ID >= 80500`. PHP **9.0** is expected to escalate
  case A from a deprecation to a thrown `Error` (uncatchable by an error handler); revisit the gate then,
  and lean on 3rd-party hooks no longer echoing during the flush.
- Remember to remove any `functions.php` test snippet (and the previous repo-root `test-ob-deprecation.php`,
  now deleted) before shipping.
