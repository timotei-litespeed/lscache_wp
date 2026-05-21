# Security Primitives & Trust Contract — LiteSpeed Cache (dev branch)

_Built 2026-05-21 from `src/` grep + diff against merge-base `e6c1e14`. This is the baseline; deviations are evidence._

## Canonical request-gating contract

All admin AJAX/POST routes go through **`Router::verify_action()`** at `src/router.cls.php:541`:

1. `$_REQUEST[self::ACTION]` must be present.
2. `Router::verify_nonce($action)` (line 695) calls `wp_verify_nonce($_REQUEST[self::NONCE], $action)`.
3. If nonce fails: falls back to `is_admin_ip()` check (whitelist of debug IPs).
4. Capability check (`current_user_can`) is layered separately in `src/admin.cls.php`, `src/rest.cls.php`, `src/router.cls.php`.

**Trust rule:** any new admin handler must run `Router::verify_action()` OR explicitly `check_admin_referer + current_user_can`. Bare `is_admin()` is NOT a security check.

## SQL access pattern

The codebase is mostly disciplined: `$wpdb->prepare(...)` is used for queries with variable values. Direct `$wpdb->query($sql)` only appears in `data.cls.php` where `$sql` is constructed from hardcoded literals + `$this->tb('csv')` (table-name helper that returns a known string from a fixed map). No raw user input flows into table names.

Risky pattern to flag: any new query string built with `.` concatenation of an attacker-influenceable value without `prepare`.

## Output escaping

Templates (`tpl/**/*.tpl.php`) use the `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `__()` family. Inline `<?= ?>` of variables not wrapped by an `esc_*` is a deviation.

## Dangerous sinks — repository-wide scan

| Sink | Where present | Notes |
|---|---|---|
| `eval(` | **none** in `src/` | Safe |
| `system / exec / shell_exec / passthru / popen / proc_open` | **none** in `src/` | Safe |
| `/e` regex modifier (`preg_replace`) | **none** | All `preg_replace` calls use static patterns; none use the deprecated `/e` flag. |
| `unserialize()` | only `maybe_unserialize()` of DB meta values (`media.cls.php`, `img-optm-*`, `object-cache-wp.cls.php`) | Semi-trusted, standard WP pattern. Flag only if attacker can write to the DB row from a lower-trust path. |
| `file_get_contents`/`file_put_contents`/`unlink`/`rename`/`fopen` | Concentrated in `src/file.cls.php`, `src/crawler.cls.php`, `src/optimizer.cls.php`, `src/img-optm-*`, `src/import.preset.cls.php`, `src/report.cls.php`. | Paths must be `realpath`-anchored under plugin/wp-content dirs. Flag any concat of `$_REQUEST` / option-derived strings without anchor check. |
| `wp_remote_get/post/request`, `curl_init/exec` | `crawler.cls.php`, `cdn/cloudflare.cls.php`, `optimax.cls.php` (NEW line 443), `guest.cls.php`, `img-optm-pull.trait.php` | URLs must originate from plugin code or already-validated options. Flag attacker-controllable host/protocol. |
| Redirects | All call sites use **`wp_safe_redirect`** ✓ | Open redirect attack surface is low. |
| `php://input` | `img-optm-pull.trait.php:35`, possibly callback handlers | Cloud callback bodies are JSON-decoded; signature verification must happen before any state change. |

## Trust tiers (lowest → highest)

1. **Untrusted:** `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER['HTTP_*']`, `php://input`, REST bodies.
2. **Semi-trusted (low-cap user):** Anything reachable via `admin-ajax.php`/`admin-post.php` without capability check.
3. **Semi-trusted (admin):** Reachable behind `Router::verify_action()` + admin capability.
4. **Semi-trusted (internal):** Values from `wp_options`, transients, plugin DB tables — may have been written by lower-trust path historically.
5. **Trusted:** Hardcoded constants, plugin source paths.

## Zero-trust enforcement rule

A function MUST NOT assume its caller has sanitized. At every sink, the function nearest the sink re-validates. Cross-function deviations are findings (e.g., `optimizer.cls.php` calls `file_put_contents($path)` while trusting that the caller validated `$path` against plugin dirs).
