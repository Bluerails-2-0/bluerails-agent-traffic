# Bluerails Agent Traffic Capture

WordPress plugin that detects AI-bot crawler traffic (GPTBot, ClaudeBot, PerplexityBot, etc.)
on a WordPress site and reports it into your Bluerails Discovery **Agent Traffic** dashboard —
an additional ingestion method for hotel customers who have no server or CDN log access
(Linear: BLUE-1346).

Install via wp-admin (Plugins → Add New → Upload Plugin, zip upload) or a WP.org listing.
No build step, no Composer, no npm — plain PHP, installable as-is.

## What it does

1. Hooks `wp_loaded` (not `template_redirect` — see "Why `wp_loaded`" below) on every
   front-end request.
2. Checks `$_SERVER['HTTP_USER_AGENT']` against a static, local list of known AI-crawler
   substrings (see `includes/class-bluerails-bot-detector.php`).
3. On a match, builds a small JSON payload (bot name, matched UA substring, page path/URL,
   timestamp, referer) and sends it to your configured ingest endpoint via `wp_remote_post()`
   with `blocking => false` — this never delays or blocks the page response for the visitor
   (or bot) that triggered it.
4. If the UA matches nothing, checks `$_SERVER['HTTP_REFERER']` against a small allow-list of
   AI-assistant domains (ChatGPT, Perplexity, Claude, Gemini — `AI_REFERER_DOMAINS`) before
   giving up. A match still sends the same payload shape, with an empty `bot_name` and the full
   raw User-Agent as `matched_ua_string` — a low-coverage fallback signal for browser-extension
   agentic sessions (e.g. ChatGPT's Chrome extension/Work app, Anthropic's Claude for Chrome)
   whose UA carries no distinguishing token.
5. (BLUE-1474, OFF by default) If enabled on the settings screen AND the site runs Complianz
   with visitor "statistics" consent granted, enqueues a small JS beacon
   (`assets/js/bluerails-behavioral-beacon.js`) that observes mouse-movement timing/quantization
   client-side and POSTs a feature summary to this plugin's own REST route
   (`includes/class-bluerails-behavioral-beacon.php`), which forwards it server-side to the same
   ingest endpoint. See "Behavioral signal beacon" below for the full design.

## Setup

1. Install and activate the plugin.
2. Go to **Settings → Bluerails Agent Traffic** in wp-admin.
3. Paste the **Ingest Endpoint URL** and **API Key** generated in your Bluerails dashboard
   (Settings → Agent Traffic).
4. Answer the **CDN** question honestly — see "CDN limitation" below.
5. If you run a full-page-cache plugin, follow the cache-exclusion instructions shown on the
   settings screen (WP Rocket specifically is called out there — see below).

## Auth convention (for the Bluerails backend team)

Every ingest POST carries the site's API key as a **Bearer token** in the `Authorization`
header:

```
Authorization: Bearer <api_key>
Content-Type: application/json
```

Example payload body:

```json
{
  "bot_name": "GPTBot",
  "matched_ua_string": "GPTBot",
  "page_path": "/rooms/deluxe-suite",
  "page_url": "https://example-hotel.com/rooms/deluxe-suite",
  "timestamp": "2026-08-23T14:05:00+00:00",
  "site_url": "https://example-hotel.com",
  "referer": "https://chatgpt.com/c/abc123"
}
```

`referer` (added, optional — empty string when absent) is the raw `Referer` header, sent
whenever a request is reported at all. On a UA-miss + AI-referer-match row, `bot_name` is an
empty string and `matched_ua_string` carries the full raw User-Agent (there is no matched
substring to send) — the backend re-derives everything server-side either way; see the next
section.

The backend resolves `org_id` **server-side from the API key** — the plugin never sends an
`org_id`, and the backend must never trust a client-supplied one. The endpoint URL and API
key are both wp-admin-configurable settings (WordPress Options API), not hardcoded, so the
ingest path can change without a plugin update.

**BLUE-1474 behavioral payload** (only sent when the beacon is enabled + consent is granted;
`matched_ua_string` is server-derived from `$_SERVER`, not the browser's own claim):

```json
{
  "matched_ua_string": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
  "page_path": "/rooms/deluxe-suite",
  "timestamp": "2026-08-28T14:05:00+00:00",
  "site_url": "https://example-hotel.com",
  "behavioral": {
    "pointer_capable": true,
    "move_count": 47,
    "duration_ms": 4230,
    "avg_interval_ms": 52.1,
    "interval_stddev_ms": 1.3,
    "quantized_ratio": 0.91
  }
}
```

## Bot list is a local copy, not a live sync

`includes/class-bluerails-bot-detector.php` ships a static seed list of AI/LLM crawlers and
live-fetch/`*-User` agents in the public record. This plugin has no access to Bluerails'
canonical `agent_bot_registry` and does not sync with it — this list **will drift** over time.
That's expected and safe: the backend re-verifies every submitted bot name server-side, so a
stale local list can under- or over-match on this plugin's side, but it never corrupts what
actually lands in your dashboard.

As of 1.3.0 (BLUE-1527), the list has two tiers, both in `BOT_SIGNATURES`:

* **Original set (training/broad crawlers):** GPTBot, ClaudeBot, PerplexityBot, Google-Extended,
  CCBot, Bytespider, Amazonbot, Applebot-Extended, meta-externalagent.
* **Tier 1 (vendor/Cloudflare-confirmed, incl. `*-User` live-fetch bots):** OAI-SearchBot,
  ChatGPT-User, Claude-User, Claude-SearchBot, Perplexity-User, Google-CloudVertexBot,
  Meta-ExternalFetcher, DuckAssistBot, MistralAI-User, Diffbot-User, Diffbot, Kagibot.
* **Tier 2 (multi-aggregator-corroborated, same evidentiary bar already applied to
  Bytespider/meta-externalagent):** Bravebot, YouBot, YiyanBot, YandexAdditionalBot, Doubaobot,
  QwenBot, TongyiBot, Timpibot, ImagesiftBot, omgilibot, omgili, webzio-extended, webzio, Andibot.

`match_bot()` is first-match-wins over `BOT_SIGNATURES` in array order. Three pairs in this
list have one token as a literal substring of another (`Diffbot`/`Diffbot-User`,
`omgili`/`omgilibot`, `webzio`/`webzio-extended`) — each longer/more-specific token is ordered
first in the array, with an inline comment at each pair, so a live-fetch hit is never
misclassified as the generic crawler. Any future addition that is a substring of an existing
entry (or vice versa) must follow the same longer-first ordering rule.

## Referer allow-list is a local copy too

`AI_REFERER_DOMAINS` (chatgpt.com, perplexity.ai, claude.ai, gemini.google.com) mirrors the
backend's own allow-list (`AI_REFERER_ALLOWLIST` in `visibility-web/app/features/agent-traffic/
ingest.ts`) but is not synced with it — same drift model as the bot-signature list above. The
backend independently re-classifies the submitted `referer` against its own copy, so a stale
list here can under- or over-fire the POST but never mislabels what lands in the dashboard.
Most AI-assistant traffic (browser-extension agentic sessions in particular — CORRECTED
2026-08-28: ChatGPT Atlas the standalone app was retired 2026-08-09; its successor is a Chrome
extension + Work desktop app on the same architecture, and Anthropic's Claude for Chrome is a
directly comparable live product) strips the Referer header entirely, so this only ever catches
a minority of that traffic — it is a cheap, additive signal, not the primary detection path.

## Behavioral signal beacon (opt-in, BLUE-1474)

A THIRD identification path, structurally different from the two above: it can only be
observed **client-side**, because it's mouse-movement behavior, not a request header. Ships
OFF by default (`bluerails_agent_traffic_behavioral_enabled`, a settings-screen checkbox
independent of the endpoint/API-key config) — closes the gap left by rendered-browser agentic
sessions (any browser-extension agent: ChatGPT's post-Atlas Chrome extension/Work app,
Anthropic's Claude for Chrome, and equivalents) that present as an ordinary Chrome UA with no
distinguishing header at all, confirmed against OpenAI's own OWL architecture writeup for Atlas
(`.claude/ticket-reviews/BLUE-1474.md` claim 2 — the same Chromium-extension architecture
applies to its successor and to Claude for Chrome) to actually execute page JS and dispatch real
pointer events — unlike a raw-fetch training crawler, which never runs this code path at all.

**Consent mechanism chosen: Complianz's documented public JS API**
(`cmplz_has_consent(category)` / `cmplz_enable_category` / `cmplz_status_change` — see
https://complianz.io/developers-guide-for-third-party-integrations/, fetched and verified this
session). Picked over a raw cookie read because Complianz's own developer docs explicitly
recommend the JS function/event API over reading its cookies directly, and because it's the
single most-installed WordPress consent plugin with a genuinely documented, stable contract —
the HALT criterion for this ticket was "a clean, single, well-documented way to read consent,"
and this is it. The beacon (`assets/js/bluerails-behavioral-beacon.js`) checks
`cmplz_has_consent('statistics')` before doing anything else; if Complianz isn't present on the
site at all, the beacon does nothing — fail closed, never assumes consent. Support for other
CMPs (CookieYes, Borlabs, a generic `window.__consentGranted` hook) is explicitly out of scope
for this version; nothing in `.claude/ticket-reviews/BLUE-1474.md` or the plugin's existing code
documented an equivalent contract for any of those, so extending coverage is future work, not a
silent gap papered over here.

**Why a REST proxy, not a direct POST to the Bluerails endpoint from the browser.** The
existing two signals run entirely in PHP and attach the API key server-side. This is the first
one whose DATA (mouse-movement timing) can only be collected in the browser — POSTing directly
from there to the Bluerails endpoint would require putting the Bearer API key in a
browser-visible JS variable, readable by any visitor via view-source or the network tab. Instead
the beacon POSTs to this SITE's own new REST route
(`bluerails-agent-traffic/v1/behavioral`, `includes/class-bluerails-behavioral-beacon.php`),
which re-derives the User-Agent from `$_SERVER` (never trusts the browser's own claim, same
discipline as the other two paths) and forwards server-side via `wp_remote_post` with the API
key attached, exactly like `report_hit()`. The API key never reaches the browser.

**Mobile/pointer gating.** The beacon reports `matchMedia('(pointer: fine)')` as
`pointer_capable`; the BACKEND (not this plugin) refuses to score pointer-absence as a bot
signal on a non-pointer-capable session, since real mobile/touch visitors commonly fire zero or
sparse `mousemove` events — the same shape this heuristic would otherwise flag. See
`visibility-web/app/features/agent-traffic/ingest.ts`'s `computeBehavioralScore` for the actual
gating logic; this plugin only reports the flag, it does not gate on it itself.

**Score, not verdict.** The beacon sends a raw feature summary (move count, timing
regularity, sub-pixel quantization ratio, pointer-capable flag) — never a confidence value or a
bot/human label. All scoring happens server-side, under the SAME discipline as the existing
bot-UA path (the plugin's own claims are never trusted for the label that lands in your
dashboard).

## Why `wp_loaded`, not `template_redirect`

WordPress's own hook reference recommends the earlier hooks (`init`/`wp_loaded`) for
full-request logging. `template_redirect` is oriented around redirect logic, and WordPress's
docs warn that calling `exit()`/`die()` there can skip subsequent handlers — not the right
hook for something that should run on every request regardless of what else happens later in
the request lifecycle.

## Full-page cache: exclude AI bots

If a page is served from a full-page cache, WordPress's PHP (and this plugin) never runs for
that request. If you use **WP Rocket**, add the bot names shown on the settings screen to
**Settings → WP Rocket → Advanced Rules → "Never Cache User Agent(s)"** so bot requests always
bypass the cache and reach PHP. Using a different cache plugin or host-level caching? Look for
an equivalent "exclude user agent" / "bypass cache" rule — this plugin cannot detect or
configure that for you.

## CDN limitation (read this)

If your site sits behind a CDN (Cloudflare, CloudFront, etc.), the CDN can serve a cached page
straight from its own edge — the request never reaches WordPress at all. This plugin runs
entirely inside WordPress, so those edge-cached bot hits are **invisible to it**; there is no
WordPress-level fix for this. The settings screen asks a plain yes/no question ("Does your
site use a CDN like Cloudflare in front of WordPress?") and, if you answer yes, shows a
persistent admin notice reminding you that crawl data will be incomplete for cached pages —
this is expected behavior, not a plugin malfunction, and it is surfaced rather than left to
silently under-report with no explanation.

**This plugin is not the only capture path.** On the paid Discovery tier, Bluerails also
supports connecting a customer's CDN logs directly (e.g. Cloudflare Logpush) via
**Dashboard → Agent Traffic → Connect Logs** — that path sees edge-cached hits this plugin
structurally cannot. The two are complementary: connect CDN logs if you have a CDN, and this
plugin still covers whatever traffic isn't cached. The settings screen and the persistent
notice both point customers at Connect Logs now, not just at the limitation.

## Files

- `bluerails-agent-traffic.php` — main plugin file (header, activation/deactivation hooks).
- `includes/class-bluerails-settings.php` — admin settings screen (Settings API).
- `includes/class-bluerails-bot-detector.php` — UA matching + async POST to the ingest endpoint.
- `includes/class-bluerails-behavioral-beacon.php` — (BLUE-1474) enqueues the JS beacon + the
  same-origin REST proxy that forwards its feature summary server-side.
- `assets/js/bluerails-behavioral-beacon.js` — (BLUE-1474) client-side mouse-movement observer,
  gated on Complianz consent.
- `includes/plugin-update-checker/` — (BLUE-1534) vendored `YahnisElsts/plugin-update-checker`
  (PUC) v5.7, unmodified. Initialized in the main plugin file, pointed at this repo's own
  GitHub Releases, so installed sites get WordPress core's native update notice instead of a
  manual zip re-upload. See "Cutting a release" below.
- `.github/workflows/check-puc-update.yml` + `.github/scripts/check-puc-update.sh` —
  (BLUE-1537) runs weekly (and on manual `workflow_dispatch`) to check the vendored
  PUC copy above against PUC's own latest GitHub release and open a PR re-vendoring
  it on drift — no Composer, so Dependabot cannot see this dependency on its own. No
  auto-merge; a human reviews the diff.

No database schema — WordPress options only: `bluerails_agent_traffic_endpoint_url`,
`bluerails_agent_traffic_api_key`, `bluerails_agent_traffic_has_cdn` (the CDN question), and
`bluerails_agent_traffic_behavioral_enabled` (BLUE-1474, OFF by default), all via the standard
WordPress Options API.

## Cutting a release

Before cutting: `php bin/boot-test.php` must exit 0 — it also runs in CI on every PR touching
`*.php` (`.github/workflows/boot-test.yml`), but re-run it locally against whatever's actually
in the working tree right before the `rsync` below, since that step packages local files, not a
pinned git ref.

The live dashboard's plugin-download link (`discovery.bluerails.com` → Settings) points at
`releases/latest/download/bluerails-agent-traffic.zip` — a stable filename, not tied to a
version number, so the link never needs a code change. Every release's assets MUST include a
copy of the zip under that exact stable name (in addition to the versioned one), or the
dashboard link silently breaks the moment this release becomes "latest":

As of 1.3.1 (BLUE-1534), the vendored update checker (`includes/plugin-update-checker/`)
also reads this repo's GitHub Releases directly, filtered to that same stable-named zip
asset (`enableReleaseAssets('/^bluerails-agent-traffic\.zip$/')` in the main plugin file).
Once a release is cut per the steps below, every already-installed site picks it up
automatically via WordPress core's own "Update available" notice — no extra step needed.

```bash
mkdir -p /tmp/plugin-zip/bluerails-agent-traffic
rsync -a --exclude='.git' --exclude='.gitignore' --exclude='README.md' --exclude='REVIEW-*.md' ./ /tmp/plugin-zip/bluerails-agent-traffic/
( cd /tmp/plugin-zip && zip -r bluerails-agent-traffic-X.Y.Z.zip bluerails-agent-traffic )
cp /tmp/plugin-zip/bluerails-agent-traffic-X.Y.Z.zip bluerails-agent-traffic.zip
gh release create vX.Y.Z /tmp/plugin-zip/bluerails-agent-traffic-X.Y.Z.zip bluerails-agent-traffic.zip \
  --repo Bluerails-2-0/bluerails-agent-traffic --title "..." --notes "..."
```

## License

GPL-2.0-or-later. See `LICENSE`.
