Implemented-By: claude-agent (this session)
Independent-Reviewer: claude-agent (fresh Agent() spawn, agent-skills:code-reviewer)

## Lens Coverage

| Lens | Verdict | Reason |
| --- | --- | --- |
| Tech | APPLIES | `README.md`'s new "Cutting a release" section (lines ~209-224). Diff is README-only, 1 file. `gh release create` syntax verified correct by independent reviewer. No competing/automated release process exists in this repo (`find .github -type f` → none). Incorporated the reviewer's suggestion: the doc now includes the actual zip-build command (rsync + zip), not just `cp`/`gh release create`. |
| Product Manager | SKIP | Internal engineering runbook for whoever cuts a release, not a product/customer-facing surface or decision — no persona, no JTBD, no rendered UI. |
| Product Designer | SKIP | No rendered/UI surface — a Markdown doc only. |
| Persona | SKIP | No end-user journey — audience is an internal release engineer. |
| Cost/Perf | SKIP | No runtime code, no hot path — documentation only. |

## Tech depth

This is a Markdown documentation change — no runtime code, no route, no schema, no external adapter. All Tech depth markers are N/A for this reason:

- **INVARIANT-ENFORCER:** N/A — no code invariant introduced; the doc describes a HUMAN-followed release process, not an enforced code path.
- **PROD-ENTRY-TRACE:** N/A — nothing runs in prod; this is a README read by a person cutting a release.
- **PARITY-CHECK:** N/A — no dual-source pair (schema/enum/type) introduced.
- **FAILURE-MODE-ENUM:** N/A — no runtime failure surface; the "failure mode" this doc exists to prevent (a forgotten manual step) is itself named in the Product Manager row's Suggestion (automate via CI) rather than a code FMEA.
- **SIDE-EFFECT-COMPLETION:** N/A — no write, no external API call.
- **STATE-MACHINE-COMPLETENESS:** N/A — no enum/state/column.
- **IDEMPOTENCY-REPLAY:** N/A — no replayable request.
- **INTEGRATION-REALITY:** N/A — no external-service adapter touched.

## Findings
None open.

## Outcome Delivery

DELIVERED: the ticket's outcome — future releases have a documented, followable checklist for the stable-asset requirement the live dashboard link depends on — is live as-merged evidence: this commit's README.md diff itself (readable at the stated line range), independently reviewed for command-syntax correctness.

## Verdict
READY.
