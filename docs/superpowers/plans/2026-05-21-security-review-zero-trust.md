# Comprehensive Security Review Plan — Zero-Trust Audit of `dev` branch

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a high-signal, low-false-positive security report for the LiteSpeed Cache `dev` branch (v7.9-a27) using a zero-trust review model: every function and file boundary is treated as a trust boundary, and every input crossing such a boundary must be re-validated and re-sanitized regardless of its origin.

**Architecture:** Three-phase pipeline — (1) Context Discovery: map the plugin's security primitives (nonce/capability helpers, escapers, sanitizers, IO sinks). (2) Per-File Zero-Trust Audit: dispatched in parallel, one subagent per modified file, each asked to assume *nothing* about caller trustworthiness. (3) Aggregation & False-Positive Filtering: parallel confidence scoring per finding, keep only findings with confidence ≥ 8, then assemble the final markdown report.

**Tech Stack:** PHP 7.4+ / WordPress plugin (LiteSpeed Cache 7.9-a27), JavaScript (frontend admin + delay loader), React (admin components). Targets the WordPress security model: nonces, `current_user_can()`, `$wpdb->prepare`, `esc_*` family, `sanitize_*` family, `wp_kses`, capability checks on AJAX/REST endpoints.

---

## Threat Model (set before reviewing)

**Trust tiers (lowest to highest):**

1. **Untrusted:** Anonymous HTTP request, `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_SERVER['HTTP_*']`, raw `php://input`, REST request bodies, query params on crawler/CDN endpoints, third-party plugin hooks.
2. **Semi-trusted:** Logged-in non-admin users (subscriber, contributor, customer for WooCommerce). Anything reachable via `admin-ajax.php` or `admin-post.php` without capability check.
3. **Semi-trusted internal:** Values pulled from `wp_options`, transients, object cache, plugin DB tables. These may have been written by an attacker in a prior breach or by a lower-trust path.
4. **Trusted:** Hardcoded constants, plugin source code paths.

**Zero-trust rule applied per the user's directive:** A function MUST NOT assume its caller has already sanitized. Each function that touches a sink (SQL, shell, filesystem path, HTML output, header, `eval`/`include`, `unserialize`, `file_get_contents`, `curl`, regex from input, redirect URL) must validate immediately before the sink.

**Sinks to trace (with WordPress equivalents):**

| Sink | Safe primitive |
|---|---|
| HTML output | `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` |
| JS context | `esc_js`, `wp_json_encode` |
| Attribute | `esc_attr` |
| URL | `esc_url`, `esc_url_raw` (for storage) |
| SQL | `$wpdb->prepare`, `esc_sql` (last resort) |
| Filesystem path | `realpath` + base-dir check, never raw concat with user data |
| Shell | `escapeshellarg` (but should not exist in this plugin) |
| Redirect | `wp_safe_redirect` + allowlist host |
| Deserialization | never `unserialize` on attacker-controllable bytes; use `json_decode` |
| Include/require | never with user input |

**Out-of-scope per skill rules:** DoS, rate limiting, memory exhaustion, dep CVEs, log spoofing, regex DoS, theoretical races, SSRF that only controls path, secrets-on-disk, hardening absence, doc-only files, *.md, GH Actions edge cases. (See Phase 3 filter list.)

---

## File Structure

This plan produces analysis artifacts only — it does not modify plugin code. All artifacts live under `docs/superpowers/plans/security-review-2026-05-21/`.

- Create: `docs/superpowers/plans/security-review-2026-05-21/00-context.md` — security primitives map.
- Create: `docs/superpowers/plans/security-review-2026-05-21/findings-raw.md` — unfiltered findings from per-file audits.
- Create: `docs/superpowers/plans/security-review-2026-05-21/findings-final.md` — final report after FP filtering.
- Read-only (sources of truth):
  - `litespeed-cache.php`
  - `src/*.cls.php` (all 50 class files, with focus on the 30 modified)
  - `thirdparty/*.cls.php`
  - `tpl/**/*.tpl.php`
  - `assets/js/*.js`

---

## Task 1: Capture branch diff and modified-file list

**Files:**
- Create: `docs/superpowers/plans/security-review-2026-05-21/00-diff.patch`
- Create: `docs/superpowers/plans/security-review-2026-05-21/00-files.txt`

- [ ] **Step 1: Determine merge-base with `master`**

Run:
```powershell
git merge-base master dev
```
Expected: a commit SHA. Save as `$MB`.

- [ ] **Step 2: Save full diff to a file (not stdout — diff is ~160KB)**

Run:
```powershell
git diff $MB..dev --no-color > docs/superpowers/plans/security-review-2026-05-21/00-diff.patch
```
Expected: file ≈ 160KB exists.

- [ ] **Step 3: Save the list of modified files**

Run:
```powershell
git diff --name-only $MB..dev > docs/superpowers/plans/security-review-2026-05-21/00-files.txt
```
Expected: ~53 paths.

- [ ] **Step 4: Commit the captured artifacts** *(skip if the user does not want artifacts committed; otherwise:)*

```powershell
git add docs/superpowers/plans/security-review-2026-05-21/00-diff.patch docs/superpowers/plans/security-review-2026-05-21/00-files.txt
git commit -m "chore(sec-review): capture dev-vs-master diff for audit"
```

---

## Task 2: Map the plugin's security primitives (Phase 1 — Context)

**Files:**
- Create: `docs/superpowers/plans/security-review-2026-05-21/00-context.md`

This is the zero-trust baseline. Before judging any code as unsafe, we must know what the plugin *already* provides so we recognize the safe path and any deviation from it.

- [ ] **Step 1: Identify nonce + capability helpers**

Run (Grep tool, not shell):
- Pattern: `check_admin_referer|wp_verify_nonce|wp_create_nonce`, type: `php`, path: `src/`
- Pattern: `current_user_can|user_can`, type: `php`, path: `src/`
- Pattern: `class Router\b|\bRouter::|function verify_`, type: `php`, path: `src/router.cls.php`

Record the canonical capability check used by admin endpoints (likely `manage_options` or a custom cap in `Router`).

- [ ] **Step 2: Identify input-fetch helpers**

Grep for: `\$_GET|\$_POST|\$_REQUEST|\$_COOKIE|\$_SERVER|php://input|file_get_contents\(\s*['\"]php`. Note whether the plugin uses a wrapper (e.g., `Router::get_var`, `Utility::`) or accesses superglobals directly.

- [ ] **Step 3: Identify SQL access pattern**

Grep for: `\$wpdb->(query|get_var|get_row|get_results|prepare|insert|update|delete)`. Note whether `prepare` is used consistently or whether interpolation appears.

- [ ] **Step 4: Identify output helpers**

Grep for: `esc_html|esc_attr|esc_url|esc_js|wp_kses|esc_textarea`. Confirm templates use them.

- [ ] **Step 5: Identify dangerous sinks**

Grep for each, type `php`:
- `unserialize\s*\(`
- `eval\s*\(`
- `\binclude\s*\(|\binclude_once\s*\(|\brequire\s*\(|\brequire_once\s*\(` *(focus only on dynamic args)*
- `preg_replace\s*\(\s*['\"][^'\"]*e[^'\"]*['\"]` *(deprecated /e modifier)*
- `system\s*\(|exec\s*\(|shell_exec\s*\(|passthru\s*\(|popen\s*\(|proc_open\s*\(`
- `file_put_contents\s*\(|fopen\s*\(|unlink\s*\(|rename\s*\(|mkdir\s*\(|rmdir\s*\(`
- `wp_remote_(get|post|request)\s*\(`
- `curl_init|curl_exec`

For each hit, note: (a) is the first arg dynamic? (b) does any modified file in this PR call into it?

- [ ] **Step 6: Document findings in `00-context.md`**

Write a one-screen map: helper names, sink locations, and known invariants (e.g., "all admin POST routes go through `Router::verify_action()` which calls `check_admin_referer`"). This becomes the **trust contract** referenced by every later finding — deviations are evidence.

---

## Task 3: Per-file zero-trust audit (Phase 2 — dispatched in parallel)

**Files:**
- Create: `docs/superpowers/plans/security-review-2026-05-21/findings-raw.md`

Use the `superpowers:dispatching-parallel-agents` skill. The 53 modified files split into 7 batches by surface area. Each batch is one subagent. Batches are independent — dispatch all in one message.

**Batching (designed so each batch fits in one subagent's context):**

| Batch | Files | Surface |
|---|---|---|
| A — Routing & REST | `src/router.cls.php`, `src/rest.cls.php`, `src/core.cls.php`, `litespeed-cache.php`, `autoload.php` | Entry points, capability + nonce checks |
| B — Admin UI | `src/admin-display.cls.php`, `src/admin-settings.cls.php`, `src/gui.cls.php` | Settings save, options output |
| C — Optimizers | `src/optimax.cls.php`, `src/optimizer.cls.php`, `src/css.cls.php`, `src/ucss.cls.php`, `src/vpi.cls.php`, `src/placeholder.cls.php` | OptiMax (new), CSS/JS rewrite, QC service lib |
| D — Cloud & Tasks | `src/cloud-queue-svc.cls.php`, `src/task.cls.php`, `src/health.cls.php`, `src/crawler.cls.php`, `src/cdn.cls.php`, `src/cdn/cloudflare.cls.php` | Outbound HTTP, callbacks, scheduled tasks |
| E — Cache / Data layer | `src/cache?`, `src/purge.cls.php`, `src/data.cls.php`, `src/file.cls.php`, `src/object-cache.cls.php`, `src/object.lib.php`, `src/base.cls.php`, `src/doc.cls.php` | Filesystem + DB + serialization |
| F — Third-party integrations | `thirdparty/entry.inc.php`, `thirdparty/rank-math.cls.php`, `thirdparty/wcml.cls.php`, `thirdparty/woocommerce.cls.php` | Hook handlers from other plugins' data |
| G — Templates & JS | All `tpl/**/*.tpl.php` (14 files), `assets/js/component.cdn.js`, `assets/js/js_delay.js`, `assets/js/js_delay.min.js`, `data/const.default.json`, `readme.txt` | Output escaping, DOM XSS, blob/URL handling |

- [ ] **Step 1: Draft the per-file subagent prompt template**

Each subagent gets this prompt (substitute `{BATCH_NAME}` and `{FILE_LIST}`):

> You are a senior application security engineer reviewing the LiteSpeed Cache WordPress plugin. Apply **zero-trust**: assume the function's caller has done nothing — re-validate every input crossing a function boundary.
>
> **Scope of changes (review only what changed):** read the diff at `docs/superpowers/plans/security-review-2026-05-21/00-diff.patch` and limit yourself to the hunks in: {FILE_LIST}.
>
> **Security primitives reference:** read `docs/superpowers/plans/security-review-2026-05-21/00-context.md` — that's the trust contract; deviations are findings.
>
> **What to flag (HIGH or MEDIUM only, ≥ 0.8 confidence):**
> - SQL injection: any `$wpdb->query/get_*` whose query string is built from a variable not previously passed through `prepare`/`esc_sql`/integer cast.
> - XSS / template injection: any `echo` / `<?= ?>` / inline JS interpolation that emits a variable not wrapped in `esc_*`.
> - Path traversal / arbitrary file: any `file_get_contents`/`file_put_contents`/`unlink`/`include` whose path concatenates an attacker-influenced value without `realpath` + base-dir check.
> - Unsafe deserialization: `unserialize` on values from HTTP, options, or transients that could be written by a lower-trust path.
> - Capability/nonce gap: admin POST/AJAX/REST route lacking `check_admin_referer` + `current_user_can` (compare against the canonical pattern from `00-context.md`).
> - SSRF where host or protocol is attacker-controlled (path-only is out of scope).
> - Open redirect via `wp_redirect` (NOT `wp_safe_redirect`) of an attacker-controlled URL.
> - Hardcoded credentials/keys, weak crypto on security-relevant data (HMAC verification of cloud callbacks etc.).
>
> **Hard exclusions** (do not report): DoS, rate limit, memory/CPU, secrets-on-disk, log spoofing, regex DoS, theoretical races, dep CVEs, doc files, tabnabbing/XS-Leaks/prototype pollution/open-redirect-path-only, lack of hardening, client-side JS auth, GH Actions, *.md.
>
> **React/JS note:** Don't flag XSS in React JSX unless it uses `dangerouslySetInnerHTML` or assigns to `innerHTML`/`document.write`. For `js_delay.js`, the new code calls `URL.revokeObjectURL(e2.src)` — verify `e2.src` cannot be cross-origin influenced in a way that causes execution of attacker content. The blob URL is created by the same plugin from script source; if not, flag it.
>
> **Output format per finding:**
> ```
> ### Finding: <category>: `<file>:<line>`
> - Severity: HIGH | MEDIUM
> - Confidence: 0.8–1.0
> - Description: <what's wrong, in 1–3 sentences>
> - Exploit Scenario: <concrete attacker steps; identify trust tier required>
> - Trust boundary crossed: <function A → function B, what wasn't validated>
> - Recommendation: <specific safe primitive>
> ```
>
> Use Read and Grep only. Do not run commands. Do not edit files. When done, append your findings to `docs/superpowers/plans/security-review-2026-05-21/findings-raw.md` under a heading `## Batch {BATCH_NAME}`.

- [ ] **Step 2: Dispatch all 7 batches in a single message (parallel)**

Use 7 `Agent` tool calls (subagent_type=`general-purpose`) in one message — one per batch — each with the prompt above and the matching file list.

- [ ] **Step 3: Confirm `findings-raw.md` has 7 sections**

Read the file. If any batch returned no findings, it should still have its heading with "No findings."

---

## Task 4: Targeted zero-trust spot-checks (in-session, not delegated)

**Files:**
- Modify: `docs/superpowers/plans/security-review-2026-05-21/findings-raw.md`

Some patterns are easier to confirm with a quick Grep across the whole repo than via per-file review. Do these in the main session.

- [ ] **Step 1: Find every admin-AJAX handler added or modified in this PR**

Grep `wp_ajax_` and `admin_post_` across the changed files; cross-reference with the diff. For each, check the handler body for `check_admin_referer` and `current_user_can` — record any gaps in `findings-raw.md` under `## Spot-check: AJAX auth`.

- [ ] **Step 2: Find every `$wpdb->query` / `$wpdb->get_*` modified in this PR**

Grep `\$wpdb->(query|get_var|get_row|get_results|get_col)` in changed files. For each, confirm the SQL uses `$wpdb->prepare` or hardcoded literals. Record in `## Spot-check: SQL`.

- [ ] **Step 3: Find every `unserialize` and `file_get_contents` of dynamic paths in changed files**

Grep `unserialize\s*\(|maybe_unserialize\s*\(`, and `file_get_contents\s*\(`, `include\s*\(`, `require\s*\(` (with dynamic arg) in changed files. Record in `## Spot-check: deserialization & inclusion`.

- [ ] **Step 4: Inspect `optimax.cls.php` end-to-end** (currently open in user's IDE — explicit user signal)

Read the file fully. For each public method, ask: which trust tier can reach it? Does it re-validate the inputs it receives? Record findings in `## Spot-check: optimax`.

- [ ] **Step 5: Inspect `cloud-queue-svc.cls.php` callback verification**

The commit log says "Uniformed CCSS QC svc lib." Cloud callbacks must verify HMAC/signature before acting on the payload. Confirm the callback path validates the signature *before* deserializing or applying the payload. If signature check happens after `unserialize` or after `update_option`, flag HIGH. Record in `## Spot-check: cloud callback`.

- [ ] **Step 6: Inspect the `data/const.default.json` change**

This is a JSON config. If new keys map into capability/role bypasses or default-permissive states, flag. Otherwise note "no security impact."

---

## Task 5: False-positive filtering (Phase 3 — parallel, one subagent per finding)

**Files:**
- Create: `docs/superpowers/plans/security-review-2026-05-21/findings-final.md`

For every finding in `findings-raw.md`, dispatch a fresh subagent to score confidence. **One subagent per finding**, all in parallel.

- [ ] **Step 1: Enumerate findings**

Parse `findings-raw.md`. Build an in-session list of N findings, each labeled F1 … FN.

- [ ] **Step 2: Dispatch N parallel filter subagents (one message)**

Each subagent prompt:

> You are filtering a candidate security finding for false positives. The finding is:
>
> ```
> {PASTE FINDING VERBATIM}
> ```
>
> Do this:
> 1. Read the cited file and lines.
> 2. Trace whether attacker-controlled input can actually reach the sink described.
> 3. Apply ALL hard exclusions: DoS, rate-limit, secrets-on-disk, log spoofing, regex DoS, theoretical races, dep CVEs, *.md/docs, GH Actions, client-side JS auth, tabnabbing, XS-Leaks, prototype pollution, open-redirect-path-only, SSRF-path-only, regex injection, AI prompt injection, lack of hardening, lack of audit logs, memory safety in memory-safe languages, test-only files. If the finding matches any of these, return CONFIDENCE: 0 and reason "hard exclusion: <which one>".
> 4. Apply the precedents:
>    - UUIDs are unguessable.
>    - Env vars / CLI flags are trusted.
>    - React/Angular templates are safe absent `dangerouslySetInnerHTML` etc.
>    - Client-side JS doesn't need auth checks.
>    - Logging non-PII is safe.
> 5. Score:
>    - 9–10: certain, concrete attacker path identified.
>    - 8: clear vulnerability pattern, attacker path plausible with stated prerequisites.
>    - ≤ 7: do not keep.
>
> Output exactly:
> ```
> CONFIDENCE: <0-10>
> JUSTIFICATION: <2-3 sentences>
> KEEP: <YES|NO>
> ```
> KEEP=YES only if CONFIDENCE ≥ 8 AND no hard exclusion applies.

- [ ] **Step 3: Collect verdicts**

For each finding, record subagent verdict. Discard any with CONFIDENCE < 8 or KEEP=NO.

- [ ] **Step 4: Resolve disagreements**

If a finding was demoted, re-read the cited code in the main session to confirm the demotion is sound (subagents can be wrong). If the main-session read confirms the vulnerability is real, keep it and note "main-session override."

---

## Task 6: Assemble the final report

**Files:**
- Create: `docs/superpowers/plans/security-review-2026-05-21/findings-final.md`

- [ ] **Step 1: Sort surviving findings by Severity (HIGH first), then Confidence desc**

- [ ] **Step 2: Write report in the required format**

Use exactly the format from the security-review skill prompt:

```markdown
# Security Review — LiteSpeed Cache `dev` (v7.9-a27)

_Reviewed against `master` at <merge-base SHA>, on 2026-05-21._

**Summary:** <H high, M medium> findings retained after zero-trust audit and FP filter (≥ 0.8 confidence).

# Vuln 1: <category>: `<file>:<line>`
- Severity: HIGH
- Description: ...
- Exploit Scenario: ...
- Recommendation: ...

# Vuln 2: ...
```

- [ ] **Step 3: If zero findings survive, say so explicitly**

Body: "No findings at HIGH or MEDIUM severity with confidence ≥ 0.8 were retained for this branch's changes. The audit covered <N> modified files across <7> batches plus 6 targeted spot-checks; the per-file methodology and primitives map are in `00-context.md` and `findings-raw.md`." A clean report with a documented method is more useful than an inflated one.

- [ ] **Step 4: Deliver the final markdown to the user as the only content of the reply**

Per the skill's instruction: "Your final reply must contain the markdown report and nothing else." Copy `findings-final.md` into the chat reply verbatim.

---

## Self-Review Checklist (run after writing the plan, before executing)

- [ ] Every modified file in the diff appears in exactly one batch (Task 3) — no gaps, no double-coverage.
- [ ] The zero-trust definition is unambiguous: "each function re-validates inputs at sinks regardless of caller."
- [ ] The hard-exclusion list is included verbatim in both the auditor prompt (Task 3.1) and the filter prompt (Task 5.2).
- [ ] The confidence threshold (≥ 0.8 / score ≥ 8) is stated in both phases.
- [ ] No step says "TBD", "as appropriate", "etc." — every action is concrete.
- [ ] The final reply is constrained to markdown only (no commentary, no tool-call narration), per skill rules.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-21-security-review-zero-trust.md`. Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch fresh subagents for the per-file audits (Task 3) and the per-finding filters (Task 5), running them in parallel and reviewing between phases. Best fit for this plan because Tasks 3 and 5 are embarrassingly parallel.
2. **Inline Execution** — I run everything in this session sequentially. Slower; risks context bloat from 7 batch reads.

Which approach?
