# Review — BLUE-1540: v1.3.2 git tag mistagged + readme.txt stale

Implemented-By: Claude (implementation session, `BLUE-1540-fix-release-integrity` branch)
Independent-Reviewer: Claude Sonnet 5 code-review agent, fresh session, no shared context with author (agentId a290957735ed6d45f)
Review-Status: INDEPENDENT
Reviewed-At: 2026-08-31

## What this PR changes

Two parts, only one shows in the file diff:
1. **Force-retagged** (already pushed, not a file diff) `v1.3.1` from `35530f9` → `0747d9e` and `v1.3.2` from `35530f9` → `5910f58` — the actual commits that made each version bump, verified via plugin-header `Version:` string and byte-identical diff against the published release zips.
2. **`readme.txt`** (`bin/boot-test.php` unaffected, `.github/workflows/boot-test.yml` unaffected — code:none, docs-only diff): `Stable tag: 1.3.2`, new `= 1.3.2 =` Changelog entry, new `= 1.3.2 =`/`= 1.3.1 =` Upgrade Notice entries. **`README.md`**: release checklist now pins `--target "$(git rev-parse HEAD)"` and requires a fresh checkout, closing the race that produced the mistagging.

## Lens Coverage

| Lens | Verdict | Why / Findings |
| --- | --- | --- |
| Tech | APPLIES | `readme.txt`, `README.md`, git tag refs `v1.3.1`/`v1.3.2`. Independent reviewer verified retag targets three ways (local `git rev-parse`, live `gh api .../git/refs/tags/*`, plugin-header `Version:` string inside each tagged commit), confirmed GitHub Release assets/digests untouched, and confirmed `bb61686` (the later BLUE-1539 boot-test commit) is correctly excluded from the v1.3.2 tag. Found one real Important issue (stale-checkout variant of the race not fully closed) — fixed. |
| Product Manager | APPLIES | Ships end-user-facing WordPress changelog/upgrade-notice copy read by site admins deciding whether to update. Reviewer confirmed content accuracy against the real fix commit and correct urgency framing (1.3.1 flagged as broken, 1.3.2 as the fix). |
| Product Designer | SKIP | No UI — readme.txt/README.md prose and git tag metadata, no rendered surface. |
| Persona | SKIP | Not end-customer-facing; the relevant persona (plugin maintainer / WP site admin reading the update nag) is covered under PM. |
| Cost/Perf | SKIP | No runtime code, no CI job added, no request path touched — doc + tag-metadata change only. |

### Depth interrogation (Tech, APPLIES)

INVARIANT-ENFORCER: the invariant is "a version git tag points at the commit that actually WAS that release." No automated enforcer exists (this is a manual `git tag -f` + documentation fix, not a CI check) — fails OPEN, same as the underlying release process itself (disclosed in BLUE-1539's own review). The README.md fix reduces the failure RATE (removes the implicit-HEAD race) but doesn't add an enforcer; a human following the updated checklist is still required.
PROD-ENTRY-TRACE: N/A — no production code path. The only "entry point" is a human running the documented release commands; traced and fixed in this diff.
PARITY-CHECK: the pair that must agree is (a) each git tag's target commit and (b) that commit's actual plugin-header `Version:` string / actual diff content. Independently verified to agree for both retagged tags (`0747d9e` → `Version: 1.3.1` exactly, no 1.3.2-worth of changes past it before the next real bump; `5910f58` → `Version: 1.3.2`, and `git diff 5910f58 bb61686` confirmed only unrelated boot-test files past it). Also verified the SEPARATE pair — GitHub Release asset content vs. tag — remains correct and untouched by the retag.
FAILURE-MODE-ENUM: (1) implicit-HEAD race (`gh release create` with no `--target`, tag lands on whatever `main` was at that moment) → CLOSED by `--target "$(git rev-parse HEAD)"`. (2) Stale-checkout variant (running the release commands from a worktree/checkout that hasn't pulled the just-pushed bump) → CLOSED by the added "run from the same/fresh checkout" instruction, found and fixed per the independent reviewer's Important finding. (3) A human simply not following the checklist at all → NOT closed, same as before this PR (no CI/branch-protection enforcement exists for this repo's release process at all, per BLUE-1539's review — out of this ticket's scope).
SIDE-EFFECT-COMPLETION: N/A — no money/row/message/external-API side effect. Retagging a git ref has no side effect beyond what `git checkout <tag>` / `git archive <tag>` resolve to; verified the GitHub Release (the actually-consumed artifact for auto-updates) is untouched.
STATE-MACHINE-COMPLETENESS: N/A — no DB schema, no enum, no response-shape change.
IDEMPOTENCY-REPLAY: the retag itself IS a replay-safe operation by construction (`git tag -f` to the same target twice is a no-op); not a webhook/queue/MCP tool.
INTEGRATION-REALITY: N/A per the gate's own carve-out — no external-service adapter touched. The one adjacent system (PUC's auto-updater, which reads GitHub Release assets) was independently confirmed unaffected by the retag, since it never reads the git tag ref at all.

### Depth interrogation (Product Manager, APPLIES)

IN-CONTEXT-REVIEW: N/A — machine/contract-adjacent surface (readme.txt is consumed by WordPress core's own update-details renderer, not a page this repo renders itself). Reviewer verified the new entries render correctly per WP readme.txt convention by comparing format/tone against the file's own existing entries (bullet+backtick style in Changelog, short paragraph in Upgrade Notice) rather than rendering it in an actual WP admin (no WP instance available in this review context) — an honest limit, not a skipped check.
SIBLING-METRIC-COHERENCE: N/A — no adjacent rendered metrics; readme.txt entries are prose, not numeric.
DECISION-CHALLENGE: the decision is "retag + backfill readme.txt + harden the release checklist" vs. the alternatives: (a) do nothing (rejected — the tag actively misleads anyone who trusts `git checkout v1.3.2`, and the readme.txt gap leaves admins with no in-nag warning about a bug that already caused an outage); (b) only fix the tags, skip readme.txt (rejected — the ticket's own stated Why is specifically about admin-facing update-approval confidence, which readme.txt directly serves); (c) only fix readme.txt, skip the tag (rejected — leaves the git-level lie in place for developers/incident-responders). This PR does all three, matching the ticket's own MUST-FIXes from its independent ticket review (sibling v1.3.1 tag also fixed, Upgrade Notice added not just Changelog, process hardening upgraded from "optional" to done).
BASELINE-ANCHOR: N/A — not a domain product surface (reservations/rate-calendar/dashboard); repo-hygiene fix with no external best-in-class scorecard.
ALL-STATES-COVERAGE: N/A — no UI data states; readme.txt has exactly the states "entry present" / "entry missing", both now present for 1.3.1 and 1.3.2.
PERSONA-JTBD-CHECK: N/A — single-persona surface (the WP site admin reading the update nag before approving), not a multi-persona operator console.
AGENTIC-OPPORTUNITY-CHECK: N/A — not a product UI or data display; this is prose consumed by WordPress core's own renderer, no smart-CTA surface applies.
PRODUCT-FRAME: N/A — non-rendered-by-us content (WordPress core renders readme.txt, not this codebase); no product shell for a user to be misoriented within.
JTBD-OUTCOME: "When I'm about to approve a WordPress plugin update, I want the in-nag Upgrade Notice to tell me if a version is broken or fixes something urgent, so I can make an informed update decision instead of updating blind or skipping a critical fix." Delivered: verified both new Upgrade Notice entries exist, are accurate against the real fix commit, and correctly flag 1.3.1 as broken / 1.3.2 as the fix to update to.
OUTCOME-ARTIFACT: terminal artifact 1 is the git tag ref record itself — live API 200 body returns `0747d9e5ebe5c8ba2cdc18ce3c837c39bdc1dabb` for v1.3.1 and `5910f5859f7246113ea4f4d44a02edb2900613ec` for v1.3.2, matching the intended commit ids exactly. Terminal artifact 2 below.

Artifact 1 — the git tag ref itself (what `git checkout v1.3.2` actually resolves to on GitHub, live, right now, independent of this PR merging):

```
$ gh api repos/Bluerails-2-0/bluerails-agent-traffic/git/refs/tags/v1.3.1 --jq '.object.sha'
0747d9e5ebe5c8ba2cdc18ce3c837c39bdc1dabb
$ gh api repos/Bluerails-2-0/bluerails-agent-traffic/git/refs/tags/v1.3.2 --jq '.object.sha'
5910f5859f7246113ea4f4d44a02edb2900613ec
```

Both match the intended targets exactly — a live API 200 response naming the resolved ref, not a "push succeeded" intermediate claim.

Artifact 2 — `readme.txt`'s Stable tag/Changelog/Upgrade Notice entries, the actual bytes WordPress core's readme.txt parser reads:

```
$ git -C /Users/ashwin/work/bluerails-agent-traffic-BLUE-1540 show HEAD:readme.txt | grep -A1 "^Stable tag:"
Stable tag: 1.3.2
```

Not verified in a rendered wp-admin screen (no WP instance in this review context), but the terminal artifact for this half IS the committed file content — WordPress's readme.txt parser is a stable, documented format with no server-side processing step between the file and the rendered modal.
PROD-REALITY: if this ships to `main` exactly as-is right now, the next site that checks for updates and opens "View version 1.3.2 details" sees the correct, complete changelog and upgrade notice — genuinely delivered, not oversold. The tag fix is also immediately live (already pushed to origin, independent of this PR's merge) — verified via live `gh api`.
PRESS-RELEASE-CLAIM: "An admin deciding whether to update this plugin now sees an accurate, complete changelog and an inline warning about the 1.3.1 fatal bug — and a developer checking out any tagged version gets the code that version actually shipped." Truthful as stated, verified independently.
VERTICAL-SLICE: wired end-to-end — the readme.txt entries are real content in the real file format WordPress core actually parses; the tag fix is already live on origin (verified). No stub, no placeholder.
PERSONA-JOURNEY: single relevant persona (WP site admin) — walked: sees update notice → opens "View version details" → sees accurate 1.3.2 changelog + inline Upgrade Notice warning about 1.3.1 → makes an informed decision to update. No dead end.

## Fix disposition

Independent reviewer's two findings — both FIXED on this branch (commit `71465ae`):
1. Important: `--target` fix didn't close the stale-checkout variant of the same race — added explicit "run from a fresh/same checkout" instruction + `git fetch && git checkout main && git pull` line in the documented command sequence.
2. Important: the "14 minutes" figure in the README's rationale paragraph is no longer independently re-derivable from live data after the retag (the retag itself altered what GitHub's release-metadata timestamps report) — removed the specific figure, kept the qualitative claim (tag landed on the wrong, OLDER commit), which remains independently verified via commit ancestry (`35530f9` sits between `0747d9e` and `5910f58` on the same line of history).
Two Suggestions (readme.txt's pre-existing phantom `= 1.1.0 =` tag-that-doesn't-exist entry; a phrasing nit) — left as-is, out of this PR's scope; the phantom-tag one is a genuine adjacent finding worth its own follow-up if the team wants full release-integrity coverage, not filed as a new ticket here to avoid ticket sprawl for a single-line historical inconsistency with zero customer impact.

## Outcome Delivery

DELIVERED: both of BLUE-1540's stated outcomes are live as of this PR + the already-pushed tag fix — `v1.3.1`/`v1.3.2` now resolve to the commits that actually shipped those versions (verified live via `gh api`, independent of this PR merging), and `readme.txt`'s Stable tag/Changelog/Upgrade Notice accurately reflect 1.3.2 (verified in this PR's diff, pending merge to `main`).
