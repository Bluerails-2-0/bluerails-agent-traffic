# Review: BLUE-2656, serve the hotel's agent door (plugin 1.8.0)

Implemented-By: implementer subagent (claude-opus-5-5)
Independent-Reviewer: fresh reviewer subagent (claude-sonnet-5-5)
Review-Provenance: spawned by the orchestrator session (claude-opus-5-5), not by the implementer
Review-Status: INDEPENDENT

Independent review of commit d4b1907 (`BLUE-2656-poppy-door-route`) against `origin/main` (v1.7.0). Reviewer did not write the change. Reviewed from a separate worktree; no code changed.

**Verdict: APPROVE WITH CHANGES.** Nothing here can fatal a hotel site or serve another hotel's file. Three items must be fixed or sequenced before 1.8.0 is released to auto-updating sites (findings 1, 2, 3).

## Lens Coverage

| Lens | Status | Why |
|---|---|---|
| Tech | APPLIES | New `wp_loaded` hook on every front-end request, outbound HTTP, transients, `exit` in a request hook, a new option, SSRF and cache-key surface. |
| Product Manager | APPLIES | Checked against the BLUE-2656 intent: same host, no redirect, 404/410 to 404, domain check, "every GET reported", empty default means nothing changes. Two of those are not met (findings 1, 2). |
| Product Designer | SKIP | Light: no guest-facing screen. The only UI is one settings field and section copy in `class-bluerails-settings.php`; I read the markup and escaping but did not render the WP admin page. |
| Persona | APPLIES | Hotel owner (auto-update, host that blocks `/.well-known/`, hand-edits `.htaccess`) and the AI agent fetching the document (404 vs 503 vs 200 semantics). |
| Cost-Perf | APPLIES | Per-request overhead on normal pages, worker time held by a slow 402, per-fetch ingest POST, transient size. |

## Findings, ranked by what a hotel or guest would see break

### Must fix before release

1. **The door reports hits on sites that have not enabled it; the docs say the opposite.**
   `includes/class-bluerails-bot-detector.php:183` sets `door_document` without asking whether a listing ID exists, and `:226` / `:421` then report the hit. `Bluerails_Agent_Door::listing_id()` is only consulted in `maybe_serve()` (`class-bluerails-agent-door.php:55`). Mechanism: on every site with the ingest URL and key set (all existing customers), any request to `/.well-known/poppy.json`, `/.well-known/poppy-0.4.json`, or any URL with `?bluerails_agent_door=poppy.json`, from any user agent, now sends an ingest POST while WordPress still answers 404. The query-variable form lets anyone trigger these POSTs on any page. Contradicting text: `README.md` ("Without a listing ID the plugin does nothing on these paths and WordPress answers as before"), the `readme.txt` Upgrade Notice ("Nothing changes until one is entered"), and the intent ("empty by default, so nothing changes on existing sites"). The test's `capture()` helper runs with no listing ID and still expects a POST, so the suite encodes the contradiction.
   Fix: in `maybe_capture()` set `$this->door_document` only when `Bluerails_Agent_Door::listing_id()` is non-null (it is only evaluated on the two door paths, so it costs nothing on normal pages), and add a test with no listing ID that expects zero POSTs. Or, if reporting probes on un-enabled sites is wanted, state it in the three docs and the Linear ticket. Not both ways.

2. **"Every GET is reported" is not true end to end yet; the backend rejects most of these reports.**
   The README says so itself. I confirmed it on `origin/main` of bluerails402: `visibility-web/app/routes/api.agent-traffic-ingest.ts:333` returns `422 unrecognized_bot` when no registry bot, verified signature, AI referral or markdown Accept header matches, and `git grep door_document origin/main -- visibility-web blue402-web` finds nothing. So a desktop-Chrome fetch (Muse unsigned, a curl check, an agent with a browser UA) is POSTed and discarded. Only registry bots and verified signers land.
   Mechanism for the hotel: the dashboard under-counts door fetches, and ingest absorbs 422s from every site.
   Fix: merge the ingest change (accept `door_document`, count it as identity) before 1.8.0 is released, or ship 1.8.0 only after it. Do not tag the release first.

3. **`maybe_serve()` has no test, and the mutation checks show it.**
   Everything that decides what the hotel's site sends is untested: gating on an unset listing ID, `DONOTCACHEPAGE`, HEAD sending no body, header emission, the `exit`. I mutated a copy of the code and ran `tests/test-agent-door.php`:

   | Mutation | Failing checks |
   |---|---|
   | remove domain check | 2 |
   | enable redirects | 1 |
   | cache 503 for 300 s | 1 |
   | `Cache-Control: public` | 1 |
   | remove door reporting | 8 |
   | UUID pattern accepts anything | 2 |
   | 410 no longer mapped to 404 | 3 |
   | remove `limit_response_size` | 2 |
   | timeout 3 s to 30 s | 1 |
   | **remove `DONOTCACHEPAGE`** | **0** |
   | **HEAD sends a body** | **0** |
   | **serve the door with no listing ID set** | **0** |

   The last three survive, so the "off until a listing ID is set" promise has no test. Fix: split `maybe_serve()` into a testable `serve( $listing_id, $document, $host, $method )` that returns status, headers and body (leave `exit` in the hook), then cover: unset listing ID returns nothing, HEAD has no body, `DONOTCACHEPAGE` is defined, `Retry-After` on 503.

### Should fix

4. **`readme.txt` "External services" is now stale and understates what leaves the site.** It still says no data goes anywhere until both the ingest URL and key are set. With only a listing ID set, the plugin now calls `https://402.bluerails.com/...` (and WordPress's default User-Agent in that call includes the site URL). The new section says "sends nothing about the visitor", which is true, but the External services block should name the 402 host, what is sent, and when. Also the Agent door text tells hotels to edit `.htaccess` or nginx using rules the README admits are unverified on the one host that needed them ("Whether SiteGround's nginx front end honours an `.htaccess` rule for a `.json` path is not verified"). Verify on schwitzers.com, or label the snippets "untested on your host" in `readme.txt` too.

5. **A typo in the settings field turns a working door off.** `class-bluerails-settings.php:178-183`: an invalid value is sanitized to `''` and saved, so the previously valid ID is lost (the notice says "stays off", but it was on). Fix: on invalid input return the stored value, and keep the error notice.

6. **Non-GET methods are reported as hits.** `requested_door_document()` does not look at the method, so HEAD from uptime monitors, POST and OPTIONS count as door fetches, against "every GET". Fix: report only GET (and HEAD if wanted, deliberately), with a test.

### Suggestions

7. `www` against apex: `maybe_serve` runs at `wp_loaded` priority 20, before WordPress's canonical redirect, so a request to the non-canonical host answers 404 where WordPress would have redirected to the host the document names. This follows the spec (never a redirect; domain must match) and is explained in `readme.txt`, but expect it to be the commonest "why 404" support case. The log line is the only signal.
8. The mismatch log (`class-bluerails-agent-door.php:158`) prints the first requester's `Host` header as "this site answers as". A request with a bare IP or odd Host header, if it arrives first after cache expiry, produces a misleading line. Log the `home_url()` host as well.
9. `limit_response_size` 65536: an oversized document is truncated, fails `json_decode`, and is logged as "returned no organization.domain" with a 503. Cheap to distinguish (check the length against the cap) so the log says what happened. The live document is under 1 KB, so this is only a future guard.
10. No lock when the cache expires: concurrent door requests each wait up to 3 s on a slow 402 and each holds a PHP worker. Only door paths are affected, never normal pages, and a failed fetch is cached for 60 s, so the exposure is small. A short `add_transient` lock would remove it.
11. When 402 is down and the cache has expired, agents get 503 even though a good document existed minutes earlier. Keeping the last good copy for a longer "stale" window would ride out short outages. The door's API is on 402 too, so low value.
12. The option name is defined twice (`Bluerails_Settings::OPT_DOOR_LISTING`, `Bluerails_Agent_Door::OPT_LISTING_ID`). Settings already calls into the door class; use one constant. `response_for()` also writes the cache; `cached_or_fetched()` says what it does.
13. Punycode: the Host header is ASCII-punycode, the document's `organization.domain` is lowercased but not punycoded, so an IDN hotel domain would mismatch. Not a current customer; note for the generator side.
14. Comments are mostly the non-obvious why. The reason "every fetch must reach PHP to be counted" appears at the `DONOTCACHEPAGE` line, the `Cache-Control` header, the README and `readme.txt`. Keep it in `headers_for()` and point to it elsewhere.

## Answers to the specific questions

- **Can it break a hotel site?** I found no path. No PHP 8-only syntax or functions (grepped; `php -l` clean on 8.6). The new code adds one `wp_loaded` callback per request that does an `is_admin()` check, a `parse_url` and a two-item loop, and the same again in `maybe_serve`; no outbound call and no option read on a normal page. Outbound calls happen only on the two door paths with a listing ID set. Unset ID: `maybe_serve` returns early (but see finding 1 for the report). `exit` after the response is correct for a door request; `header()` is unguarded against already-sent headers (a notice, not a fatal).
- **Cache poisoning or another hotel's file?** No. The transient key is `md5(listing/document)` and holds the upstream result including `organization.domain`; the host comparison happens on every request, after the cache read, so a request with a foreign `Host` gets 404 without changing what is cached. A wrong listing ID yields a 404 because the document names a different host. A staging copy of the site (different host, same ID) also 404s.
- **SSRF and escaping?** The upstream is fixed to `https://402.bluerails.com/.../<uuid>/<one of two names>`. The UUID regex is anchored and `trim()` runs first (so the `$`-before-trailing-newline quirk does not apply), the document name comes from an allow-list, redirects are off, and the value is re-sanitized on every read, so `wp option update` cannot smuggle a path. The body is echoed raw but only after `json_decode` yields an object with a string `organization.domain`, with `application/json` and `nosniff`; no HTML context. Settings output uses `esc_attr`, `esc_url`, `esc_html`.
- **402 slow or down?** 3 s timeout, no redirects, 64 KB cap. A failure is cached 60 s as a 503 with `Retry-After: 60` and `Cache-Control: no-store`, `DONOTCACHEPAGE` defined, so neither a CDN nor a page cache holds a 503. Test: `503 cached 300` mutation fails one check, so the 60 s is pinned. Remaining risk: findings 10 and 11.
- **No-cache headers and ingest load or leaks?** Each door fetch is one PHP request plus one non-blocking POST; there is no rate limit, which is the same exposure as any 404 today. Finding 1 widens that to sites that have not enabled the door. For non-bot door fetches the full raw User-Agent and the referer are sent to Bluerails; this is disclosed in `readme.txt`. No IP address or cookie is sent.
- **Do the tests fail when behaviour breaks?** For the proxy and reporting logic, yes (table above). For serving and gating, no (finding 3). The changed tests did not delete or loosen assertions: the only existing-test-adjacent edit is `bin/boot-test.php` comments (four to five classes).
- **Readability and naming?** Clear and small (184 lines). See 12 and 14.

## What is done well

- Cache key plus per-request host check is the right shape: one cache entry per listing, the safety decision made fresh for every request.
- Failure modes are explicit and tested: 404/410 to 404, mismatch to 404 with a log naming both hosts, upstream trouble to a short-cached 503 with `Retry-After`.
- `requested_door_document()` is one function used by both the reporter and the server, so the two cannot disagree about what a door request is, and a test pins that other `.well-known` paths and ordinary page views are not reported.
- Documentation is unusually candid: the README states the ingest 422 limitation and what was not verified, rather than hiding it.

## Verification story

- **Artifacts covered:** PHP (door class, bot-detector edit, settings edit: correctness, security, perf, compat, readability), tests (mutation-tested), docs (`README.md`, `readme.txt`: accuracy against code and against bluerails402 `origin/main`), plugin header/version, boot-test comment edit. CI YAML unchanged; I noted the CI runs only `bin/boot-test.php`, so `tests/*.php` do not run on PRs (pre-existing, but it means finding 3's gap is not caught in CI).
- **Run:** `php tests/test-agent-door.php` (0 failures), the other six tests in `tests/` (all 0 failures, including `node tests/test-openai-ads-measurement-js.mjs`), `php bin/boot-test.php` (OK), `php -l` on all non-vendored PHP files (clean), twelve single-line mutations of the door and detector code against the door test.
- **Not checked:** I ran PHP 8.6 only; PHP 7.4 and WordPress 5.8 are not installed here, so compatibility is by reading and grepping for 8-only constructs, not by running. Nothing ran inside a real WordPress (no `wp_loaded`, real transients, real `exit` and headers, or the settings page); the stubs mimic `get_transient`, `wp_remote_get` and `wp_remote_post`. I did not hit the live 402 endpoint. The Apache and nginx rules were not executed on any host. I did not render the settings screen. Whether the ingest change for `door_document` exists on an unmerged branch is unknown; I checked `origin/main` only.
