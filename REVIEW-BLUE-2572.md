# Review — plugin branch BLUE-2572-markdown-and-new-bot-names

Copy of the independent review record for both repos; the original is `reviews/REVIEW-BLUE-2572.md` in Bluerails-2-0/bluerails402 (PR #2620).


Implemented-By: implementer subagent (claude-opus-5-5), backend branch BLUE-2572-markdown-agents-and-new-bot-names and plugin branch BLUE-2572-markdown-and-new-bot-names (bluerails-agent-traffic)
Independent-Reviewer: fresh code-review subagent (claude-sonnet-5-5), no implementation context; read both diffs, the ticket snapshot and the pre-build review
Review-Status: INDEPENDENT
Verdict: FIX-THEN-SHIP, fixes applied in one round (below)

## Lens Coverage
| Lens | Verdict | Note |
|---|---|---|
| Tech | APPLIES | Both diffs; `visibility-web/app/features/agent-traffic/accept-ranking.ts` checked line by line against Mintlify `src/classify.ts` (q-values, tie order, q=0, malformed q, `text/x-markdown`); route priority UA → referer → markdown → beacon in `visibility-web/app/routes/api.agent-traffic-ingest.ts`; plugin first-match order. Clean after fixes. |
| Product Manager | APPLIES | Every new piece has a consumer: markdown rows and the 13 names reach the dashboard via the operator-row fallback (`visibility-web/app/features/agent-traffic/agent-traffic.ts:117`). One out-of-ticket fix (plugin token casing) judged correct and in service of the outcome. |
| Product Designer | SKIP | No UI change; the new row name is a string in the existing operator table. |
| Cost/Perf | APPLIES | MEDIUM found: plugin posted every request that merely mentioned markdown, each costing a key lookup, entitlement read and rate-limit slot before a 422 while the flag is off. Fixed: plugin now ranks before sending (`includes/class-bluerails-bot-detector.php` `prefers_markdown()`); server still re-ranks. |
| Persona | APPLIES | NO-RENDER: drove the ingest route tests in `visibility-web/app/routes/__tests__/api.agent-traffic-ingest.test.ts` ("a markdown row shows as its own operator row"). Hotel on plugin 1.4.0 with the flag off sees the 13 new names immediately and markdown rows only after the flag is on; cached pages never run PHP, so those hits are invisible (stated in `readme.txt`). |

DECISION-CHALLENGE: count markdown-preferring agents as a separate low-confidence row behind a flag, versus not counting them at all. Chosen because browsers never rank markdown above HTML, the label says low confidence, and the flag stays off until a sandbox day shows real volume.

SIBLING-METRIC-COHERENCE: N/A — ingest contract surface; new rows join the existing total-visits and operator table without a new metric.

BASELINE-ANCHOR: N/A — no new surface; external reference is Mintlify's classifier (github.com/mintlify/agent-traffic), whose markdown rule this ports.

## Findings and disposition
- MEDIUM: Plugin markdown volume while the flag is off. FIXED (plugin commit 121b29d): plugin posts only when markdown ranks above HTML; 13 ranking cases in `tests/test-markdown-accept.php`, 7 of which failed on the previous code.
- LOW: Flag read at module load; flipping it needs a redeploy. Stated in the PR handoff.
- LOW: HOT-PATH-COST comment missed the markdown path. FIXED (backend 9309e5737).
- LOW: Two long history comments in `identifyRow()`. FIXED, one line each.
- LOW: Plugin casing fix for `Meta-ExternalAgent` and `Omgilibot`: correct (registry spellings at `visibility-web/scripts/seed-agent-bot-registry.ts:244`, `:360`; backend match is case-sensitive). Effect: Meta's crawler starts counting from the plugin; Omgilibot rows move from `omgili` to `Omgilibot`. Stated in the PR body.

## Verification
- Implementer: backend affected tests (accept-ranking, route, seed, migration journal, ingest) pass against a migrated throwaway Postgres; typecheck, lint, format, knip, semgrep clean. Plugin: `tests/test-bot-signature-ordering.php` 49/0, `tests/test-markdown-accept.php` 0 failures, `bin/boot-test.php` OK.
- Reviewer re-ran read-only: `accept-ranking.test.ts` 13/13, both PHP tests 0 failures. DB tests not re-run by the reviewer (no Postgres in its session); CI runs them.
- Migration 0126 reviewed separately by a Sonnet reviewer: PASS (single `ADD VALUE`, same shape as 0068/0069, no number collision).
- `transacting` reserved: `visibility-web/tests/scripts/seed-agent-bot-registry.test.ts:71`.

## Outcome Delivery

PARTIAL: code for both paths is complete and tested — missing: prod registry seed (`--write`), `ACCEPT_HEURISTIC_ENABLED=true` in prod, the deploy and the plugin 1.4.0 release, all human steps — tracked: BLUE-2572
