# REVIEW-BLUE-1473: Referer-based AI-assistant fallback signal

Implemented-By: bluerails-agent-traffic-build-agent
Independent-Reviewer: bluerails-agent-traffic-review-agent

**Verdict: CHANGES-NEEDED** (docs/compliance gap; no code-correctness blocker)

## Overview

Diff reviewed: `main...HEAD` on `BLUE-1473-referer-header-capture` (commit `06f53bd`), repo
`Bluerails-2-0/bluerails-agent-traffic`. Adds a referer-based fallback signal to
`includes/class-bluerails-bot-detector.php`: on a User-Agent miss, checks
`$_SERVER['HTTP_REFERER']`'s hostname against a small named allow-list
(`AI_REFERER_DOMAINS`) and, on a match, fires the existing async POST with an empty
`bot_name`, the raw UA as `matched_ua_string`, and a new `referer` field. Also bumps the
plugin to 1.1.0 and updates `README.md` and `readme.txt` (partially — see findings).

The core PHP logic is correct, safely written, and well-tested by inspection (hostname-exact
match with anti-spoofing via `wp_parse_url`, verified by hand against several bypass attempts
below). The gap is that the WordPress.org-facing `readme.txt` — the actual external-services
disclosure surface — was not updated to reflect that a new field is now sent on every report.

## Lens Coverage

| Lens | Verdict | Notes |
| --- | --- | --- |
| Tech | APPLIES | Correctness, security, architecture, performance of `includes/class-bluerails-bot-detector.php` and `bluerails-agent-traffic.php` reviewed line-by-line; `php -l` run; anti-spoof hostname logic hand-traced against 3 bypass attempts. No blockers; 2 suggestions (see below). |
| Product Manager | APPLIES | Ships the ticket's stated scope (referer fallback on UA miss, small named allow-list, mirrors server-side BLUE-1473). Finding: `readme.txt`'s "External services" field list and "How it works" description are now stale relative to the actual payload — a PM-owned trust/disclosure defect, not a docs nit, since this is a data-collection plugin distributed via WordPress.org. See Important Issues. |
| Product Designer | SKIP | This diff touches no rendered UI — `includes/class-bluerails-settings.php` (the only screen a user sees) is unchanged, no CSS/layout/component edits in the diff. No screenshot or DOM to verify. The one settings-screen-copy gap found is a disclosure-accuracy issue, not a visual/design defect, so it's carried under the Product Manager lens instead of here. |
| Persona | APPLIES | Two personas walked: (1) the WP site admin — settings screen unchanged, no new capability required of them, disclosure-accuracy finding captured under Product Manager; (2) the AI-assistant/agentic-browser persona actually named in `AI_REFERER_DOMAINS` (e.g. https://chatgpt.com, https://claude.ai, https://perplexity.ai, https://gemini.google.com — the real external domains this code matches against). Unlike an x402-paying agent, this persona needs **no capability at all**: detection is entirely passive server-side header inspection of whatever `Referer` it happens to send during ordinary browsing — it performs no action, signs nothing, and is unaware the plugin exists. Verified this persona's traffic is correctly classified (and a spoofing attempt correctly rejected) with an actual executed run against the real plugin code — see the harness output in Correctness below. |
| Cost/Perf | APPLIES | New work per request is one `wp_parse_url()` call plus a 4-entry array scan, only on the already-existing UA-miss path; no new network call (still the same single fire-and-forget POST); no unbounded loops, no N+1. Clean. |

## Correctness (verified by hand)

- `php -l` on both changed PHP files: **no syntax errors** (ran directly, not trusting the
  commit message).
- Existing UA-match path is unchanged in behavior other than now also passing `$referer`
  through to `report_hit()` — confirmed by reading `maybe_capture()` end-to-end; the `if (
  null !== $match ) { ...; return; }` branch preserves the pre-existing early exit.
- `match_ai_referer()` matching is **hostname-exact-or-subdomain via `wp_parse_url`**, not a
  substring scan — confirmed this resists the spoof the ticket calls out:
  - `https://chatgpt.com.attacker.net/x` → host `chatgpt.com.attacker.net`; last-12-chars
    check yields `attacker.net` vs required `.chatgpt.com` → **no match** (correctly
    rejected).
  - `https://evilchatgpt.com/x` → host `evilchatgpt.com`; suffix check requires a literal `.`
    boundary before `chatgpt.com`, which isn't present → **no match** (correctly rejected).
  - `https://chatgpt.com@evil.com/` (userinfo trick) → `wp_parse_url(..., PHP_URL_HOST)`
    returns `evil.com`, not the userinfo segment → **no match** (correctly rejected).
  - Traced these by hand against the actual `substr()`/length arithmetic in
    `match_ai_referer()` (`includes/class-bluerails-bot-detector.php:133-148`), not just by
    reading the docblock's claim.
- Empty/missing referer (`empty( $referer )` guard) and unparseable host (`empty( $host )`
  guard) both short-circuit to `null` — no PHP warnings on malformed input.
- Sanitization is consistent with the existing UA path: `esc_url_raw( wp_unslash( ... ) )` for
  the referer mirrors `sanitize_text_field( wp_unslash( ... ) )` for the UA — appropriate
  choice for a value that's being parsed as a URL and later re-sent as one.
- Version bump `1.0.0` → `1.1.0` in both `bluerails-agent-traffic.php` (header comment +
  `BLUERAILS_AGENT_TRAFFIC_VERSION`) and `readme.txt` (`Stable tag`) — consistent, correct
  semver bump for an additive feature.

**Executed run, not just static reading.** Built a minimal harness that stubs the ~9 WordPress
functions this class calls (`add_action`, `is_admin`, `sanitize_text_field`, `esc_url_raw`,
`get_option`, `home_url`, `wp_remote_post`, etc. — no framework changed, no plugin logic
touched) and `require`s the **actual, unmodified**
`includes/class-bluerails-bot-detector.php` from this branch, then calls `maybe_capture()`
directly with four simulated requests, capturing whatever `wp_remote_post()` would have sent:

```
$ php harness.php
=== UA-miss + AI-referer subdomain match (chat.chatgpt.com) ===
POST fired to: https://discovery.bluerails.com/api/agent-traffic-ingest
Payload: {"bot_name":"","matched_ua_string":"Mozilla/5.0 (Macintosh...) ...","page_path":"/rooms/deluxe-suite",
  "page_url":"https://example-hotel.com/rooms/deluxe-suite","timestamp":"2026-08-28T14:48:25+00:00",
  "site_url":"https://example-hotel.com","referer":"https://chat.chatgpt.com/session/abc123?q=deluxe+suite+availability"}

=== UA match (GPTBot) with a referer present ===
POST fired to: https://discovery.bluerails.com/api/agent-traffic-ingest
Payload: {"bot_name":"GPTBot","matched_ua_string":"GPTBot", ... ,"referer":"https://chatgpt.com/c/xyz789"}

=== Spoof attempt: chatgpt.com.attacker.net (must NOT fire) ===
NO POST FIRED

=== Ordinary human visit (must NOT fire) ===
NO POST FIRED
```

This is a real execution of the shipped code (not a copy, not a rewrite), and confirms
end-to-end: the referer-fallback path fires and builds the exact documented payload shape;
the pre-existing UA-match path is unmodified and now also carries `referer`; the
`chatgpt.com.attacker.net` spoof attempt and an ordinary human visit both correctly produce
no POST at all.

No test suite exists in this repo at all (`find . -iname "*test*"` returns nothing, no
`composer.json`/`phpcs.xml`/CI workflow) — that's a pre-existing gap, not introduced by this
diff, but it means the anti-spoof hostname logic above has zero automated regression coverage
going forward. Worth a follow-up ticket, not a blocker for this PR.

## Important Issues

- **`readme.txt:46-56` ("External services") is now stale/incomplete.** This is the
  WordPress.org-facing disclosure of exactly what JSON payload gets sent to Bluerails'
  endpoint. It still lists only `bot_name`, `matched_ua_string`, `page_path`, `page_url`,
  `timestamp`, `site_url` — the new `referer` field (sent on **every** reported hit, not just
  the fallback path, per `report_hit()`'s payload at
  `includes/class-bluerails-bot-detector.php:176`) is missing from this list. The adjacent "No
  personally identifiable information ... are sent" claim (`readme.txt:58-59`) is also now
  unqualified — a `Referer` URL can carry query-string content from the referring
  page/session (e.g. a chat-thread ID, a search query) that wasn't present in the payload
  before this change. **Fix:** add `referer` to the bulleted field list and add one sentence
  either scoping the PII claim ("referer URLs are passed through unmodified and may contain
  incidental identifiers from the referring page") or explaining why it's still considered
  safe. `README.md`'s equivalent section (`README.md:47-65`) was correctly updated — this is
  specifically a `readme.txt` gap.
- **`readme.txt:21-30` ("How it works", Description section) doesn't mention the new referer
  fallback at all** — only the Changelog entry further down (`readme.txt:139-144`) discloses
  it. A reader skimming the primary Description (what WP.org surfaces most prominently, and
  what a site admin reads *before* installing) would not learn that a second detection path
  now exists or that it changes what's sent. `README.md`'s "What it does" list got a new step
  4 for exactly this (`README.md:21-25`); `readme.txt`'s parallel "How it works" list did not.
  **Fix:** add a step 4 to `readme.txt`'s Description mirroring `README.md`'s.
- **Settings screen (`includes/class-bluerails-settings.php`, unchanged by this diff) still
  only describes UA-based bot detection** to the site admin — its "Connection" section intro
  (`render_section_intro()`) and FAQ-adjacent copy never mention that a referer is now
  captured/sent as a fallback signal. This is the one surface a WP admin persona actually sees
  inside their own dashboard before/after opting in (readme.txt and README.md are read on
  WP.org or GitHub, not in wp-admin). Not calling this a blocker since the existing "No data
  leaves this site until both fields are filled in" disclosure (`class-bluerails-settings.php:100`)
  is still accurate — the gate on sending anything is unchanged — but the admin never learns
  the *shape* of what gets sent once it starts. **Suggested fix (can be a fast follow, not
  necessarily this PR):** one line added to the settings intro paragraph.
- **Cross-repo list parity and deploy sequencing not verifiable from this review.**
  `AI_REFERER_DOMAINS` (this repo) is documented as mirroring `AI_REFERER_ALLOWLIST` in
  `visibility-web/app/features/agent-traffic/ingest.ts` (bluerails402 repo, companion BLUE-1473
  PR) — this review has no access to that file/PR and could not confirm the two lists actually
  name the same four domains today. More importantly: this plugin will send a new `referer`
  field to the **existing, already-deployed** ingest endpoint the moment this PR merges and a
  site updates, regardless of whether the companion backend PR has shipped yet. If the current
  production ingest handler does strict JSON-schema validation (`additionalProperties: false`
  or similar) rather than tolerating unknown fields, sites running the new plugin version ahead
  of the backend deploy could have **every** report rejected (not just the referer-fallback
  ones) until the backend catches up — a regression on the existing UA-match path, not just a
  no-op for the new field. **Fix:** confirm (in the companion PR or separately) that the prod
  ingest endpoint already tolerates/ignores unknown JSON fields, or explicitly sequence the
  backend deploy before this plugin version is published to WP.org / distributed to customers.

## Suggestions

- `includes/class-bluerails-bot-detector.php:44-56` — the `AI_REFERER_DOMAINS` docblock
  restates the same "local copy, drifts, backend re-verifies" rationale already given in the
  class-level docblock (lines 3-13) for `BOT_SIGNATURES`, in fresh prose rather than pointing
  back to it (`README.md`'s parallel section does this correctly: "same drift model as the
  bot-signature list above"). Not wrong, just a second full restatement of the same "why" in
  the same file. Consider trimming to something like "Same drift model as `BOT_SIGNATURES`
  above (see class docblock); deliberately not exhaustive — agentic browsers like ChatGPT
  Atlas typically strip the Referer header entirely."
- `report_hit()`'s `$referer` payload value is sent unbounded — a maliciously long
  `Referer` header (fully attacker-controlled, like any HTTP header) is passed through
  `esc_url_raw()` and forwarded as-is with no length cap. Low severity (this already exists
  for `matched_ua_string`/raw UA today, `blocking => false` bounds any client-side impact),
  but worth a `substr( $referer, 0, N )` defensive cap alongside the existing patterns if this
  file is touched again.
- No automated tests exist anywhere in this repo. The new `match_ai_referer()` is exactly the
  kind of small, pure, security-relevant function (anti-spoofing hostname match) that's cheap
  to unit-test and easy to silently regress later. Worth a follow-up ticket to add a minimal
  PHPUnit harness, not a blocker for this PR.

## What's Done Well

- The hostname-matching logic in `match_ai_referer()` is genuinely careful: it explicitly
  avoids the naive-substring trap the review prompt asked about, and does so with a documented
  rationale plus a concrete attack example in its own docblock — verified by hand above, not
  just taken on faith.
- `README.md` was updated thoroughly and accurately (payload example, new field explanation,
  drift-model note for the new allow-list, "What it does" step 4) — the gap is specifically in
  `readme.txt` and the settings UI, not in the primary engineering doc.
- Consistent sanitization pattern reuse (`esc_url_raw`/`wp_unslash` mirroring the existing
  `sanitize_text_field`/`wp_unslash` pattern for UA) rather than inventing a new one.
- Clean, minimal control-flow change in `maybe_capture()` — the UA-match early-return is
  preserved unchanged, and the new referer-fallback branch is additive and easy to read.

## Depth Interrogation

This repo is a standalone WordPress plugin (telemetry capture only) with no rendered
product UI, no money movement, no LLM call, no operator console, and no external-service
payment/booking adapter — most markers below correctly resolve to N/A for this artifact
type; each says why rather than being skipped silently.

**Product Manager**

- IN-CONTEXT-REVIEW: N/A — no rendered page. This is a backend WordPress hook plus static
  doc files, not a UI surface. In lieu of a page walk, read the full changed function
  (`maybe_capture()`) and its two call sites end-to-end, not just the diff hunks, to confirm
  the new branch composes correctly with the pre-existing UA-match branch (confirmed above
  under Correctness).
- SIBLING-METRIC-COHERENCE: N/A — no metrics/numbers are displayed anywhere in this diff.
- DECISION-CHALLENGE: The diff embodies "add referer as an ADDITIONAL fallback signal,
  fired only on a UA miss" — not the alternative of always sending referer regardless of UA
  match, and not the alternative of just growing `BOT_SIGNATURES` instead. This matches the
  task's stated scope exactly ("on a UA miss, check referer... mirroring the server-side
  ticket BLUE-1473") and the plugin's existing "small local allow-list, backend
  re-verifies" design pattern already used for `BOT_SIGNATURES` — the right direction, not a
  wrong-target cut.
- BASELINE-ANCHOR: N/A — not a bluerails402 domain product surface (no reservations/
  rate-calendar/dashboard/operator console exists in this repo); no external best-in-class
  scorecard applies to a WP telemetry-capture plugin.
- ALL-STATES-COVERAGE: N/A — no UI states (empty/sparse/rich) exist for this feature.
- PERSONA-JTBD-CHECK: N/A — not an operator console / multi-persona surface. Single
  persona (WP site admin) interacts only with the settings screen, which this diff doesn't
  touch.
- AGENTIC-OPPORTUNITY-CHECK: N/A — non-product-UI backend capture change, no data display
  to a user.
- PRODUCT-FRAME: N/A — non-rendered value-flow change (a backend PHP hook), not a rendered
  frame a user views.
- JTBD-OUTCOME: "When an AI-assistant/agentic browser visits a customer's WP site with a UA
  that matches no known bot signature, I want the plugin to also check the Referer header
  against a small named AI-domain allow-list, so I can still capture a fallback signal for
  that traffic instead of missing it entirely." The mechanism is delivered and correct
  (verified above). The full "so I can" outcome — a visible row in the Bluerails Agent
  Traffic dashboard — additionally depends on the companion backend ingest PR
  (bluerails402, `visibility-web/app/features/agent-traffic/ingest.ts`), which is **out of
  scope for this repo/review** and not verified here. Named, not assumed.
- OUTCOME-ARTIFACT: The terminal artifact this ticket's user (a hotel-customer site admin
  watching their Bluerails Agent Traffic dashboard) came for is a dashboard-visible traffic
  row for the AI-assistant hit. **No API 200 body and no dashboard DB row were obtained in
  this review** — deliberately: getting a real API 200 body would require POSTing
  fabricated test data to the **live production** ingest endpoint
  (`https://discovery.bluerails.com/api/agent-traffic-ingest`) with a real API key, which
  this review does not have and would not be appropriate to do from a code-review pass
  (would pollute a real or test customer's live dashboard; this repo has no sandbox ingest
  endpoint to target instead). The strongest real-run evidence obtainable without that risk:
  a stub harness `require`s the actual, unmodified `class-bluerails-bot-detector.php` from
  this branch and calls `maybe_capture()` against simulated requests; the exact `body` that
  would become that API 200/dashboard row is captured at the `wp_remote_post()` call site
  and matches the documented payload precisely (see the harness output block in Correctness
  above). This proves the **request this plugin would send is correct**; it does NOT prove
  the backend accepts it, stores it, or renders it — that hop is a downstream, cross-repo
  artifact (companion backend PR, different repo, not reviewed here) and is named as the
  boundary of this review's evidence, not claimed as verified.
- PROD-REALITY: If merged and shipped exactly as-is: a WP site admin sees **no visible
  change at all** (no UI touched); the site silently starts sending a `referer` field on
  every reported hit once the plugin is updated and already configured. Whether that field
  is stored/used server-side depends on the companion backend PR shipping — see the new
  Important Issue above on deploy sequencing/schema tolerance. This matches what the
  Changelog/README promise (a fallback signal, not a guarantee), so no over-promising
  found — but real delivery of the *value* is contingent on a piece outside this repo.
- PRESS-RELEASE-CLAIM: "Bluerails now catches a low-coverage fallback signal for
  AI-assistant traffic whose browser doesn't identify itself as a bot" — truthful today,
  and notably the diff's own docs already self-impose this modest, accurate framing
  ("low-coverage fallback", "minority of traffic") rather than overselling it — good
  restraint, not a claim the diff makes but can't back up.
- VERTICAL-SLICE: Last WIRED step in this repo: `wp_remote_post()` fired with the correct
  payload (confirmed). First step UNWIRED-from-this-review: ingestion/storage/dashboard
  display in the companion backend PR (different repo, not reviewed here) — named as a
  boundary, not assumed to work.
- PERSONA-JOURNEY: Persona = WP site admin. Journey: install plugin → configure
  endpoint+API key in wp-admin → traffic capture (including the new referer path) runs
  silently in the background → results appear in the Bluerails dashboard (outside this
  plugin/repo). No step within this repo fails or dead-ends for this persona; the admin
  never sees or interacts with the new referer logic directly (by design — it's a backend
  signal, not a UI feature).

**Cost/Perf**

- HOT-PATH-COST: New work runs inside the existing `wp_loaded` handler
  (`maybe_capture()`), once per non-admin/non-cron/non-AJAX front-end HTTP request, and only
  on the already-existing UA-miss branch. Added cost per such request: one
  `wp_parse_url()` call plus a linear scan of a 4-entry constant array — no new network
  call (the POST is the same pre-existing fire-and-forget call, still gated by the same
  `blocking => false` + missing-config short-circuit). Does not block page render (unchanged
  from before this diff). No LLM/DB call involved, so no model-tier question applies.

**Tech**

- INVARIANT-ENFORCER: Invariant — "the referer-fallback path fires only when the referer's
  parsed HOST exactly equals, or is a subdomain of, an entry in `AI_REFERER_DOMAINS` — never
  on a substring match anywhere in the raw referer string." Single enforcer:
  `match_ai_referer()` (`includes/class-bluerails-bot-detector.php:133-148`), specifically
  the `wp_parse_url(..., PHP_URL_HOST)` + exact-or-dot-suffix check. Fail direction: if this
  were ever weakened to a naive `strpos($referer, $domain)` substring scan, it would fail
  **open** — verified by hand that `https://chatgpt.com.attacker.net/x` would then
  incorrectly match and cause the plugin to report a fabricated "AI referer" hit into a
  customer's dashboard for any attacker-crafted URL containing the domain string anywhere.
  The current code does not have this weakness (see Correctness section above).
- PROD-ENTRY-TRACE: Not a bluerails402-deployed service, so there's no Lambda/route-mount
  entry point to trace. The real analog: `wp_loaded` is a stable, unmodified core WordPress
  hook the pre-existing UA-match code already relies on (this diff adds no new hook
  registration, only extends the existing `maybe_capture()` callback). Reachability in a
  real production WP install is therefore inherited, not newly introduced — not
  independently re-verified against a live WP instance in this pass (no WP test environment
  available), which is a real limitation of this review, disclosed rather than hidden.
- PARITY-CHECK: The dual-source pair that must agree is `AI_REFERER_DOMAINS` (this repo,
  PHP) ↔ `AI_REFERER_ALLOWLIST` (`visibility-web/app/features/agent-traffic/ingest.ts`,
  bluerails402 repo, companion PR). This review confirmed the PHP-side list's contents
  (chatgpt.com, perplexity.ai, claude.ai, gemini.google.com) but **could not cross-check
  them against the actual backend file**, since that file lives in a different repo/PR not
  provided for this review. Flagged as UNVERIFIED. A mismatch wouldn't corrupt stored data
  (the backend independently re-classifies, per both READMEs' documented design) but would
  cause silent under/over-firing drift from day one rather than emerging gradually over
  time — worth a one-line confirmation before/at merge.
- FAILURE-MODE-ENUM: five modes enumerated across state/time/scale/trust/partial-failure,
  each with its effect and detection mechanism traced against the actual code:
  - *State*: endpoint/API key not configured → both the pre-existing and new code paths
    already no-op safely inside `report_hit()`'s early return (unchanged, confirmed at
    `class-bluerails-bot-detector.php:160-162`).
  - *Time*: `Referer` header absent (increasingly common — agentic browsers strip it, this
    is self-documented in the diff) → `match_ai_referer()`'s `empty($referer)` guard returns
    null; no report sent, no error, no PHP warning. Correctly degrades to "misses this
    traffic" rather than crashing.
  - *Scale*: high concurrent request volume → no new shared/global/DB state introduced;
    fully stateless per-request. No scale risk from this diff.
  - *Trust*: a malicious/crafted `Referer` header attempting to spoof a match → tested and
    confirmed rejected (see the three bypass attempts traced under Correctness above).
  - *Partial failure*: the `wp_remote_post()` call itself fails or times out → unchanged,
    pre-existing behavior: `blocking => false`, no error handling, silent failure by design
    (the plugin's whole point is to never block the visitor). Not a regression introduced by
    this diff.
- SIDE-EFFECT-COMPLETION: The only side effect is "fire one telemetry POST." This is not a
  financial transaction or multi-step saga — there is no compensating/rollback concept
  needed; a dropped POST just means one missed traffic row, not corrupted state anywhere.
- STATE-MACHINE-COMPLETENESS: N/A — no enum/state machine, no DB schema, no new column in
  this plugin. The one shape change is the JSON payload gaining a `referer` key; confirmed
  both call sites (`maybe_capture()`'s UA-match branch and its new referer-fallback branch)
  route through the single `report_hit()` function that constructs the full payload, so
  there's no risk of one path forgetting the field (verified by reading both call sites).
- IDEMPOTENCY-REPLAY: Each qualifying HTTP request triggers at most one `report_hit()`
  call — either via the UA-match branch's early `return`, or via the referer-fallback
  branch, never both (confirmed by the `if (...) { ...; return; }` structure in
  `maybe_capture()`). No client-side retry-on-failure logic exists (fire-and-forget, no
  retry), so this plugin cannot itself double-submit for a single request. A literal
  HTTP-level replay by a third party would look like two independent real requests to
  WordPress — this plugin has no idempotency key for that today, but that's a pre-existing
  property of the entire telemetry-ingestion design (inherently at-least-once), not
  something this diff changes or worsens.
- INTEGRATION-REALITY: N/A — this diff touches no external-service adapter in the gate's
  named sense (no Stripe/x402/DIRS21/bvnk/swap call, no LLM-provider call).
  `wp_remote_post()` here is a fire-and-forget telemetry POST to Bluerails' own ingest
  endpoint, unchanged in mechanism from the pre-existing UA-match path; the only new
  external-facing detail is one additional field in the JSON body.

## Outcome Delivery

PARTIAL: the referer-based fallback-signal capture mechanism itself is delivered and
correct as merged in this repo — verified via `php -l`, and by hand-tracing the anti-spoof
hostname-match logic against three concrete bypass attempts (subdomain-suffix, prefix, and
userinfo tricks), all correctly rejected — missing: (a) the WordPress.org-facing
`readme.txt` external-services disclosure and the settings-screen copy were not updated to
reflect the new `referer` field now sent on every report (Important Issues above), and (b)
this review could not confirm cross-repo parity with the companion backend's
`AI_REFERER_ALLOWLIST`, nor whether the live prod ingest endpoint tolerates the new
unrecognized field ahead of the companion backend PR shipping — both are load-bearing for
the ticket's actual user outcome (an accurate, working, disclosed capture pipeline) but are
outside what a single-repo diff review can verify. Tracked: BLUE-1473 — fix the doc gaps and
confirm the two cross-repo items before merge, in the same ticket, not deferred further.

## Verification Story

- Artifacts covered: PHP application code (`includes/class-bluerails-bot-detector.php`,
  `bluerails-agent-traffic.php`) — correctness/security/architecture/performance/readability
  all checked; Markdown docs (`README.md`, `readme.txt`) — factual accuracy, internal
  consistency, and stale-content checked (finding above); no CI/YAML, no SQL/IaC, no shell
  scripts in this diff (N/A, confirmed by `git diff --stat`).
- Tests reviewed: no test suite exists in this repo at all (pre-existing, confirmed via
  `find`); no tests added or expected to be added by this diff's own scope, flagged as a
  suggestion for a follow-up.
- Build verified: `php -l` run directly against both changed PHP files, both pass. No
  composer/CI harness exists in this repo to run beyond that.
- Security checked: yes — hostname-match anti-spoofing logic traced by hand against three
  bypass attempts (subdomain-suffix trick, prefix trick, userinfo trick), all correctly
  rejected; sanitization pattern consistent with existing code; no secrets, no new
  auth/authz surface, no SQL/IaC in scope.
- Docs/prose checked: yes — `README.md` vs `readme.txt` vs the settings-screen UI copy
  cross-checked against the actual new payload shape in `report_hit()`; found `readme.txt`
  (the WP.org-facing disclosure) and the settings screen both stale relative to
  `README.md`, which was updated correctly. See Important Issues above.
