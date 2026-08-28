# REVIEW-BLUE-1474: Client-side behavioral signal beacon for rendered-browser agent traffic

Implemented-By: bluerails-agent-traffic-build-agent (PR #2, branch `BLUE-1474-behavioral-signal-beacon`)
Independent-Reviewer: Claude, fresh orchestrator-spawned agent, 2026-08-28 — no shared context with the implementer

**Verdict: CHANGES-NEEDED**

## Overview

Diff reviewed: PR #2 (based on `BLUE-1473-referer-header-capture`, per the PR's own
"Stacked-PR note") → `BLUE-1474-behavioral-signal-beacon`, repo
`Bluerails-2-0/bluerails-agent-traffic`. Adds `assets/js/bluerails-behavioral-beacon.js` (a
consent-gated mouse-movement/timing observer) and `includes/class-bluerails-behavioral-beacon.php`
(enqueue + a same-origin REST proxy at `/wp-json/bluerails-agent-traffic/v1/behavioral` that
forwards the beacon's feature summary server-side, with the org's API key, to the Bluerails
ingest endpoint). Settings screen gets a new OFF-by-default checkbox and section. `README.md`/
`readme.txt` are both updated (unlike BLUE-1473, which initially missed `readme.txt`). Version
bumped 1.1.0→1.2.0. `includes/class-bluerails-bot-detector.php` is untouched (confirmed via
byte-for-byte diff — 0 changes).

The client-side consent/fail-closed logic, the API-key-never-reaches-the-browser design, and the
field-name contract with the companion server-side PR are all sound and independently verified
below. The blocking defect is server-side: the new REST route is registered with
`permission_callback => '__return_true'` and performs **no origin, referer, nonce, or
anti-forgery check of any kind**. Any anonymous third party who has never loaded the page — not
just the site's own beacon — can POST directly to this URL and have the customer's own
WordPress server relay attacker-chosen telemetry to Bluerails using that customer's own API key.
This is a new, lower-bar attack surface this diff introduces (the existing bot-UA/referer paths
never expose a public unauthenticated intake at all — they read `$_SERVER` and POST out from a
core WordPress hook, never accept an arbitrary body from a public route).

## Lens Coverage

| Lens | Verdict | Notes |
| --- | --- | --- |
| Tech | APPLIES | Consent-gating, key-handling, and field contracts verified line-by-line in `includes/class-bluerails-behavioral-beacon.php` and cross-checked against `Bluerails-2-0/bluerails402#1255`'s `visibility-web/app/routes/api.agent-traffic-ingest.ts`; `php -l` run on all 4 changed PHP files (all pass). Blocking finding: `register_rest_route()`'s `permission_callback => '__return_true'` with no origin/nonce check. |
| Product Manager | APPLIES | Ships the ticket's stated scope faithfully — verified against `.claude/ticket-reviews/BLUE-1474.md`'s three pre-build MUST-FIX items and the shipped `assets/js/bluerails-behavioral-beacon.js`/`includes/class-bluerails-behavioral-beacon.php` — but the same REST-route gap undermines the JTBD outcome (a trustworthy signal); see PM findings below. |
| Product Designer | SKIP | One settings-screen checkbox + intro paragraphs added to `includes/class-bluerails-settings.php`, reusing the exact `checked()`/`esc_attr()`/`<label><input type=checkbox>` pattern the pre-existing, already-shipped `OPT_HAS_CDN` field uses one function above it — no new CSS/layout/component. No live WP install exists to screenshot against (this is a standalone plugin repo, no local render harness); the finding this review makes about that screen (settings copy doesn't disclose the REST-route gap) is carried under Product Manager instead, where it is `APPLIES` with a real finding, not a vanishing deferral. |
| Persona | APPLIES | NO-RENDER: `includes/class-bluerails-behavioral-beacon.php`'s `handle_rest_request()` — a server-side REST callback with no rendered surface of its own. Four personas walked below against a stub-WP harness that `require`s the actual, unmodified file and calls `handle_rest_request()` directly (`php harness.php`, output captured under Tech/PARITY findings), including an anonymous attacker who never loads the page at all. |
| Cost/Perf | APPLIES | `handle_rest_request()` (`includes/class-bluerails-behavioral-beacon.php`) triggers one blocking `wp_remote_post()` (3s timeout) per accepted request with no per-IP/per-origin limiting at the WP layer — same root cause as the Tech finding. See HOT-PATH-COST below. |

## Correctness (verified by hand)

- `php -l` on all 4 changed PHP files (`bluerails-agent-traffic.php`,
  `includes/class-bluerails-behavioral-beacon.php`, `includes/class-bluerails-settings.php`,
  and the untouched `includes/class-bluerails-bot-detector.php` for completeness): **no syntax
  errors** on any.
- **Claim 1 (Complianz API), independently checked against the cited page
  (`https://complianz.io/developers-guide-for-third-party-integrations/`, fetched this
  session) — CONFIRMED.** The page documents exactly `cmplz_has_consent(category)` as a
  function ("Check if there is consent for a category or service") and both
  `cmplz_enable_category` and `cmplz_status_change` as real jQuery events dispatched on
  `$(document)`. The beacon's usage (`window.cmplz_has_consent('statistics')`, and
  `jQuery(document).on('cmplz_enable_category cmplz_status_change', ...)`) matches the
  documented contract exactly — this is not a fabricated or guessed API surface.
- **Claim 2 (fail-closed if Complianz absent) — CONFIRMED by reading the control flow, not
  the docblock's claim.** `assets/js/bluerails-behavioral-beacon.js`'s only path into
  `start()` is `consentGate()`. `consentGate()`'s first line is
  `if ( ! hasComplianz() ) { return; }` (`hasComplianz()` = `typeof
  window.cmplz_has_consent === 'function'`) — no Complianz global means an immediate return,
  no beacon activity of any kind. The one `try { ... } catch (e) { return; }` around the
  actual consent check means a throwing/misbehaving CMP integration also fails closed. There
  is no other call site of `start()` anywhere in the file. Client-side, this is genuinely
  fail-closed. **However**, this guarantee is entirely undermined server-side — see the
  Important Issues finding below: the REST proxy this JS posts to has no way to know whether
  this JS (or any consent gate) ran at all, and does not try to.
- **Claim 3 (pointer-capable gating + field-name parity with the server) — CONFIRMED, no
  mismatch found.** The beacon sends `{ behavioral: { pointer_capable, move_count,
  duration_ms, avg_interval_ms, interval_stddev_ms, quantized_ratio } }` (snake_case, exactly
  those six keys). Cross-checked against the companion server-side PR
  (`Bluerails-2-0/bluerails402#1255`, OPEN, not yet merged — `visibility-web/app/routes/
  api.agent-traffic-ingest.ts`'s new `parseBehavioralSignals`): it reads
  `behavioral['pointer_capable']`, `['move_count']`, `['duration_ms']`,
  `['avg_interval_ms']`, `['interval_stddev_ms']`, `['quantized_ratio']` — an exact key-name
  match, and its `BEHAVIORAL_BOUNDS` (`moveCount` 0–100,000; the three ms fields 0–1,800,000;
  `quantizedRatio` 0–1) match the PHP proxy's own `self::BOUNDS` constant exactly, field for
  field. `pointerCapable` is consumed by the server's `computeBehavioralScore` specifically to
  refuse to score pointer-absence on a non-pointer-capable session with few samples — the
  mobile/pointer mitigation the pre-build ticket review required is present end-to-end, not
  just claimed. No naming drift, no bound mismatch, across all three layers (JS → PHP proxy →
  TS route).
  - **Sequencing note (not a blocker, worth stating):** PR #1255 (the server-side half) is
    still OPEN on `main`, unmerged, as of this review. If this plugin PR ships to WP.org
    before #1255 merges, the current production `api.agent-traffic-ingest.ts` (read directly,
    unmodified) will still accept the extra `behavioral` key silently (its `parsePayload`
    only reads named fields, ignoring unknown ones) but will then run
    `matchBotAgainstRegistry` against the real browser's ordinary UA, which will not match any
    registry row, and reject the whole request with **422** — i.e., pre-#1255 rollout fails
    closed (every behavioral POST is a harmless no-op), not open. Confirm #1255 merges before
    or alongside this plugin's WP.org release so the feature actually does something once
    enabled, but there is no data-corruption risk from shipping this plugin first.
- **Claim 4 (API key never reaches the browser) — CONFIRMED.**
  `maybe_enqueue_beacon()`'s `wp_localize_script` call passes only
  `{ restUrl: rest_url(...) }` — a same-origin URL, not a secret. The API key
  (`get_option('bluerails_agent_traffic_api_key')`) is read only inside
  `handle_rest_request()`, entirely server-side, and used solely as the `Authorization: Bearer`
  header on the outbound `wp_remote_post()`. No inline `<script>` block, no other
  `wp_localize_script`/`wp_add_inline_script` call in this diff touches the key. Grepped the
  whole diff for the option name and the string `api_key` — every reference is inside PHP that
  never executes in the browser.
- **Claim 5 (checkbox defaults OFF) — CONFIRMED, checked at the storage layer, not the label.**
  `register_setting(..., self::OPT_BEHAVIORAL, ['default' => ''])`, `sanitize_yes_no()` returns
  `''` for anything other than the literal string `'1'`, and `is_enabled()` requires
  `'1' === get_option(self::OPT_ENABLED, '')` — an unset/fresh install reads `''`, so
  `is_enabled()` is `false` and the beacon is never enqueued. `bluerails_agent_traffic_activate()`
  also explicitly seeds `add_option('bluerails_agent_traffic_behavioral_enabled', '')` on
  activation, reinforcing the OFF default rather than leaving it to `get_option`'s fallback
  alone.
- **Claim 6 (version/changelog/readme) — CONFIRMED.** `Version: 1.2.0` and
  `BLUERAILS_AGENT_TRAFFIC_VERSION = '1.2.0'` in `bluerails-agent-traffic.php`; `Stable tag:
  1.2.0` in `readme.txt`; a `= 1.2.0 =` changelog entry in `readme.txt`. `readme.txt`'s
  "External services" field list was updated to include all six new payload fields
  (`pointer_capable`, `move_count`, `duration_ms`, `avg_interval_ms`, `interval_stddev_ms`,
  `quantized_ratio`) with a one-line gloss on each — this plugin does NOT repeat BLUE-1473's
  gap (that PR initially forgot to update `readme.txt`'s disclosure list; this one updates it
  in the same commit as the feature). `README.md` also carries a full worked payload example
  and a "Behavioral signal beacon" section. Both docs are consistent with each other and with
  the actual code.
- **Claim 7 (`php -l`) — done, see above; PASS on all four files.**

## Important Issues

- **BLOCKING — the new REST route has no anti-forgery/origin check, letting anyone forge a
  behavioral row for this org without ever loading the page (`includes/class-bluerails-
  behavioral-beacon.php`, `register_rest_route()`).** `permission_callback` is
  `'__return_true'` — deliberately public, which is correct in principle (an anonymous site
  visitor's browser has no WP identity to authenticate). But nothing else stands in for that:
  no nonce, no `Origin`/`Referer` header check against `home_url()`, no shared per-page-load
  token, no rate limit at the WP layer. `handle_rest_request()` re-checks `is_enabled()` (the
  feature is turned on) and bounds-checks the numeric fields, but performs no check that the
  request came from this site's own rendered page, from a real browser, or from anyone who
  ever ran the consent-gated JS at all. Concretely: once a site owner opts in, `curl -X POST
  https://victim-hotel.com/wp-json/bluerails-agent-traffic/v1/behavioral -d
  '{"behavioral":{"pointer_capable":false,"move_count":50,"duration_ms":5000,
  "avg_interval_ms":40,"interval_stddev_ms":1,"quantized_ratio":0.95}}'` — from anywhere, by
  anyone, with no cookie, no session, no prior page load — gets a 202 and causes the site's own
  WordPress server to relay attacker-chosen telemetry to Bluerails **using that site's real,
  configured API key**, exactly as if it had come from the real beacon. This is a materially
  new attack surface versus the existing bot-UA/referer paths in this same plugin: those never
  expose a public endpoint that accepts an arbitrary body at all (they read `$_SERVER` inside a
  core WP hook and POST out; the request body is never attacker-supplied). Here, an attacker
  who does not have — and was never meant to need — this org's Bluerails API key can still
  attribute fabricated "AI agent" rows to that org's own paid dashboard, poisoning exactly the
  business-intelligence signal this feature exists to produce, and can do so entirely without
  ever visiting the site, satisfying consent, or moving a mouse. **Recommend before merge:** at
  minimum verify `Origin`/`Referer` against `home_url()` (weak but non-zero, and consistent
  with the anti-spoofing discipline already applied to `AI_REFERER_DOMAINS` in the sibling
  BLUE-1473 diff), and/or bind each legitimate beacon POST to a short-lived, per-page-load
  token issued via `wp_localize_script` and checked (and invalidated) server-side — not fixed
  here per this review's scope, only named.
- **Related, same root cause — unbounded relay amplification / DoS on the customer's own WP
  host.** Because the same open endpoint triggers a real, blocking `wp_remote_post()` (3s
  timeout) on every accepted request, and there is no per-request-origin or per-IP limiting at
  the WP layer, the same anonymous attacker above can force the customer's own PHP workers to
  spend up to 3 seconds each on an arbitrary number of concurrent outbound requests — a
  resource-exhaustion vector on the customer's own hosting, independent of whether Bluerails'
  own `AGENT_INGEST_RATE_LIMIT_PER_MINUTE` (120/org/minute, enforced only once the request
  already reached bluerails402) eventually 429s it. The org-side rate limit is also shared
  across ALL three identification paths (bot-UA, referer, behavioral) on the same counter, so a
  sustained flood of forged behavioral POSTs can additionally crowd out the site's real
  bot-UA/referer capture for that same window — an availability regression on the two features
  this plugin already shipped and had reviewed as PASS.

## Suggestions

- `handle_rest_request()` does not itself cap request body size (unlike
  `api.agent-traffic-ingest.ts`'s explicit `MAX_BODY_BYTES = 4_096`); it currently relies
  entirely on generic PHP/WP-level limits (`post_max_size`, etc.), which are not this plugin's
  own defense. Low severity given the payload shape is small and bounds-checked after decode,
  but worth an explicit cap for parity with the sibling endpoint's discipline.
- `class-bluerails-behavioral-beacon.php`'s own docblock explains *why* the API key must stay
  server-side but doesn't mention the REST route's own trust boundary at all — worth a docblock
  note once the Important Issue above is addressed, so a future reader doesn't have to
  re-derive the tradeoff from scratch.
- Minor: `readme.txt`'s new "Behavioral signal" FAQ answer and description entries are
  thorough, but the settings-screen intro copy (`render_behavioral_section_intro()`) doesn't
  mention that the underlying REST endpoint is publicly reachable regardless of the
  Complianz gate — an accurate-but-incomplete disclosure to the site admin. Not calling this a
  blocker on its own, since fixing the REST route itself removes the need to disclose the gap
  in the first place.

## What's Done Well

- The consent-gating control flow (fail-closed on missing Complianz, fail-closed on a throwing
  integration, using the exact documented API) is careful, correctly cited, and verified to
  actually behave that way by reading the code, not just the comment.
- The API-key-never-reaches-the-browser design is exactly right and cleanly executed — a
  same-origin proxy with the key attached exclusively server-side, consistent with the rest of
  this plugin's existing trust model (mirrors `report_hit()`'s pattern).
- Cross-repo field-name and bounds parity with the companion server PR (#1255) is exact across
  all six fields and both numeric bound sets — genuinely double-checked against the other
  repo's actual diff, not assumed.
- Defaults (OFF checkbox, `add_option` seeding on activation) are correct and belt-and-braces.
- Both `README.md` and `readme.txt` were updated together this time, closing the exact gap
  BLUE-1473's own review found and had to fix in a follow-up commit.

## Depth Interrogation

**Product Manager**

- IN-CONTEXT-REVIEW: N/A for a rendered-page walk — the only new rendered surface is one
  settings-screen checkbox reusing an existing pattern one field above it (`OPT_HAS_CDN`), no
  live WP render environment exists in this repo to walk it in. For the MACHINE/CONTRACT
  surface this diff actually is (`includes/class-bluerails-behavioral-beacon.php`'s REST
  route), the integration read is the one done above: `handle_rest_request()` traced end-to-end
  against a hand-constructed request, confirming it accepts an unauthenticated POST and relays
  it — this is the review's central finding, not a clean pass.
- SIBLING-METRIC-COHERENCE: N/A — machine/contract surface, no adjacent rendered metrics in
  this diff (the dashboard row this eventually produces lives in a different repo/PR,
  `dashboard_.agent-traffic.tsx`, out of scope here).
- DECISION-CHALLENGE: The diff embodies "add a THIRD, separate, off-by-default identification
  path via a same-origin REST proxy, rather than (a) a direct browser POST to the Bluerails
  endpoint (rejected in the PR's own 'Why' section — would leak the API key) or (b) piggy-backing
  the referer/bot-UA path's existing server-only architecture." Proxying is the right call to
  keep the key server-side, and the ticket's own three pre-build MUST-FIXes (consent mechanism,
  mobile gating, FPR bar) are all reflected in the built code, not skipped — the DIRECTION taken
  is correct. What was not interrogated at design time is the NEW trust boundary that direction
  necessarily creates (an unauthenticated public write endpoint didn't exist in this plugin
  before this ticket) — the alternative of gating that specific endpoint (not the browser→proxy
  hop, the proxy's own `permission_callback`) was apparently never considered, which is exactly
  where this review's blocking finding sits.
- BASELINE-ANCHOR: N/A — not a bluerails402 domain product surface (no reservations/
  rate-calendar/dashboard/operator console in this repo); no external best-in-class scorecard
  applies to a WP telemetry-capture plugin.
- ALL-STATES-COVERAGE: N/A — no UI states (empty/sparse/rich) exist for this feature; the one
  new UI element is a single settings checkbox with no data-dependent states.
- PERSONA-JTBD-CHECK: N/A — not an operator console/multi-persona surface; single WP-admin
  persona interacts with the (unchanged-pattern) settings screen.
- AGENTIC-OPPORTUNITY-CHECK: N/A — non-product-UI backend capture change, no data display to a
  user in this repo.
- PRODUCT-FRAME: N/A — non-rendered value-flow change (a backend PHP hook + REST route), not a
  rendered frame a user views.
- JTBD-OUTCOME: "When a rendered-browser agentic session (no distinguishing UA/referer) visits a
  customer's WP site, I want the plugin to observe consent-gated mouse-movement telemetry and
  report a scored signal, so the operator can see traffic UA/referer alone would miss." The
  mechanism (observe, proxy, forward) is delivered and the client-side pieces are verified
  correct. The "so I can" outcome — a *trustworthy* signal — is undermined by the REST-route
  gap above: today, the signal can be forged by anyone, not only produced by real consenting
  visitors, so the operator cannot actually trust what lands on their dashboard once this ships
  with the flag on. This is the one place the mechanism is right but the outcome is not yet
  reached.
- OUTCOME-ARTIFACT: The terminal artifact this ticket's user (a hotel-customer site admin
  watching their Bluerails Agent Traffic dashboard) came for is a trustworthy
  `behavioral_heuristic` **dashboard DB row**. **No dashboard DB row and no real production API
  200 body were obtained in this review** — deliberately: getting either would require POSTing
  to the **live production** ingest endpoint with a real org API key, which this review does not
  have and would not be appropriate to exercise from a code-review pass (would pollute a real or
  test customer's live dashboard). The strongest real-run evidence obtainable without that risk,
  and the concrete confirmation-style artifact this review DOES produce: a stub-WP harness
  (`harness.php`) that `require`s the actual, unmodified
  `includes/class-bluerails-behavioral-beacon.php` from this branch (no plugin logic touched or
  rewritten, only WordPress core functions stubbed) and calls `handle_rest_request()` directly
  with two payloads, capturing the EXACT outbound request — URL, `Authorization` header value,
  and JSON body — at the real `wp_remote_post()` call site that would become that dashboard row,
  the same technique BLUE-1473's review used for its own PHP class:
  ```
  === Case 1 — legitimate-shaped beacon payload (simulating the real JS) ===
  REST response: {"ok":true} (status 202)
  wp_remote_post FIRED to: https://discovery.bluerails.com/api/agent-traffic-ingest
    Authorization header sent: Bearer bak_SECRET_ORG_KEY_never_should_leave_server
    Body: {"matched_ua_string":"unknown","page_path":"/rooms/deluxe-suite", ...
      "behavioral":{"pointer_capable":true,"move_count":42, ... "quantized_ratio":0.05}}

  === Case 2 — forged request, no page load / no consent / no beacon JS ever ran ===
  REST response: {"ok":true} (status 202)
  wp_remote_post FIRED to: https://discovery.bluerails.com/api/agent-traffic-ingest
    Authorization header sent: Bearer bak_SECRET_ORG_KEY_never_should_leave_server
    Body: {"matched_ua_string":"unknown","page_path":"/", ...
      "behavioral":{"pointer_capable":false,"move_count":200, ... "quantized_ratio":0.95}}
  ```
  This is a REAL execution of the shipped, unmodified code (not a copy, not a rewrite), and it
  proves — not merely argues — the Important Issue above: Case 2 (constructed with zero page
  load, zero consent, zero beacon JS execution) is accepted and relayed **identically** to Case
  1, complete with the org's real API key attached, at the exact call site that produces the
  eventual dashboard row. This is the concrete run evidence for both halves of the claim: the
  request THIS plugin sends is correctly shaped (Case 1), and that shape is NOT gated on
  anything this review's threat model requires (Case 2). The downstream hop (bluerails402
  actually storing/rendering the row once #1255 merges) is outside this repo and not
  re-verified here, consistent with BLUE-1473's review boundary.
- PROD-REALITY: If merged and shipped exactly as-is, with the flag enabled and the companion
  server PR (#1255) also merged: a WP site admin sees one new settings checkbox and, once
  enabled, a new labeled row type appears on their Bluerails dashboard — but that row is not
  provably tied to real visitor mouse behavior, because of the open REST route. This is a gap
  between what the feature promises ("a behavioral signal from real sessions") and what it can
  currently guarantee ("a signal from whatever anyone POSTs").
- PRESS-RELEASE-CLAIM: "Bluerails can now flag rendered-browser AI agents your site's User-Agent
  and Referer can't see." Not fully truthful as currently shippable — a security-literate reader
  would immediately ask "what stops someone from just POSTing this themselves," and today
  nothing does.
- VERTICAL-SLICE: Last WIRED step in this repo: `wp_remote_post()` fired from
  `handle_rest_request()` with the correctly-shaped payload (confirmed). First UNWIRED/BROKEN
  step: the trust boundary that should sit between "an HTTP request reached this URL" and "this
  represents a real, consenting visitor's browser" — currently absent, not merely unimplemented
  downstream.
- PERSONA-JOURNEY: See the four-persona walk under Persona findings below — persona 4 (the
  anonymous attacker) is the one whose journey should dead-end and currently does not.

**Persona findings** (four personas, walked against the actual shipped code)

1. **EU hotel-site visitor (human, consents via Complianz).** Page loads, Complianz reports
   `statistics` consent granted → `start()` runs → mouse telemetry observed →
   `finish()` POSTs via `sendBeacon`/`fetch` to this site's own REST route → PHP proxy re-derives
   UA server-side and relays with the API key. Journey completes as designed, no dead end.
2. **EU hotel-site visitor (human, does NOT consent, or site has no CMP).** `consentGate()`
   returns immediately in both cases (`hasComplianz()` false, or `cmplz_has_consent('statistics')`
   false) — no beacon activity, no POST, nothing sent. Correctly reaches "no signal" as its
   terminal state, verified by reading the control flow, not assumed.
3. **The agentic browser itself (ChatGPT extension/Work app, Claude for Chrome).** Runs the
   real JS, dispatches real pointer events (per the companion ticket review's claim 2 research,
   not re-verified here), scored server-side once #1255 merges. This persona's journey depends
   on a downstream piece (the score threshold in `computeBehavioralScore`, a different repo) not
   reviewed in this pass — named, not assumed working.
4. **An anonymous attacker who never loads the page at all.** Sends a raw POST directly to
   `/wp-json/bluerails-agent-traffic/v1/behavioral` with a crafted `behavioral` object. Traced
   against the actual `register_rest_route()`/`handle_rest_request()` code: `permission_callback`
   is `__return_true` (no auth check), `is_enabled()` only checks the SITE's own configuration
   (not anything about the requester), and the bounds check only rejects out-of-range numbers,
   not out-of-context requesters. This persona's journey reaches the exact same terminal state as
   persona 1 (a forwarded, API-keyed POST to Bluerails) despite doing none of the things personas
   1–3 must do (load the page, consent, move a mouse). **This is the journey that should dead-end
   and currently does not — the core finding of this review.**

**Cost/Perf**

- HOT-PATH-COST: New work runs inside `handle_rest_request()`, once per accepted POST to the new
  REST route (`includes/class-bluerails-behavioral-beacon.php`). Per accepted request: JSON
  decode + six bounds checks (cheap, in-process) + one blocking `wp_remote_post()` with a 3s
  timeout (the same cost shape the existing bot-UA/referer paths already accept for their own
  fire-and-forget POST). The materially different cost dimension versus the existing paths is
  volume and gating: the bot-UA/referer paths only ever fire on an already bot-shaped request
  (a UA match or a referer match), while this route accepts a request from *anyone*, consenting
  visitor or not — so its true request volume is bounded only by what an external caller chooses
  to send, not by any property of real traffic. Combined with the missing origin check (Important
  Issues above), this means the "per accepted request" cost can be driven arbitrarily by a
  non-visitor, which is the DoS/amplification finding above, not just an accounting note.

**Tech**

- INVARIANT-ENFORCER: Invariant — "a `behavioral_heuristic` row (and the underlying
  `wp_remote_post()` relay that produces it) should only ever originate from this site's own
  consent-gated beacon JS actually having run." Intended enforcer: none exists —
  `handle_rest_request()`'s only checks are `is_enabled()` (a site-level config flag, not a
  per-request property) and per-field numeric bounds. Fail direction: **fails OPEN** — verified
  by hand-tracing a request that skips the beacon, consent, and any real page load entirely
  (see Persona finding 4) and reaching the identical `wp_remote_post()` relay a legitimate
  request would reach. This is the review's central Tech finding.
- PROD-ENTRY-TRACE: Entry point is `rest_api_init` → `register_rest_route()`, a standard,
  already-in-use WordPress hook (no new hook type introduced). Confirmed reachable the moment
  `is_enabled()` is true (endpoint/key configured AND the new checkbox is on) — this is a real,
  live, internet-reachable URL the moment a site owner opts in, not a design-time-only surface;
  the review's finding is not theoretical.
- PARITY-CHECK: Three dual-source pairs, all confirmed to agree: (1) JS wire field names
  (`pointer_capable`, `move_count`, `duration_ms`, `avg_interval_ms`, `interval_stddev_ms`,
  `quantized_ratio`) ↔ PHP proxy's `self::BOUNDS` keys — exact match, read directly. (2) PHP
  proxy's `self::BOUNDS` numeric ranges ↔ the companion PR's TS `BEHAVIORAL_BOUNDS` — exact
  match, field-by-field, confirmed by reading `Bluerails-2-0/bluerails402#1255`'s actual diff
  (not the PR description). (3) `QUANTIZE_STEP = 0.25` in the JS ↔ the companion ticket review's
  claim-1 "0.25-pixel increments" citation — consistent framing, same constant. No drift found
  in any of the three pairs.
- FAILURE-MODE-ENUM: five modes, each traced against the actual code:
  - *State*: endpoint/API key not configured, or the new checkbox is off →
    `is_enabled()`/`maybe_enqueue_beacon()` both no-op cleanly (confirmed at
    `class-bluerails-behavioral-beacon.php`'s `is_enabled()`); `handle_rest_request()` also
    re-checks `is_enabled()` at request time, so disabling mid-session stops new relays
    immediately.
  - *Time*: a page-visibility change or `pagehide` fires `finish()` early — `sent`/`started`
    guards prevent a double-send; `durationMs < MIN_DWELL_MS` silently drops the send. No crash,
    no duplicate POST from normal browser lifecycle events.
  - *Scale*: no new shared/global/DB state in this plugin; fully stateless per-request. Scale
    risk is entirely on the *volume* an unauthenticated caller can drive (see Cost/Perf), not on
    any per-request cost.
  - *Trust*: **this is the mode that fails.** A malicious/crafted direct POST to the REST route
    is accepted identically to a real beacon POST — traced and confirmed NOT rejected (Persona
    finding 4, Important Issues above). Contrast with the sibling BLUE-1473 review, which traced
    three separate spoof attempts against `match_ai_referer()` and confirmed all three correctly
    rejected — this diff's trust-boundary check for its own new endpoint does not exist at all.
  - *Partial failure*: `wp_remote_post()` itself fails/times out → the REST response to the
    caller is still `202` regardless (fire-and-forget from the visitor's perspective, matching
    the existing plugin's design) — not a regression, consistent with pre-existing behavior.
- SIDE-EFFECT-COMPLETION: The side effect is "relay one telemetry POST, with this org's API
  key attached, to an external endpoint." Not a financial transaction — no compensating/rollback
  concept needed for a dropped or malformed row. The side effect that IS irreversible-in-spirit
  is reputational/data-integrity: once a forged row is accepted and forwarded, there's no
  mechanism in this diff (or the companion PR, as far as this review's scope covers) to
  distinguish or retract a forged row after the fact — worth naming even though it's outside a
  "rollback" frame.
- STATE-MACHINE-COMPLETENESS: N/A — no enum/state machine, no DB schema/column owned by this
  repo. The one shape is the JSON payload gaining a `behavioral` object; confirmed the beacon's
  single call site (`finish()`) always includes all six sub-fields together (no partial-shape
  send path exists in the JS), and `handle_rest_request()` rejects the whole request if any of
  the six is missing/malformed (`return new WP_REST_Response(...400)` on the first failing
  check) — no risk of a partially-populated relay.
- IDEMPOTENCY-REPLAY: Each accepted REST POST triggers exactly one `wp_remote_post()` call, no
  client-side retry logic in this proxy. The downstream `syntheticVisitKey`
  (bluerails402/`ingest.ts`, not reviewed fresh here but read for context) provides
  at-least-once-safe idempotency keyed on `(orgId, domain, pagePath, botName, timestamp)` — a
  replayed identical POST would collide and no-op downstream. A literal HTTP-level replay of a
  *forged* request (Important Issues) is not deduplicated any differently than a real one — the
  idempotency mechanism protects against double-counting, not against forgery, which is a
  separate axis this finding is about.
- INTEGRATION-REALITY: The one external-service call in this diff is `wp_remote_post()` from
  `handle_rest_request()` to Bluerails' own ingest endpoint. This is BlueRails' own first-party
  endpoint, not a third-party payment/booking/LLM provider adapter (no Stripe/x402/DIRS21/
  swap/LLM-provider call) — a happy-path-only mocked/stubbed unit test would ordinarily be a
  concern here per this marker's own standard (proves shape, not safety), so this review ran the
  un-mocked harness described under OUTCOME-ARTIFACT above (`harness.php`, requiring the real,
  unmodified PHP file, not a rewrite) specifically to go past shape-only and map it against the
  five FAILURE-MODE-ENUM cases:
  - *State* (feature/config off) — driven live: with `bluerails_agent_traffic_behavioral_enabled`
    unset, `is_enabled()` returns false and `handle_rest_request()` 404s before ever reaching
    `wp_remote_post()` — verified by reading the guard clause directly (not re-run in the harness
    above, since the two harness cases both deliberately set the flag on to test the interesting
    path; the off-path is a single `if` with no branching complexity, low risk of drift).
  - *Time* (page-lifecycle POST timing) — client-side only (`pagehide`/`visibilitychange` in
    the JS); not reachable from this server-side harness, and not a property of the
    `wp_remote_post()` adapter call itself. N/A to this specific integration point.
  - *Scale* — no shared state in the PHP proxy; stateless per-request, confirmed by reading
    `handle_rest_request()` end-to-end (no static/global accumulator). Not independently load-
    tested (out of scope for a code review), but no code path suggests a scale-dependent failure
    mode exists to test.
  - *Trust* — **driven live, this is the mode that matters most and the one this review's finding
    is about.** Harness Case 2 (zero page load, zero consent, zero beacon JS) reaches the
    identical `wp_remote_post()` call, with the identical `Authorization: Bearer <real key>`
    header, as Case 1's legitimate-shaped payload. This is the un-mocked, real-code proof that
    the "trust" failure mode is not merely theoretical — it is exercised and confirmed exactly as
    described in Important Issues.
  - *Partial failure* (`wp_remote_post()` itself fails/times out) — not independently re-tested
    against a real network failure in this harness (the stub `wp_remote_post()` always "succeeds"
    at the PHP-function-return level); confirmed instead by reading the call site: the REST
    response to the caller (`WP_REST_Response(['ok' => true], 202)`) is returned unconditionally
    regardless of `wp_remote_post()`'s outcome, matching the pre-existing, already-reviewed
    fire-and-forget design in `report_hit()` — same discipline, not a new code path to verify.

## Outcome Delivery

PARTIAL: the client-side mechanism (consent gating, key handling, field/bounds contract with
the companion server PR) is delivered and independently verified correct as merged in this
branch — missing: a server-side check that a behavioral POST actually originated from this
site's own consent-gated beacon rather than an arbitrary anonymous caller
(`includes/class-bluerails-behavioral-beacon.php`'s `register_rest_route()`/
`handle_rest_request()`, currently `permission_callback => '__return_true'` with no
origin/nonce/token check) — tracked: BLUE-1474 (this ticket; fix belongs in the same ticket,
before this PR merges, per the Important Issues section above).

## Verification Story

- Artifacts covered: PHP (`bluerails-agent-traffic.php`, `class-bluerails-behavioral-beacon.php`,
  `class-bluerails-settings.php`; `class-bluerails-bot-detector.php` confirmed unchanged),
  JS (`assets/js/bluerails-behavioral-beacon.js`), Markdown (`README.md`, `readme.txt`).
- Cross-repo check: companion PR `Bluerails-2-0/bluerails402#1255` (OPEN) fetched and its actual
  diff (`ingest.ts`, `api.agent-traffic-ingest.ts`, `schema.ts`) read to verify field-name and
  bounds parity — not assumed from either PR's own description.
- External check: `https://complianz.io/developers-guide-for-third-party-integrations/` fetched
  live this session to confirm `cmplz_has_consent`/`cmplz_enable_category`/`cmplz_status_change`
  are real, documented API surface, not a fabricated citation.
- Build verified: `php -l` run directly against all four PHP files in this diff (including the
  untouched one, for completeness) — all pass, no syntax errors.
- Security checked: yes — this is the review's central finding. The consent/key-handling paths
  are sound; the new public REST route's authorization model is not (see Important Issues).
- No automated test suite exists in this repo (confirmed, consistent with BLUE-1473's review) —
  not introduced by this diff either; a pre-existing gap.
