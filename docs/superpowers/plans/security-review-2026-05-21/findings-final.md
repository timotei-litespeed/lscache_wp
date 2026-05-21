# Security Review — LiteSpeed Cache `dev` (v7.9-a29)

_Original review: 2026-05-21 against `master` at merge-base `e6c1e14`, covering through `d177dc0b` (v7.9-a27). Refreshed: 2026-05-21 against current `dev` HEAD `a47f5ce0` (v7.9-a29). Methodology: zero-trust audit across 53 modified files in 7 parallel batches, plus targeted in-session spot-checks, then per-finding false-positive filtering (keep only confidence ≥ 0.8). Methodology and primitives map preserved in `docs/superpowers/plans/security-review-2026-05-21/`._

## Delta since original review (3 new commits)

| Commit | Files | Security delta |
|---|---|---|
| `957bbf2c` v7.9-a28 — Uniformed VPI/UCSS/OX QC svc lib | `src/optimax.cls.php`, `src/ucss.cls.php`, `src/vpi.cls.php` | Pure refactor moving queue/cron/`_send_req` logic into the `Cloud_Queue_Svc` base class. `_save_imgs()` and `_fetch_img()` (the SSRF code path) are **untouched** — `grep -E '_fetch_img\|_save_imgs\|sslverify\|wp_remote_get\|is_internal_file' 01-delta.patch` matches only the unchanged `_save_imgs( $ox['imgs'] )` call site. No new sinks introduced. |
| `ec2dfc07` v7.9-a29 — WP 7 admin design changes (#989) | `assets/css/litespeed.css`, `assets/css/litespeed-dark-mode.css` | CSS only. Out of security scope. |
| `a47f5ce0` v7.9-a29 | `litespeed-cache.php` | Version-string bump only. |

**Net effect on findings:** Vuln 1 (SSRF in `_fetch_img`) **persists at the same line numbers** in current HEAD's `src/optimax.cls.php` — the refactor did not touch this code path.

**Summary:** **1 MEDIUM** finding retained (unchanged from original review).

---

# Vuln 1: SSRF: `src/optimax.cls.php:302`

* Severity: **MEDIUM**
* Confidence: 0.8
* Category: `ssrf` (host- and scheme-controllable) + amplified read primitive
* Description: `Optimax::_fetch_img($url, $save_path)` calls `wp_remote_get( $url, [ 'timeout' => 60, 'sslverify' => false ] )` (lines 302–309) where `$url` is `$img['webp_url']` or `$img['avif_url']` taken directly from the QC.cloud JSON response (passed in by `_save_imgs()` at lines 283 and 288, which receives `$ox['imgs']` from `_save_result()` at lines 124–126). There is no host allowlist, no scheme constraint, and no IP-range guard before the request is issued; the response body is then written to `<is_internal_file($img['src'])>.webp` / `.avif` via `File::save()` (line 322). The local save path is anchored to an existing docroot file by `Utility::is_internal_file()` (`utility.cls.php:815-819` — `realpath()` + `is_file()`), but only the *save target* is anchored — not the fetched URL.
* Exploit Scenario: An adversary who controls the QC.cloud response — credible under the zero-trust threat model (compromised cloud account, malicious mirror, or a compromise of QC.cloud infrastructure; on-path MITM of `Cloud::post()` is largely mitigated because that hop uses `wp_safe_remote_post` with TLS verify) — returns `{"data_optimax":{"imgs":[{"src":"https://victim/wp-content/uploads/2024/01/photo.jpg","webp_url":"http://169.254.169.254/latest/meta-data/iam/security-credentials/<role>/","avif_url":"http://10.0.0.5/internal-admin/"}]}}`. The WordPress host fetches the cloud-instance-metadata endpoint and the internal admin URL (with TLS verification disabled on this hop), then writes each response body next to the existing image as `photo.jpg.webp` and `photo.jpg.avif`. Because the `src` selected by the attacker is a normal `/wp-content/uploads/` URL, the resulting files are web-accessible: the attacker fetches `https://victim/wp-content/uploads/2024/01/photo.jpg.webp` over the public web and reads the IMDS credentials or internal-admin response. This turns blind SSRF into a readable exfiltration channel.
* Recommendation: Inside `_fetch_img()`, before `wp_remote_get()`:
  1. Parse the URL with `wp_parse_url()`. Require `scheme === 'https'`. Reject otherwise.
  2. Require the host to match an allowlist derived from `Cloud::detect_cloud()` (the same node QC.cloud told the plugin to use), or — at minimum — call `wp_safe_remote_get()` instead of `wp_remote_get()` (which routes through `wp_http_validate_url()` and rejects private / loopback / link-local IPs by default).
  3. Drop `'sslverify' => false`; rely on WP defaults so an on-path attacker cannot tamper with the second-hop response either.
  4. After fetch, verify `wp_remote_retrieve_header( $response, 'content-type' )` is `image/webp` or `image/avif` and that the body's first bytes match the file-format magic, before passing to `File::save()`.

---

## Notes on the rest of the diff

The following were examined and produced **no findings** at confidence ≥ 0.8:

- **Routing / REST (`src/router.cls.php`, `src/rest.cls.php`, `src/core.cls.php`, `litespeed-cache.php`, `autoload.php`):** New `ACTION_OPTIMAX` constant routes through the unchanged `Router::verify_action()` gate (nonce + admin capability). Three REST cloud-callback routes were *removed* (net reduction of attack surface). New `Cloud_Queue_Svc::handler()` retains the canonical `Router::verify_action()` gate.
- **Admin UI (`src/admin-display.cls.php`, `src/admin-settings.cls.php`, `src/gui.cls.php`):** Admin-bar hook moved to `admin_bar_menu` priority 95 — still gated by `current_user_can( manage_options / manage_network_options )`. New CDN filetype auto-strip in `Admin_Settings::save()` only does `array_diff` against a hardcoded whitelist behind the existing save-settings gate.
- **Optimizers (`src/optimizer.cls.php`, `src/css.cls.php`, `src/ucss.cls.php`, `src/vpi.cls.php`, `src/placeholder.cls.php`):** Filesystem sinks are anchored to `LITESPEED_STATIC_DIR` + hardcoded type slug + `md5($content)` filename. Cloud-returned CSS payloads pass through `wp_strip_all_tags()` before being persisted, killing both XSS via `</style>` breakout and traversal. VPI payloads pass through `Utility::sanitize_lines($val, 'basename,drop_webp')` before `update_post_meta()`.
- **Cloud & Tasks (`src/cloud-queue-svc.cls.php`, `src/task.cls.php`, `src/health.cls.php`, `src/crawler.cls.php`, `src/cdn.cls.php`, `src/cdn/cloudflare.cls.php`):** New `Cloud_Queue_Svc` is an outbound-only orchestrator (uses `wp_safe_remote_post` over TLS; no `php://input`, no `unserialize`, no callback receiver). `Cloudflare::request()` targets the hardcoded `api.cloudflare.com` host with `wp_remote_request()`; the ALPN-disable in the `http_api_curl` filter is a compatibility tweak per CVE rubric. Crawler `curl_exec` targets remain sitemap-derived or loopback warm-cache URLs with `CURLOPT_RESOLVE` pinned to `$this->_server_ip`.
- **Cache / Data layer (`src/purge.cls.php`, `src/data.cls.php`, `src/file.cls.php`, `src/object-cache.cls.php`, `src/object.lib.php`, `src/base.cls.php`, `src/doc.cls.php`):** All new `$wpdb` sites build SQL from literals plus the table-name helper `Data::tb()` (switch-mapped to fixed `wpdb->prefix . TB_*` constants), with `prepare()` typed placeholders on every variable. New `File::build_static_dir_htaccess()` writes to two literal paths under `LITESPEED_STATIC_DIR`. phpredis `OPT_COMPRESSION = COMPRESSION_ZSTD` is payload-level only; an inline comment forbids `OPT_SERIALIZER`, so cache values are not handed to PHP `unserialize()` at the phpredis layer.
- **Third-party integrations (`thirdparty/entry.inc.php`, `thirdparty/rank-math.cls.php`, `thirdparty/wcml.cls.php`, `thirdparty/woocommerce.cls.php`):** WCML `_handle_save()` is reachable only through `Admin_Settings::save()` behind nonce + `manage_options`; posted currency codes are `sanitize_text_field`'d then `array_intersect`'d against the WCML allowlist before `update_option`. WooCommerce sale-state purge handler validates each product ID via `wc_get_product()` and only triggers cache invalidation. Rank Math purge handler calls `\RankMath\Sitemap\Cache::invalidate_storage()` with no parameters.
- **Templates & JS (16 TPL files + 3 JS files + `data/const.default.json`):** TPL changes are documentation-link `href` updates to hardcoded `docs.litespeedtech.com` URLs. `assets/js/component.cdn.js` adds a JSX `<a href={litespeed_data['lang']['cdn_file_types_url']}>` — server-side value is a hardcoded literal, React auto-escapes the `href`. `assets/js/js_delay.js` `handler` calls `URL.revokeObjectURL(e2.src)` only when `e2.src.startsWith('blob:')`; `revokeObjectURL` is a non-executing API and the blob URL is created by the plugin itself from the page's own inline `<script>` content. `data/const.default.json` *tightens* defaults (`cache-page_login` flipped `"1"` → `"0"`).

## Demoted candidate (kept here for transparency)

A second candidate finding — *"Arbitrary file write via unbounded local path from cloud response"* — was raised against the same `Optimax::_save_imgs()` flow but was **dropped at FP filtering (confidence 6)**. The premise (no path anchoring) was wrong: `Utility::is_internal_file()` (`utility.cls.php:815-819`) calls `realpath()` and `is_file()`, requiring the target to be an *existing* docroot file. Combined with the hardcoded `.webp`/`.avif` extension (which is non-executable under default Apache/Nginx), the impact collapses to an integrity-only issue (overwriting existing optimized images) — below the severity threshold for this report.
