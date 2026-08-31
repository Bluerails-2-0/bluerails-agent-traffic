# Review — BLUE-1534: wire in auto-update checker (GitHub Releases)

Implemented-By: Claude session `session_014kENFmdTwdaz9CBmxzHaPy`, branch `BLUE-1534-auto-update-checker`, commit `7fc1968`
Independent-Reviewer: Claude Sonnet 5, independent review agent, 2026-08-31 (fresh clone, no shared context with the implementer)
Review-Status: INDEPENDENT
Reviewed-Commit: `7fc1968f95e1e16e09ed344eb67e92775e878eaf` (base `main` = `616bbea807f85f36e7d474158775a2abb087e13e`)
Verdict: **APPROVE** (one Important, non-blocking hardening gap; ships the intended mechanism correctly)

## Overview

Vendors `YahnisElsts/plugin-update-checker` v5.7 unmodified, initializes it against this
repo's own GitHub Releases API on `plugins_loaded`, filters release assets to the
stable-named zip, and adds the `Update URI` header + a 1.3.0→1.3.1 version bump. All
ground-truth claims below were independently re-derived from a fresh clone, `gh api`,
`php -l`, the existing test suite, and PUC's own upstream v5.7 tag — not taken from the
commit message or the prior ticket review.

## Lens Coverage

| Lens | Verdict | Why / Findings |
| --- | --- | --- |
| Tech | APPLIES | `bluerails-agent-traffic.php:47-64` (init block), `includes/plugin-update-checker/Puc/v5p7/Vcs/{GitHubApi.php,ReleaseAssetSupport.php}` (asset-filter trace). Real library, byte-identical vendoring, correct current v5 API shape, correct hook, correct asset-filter regex — with one real hardening gap (silent fallback to raw source zip). See `## Tech findings`. |
| Product Manager | APPLIES | `.claude/ticket-reviews/BLUE-1534.md` (bluerails402, pre-build review) cross-checked against `bluerails-agent-traffic.php` v1.3.1 + `gh api .../releases` v1.3.0. Matches the pre-build ticket review's verified JTBD; the one MUST-FIX from that review (cut a real v1.3.0 release to test against) was actually done. See `## PM findings`. |
| Product Designer | SKIP | No new UI — the "Update available" row is WP core's own native `plugins.php` chrome, unchanged by this diff. Same conclusion the pre-build review reached, independently re-confirmed: nothing in this diff renders anything. |
| Persona | APPLIES | https://github.com/Bluerails-2-0/bluerails-agent-traffic/releases/tag/v1.3.0 (real, rendered, fetched-live release page showing the exact two real assets — `bluerails-agent-traffic-1.3.0.zip` and `bluerails-agent-traffic.zip` — the filter regex chooses between). WP-admin/site-owner persona walked against that real page plus the code trace; WP core's own `plugins.php` chrome itself was not screenshotted since no live WP install/wp-admin exists in this review environment — same gap the pre-build ticket review disclosed under its own Halt-licence section. See `## Persona findings`. |
| Cost/Perf | APPLIES | `includes/plugin-update-checker/Puc/v5p7/Scheduler.php:11` (`$checkPeriod = 12`). One outbound GET to `api.github.com` per WP-cron cycle (default 12h), confirmed non-synchronous — constructing the checker only registers hooks, it does not call the network on every page load. See `## Tech findings` and `HOT-PATH-COST` below. |

## Depth interrogation

**PM — IN-CONTEXT-REVIEW:** No new rendered page exists (this is backend library-wiring, not a UI change), so per the depth gate's own MACHINE/CONTRACT carve-out, the applicable artifact is the existing regression suite plus a direct code trace: `tests/test-bot-signature-ordering.php` re-run (29/29, unaffected — confirms no regression to the plugin's core detection surface from this change) and the live-API trace in ground-truth items 1-4 above, which drove the actual mounted mechanism (a real `gh api` call against the real target repo, and a real trace through the real, unmodified PUC source) rather than reading prose about it.

**PM — SIBLING-METRIC-COHERENCE:** N/A — this diff displays no metrics/numbers to a user; it introduces no dashboard, count, or rate.

**PM — DECISION-CHALLENGE:** The decision this diff embodies: vendor + wire PUC now (the systemic fix) rather than the cheaper alternative the ticket review's own brief asked about — a one-time manual zip reinstall on the one live site (Schwitzers) with the systemic fix ticketed separately. `.claude/ticket-reviews/BLUE-1534.md`'s PM findings explicitly compared both and concluded the manual reinstall is real, cheap, same-day parallel ops work but "not a substitute... leaves the exact same gap open for the next release." This diff takes the systemic-fix direction, matching both the ticket's stated intent and that independent comparison — not a narrower patch that would have left the underlying gap unaddressed.

**PM — BASELINE-ANCHOR:** N/A — not a bluerails402 domain product surface (no reservations/rate-calendar/dashboard). This is `bluerails-agent-traffic`, a standalone WordPress plugin repo with no external best-in-class scorecard defined for "plugin auto-update wiring."

**PM — ALL-STATES-COVERAGE:** N/A — no UI states are rendered by this diff. The states that exist (update-available / up-to-date / check-failed) are entirely WP core's own pre-existing, unmodified handling.

**PM — PERSONA-JTBD-CHECK:** N/A — single persona applies (WP-admin/site-owner), not bluerails402's operator-console multi-persona set (front-desk/revenue/housekeeping/night-audit/GM). This is a different product entirely — a self-hosted WordPress plugin, not the Discovery/CM console — so that persona roster doesn't apply here.

**PM — AGENTIC-OPPORTUNITY-CHECK:** N/A — not a Bluerails agentic-commerce/AI-visibility product UI and displays no data; it is WordPress-plugin release-distribution infrastructure.

**PM — PRODUCT-FRAME:** N/A — no rendered frame is introduced or changed by this diff. The eventual surface stays WP core's own `plugins.php` chrome; this diff does not create or mislabel any Bluerails-branded surface.

**PM — JTBD-OUTCOME:** "When a `bluerails-agent-traffic` release is cut, I want every already-installed WP site to see it automatically, so I can stop manually re-uploading a zip to each customer site for every release." The mechanism is delivered and code-verified (ground-truth items 1-5). The "so I can" outcome — an operator actually skipping a manual reinstall on a real future release — is not yet observed live; see `## Outcome Delivery` below for the honest split.

**PM — OUTCOME-ARTIFACT:** The terminal artifact is a real installed site's WP-admin "Update available" notice plus a successfully-downloaded, correctly-packaged plugin zip. Concrete run evidence obtained this session (not an intermediate "success:true"): `gh api repos/Bluerails-2-0/bluerails-agent-traffic/releases` returned the actual, live v1.3.0 release object with a real `bluerails-agent-traffic.zip` asset — `id: 537776596`, `size: 32733`, `digest: sha256:55c02a4dd7895599ee37f45a266b0bf30c29a194984547559fd6a00e027ab953`, `browser_download_url: https://github.com/Bluerails-2-0/bluerails-agent-traffic/releases/download/v1.3.0/bluerails-agent-traffic.zip` — i.e. the exact real object PUC's filter (ground-truth item 4) would select and WP core would download, confirmed to exist today, not merely asserted. What was NOT run end-to-end: an actual WP-core download-and-install cycle against that URL on a live site (no wp-admin access in this environment).

**PM — PROD-REALITY:** If merged and a `v1.3.1` release is later cut carrying the stable-named asset (matching v1.3.0's own pattern, ground-truth item 1), a real site running 1.3.0 would receive a genuine "Update available" notice pointing at a correctly-packaged zip — this is what the code-level trace (ground-truth items 3-4) shows would happen, though it has not been watched live end-to-end. If a future release omits that asset (the Important finding), the site would instead silently be offered GitHub's raw, malformed source archive — a real gap between promise and delivery that exists today, not hypothetically.

**PM — PRESS-RELEASE-CLAIM:** "Bluerails Agent Traffic Capture now updates itself — no more manual zip re-uploads for every release." Truthful based on code-level verification against the real upstream library and the real GitHub Releases API; not yet truthful *as observed live*, since no real update cycle has been watched end-to-end against an installed site in this review.

**PM — VERTICAL-SLICE:** Wired end-to-end at the code level: vendored library (`includes/plugin-update-checker/`, byte-verified) → init call (`bluerails-agent-traffic.php:47-64`) → real GitHub Releases API (ground-truth item 1) → asset-filter regex (ground-truth item 4, verified against the real v1.3.0 asset names) → WP core's own update transient (unmodified, upstream). Last WIRED-and-verified step: the regex would correctly select exactly one of the two real published assets. First step not exercised in this review: WP core's live "Update available" render and the one-click update flow against a real install.

**PM — PERSONA-JOURNEY:** Site-owner (Schwitzers-style operator): sees the native "Update available" row → clicks "Update now" → WP core requests the filtered release asset → plugin reactivates at the new version. Every step through "which asset gets downloaded" is code-verified (ground-truth items 1-4); the final render-and-click step was not driven live — same gap the pre-build ticket review already flagged as unverifiable without wp-admin access.

**Tech — INVARIANT-ENFORCER:** Invariant: "the update package offered to any site is always the intentionally-built, stable-named plugin zip, never GitHub's raw source archive." Enforcer: the `enableReleaseAssets()` regex filter alone (`bluerails-agent-traffic.php:62`) — and its current configuration fails OPEN (falls back to the raw source zip on no match) rather than fail-closed, per `Vcs/ReleaseAssetSupport.php`'s default `$releaseAssetPreference = Api::PREFER_RELEASE_ASSETS`. This is the single most load-bearing invariant in this diff, and its current enforcer is the flagged Important gap above.

**Tech — PROD-ENTRY-TRACE:** Real entry point traced end-to-end: `plugins_loaded` (WP core's own bootstrap hook, fires on every real request once deployed, `bluerails-agent-traffic.php:64`) → `bluerails_agent_traffic_init_update_checker()` → `PucFactory::buildUpdateChecker()` → PUC's own `Scheduler` (`Puc/v5p7/Scheduler.php`) registers a real WP-cron event → cron fires → `wp_remote_get` against the real `api.github.com`. `add_action('plugins_loaded', ...)` is unconditional — not gated behind a flag, admin check, or test-only branch — so this runs in prod on every real request the moment this ships, not merely present in the tree.

**Tech — PARITY-CHECK:** The one dual-source pair that must agree here: the plugin's own declared version (`Version:` header + `BLUERAILS_AGENT_TRAFFIC_VERSION`, both `1.3.1`) vs. the GitHub tag that will eventually be cut for it. Confirmed no current mismatch (ground-truth item 5): `1.3.1` is strictly ahead of the one real published release (`v1.3.0`, targeting this branch's exact base commit). No DB schema, TS union, or other dual-source pair exists in this diff — the plugin has no database schema per its own `README.md`.

**Tech — FAILURE-MODE-ENUM:** State failure: a release is cut without the stable-named asset present, so `enableReleaseAssets()`'s regex matches nothing and PUC falls back to GitHub's raw source-zip as the "update," which WP core would then unpack and install — effect is a broken/malformed live update; detection today is NONE (this is the Important finding above; recommended detection/prevention is switching to `Api::REQUIRE_RELEASE_ASSETS` so it fails closed instead). Scale failure: the unauthenticated GitHub API rate limit (60 req/hr per source IP, confirmed against PUC's own auth model in ground-truth item 7) is exceeded once enough installs share a hosting IP — effect is update checks quietly stop finding anything; detection today is NONE, no operator-visible warning surfaces (flagged should-fix in the pre-build ticket review, still unaddressed and undocumented in this diff). Partial-failure/trust: `api.github.com` is unreachable, times out, or returns an error — traced PUC's own unmodified `is_wp_error($tags)`-style guards throughout `Vcs/GitHubApi.php` — effect is the check cycle is simply skipped; detection/recovery is automatic retry on the next 12h cron tick, safe, no user-facing error surfaced incorrectly. Time failure: version comparison (`1.3.1` vs. published tags) is correct today (ground-truth item 5) and self-correcting on every future release, since PUC compares semantic versions read live from the API rather than any hardcoded state this diff owns, so there is no drift mode here to detect.

**Tech — SIDE-EFFECT-COMPLETION:** N/A in the money/booking sense — no irreversible side effect is triggered by this diff itself. The only real mutating action (overwriting plugin files) happens only when a site owner clicks "Update now," entirely inside WP core's own unmodified upgrader, which already keeps a backup during its native flow. Nothing here writes a row, moves money, or sends a message.

**Tech — STATE-MACHINE-COMPLETENESS:** N/A — no enum/state values, no DB columns, no response-shape change. The only "state" touched is the plugin version number, already covered under PARITY-CHECK above.

**Tech — IDEMPOTENCY-REPLAY:** The relevant replay case is the WP-cron update-check firing repeatedly (every 12h) or a manual "Check Now." Traced: PUC's `Scheduler`/`StateStore` (upstream, unmodified) cache last-known metadata and only re-fetch on schedule or explicit trigger; re-checking a stateless public GET is naturally idempotent, and "applying" an update is a separate, WP-core-owned, user-initiated action this checker never performs automatically — no double-apply path exists.

**Tech — INTEGRATION-REALITY:** This diff does touch a real external-service call shape (GitHub's REST API, via PUC's `Vcs\GitHubApi`), but it is upstream, unmodified, widely-used third-party library code (2,562-star library, confirmed via `gh api` in the linked ticket review), not a Bluerails-authored adapter under `blue402-web/settlement` or `visibility-web/app/services/llm`. No mocked unit test was added; verification here was a direct trace against the real upstream v5.7 source (byte-identical, ground-truth item 2) plus a real, live `gh api` call against the actual target repo's real Releases endpoint (ground-truth item 1) — i.e. the integration was exercised against the real live API during this review, not mocked. This is a read-only, unauthenticated, public-metadata GET with no money/booking/PII path, so the money-adapter stakes this marker targets (Stripe/x402/DIRS21-style silent-failure risk) don't apply at the same severity; the one failure mode with real product consequence (fail-open on a missing release asset) is already named above and mapped to a concrete one-line fix.

**Cost/Perf — HOT-PATH-COST:** New call: `wp_remote_get` to `api.github.com`, executed only inside PUC's own WP-cron callback — not inline in `plugins_loaded` itself, which only registers the checker object and its cron hook (constructor traced, ground-truth item 7: no synchronous network call). Frequency: once per 12h per site by default (`Puc/v5p7/Scheduler.php:11`, `$checkPeriod = 12`), or on-demand via an explicit admin "Check for updates" click. Does not block page render on the front end or in wp-admin. No LLM call, so model-tier is N/A. Confirmed by direct code trace, not a mocked-green test claim.

## Ground-truth verification (independently re-derived)

1. **`v1.3.0` release — CONFIRMED real and published**, not just a version-header bump.
   `gh api repos/Bluerails-2-0/bluerails-agent-traffic/releases` shows `tag_name: v1.3.0`,
   `draft: false`, `published_at: 2026-08-31T09:46:37Z`, `target_commitish: 616bbea...`
   (exactly this branch's base commit). Its asset list carries **both**
   `bluerails-agent-traffic-1.3.0.zip` (versioned) and `bluerails-agent-traffic.zip`
   (stable-named), same sha256 digest for both — satisfying README's own "Cutting a
   release" checklist. This was the pre-build ticket review's one MUST-FIX
   (`.claude/ticket-reviews/BLUE-1534.md` in `bluerails402`, "the ticket's own Testing
   step names a release that does not exist yet") — it was actually resolved before this
   branch's code was written, not just asserted.

2. **PUC v5.7 vendoring — CONFIRMED genuine, not a stub.** Diffed the vendored
   `includes/plugin-update-checker/` tree against a fresh `git clone --depth 1 --branch
   v5.7 https://github.com/YahnisElsts/plugin-update-checker`. The only files present
   upstream and absent from the vendored copy are `.git*`, `.editorconfig`,
   `build/bump-version.php`, `examples/*.json`, and `phpcs.xml` — all dev/meta files, not
   runtime code. Spot-checked 5 files byte-for-byte (`diff`, zero output): `UpdateChecker.php`
   (1181 lines), `Vcs/GitHubApi.php`, `Vcs/ReleaseAssetSupport.php`, `PucFactory.php`,
   `Autoloader.php` — all identical to upstream. `plugin-update-checker.php`'s own header
   comment ("Plugin Update Checker Library 5.7") matches upstream verbatim.

3. **`PucFactory::buildUpdateChecker(...)` call shape — CONFIRMED current v5 API**, not
   stale. Fetched PUC's own upstream `README.md` (same v5.7 clone) — its "GitHub
   Integration" section's example is:
   ```php
   use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
   $myUpdateChecker = PucFactory::buildUpdateChecker(
       'https://github.com/user-name/repo-name/', __FILE__, 'unique-plugin-or-theme-slug'
   );
   ```
   `bluerails-agent-traffic.php` calls `\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker('https://github.com/Bluerails-2-0/bluerails-agent-traffic', BLUERAILS_AGENT_TRAFFIC_FILE, 'bluerails-agent-traffic')` — matches exactly (fully-qualified call instead of a `use` import is semantically identical). Hook choice (`plugins_loaded`) matches PUC's own explicit recommendation: "It's recommended to do this either during the `plugins_loaded` action or outside of any hooks... If you do it only during an `admin_*` action, updates will not be visible to a wide variety of WordPress management tools."

4. **`enableReleaseAssets('/^bluerails-agent-traffic\.zip$/')` — CONFIRMED correctly
   targets the stable-named asset, not the versioned one or GitHub's auto-zip.** Traced
   `Vcs/GitHubApi.php:382` (`getFilterableAssetName`) → returns `$releaseAsset->name` (the
   actual GitHub release-asset filename) → `Vcs/ReleaseAssetSupport.php`'s
   `matchesAssetFilter()` runs `preg_match($this->assetFilterRegex, $releaseAsset->name)`.
   The regex is anchored (`^...$`), so it matches `bluerails-agent-traffic.zip` and
   correctly rejects `bluerails-agent-traffic-1.3.0.zip` (an unanchored or suffix-only
   regex would have matched both). Verified against the real v1.3.0 release's actual two
   asset names from ground-truth item 1 — the regex would select exactly one of them.

5. **`Update URI` header and version `1.3.1` — CONFIRMED.** `bluerails-agent-traffic.php`
   docblock carries `Update URI: https://github.com/Bluerails-2-0/bluerails-agent-traffic`;
   `Version:` and `BLUERAILS_AGENT_TRAFFIC_VERSION` are `1.3.1`, `readme.txt`'s `Stable
   tag:` is `1.3.1`. Confirmed no collision: `main` at this branch's base (`616bbea`,
   already merged as BLUE-1527) already carried `1.3.0` in both places, and the real
   published `v1.3.0` GitHub Release (item 1) targets that same `616bbea` commit — so
   `1.3.1` is strictly newer than the one already-published release, and cutting a future
   `v1.3.1` release will not overwrite or collide with `v1.3.0`'s assets.

6. **`php -l` — CONFIRMED clean** on `bluerails-agent-traffic.php` and every vendored
   `.php` file (all report "No syntax errors detected"). **Test suite — CONFIRMED
   29/29 passing**, unaffected by this change (`php tests/test-bot-signature-ordering.php`
   → `29 case(s), 0 failure(s)`); expected, since PUC is orthogonal to bot-signature
   matching and the diff touches no matcher code.

7. **Credential/phone-home review — CONFIRMED clean.** `setAuthentication()` is optional
   in PUC and is never called in this diff; `GitHubApi::$accessToken` defaults to `null`.
   The target repo is confirmed public (prior ticket review's `gh api` check,
   `"visibility": "public"`), so the update checker's `wp_remote_get` calls hit
   `api.github.com`'s public, unauthenticated REST API with no secret embedded anywhere
   in the vendored code or the init call. Constructing `UpdateChecker` in `__construct()`
   only registers WordPress hooks/filters — no synchronous network call — the actual GET
   only fires on PUC's own WP-cron schedule (default every 12h) or an explicit "Check for
   updates" click, not on every page load. No new credential surface, no telemetry beyond
   the intended update-check itself.

## Tech findings

- **Important — silent fallback to GitHub's raw, unprocessed source zip if a future
  release omits the stable-named asset.** `enableReleaseAssets($regex)` is called with
  only the regex argument, so `$releaseAssetPreference` defaults to
  `Api::PREFER_RELEASE_ASSETS` (confirmed in `Vcs/ReleaseAssetSupport.php`). Traced the
  no-match path in `Vcs/GitHubApi.php`: when `PREFER_RELEASE_ASSETS` is set (not
  `REQUIRE_RELEASE_ASSETS`) and no asset matches the filter, PUC falls back to GitHub's
  auto-generated `zipball_url` — an unprocessed git-archive of the tag, containing dev
  files (`REVIEW-*.md`, `.gitignore`, etc.) and a top-level directory name that does not
  match the installed plugin slug. The README's own "Cutting a release" checklist already
  documents this exact human-error mode ("Every release's assets MUST include a copy of
  the zip under that exact stable name... or the dashboard link silently breaks") for the
  *manual-download* path — but the same mistake on a release now also feeds the
  *auto-update* path, and would push a malformed package to every subscribed site's
  one-click "Update now" instead of failing loudly. Fix: pass
  `\YahnisElsts\PluginUpdateChecker\v5\Vcs\Api::REQUIRE_RELEASE_ASSETS` as the second
  argument to `enableReleaseAssets()`, so a release missing the stable asset simply shows
  no update (safe) instead of offering a broken one (unsafe). One-line change, not
  blocking — no release has yet omitted the asset — but should be fixed before this
  becomes load-bearing for a real customer site.
- **Should-fix from the pre-build ticket review, silently dropped without documentation.**
  `.claude/ticket-reviews/BLUE-1534.md` (bluerails402) flagged the unauthenticated GitHub
  API rate limit (60 req/hr per source IP) as a should-fix and asked the ticket to
  "decide now whether to wire a PAT via `setAuthentication()`... or explicitly defer as a
  known scaling limit." Neither happened: no `setAuthentication()` call, and no comment,
  README note, or commit-message line records this as a deliberate, accepted deferral.
  For the current single-site footprint this is a non-issue (well under 60 req/12h), but
  per this repo's own established convention (BLUE-1527's PR body explicitly documents
  every should-fix item it did or didn't fold in), a one-line note or a tracked follow-up
  ticket would close the loop rather than leaving a reviewed-and-flagged item to silently
  vanish between ticket review and shipped code.
- Trailing-slash-free repo URL (`'https://github.com/Bluerails-2-0/bluerails-agent-traffic'`
  vs. upstream's example with a trailing slash) — traced `GitHubApi::__construct()`
  (`wp_parse_url($repositoryUrl, PHP_URL_PATH)`); path parsing is slash-tolerant. Not a
  defect.
- Vendoring is consistent with this repo's existing no-build-step convention
  (`README.md`: "No build step, no Composer, no npm"); PUC's basic non-Composer usage
  matches.

## PM findings

- The JTBD this ticket exists for (merged work — including BLUE-1527, already 2 tickets
  ahead of the one live install — reaching a real customer site with zero mechanism
  today) was independently corroborated by the prior ticket review and is unaffected by
  anything in this diff; this review re-confirms the mechanism that closes that gap is
  correctly wired, not merely described.
- Scope matches exactly what the ticket review approved: vendor PUC, wire the init block,
  add `Update URI`, bump to 1.3.1. No scope creep (no WP.org-submission work bundled, no
  unrelated plugin changes).

## Persona findings

Re-walked the WP-admin/site-owner persona against the actual shipped mechanism (not the
ticket's stated intent): once a `v1.3.1`+ release is cut with the stable-named asset
present, PUC populates WP core's native update transient the same way any WP.org plugin
does, and the site owner sees the identical "Update available" / "Update now" row and
one-click flow already familiar from every other installed plugin — no new trust UI, no
self-hosted-source warning. This matches the pre-build review's persona finding and this
review found nothing in the actual code that would introduce a different UX than what was
promised (e.g., no custom notice, no extra confirmation step was added that would diverge
from native WP behavior).

## What's Done Well

- The vendoring is genuinely faithful — not a common shortcut (a stub, a trimmed subset,
  or a hand-edited copy) — verified byte-for-byte against the real upstream tag rather
  than trusted from the commit message.
- The asset-filter regex is anchored correctly on the first attempt, correctly
  distinguishing the stable and versioned zip names — an easy place to get an unanchored
  regex wrong (e.g. `/bluerails-agent-traffic\.zip/` would also match the versioned name
  as a substring) and it wasn't.
- The one MUST-FIX from the pre-build ticket review (cut a real `v1.3.0` release before
  claiming a release exists to test against) was actually done, not just re-asserted —
  confirmed independently via `gh api`, not trusted from the ticket text.

## Outcome Delivery

PARTIAL: the auto-update MECHANISM is delivered and live as-merged — evidence: `bluerails-agent-traffic.php:47-64` (real init call, byte-verified against PUC's real upstream v5.7 API), `includes/plugin-update-checker/**` (byte-identical to the real upstream tag), the asset-filter regex independently proven to correctly select the real, live `bluerails-agent-traffic.zip` asset (`id 537776596`) over the real `bluerails-agent-traffic-1.3.0.zip` asset out of the real published `v1.3.0` release (ground-truth items 1 and 4), and `tests/test-bot-signature-ordering.php` at 29/29 unaffected. Missing: the ticket's ultimate user outcome — a real installed site (Schwitzers) actually receiving and applying an automatic update — has not been observed against a live WordPress instance; no wp-admin access exists in this review environment, the same gap the pre-build ticket review (`.claude/ticket-reviews/BLUE-1534.md`, bluerails402) already disclosed as unverifiable in its own session. This becomes observable, and should be watched, the next time a real release (e.g. `v1.3.1`) is cut per the repo's own "Cutting a release" checklist — tracked: BLUE-1534 (this same ticket; the live-install observation is that ticket's own natural next verification step at release-cut time, not separate follow-up work).

## Verification Story

- Artifacts covered: PHP application code (`bluerails-agent-traffic.php` — correctness,
  security, hook timing, API-shape fidelity), vendored third-party PHP library
  (`includes/plugin-update-checker/**` — provenance/integrity checked, not re-reviewed
  line-by-line since it's unmodified upstream code), Markdown docs (`README.md`,
  `readme.txt` — factual accuracy checked against live release state and code), existing
  test suite (re-run, not just assumed passing). No CI config, IaC, or SQL in this diff —
  N/A.
- Tests reviewed: yes. `tests/test-bot-signature-ordering.php` re-run against this branch:
  29/29 pass, unaffected by this diff (expected — no matcher code touched). No new test
  was added for the update-checker init itself; this is an accepted, reasonable gap given
  it requires a live WP runtime + real GitHub Releases API to exercise meaningfully, not a
  silently-dropped requirement.
- Build verified: yes. `php -l` clean on all touched/vendored PHP files (`bluerails-agent-traffic.php` + all files under `includes/plugin-update-checker/`).
- Security checked: yes. No credentials embedded or required (public repo, unauthenticated
  API, `setAuthentication()` never called); no synchronous network call introduced;
  vendored library diffed against real upstream to rule out a tampered/backdoored copy.
- Docs/prose checked: yes. `README.md`'s "Cutting a release" and "Files" sections were
  checked against the live GitHub Releases API and the actual code — both accurate as of
  this review. No stale "pending/TODO" language found for this feature. `readme.txt`
  changelog entry for 1.3.1 accurately describes the shipped mechanism.
