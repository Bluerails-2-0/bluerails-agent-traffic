=== Bluerails Agent Traffic Capture ===
Contributors: bluerails
Tags: ai bots, crawler, ai crawler, seo, analytics
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detects AI-bot crawler traffic (GPTBot, ClaudeBot, PerplexityBot, etc.) and reports it to your Bluerails Discovery Agent Traffic dashboard.

== Description ==

Bluerails Agent Traffic Capture detects AI-bot crawler traffic — GPTBot, ClaudeBot,
PerplexityBot, Google-Extended, CCBot, Bytespider, Amazonbot, Applebot-Extended,
meta-externalagent — hitting your WordPress site, and reports each hit to your
Bluerails Discovery **Agent Traffic** dashboard. It exists as an additional ingestion
method for site owners who have no server or CDN log access of their own.

**How it works:**

1. Hooks `wp_loaded` on every front-end request (front-end only — admin, cron, and
   AJAX requests are skipped).
2. Checks the request's `User-Agent` header against a static, local list of known
   AI-crawler substrings.
3. On a match, sends a small JSON payload (bot name, matched User-Agent substring,
   page path/URL, timestamp, site URL) to your configured Bluerails ingest endpoint
   using `wp_remote_post()` with `blocking => false`, so this never delays or blocks
   the page response for the visitor (or bot) that triggered it.

No database table and no custom schema are used — the plugin stores its settings as
three entries in the standard WordPress Options API (ingest endpoint URL, API key,
and a CDN yes/no flag).

= External services =

This plugin is a client for the Bluerails Discovery **Agent Traffic** ingest API, a
service operated by Bluerails (https://bluerails.com). It is inherently an "opt-in,
software-as-a-service" integration: **no data is sent anywhere until you configure
both the Ingest Endpoint URL and the API key on the plugin's settings screen.** Both
fields are empty by default on activation, and the plugin's send routine checks for a
non-empty endpoint URL and API key before every request — if either is missing, the
request is skipped entirely and nothing leaves your site.

Once configured, on every detected AI-bot hit the plugin sends this JSON payload to
your configured endpoint (`https://discovery.bluerails.com/api/agent-traffic-ingest`
by default, but this is a setting, not hardcoded, and can point elsewhere if Bluerails
changes it):

* `bot_name` — the matched bot's name (e.g. "GPTBot")
* `matched_ua_string` — the User-Agent substring that matched
* `page_path` — the requested page's path
* `page_url` — the requested page's full URL
* `timestamp` — an ISO 8601 UTC timestamp of the hit
* `site_url` — this site's home URL

No personally identifiable information, no page content, and no visitor/bot IP
addresses are sent. The request also carries your configured API key as a Bearer
token in the `Authorization` header, so Bluerails can attribute the hit to your
account server-side.

By configuring this plugin you agree to Bluerails' Terms of Service
(https://bluerails.com/terms) and Privacy Policy (https://bluerails.com/privacy).

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
bot name, matched User-Agent substring, page path/URL, timestamp, and your site URL —
no PII, no page content, no visitor IP addresses.

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

No. It stores three settings via the standard WordPress Options API and creates no
custom table or schema.

== Screenshots ==

1. Settings screen — configure the ingest endpoint URL, API key, and CDN flag.

== Changelog ==

= 1.1.0 =
* Also reads the Referer header on a request whose User-Agent matches no known
  bot signature, and reports it when it points at a small allow-list of
  AI-assistant domains (ChatGPT, Perplexity, Claude, Gemini) — a low-coverage
  fallback signal for agentic browsers (e.g. ChatGPT Atlas) whose UA carries no
  distinguishing token.

= 1.0.0 =
* Initial release: AI-bot User-Agent detection, async POST to the configured
  Bluerails ingest endpoint, wp-admin settings screen, CDN and full-page-cache
  guidance.

== Upgrade Notice ==

= 1.1.0 =
Adds a low-coverage referer-based fallback signal for AI-assistant traffic that presents no bot User-Agent.

= 1.0.0 =
Initial release.
