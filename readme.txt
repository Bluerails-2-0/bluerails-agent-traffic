=== Bluerails Agent Traffic Capture ===
Contributors: bluerails
Tags: ai bots, crawler, ai crawler, seo, analytics
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.0
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
4. If the User-Agent matches nothing, checks whether the visitor clicked through from
   an AI assistant (ChatGPT, Perplexity, Claude, Gemini or Copilot): the `Referer`
   header's host is one of theirs, or the landing URL's `utm_source` names one. A match
   sends the same payload shape, with an empty bot name and the full raw User-Agent in
   place of a matched substring. Bluerails counts these as people sent by AI
   assistants, separately from bots, and keeps only a daily count per assistant and
   page folder.
5. If neither matched and the request's `Accept` header ranks `text/markdown` or
   `text/x-markdown` above `text/html`, sends the same payload plus that raw header.
   Tools that fetch a page for an AI often ask for markdown while posing as a browser;
   browsers never put markdown ahead of HTML. Bluerails ranks the header again
   server-side and records the hit as an unidentified agent (low confidence).

6. Optionally (OFF by default — Settings → Bluerails Agent Traffic → "Behavioral
   signal (beta)"): enable a small JS beacon that runs in visitors' browsers to help
   identify rendered-browser AI agents (browser-extension agentic sessions such as
   ChatGPT's Chrome extension/Work app or Anthropic's Claude for Chrome) that present as an
   ordinary Chrome browser with no distinguishing User-Agent or Referer. Only runs
   after this site's own **Complianz** consent plugin reports visitor "statistics"
   consent — see "Behavioral signal beacon (opt-in, BLUE-1474)" below.

7. When Bluerails switches on ChatGPT ad measurement for your account ("OpenAI Ads
   Messung"), loads OpenAI's Measurement Pixel after the visitor's consent and reports
   page views and clicks on your booking links to your ChatGPT ad campaigns. See "OpenAI
   Ads measurement" below.

8. With a listing ID from Bluerails, serves your hotel's agent door:
   `/.well-known/poppy.json` and `/.well-known/poppy-0.4.json`, which tell AI agents
   where to get this hotel's live prices and booking, and reports every fetch of them.
   See "Agent door" below.

No database table and no custom schema are used — the plugin stores its settings as
entries in the standard WordPress Options API (ingest endpoint URL, API key, a CDN
yes/no flag, the behavioral-signal opt-in flag and the agent-door listing ID), plus the
cached OpenAI Ads measurement settings (a transient and an option) and the cached
agent-door documents (transients).

= External services =

This plugin is a client for the Bluerails Discovery **Agent Traffic** ingest API, a
service operated by Bluerails (https://bluerails.com). It is inherently an "opt-in,
software-as-a-service" integration: **no data is sent anywhere until you configure
both the Ingest Endpoint URL and the API key on the plugin's settings screen.** Both
fields are empty by default on activation, and the plugin's send routine checks for a
non-empty endpoint URL and API key before every request — if either is missing, the
request is skipped entirely and nothing leaves your site.

The full list of AI-bot User-Agent substrings this plugin matches against (as of
1.4.0, matched without regard to case) is: GPTBot, ClaudeBot, PerplexityBot,
Google-Extended, CCBot, Bytespider, Amazonbot, Applebot-Extended, Meta-ExternalAgent,
OAI-SearchBot, ChatGPT-User, Claude-User, Claude-SearchBot, Perplexity-User,
Google-CloudVertexBot, Meta-ExternalFetcher, DuckAssistBot, MistralAI-User,
Diffbot-User, Diffbot, Kagibot, Bravebot, YouBot, YiyanBot, YandexAdditionalBot,
Doubaobot, QwenBot, TongyiBot, Timpibot, ImagesiftBot, Omgilibot, omgili,
webzio-extended, webzio, Andibot, Google-Agent, GoogleOther, Google-GeminiNotebook,
Amzn-User, Amzn-SearchBot, MistralAI-Index, ExaUser, Liner-User, grok-agent,
kagi-fetcher, TavilyBot, AzureAI-SearchBot, Gemini-Deep-Research, cohere-ai, AI2Bot,
Kimi-User. The same list is also shown on the settings screen for cache-exclusion
rules.

Once configured, on every detected AI-bot hit the plugin sends this JSON payload to
your configured endpoint (`https://discovery.bluerails.com/api/agent-traffic-ingest`
by default, but this is a setting, not hardcoded, and can point elsewhere if Bluerails
changes it):

* `bot_name` — the matched bot's name (e.g. "GPTBot"), empty string on a
  referer-fallback match (see below)
* `matched_ua_string` — the User-Agent substring that matched, or the full raw
  User-Agent on a referer-fallback match (there is no matched substring to send)
* `page_path` — the requested page's path, including its query string as received
  (Bluerails stores the path without the query)
* `page_url` — the requested page's full URL
* `timestamp` — an ISO 8601 UTC timestamp of the hit
* `site_url` — this site's home URL
* `referer` — the raw `Referer` header value (empty string when absent), sent on
  every reported hit (Bluerails stores only its host)
* `accept` — the raw `Accept` header, sent only on a markdown hit (step 5 above)
* `door_document` — `poppy.json` or `poppy-0.4.json`, sent only on a fetch of the agent
  door (step 8 above). Every such fetch is reported whatever its User-Agent, with the
  full raw User-Agent (or `-` when there is none)

On a request whose User-Agent matches no known bot signature, the plugin also reports a
visitor who clicked through from an AI assistant: the `Referer` host is chatgpt.com,
perplexity.ai, claude.ai, gemini.google.com or copilot.microsoft.com (or a subdomain of
one), or the landing URL's `utm_source` is one of those hosts or the assistant's name
(chatgpt, perplexity, claude, gemini, copilot). It sends the same payload shape described
above. For these visitors Bluerails keeps only a count per day, assistant and page
folder; it does not store their User-Agent, Referer or page address. Search engines
such as Google and Bing never match.

No page content and no visitor/bot IP addresses are sent. The `referer` and
`page_path`/`page_url` fields are passed through unmodified from the request and, being
URLs, may incidentally carry identifiers (for example a search query, a chat-thread ID
or an email address in a query string) — they are not scrubbed before being sent.
Bluerails reduces the referrer to its host and drops the page address's query before
storing anything. The request also carries your configured API key as a
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

= OpenAI Ads measurement (BLUE-2725) =

For hotels whose ChatGPT ads Bluerails manages. **Off until Bluerails switches it on** for
your account; nothing on this site changes before that, and there is no setting to tick.

**How it is switched on.** About once an hour, in the background (WP-cron, never while a
visitor waits), the plugin asks Bluerails for its measurement settings at
`https://discovery.bluerails.com/api/ads-measurement-config` (same host as your ingest
endpoint), sending the API key you already configured. The answer is the on/off flag, your
OpenAI Pixel ID and the booking links to count. It is cached for an hour. If Bluerails cannot
be reached, the last answer stays in use for up to seven days; after that measurement switches
itself off until Bluerails answers again. The request goes only to an `https` address (plain
`http` only while `WP_DEBUG` is on), follows no redirects and reads at most 16 KB.

**How long a change takes.** Up to an hour for the cached answer, plus two things outside the
plugin's control:

* **WP-cron** runs only when someone visits the site (unless your host runs it from a real cron
  job). On a quiet site, or one with `DISABLE_WP_CRON` and no system cron, the refresh waits for
  the next run.
* **Page caches** (WP Rocket, host or CDN caching) keep serving pages built with the old
  answer until they expire or are purged. Purge the page cache after Bluerails switches
  measurement on or off if the change must apply at once.

Nothing runs in the Customizer preview or on post previews.

**What it loads, and when.** When switched on, a small script in the page `<head>` waits for
the visitor's consent and only then loads OpenAI's Measurement Pixel
(`https://bzrcdn.openai.com/sdk/oaiq.min.js`) exactly as OpenAI's install guide gives it:

* **Borlabs Cookie:** consent to the **Marketing** service group
  (`BorlabsCookie.Consents.hasConsentForServiceGroup('marketing')`).
* **Real Cookie Banner:** consent to a service with the unique name
  `openai-ads-measurement` (create it in Real Cookie Banner; the name can be changed with the
  `bluerails_oai_ads_rcb_service` filter). Without such a service nothing loads.
* **Neither:** nothing is loaded at all. The plugin waits up to 3 seconds after the page has
  loaded for Borlabs Cookie or Real Cookie Banner to appear, and otherwise does nothing. A site
  whose consent is handled by something else can declare that with
  `add_filter( 'bluerails_oai_ads_assume_consent', '__return_true' );`, and is then responsible
  for having that consent.

Consent withdrawn later in the visit stops the Pixel, deletes its cookies and stops booking
clicks from being counted; consent given again resumes it.

It then sends `page_viewed` once per page, and `checkout_started` when a visitor clicks a link
to one of your booking links (a host such as your booking engine's, or a page on your own site
such as `/hotel-buchen`).

If the site already runs an OpenAI Pixel (`window.oaiq` exists), the plugin loads nothing, so
the Pixel is never installed twice. The Pixel sets two first-party cookies, `__oppref` (30 days)
and `__obref` (365 days), and sends events to `https://bzr.openai.com`. If your site enforces a
Content Security Policy, allow `https://bzrcdn.openai.com` in `script-src` and
`https://bzr.openai.com https://bzrcdn.openai.com` in `connect-src` (and `https://bzr.openai.com`
in `img-src`).

= Agent door (BLUE-2656) =

**Off until you enter a Bluerails listing ID** (Settings → Bluerails Agent Traffic → Agent
door). With one set, this site answers `/.well-known/poppy.json` and
`/.well-known/poppy-0.4.json` itself, on its own address. The content comes from Bluerails:
the plugin fetches `https://402.bluerails.com/mcp/agent/v1/hotels/<listing ID>/poppy.json`
(and `poppy-0.4.json`) when an agent asks for it, sends nothing about the visitor in that
request, keeps the answer for five minutes, and passes it on unchanged as `application/json`.
The documents name one address: a Bluerails server that answers for this hotel only, never
for other hotels.

* When Bluerails switches the door off, or the listing is unknown, both paths answer 404.
* If the document names a different host than the one the request came to (for example
  `www.` against no `www.`), the plugin answers 404 and writes a line to the PHP error log,
  instead of serving a document agents would reject.
* If Bluerails cannot be reached, the plugin answers 503 and tries again after a minute.
* The answers are marked not to be cached, so every fetch reaches WordPress and is counted.

**Hosts that answer `/.well-known/` themselves.** Some hosts serve `/.well-known/*.json`
straight from disk and answer with their own 404 page, without asking WordPress. Open
`https://<your site>/.well-known/poppy.json` after saving the listing ID: if you see your
host's error page instead of JSON, add one rule that hands the two paths to WordPress.

Apache (`.htaccess`, above `# BEGIN WordPress` and above any other `.well-known` rule):

    RewriteEngine On
    RewriteRule ^\.well-known/(poppy(-0\.4)?\.json)$ /index.php?bluerails_agent_door=$1 [L,QSA]

nginx (in the site's `server` block; exact matches win over every other `location`):

    location = /.well-known/poppy.json     { rewrite ^ /index.php?bluerails_agent_door=poppy.json last; }
    location = /.well-known/poppy-0.4.json { rewrite ^ /index.php?bluerails_agent_door=poppy-0.4.json last; }

If your host lets you change neither, the agent door cannot be served by this plugin.

This plugin serves no `/.well-known/oauth-protected-resource`, `/.well-known/mcp.json` or
`llms.txt`. If another plugin serves `oauth-protected-resource` on your site, agents that
read the door may try to sign in to it; remove it unless you run an MCP server on this site.

= Known limitations =

* **CDN edge caching**: if a CDN (Cloudflare, CloudFront, etc.) serves a cached page
  directly from its edge, the request never reaches WordPress and this plugin cannot
  see it — there is no WordPress-level fix for that. The settings screen asks whether
  your site uses a CDN and, if so, shows a persistent admin notice explaining that
  crawl data will be incomplete for cached pages.
* **Full-page cache plugins**: if a page is served from a full-page cache (e.g. WP
  Rocket), WordPress's PHP — and this plugin — never runs for that request. The
  settings screen lists the bot User-Agent substrings so you can add a "never cache
  these user agents" rule in your cache plugin. Tools that ask for markdown while posing
  as a browser cannot be excluded that way, so their hits on cached pages are invisible
  to this plugin.
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
`referer` and page fields are passed through unmodified and may incidentally carry
identifiers in a query string. Bluerails stores the referrer's host only and the page
path without its query. For a visitor sent by an AI assistant it stores only a daily
count.

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

= 1.8.0 =
* Adds the agent door: with a Bluerails listing ID set, serves `/.well-known/poppy.json`
  and `/.well-known/poppy-0.4.json` on your own address, fetched from Bluerails and kept
  for five minutes, so AI agents find this hotel's own booking server (never a list of
  other hotels). Answers 404 when Bluerails switches it off or the document names another
  host. Every fetch of the two paths is reported, whatever the User-Agent. Off until a
  listing ID is entered.

= 1.7.0 =
* Adds OpenAI Ads measurement ("OpenAI Ads Messung"): once Bluerails switches it on for your
  account, loads OpenAI's Measurement Pixel after visitor consent (Borlabs Cookie marketing
  group or Real Cookie Banner; nothing loads without one of them) and reports page views and
  clicks on your booking links (`checkout_started`). Skipped when the site already has a
  Pixel. Off until Bluerails switches it on.

= 1.6.0 =
* Reports visitors who clicked through from an AI assistant when the landing URL's
  `utm_source` names one (for example `utm_source=chatgpt.com`), not only when the
  `Referer` header does. Bluerails counts them as people sent by AI assistants, apart
  from bots.
* Adds Copilot (copilot.microsoft.com) to the AI-assistant referrer list. Bing search
  does not match.

= 1.5.0 =
* Reports requests signed by an AI agent (carrying `Signature`, `Signature-Input` and
  `Signature-Agent`), such as ChatGPT's agent, even when the user agent is an ordinary
  browser. The three headers are sent as received, with the requested host, scheme,
  HTTP method and raw request URI, so Bluerails can verify the signature; only verified
  requests are counted as that agent.

= 1.4.0 =
* Reports requests whose `Accept` header prefers markdown (`text/markdown` or
  `text/x-markdown` ranked above `text/html`) when no known bot or AI referrer
  matched, forwarding the raw header. Bluerails ranks it again server-side and counts
  the hit as an unidentified agent.
* Adds AI assistant and agent signatures: Google-Agent, GoogleOther,
  Google-GeminiNotebook, Amzn-User, Amzn-SearchBot, MistralAI-Index, ExaUser,
  Liner-User, grok-agent, kagi-fetcher, TavilyBot, AzureAI-SearchBot,
  Gemini-Deep-Research, plus cohere-ai, AI2Bot (also catches Ai2Bot-Dolma) and
  Kimi-User.
* Fixes Meta-ExternalAgent and Omgilibot hits: the plugin sent these tokens in a
  casing Bluerails did not recognise, so Meta-ExternalAgent hits were dropped and
  Omgilibot hits were counted as omgili.

= 1.3.2 =
* Hotfix: fixes a PHP namespace error in the auto-update checker introduced in 1.3.1
  (`YahnisElsts\PluginUpdateChecker\v5\Vcs\Api` referenced a class that does not exist
  in the vendored library; the real namespace is `v5p7\Vcs\Api`) that fataled the
  plugin on every single request. Sites still on 1.3.1 should update immediately.

= 1.3.1 =
* Adds an auto-update checker (vendored `YahnisElsts/plugin-update-checker`) pointed at
  this plugin's own GitHub Releases, so installed sites see WordPress core's native
  "Update available" notice and one-click update instead of needing a manual zip
  re-upload for every release.

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

= 1.8.0 =
Lets this site serve its own agent door (poppy.json) once Bluerails gives you a listing ID. Nothing changes until one is entered.

= 1.7.0 =
Lets Bluerails switch on ChatGPT ad measurement (OpenAI Measurement Pixel, after visitor consent) for your site.

= 1.6.0 =
Counts visitors sent by ChatGPT, Perplexity, Claude, Gemini and Copilot from the link's utm_source tag as well as the referrer.

= 1.5.0 =
Counts AI agents that sign their requests, such as ChatGPT's agent, which browse with an ordinary browser user agent.

= 1.4.0 =
Recognises 16 more AI assistants and crawlers, reports agents that ask for markdown, and fixes dropped Meta-ExternalAgent hits.

= 1.3.2 =
Hotfix for a fatal error on every request introduced in 1.3.1. Update immediately if you are on 1.3.1.

= 1.3.1 =
Adds an auto-update checker so future releases show WordPress's native update notice. Note: this version has a known fatal-error bug, fixed in 1.3.2 — update again immediately after installing.

= 1.3.0 =
Adds vendor-confirmed AI live-fetch *-User/*-SearchBot signatures and additional
aggregator-corroborated crawler signatures — see Changelog for the full list.

= 1.2.0 =
Adds an OFF-by-default, opt-in behavioral signal for rendered-browser AI agents (requires Complianz for visitor consent).

= 1.1.0 =
Adds a low-coverage referer-based fallback signal for AI-assistant traffic that presents no bot User-Agent.

= 1.0.0 =
Initial release.
