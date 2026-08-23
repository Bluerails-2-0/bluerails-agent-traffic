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
   timestamp) and sends it to your configured ingest endpoint via `wp_remote_post()` with
   `blocking => false` — this never delays or blocks the page response for the visitor
   (or bot) that triggered it.

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
  "site_url": "https://example-hotel.com"
}
```

The backend resolves `org_id` **server-side from the API key** — the plugin never sends an
`org_id`, and the backend must never trust a client-supplied one. The endpoint URL and API
key are both wp-admin-configurable settings (WordPress Options API), not hardcoded, so the
ingest path can change without a plugin update.

## Bot list is a local copy, not a live sync

`includes/class-bluerails-bot-detector.php` ships a static seed list of the most-cited AI/LLM
crawlers in the public record (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot,
Bytespider, Amazonbot, Applebot-Extended, meta-externalagent). This plugin has no access to
Bluerails' canonical `agent_bot_registry` and does not sync with it — this list **will drift**
over time. That's expected and safe: the backend re-verifies every submitted bot name
server-side, so a stale local list can under- or over-match on this plugin's side, but it
never corrupts what actually lands in your dashboard.

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

No database schema — only two WordPress options are used (`bluerails_agent_traffic_endpoint_url`,
`bluerails_agent_traffic_api_key`), plus one boolean flag (`bluerails_agent_traffic_has_cdn`)
for the CDN question, all via the standard WordPress Options API.

## License

GPL-2.0-or-later. See `LICENSE`.
