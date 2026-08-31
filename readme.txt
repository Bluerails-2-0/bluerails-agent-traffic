=== Bluerails Agent Traffic Capture ===
Contributors: bluerails
Tags: ai bots, crawler, ai crawler, seo, analytics
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detects AI-bot crawler traffic (GPTBot, ClaudeBot, PerplexityBot, etc.) and reports it to your Bluerails Discovery Agent Traffic dashboard.

== Description ==

Bluerails Agent Traffic Capture detects AI-bot crawler traffic — including training
crawlers (GPTBot, ClaudeBot, PerplexityBot, and more) and vendor-confirmed live-fetch
`*-User` bots (ChatGPT-User, Claude-User, Perplexity-User, and more) — hitting your
WordPress site, and reports each hit to your Bluerails Discovery **Agent Traffic**
dashboard. It exists as an additional ingestion method for site owners who have no
server or CDN log access of their own. See "External services" below for the full
signature list.

**How it works:**

1. Hooks `wp_loaded` on every front-end request (front-end only — admin, cron, and
   AJAX requests are skipped).
2. Checks the request's `User-Agent` header against a static, local list of known
   AI-crawler substrings.
3. On a match, sends a small JSON payload (bot name, matched User-Agent substring,
   page path/URL, timestamp, site URL, referer) to your configured Bluerails ingest
   endpoint using `wp_remote_post()` with `blocking => false`, so this never delays
   or blocks the page response for the visitor (or bot) that triggered it.
4. If the User-Agent matches nothing, checks the `Referer` header against a small
   allow-list of AI-assistant domains (ChatGPT, Perplexity, Claude, Gemini) before
   giving up. A match still sends the same payload shape, with an empty bot name and
   the full raw User-Agent in place of a matched substring — a low-coverage fallback
   signal for browser-extension agentic sessions (e.g. ChatGPT's Chrome extension/Work
   app, Anthropic's Claude for Chrome) whose UA carries no distinguishing token.

5. Optionally (OFF by default — Settings → Bluerails Agent Traffic → "Behavioral
   signal (beta)"): enable a small JS beacon that runs in visitors' browsers to help
   identify rendered-browser AI agents (browser-extension agentic sessions such as
   ChatGPT's Chrome extension/Work app or Anthropic's Claude for Chrome) that present as an
   ordinary Chrome browser with no distinguishing User-Agent or Referer. Only runs
   after this site's own **Complianz** consent plugin reports visitor "statistics"
   consent — see "Behavioral signal beacon (opt-in, BLUE-1474)" below.

No database table and no custom schema are used — the plugin stores its settings as
entries in the standard WordPress Options API (ingest endpoint URL, API key, a CDN
yes/no flag, and the behavioral-signal opt-in flag).

= External services =

This plugin is a client for the Bluerails Discovery **Agent Traffic** ingest API, a
service operated by Bluerails (https://bluerails.com). It is inherently an "opt-in,
software-as-a-service" integration: **no data is sent anywhere until you configure
both the Ingest Endpoint URL and the API key on the plugin's settings screen.** Both
fields are empty by default on activation, and the plugin's send routine checks for a
non-empty endpoint URL and API key before every request — if either is missing, the
request is skipped entirely and nothing leaves your site.

The full list of AI-bot User-Agent substrings this plugin matches against (as of
1.3.0) is: GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, Bytespider,
Amazonbot, Applebot-Extended, meta-externalagent, OAI-SearchBot, ChatGPT-User,
Claude-User, Claude-SearchBot, Perplexity-User, Google-CloudVertexBot,
Meta-ExternalFetcher, DuckAssistBot, MistralAI-User, Diffbot-User, Diffbot, Kagibot,
Bravebot, YouBot, YiyanBot, YandexAdditionalBot, Doubaobot, QwenBot, TongyiBot,
Timpibot, ImagesiftBot, omgilibot, omgili, webzio-extended, webzio, Andibot. The same
list is also shown on the settings screen for cache-exclusion rules.

Once configured, on every detected AI-bot hit the plugin sends this JSON payload to
your configured endpoint (`https://discovery.bluerails.com/api/agent-traffic-ingest`
by default, but this is a setting, not hardcoded, and can point elsewhere if Bluerails
changes it):

* `bot_name` — the matched bot's name (e.g. "GPTBot"), empty string on a
  referer-fallback match (see below)
* `matched_ua_string` — the User-Agent substring that matched, or the full raw
  User-Agent on a referer-fallback match (there is no matched substring to send)
* `page_path` — the requested page's path
* `page_url` — the requested page's full URL
* `timestamp` — an ISO 8601 UTC timestamp of the hit
* `site_url` — this site's home URL
* `referer` — the raw `Referer` header value (empty string when absent), sent on
  every reported hit, not only the fallback path

On a request whose User-Agent matches no known bot signature, the plugin also checks
the `Referer` header against a small allow-list of AI-assistant domains (ChatGPT,
Perplexity, Claude, Gemini) and, on a match, sends the same payload shape described
above as a low-coverage fallback signal.

No page content and no visitor/bot IP addresses are sent. The `referer` field is
passed through unmodified from the visitor's browser and, being a URL, may
incidentally carry identifiers from the referring page or session (for example a
search query or a chat-thread ID in the query string) — it is not scrubbed or
redacted before being sent. The request also carries your configured API key as a
Bearer token in the `Authorization` header, so Bluerails can attribute the hit to
your account server-side.

By configuring this plugin you agree to Bluerails' Terms of Service
(https://bluerails.com/terms) and Privacy Policy (https://bluerails.com/privacy).

= Behavioral signal beacon (opt-in, BLUE-1474) =

A THIRD, separate signal, **OFF by default**, enabled only via the new "Behavioral
signal (beta)" checkbox on the settings screen (an opt-in independent from the
endpoint URL/API key configuration above). Unlike the bot-UA and referer signals,
which run entirely in PHP, this one runs a small JS file
(`assets/js/bluerails-behavioral-beacon.js`) in the visitor's own browser, because
mouse-movement timing can only be observed client-side.

**Consent — read this before enabling.** The beacon never runs at all unless this
site's own **Complianz** cookie-consent plugin (https://wordpress.org/plugins/complianz-gdpr/)
reports the visitor has granted "statistics" consent, checked via Complianz's own
documented public JS API (`cmplz_has_consent()`). If this site does not run
Complianz, enabling this setting has no effect: the beacon script may load, but it
checks for Complianz first and does nothing if it isn't found — no visitor data is
ever collected or sent. Support for other consent-management plugins (CookieYes,
Borlabs, etc.) is not implemented in this version. You, as the site operator, remain
the data controller for your own site's visitors and are responsible for ensuring
your consent configuration is lawful in your jurisdiction before enabling this
feature.

**What it sends.** Once consent is confirmed, the beacon observes mouse-movement
timing/quantization for the current page view (an ordinary consenting visit — never
raw mouse coordinates, keystrokes, or page content) and, via this site's own
`/wp-json/bluerails-agent-traffic/v1/behavioral` REST route (so your Bluerails API
key never has to leave the server and reach the browser), forwards a compact feature
summary to your configured ingest endpoint:

* `pointer_capable` — whether the session has a fine pointer (desktop mouse) at all
* `move_count`, `duration_ms` — how much movement was observed and over what window
* `avg_interval_ms`, `interval_stddev_ms` — timing regularity between movements
* `quantized_ratio` — the fraction of movements landing on a sub-pixel grid pattern
  associated with synthesized (non-human) pointer paths

Bluerails scores this summary server-side into a confidence value — never a hard
bot/human verdict — and stores it under its own `behavioral_heuristic` row in your
Agent Traffic dashboard, kept separate from AI-bot and referrer-based rows. Sessions
with no fine pointer (mobile/touch visitors) are never scored on pointer-absence
alone, to avoid mislabeling ordinary mobile guests.

= Known limitations =

* **CDN edge caching**: if a CDN (Cloudflare, CloudFront, etc.) serves a cached page
  directly from its edge, the request never reaches WordPress and this plugin cannot
  see it — there is no WordPress-level fix for that. The settings screen asks whether
  your site uses a CDN and, if so, shows a persistent admin notice explaining that
  crawl data will be incomplete for cached pages.
* **Full-page cache plugins**: if a page is served from a full-page cache (e.g. WP
  Rocket), WordPress's PHP — and this plugin — never runs for that request. The
  settings screen lists the bot User-Agent substrings so you can add a "never cache
  these user agents" rule in your cache plugin.
* **Bot list drift**: the AI-crawler list shipped in this plugin is a local, static
  copy, not a live sync with Bluerails' canonical bot registry, and will drift over
  time. This is expected: the Bluerails backend re-verifies every submitted bot name
  server-side, so a stale local list can under- or over-match on the plugin's side,
  but it can never corrupt what lands in your dashboard.
* **Behavioral signal requires Complianz**: the opt-in beacon (see above) only runs
  on sites using the Complianz consent plugin. Sites using a different
  consent-management plugin, or none at all, cannot use this signal yet.

== Installation ==

1. Install and activate the plugin (Plugins → Add New, or upload the zip).
2. Go to **Settings → Bluerails Agent Traffic** in wp-admin.
3. Paste the **Ingest Endpoint URL** and **API Key** generated in your Bluerails
   dashboard (Settings → Agent Traffic).
4. Answer the **CDN** question honestly — see "Known limitations" above.
5. If you run a full-page-cache plugin, follow the cache-exclusion instructions shown
   on the settings screen.

Nothing is sent to Bluerails until step 3 is complete.

== Frequently Asked Questions ==

= Does this plugin send any data before I configure it? =

No. The endpoint URL and API key are both empty by default. The plugin checks for a
non-empty endpoint URL and API key immediately before every send and skips the
request entirely if either is missing, so nothing leaves your site until you've
pasted both values in from your Bluerails dashboard.

= What data gets sent, and where? =

See "External services" above for the exact payload fields and destination. In short:
bot name, matched User-Agent substring, page path/URL, timestamp, site URL, and the
raw `Referer` header — no page content, no visitor IP addresses, but note the
`referer` field is passed through unmodified and may incidentally carry identifiers
from the referring page or session.

= I use Cloudflare / CloudFront in front of my site — will this still work? =

Partially. This plugin only runs inside WordPress, so AI-bot hits your CDN serves
straight from its own edge cache never reach WordPress and are invisible to it. On
the paid Discovery tier, Bluerails also supports connecting your CDN's own logs
directly (e.g. Cloudflare Logpush) via Dashboard → Agent Traffic → Connect Logs, which
sees edge-cached hits this plugin structurally cannot. The two are complementary.

= I use a full-page cache plugin — will bot hits still get through? =

Only if you exclude the listed AI-bot User-Agents from your cache plugin's caching
rules. The settings screen lists the exact substrings to exclude (WP Rocket's
"Never Cache User Agent(s)" rule is called out specifically as an example).

= Is the AI-bot list kept up to date automatically? =

No — it's a static local copy bundled with the plugin. See "Known limitations" above.

= Does this plugin add a database table? =

No. It stores its settings via the standard WordPress Options API and creates no
custom table or schema.

= What is the behavioral signal, and do I need it? =

An OFF-by-default, opt-in third signal that helps identify rendered-browser AI
agents (browser-extension agentic sessions such as ChatGPT's Chrome extension/Work
app or Anthropic's Claude for Chrome) that the bot-UA and referer signals structurally cannot
see, because those agents present as an ordinary Chrome browser. It requires the
Complianz consent plugin and visitor "statistics" consent — see "Behavioral signal
beacon" above for the full disclosure. Most sites do not need to enable it; it's
aimed at sites that want the most complete picture of AI-agent traffic and already
run Complianz.

== Screenshots ==

1. Settings screen — configure the ingest endpoint URL, API key, and CDN flag.

== Changelog ==

= 1.3.0 =
* Adds vendor/Cloudflare-confirmed AI live-fetch `*-User` and `*-SearchBot` signatures
  (OAI-SearchBot, ChatGPT-User, Claude-User, Claude-SearchBot, Perplexity-User,
  Google-CloudVertexBot, Meta-ExternalFetcher, DuckAssistBot, MistralAI-User,
  Diffbot-User, Diffbot, Kagibot) and multi-aggregator-corroborated crawler
  signatures (Bravebot, YouBot, YiyanBot, YandexAdditionalBot, Doubaobot, QwenBot,
  TongyiBot, Timpibot, ImagesiftBot, omgilibot, omgili, webzio-extended, webzio,
  Andibot) to the bot-signature list, closing a gap where live, user-initiated AI
  agent visits went entirely undetected. See "External services" above for the full
  list.

= 1.2.0 =
* Adds an OFF-by-default, opt-in behavioral signal: a small JS beacon that observes
  mouse-movement timing/quantization in visitors' browsers to help identify
  rendered-browser AI agents (any browser-extension agentic session — ChatGPT's
  Chrome extension/Work app, Anthropic's Claude for Chrome) that present as an ordinary
  Chrome browser. Gated on the site's own Complianz consent plugin reporting
  visitor "statistics" consent — never runs without it. Sent via this site's own
  new REST route, not directly to Bluerails, so the API key never reaches the
  browser. See "Behavioral signal beacon (opt-in, BLUE-1474)" above.

= 1.1.0 =
* Also reads the Referer header on a request whose User-Agent matches no known
  bot signature, and reports it when it points at a small allow-list of
  AI-assistant domains (ChatGPT, Perplexity, Claude, Gemini) — a low-coverage
  fallback signal for browser-extension agentic sessions (e.g. ChatGPT's Chrome
  extension/Work app, Anthropic's Claude for Chrome) whose UA carries no
  distinguishing token.

= 1.0.0 =
* Initial release: AI-bot User-Agent detection, async POST to the configured
  Bluerails ingest endpoint, wp-admin settings screen, CDN and full-page-cache
  guidance.

== Upgrade Notice ==

= 1.3.0 =
Adds vendor-confirmed AI live-fetch *-User/*-SearchBot signatures and additional
aggregator-corroborated crawler signatures — see Changelog for the full list.

= 1.2.0 =
Adds an OFF-by-default, opt-in behavioral signal for rendered-browser AI agents (requires Complianz for visitor consent).

= 1.1.0 =
Adds a low-coverage referer-based fallback signal for AI-assistant traffic that presents no bot User-Agent.

= 1.0.0 =
Initial release.
