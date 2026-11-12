# Review — plugin branch BLUE-2571-forward-signed-requests

Copy of the independent review record for both repos; the original is `reviews/REVIEW-BLUE-2571.md` in Bluerails-2-0/bluerails402 (PR #2621).


Implemented-By: implementer subagent (claude-opus-5-5), backend branch BLUE-2571-plugin-signed-agent-requests and plugin branch BLUE-2571-forward-signed-requests (bluerails-agent-traffic)
Independent-Reviewer: fresh code-review subagent (claude-sonnet-5-5), no implementation context; read both diffs, the ticket snapshot, the pre-build review and the web-bot-auth library source
Review-Status: INDEPENDENT
Verdict: FIX-THEN-SHIP, fixes applied in one round (below); branches then rebased onto the ticket-2572 branches with the conflict resolved and re-tested

## Lens Coverage
| Lens | Verdict | Note |
|---|---|---|
| Tech | APPLIES | Both diffs; `visibility-web/app/features/agent-traffic/verify-web-bot-auth.ts` (quote strip only in the domain lookup, method passed), `visibility-web/app/features/agent-traffic/signed-request.ts`, library `verify` in `node_modules/web-bot-auth/dist/index.js`; SSRF of the key-directory fetch (allowlisted URLs only, redirects refused, timeout). One MEDIUM found and fixed: the library never requires `@authority`, so the site-host binding needed an explicit check. |
| Product Manager | APPLIES | Every new piece has a consumer; a signed ChatGPT-agent request becomes one `web_bot_auth` row and its own operator row (`visibility-web/app/features/agent-traffic/agent-traffic.ts:117`). Fixed: a failed signature now falls through to the referer path instead of dropping a signal the route stored before. |
| Product Designer | SKIP | No UI change; the operator row reuses the existing fallback. |
| Cost/Perf | APPLIES | `visibility-web/app/features/agent-traffic/signed-request.ts` and `verify-web-bot-auth.ts` refresh path: MEDIUM found and fixed: key-directory refresh ran for every signed request, for both signers, with no failure cache, triggerable by anyone sending forged headers to a hotel site. Now: allowlist check before any fetch, only the claimed signer refreshed, 60-second backoff after a failure. |
| Persona | APPLIES | NO-RENDER: drove `visibility-web/tests/routes/api.agent-traffic-ingest.signed.test.ts` (row, daily total, operator row). A hotel behind a proxy that rewrites the host gets nothing from this path, which is safe (no miscount). |

DECISION-CHALLENGE: count only cryptographically verified signatures, versus counting any request that carries signature headers. Verified only, because unverified headers are free to forge and would inflate a paid number; the earlier IP-verification idea was cut under decision G1.

IN-CONTEXT-REVIEW: machine/contract surface; driven end to end by `visibility-web/tests/routes/api.agent-traffic-ingest.signed.test.ts` through the mounted ingest route into the daily rollup and the dashboard reader.

ALL-STATES-COVERAGE: site with no signed traffic sees no change; signed traffic with a valid signature adds rows; a failed signature falls back to the existing referer and markdown paths; headers stripped by a proxy means no change.

PERSONA-JTBD-CHECK: N/A — not an operator console; the reader is the hotel owner viewing the existing agent-traffic card.

SIBLING-METRIC-COHERENCE: N/A — ingest contract surface; signed rows join the existing visit total and operator table.

BASELINE-ANCHOR: N/A — no new surface; external reference is Cloudflare's Web Bot Auth spec (developers.cloudflare.com/bots/concepts/bot/verified-bots/web-bot-auth/).

## Findings and disposition
- MEDIUM: `@authority` not required among covered components, so host binding was hollow. FIXED; test "a valid signature that does not cover @authority (the host) writes nothing".
- MEDIUM: key-directory refresh cost and abuse. FIXED; tests "a Signature-Agent off the allowlist fetches no key directory; an allowlisted one fetches only its own" and "a failed directory fetch is not retried within 60 seconds".
- MEDIUM: failed signature with an AI referer returned 422 (lost a referer row). FIXED by falling through; this departs from the ticket's "drop the row" on purpose.
- LOW: duplicate fetch helper. FIXED: shared `fetchAllowlistedJson` (`visibility-web/app/features/agent-traffic/fetch-json.ts`), now also used by the log worker, which gains a 10-second timeout it lacked.
- LOW: a resend across UTC midnight can still double-count while the signature is unexpired. Accepted.
- LOW: plugin ingest auto-verifies an unknown site domain (existing behaviour). Accepted; the `@authority` check narrows replay.
- LOW: comments. FIXED.

## Verification
- Before review: 8 acceptance lines PASS; new tests failed on the unfixed code first (verifier 2, route 8 of 10, plugin 9 of 12).
- After fixes and the rebase onto the ticket-2572 branches: 13 affected backend files, 205 tests pass against a migrated throwaway Postgres (includes migration 0126 and two new cases for signed-versus-markdown precedence); typecheck and lint clean; agent-ingest Lambda bundles. Plugin: signed forwarding 15 PASS, ordering 49/0, markdown 0 failures, boot test OK.
- Order in the route and plugin: known user agent, then signed request, then AI referer, then markdown, then beacon.
- The quoted-header failure was reproduced independently: `node -e 'new URL("\"https://chatgpt.com\"")'` throws ERR_INVALID_URL.
- Not re-reviewed after the fix round and rebase (two-round cap); the rebase was a conflict resolution re-tested in full.

## Outcome Delivery

PARTIAL: signed-request counting is complete and tested — missing: deploy and plugin 1.5.0 release, both human steps — tracked: BLUE-2571
