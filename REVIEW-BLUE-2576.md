# Review: BLUE-2576 plugin change (1.6.0), branch BLUE-2576-utm-ai-referrals, commit 202895b5f

Implemented-By: implementer subagent (claude-opus-5-5)
Independent-Reviewer: fresh reviewer subagent (claude-sonnet-5-5)
Review-Provenance: spawned by the orchestrator session (claude-opus-5-5), not by the implementer
Review-Status: INDEPENDENT

Sources: `git diff origin/main...HEAD` in /Users/ashwinkumar/work/bluerails-agent-traffic-BLUE-2576, the draft plugin PR body, the backend diff in the bluerails402 worktree (to compare lists and behaviour), and my own PHP runs. Companion backend review: /Users/ashwinkumar/work/bluerails402/.worktrees/BLUE-2576-ai-referred-visitors/reviews/REVIEW-BLUE-2576-ai-referred-visitors.md.

## Lens Coverage

| Lens | Verdict | Why / Findings |
| --- | --- | --- |
| Tech | APPLIES | `includes/class-bluerails-bot-detector.php` `has_ai_utm_source()` and the order of checks (after bot and signed-request matches); `AI_UTM_SOURCES` equals the backend list in `visibility-web/app/features/agent-traffic/ai-assistants.ts` (10 entries, same values). clean except P1. |
| Product Manager | APPLIES | Closes the gap the ticket names: a tagged link with no referrer now reaches the backend. P2 on guessed tag values. |
| Product Designer | SKIP | The only user-visible text is one paragraph on the WordPress settings screen (`includes/class-bluerails-settings.php`); no layout or component changed, nothing to render. |
| Persona | APPLIES | WordPress site owner reading the settings-screen paragraph and `readme.txt` disclosure: both now say what is sent for these visitors and what Bluerails keeps (count only, referrer host, path without query). Read from source text; the WP admin screen was not rendered. P3 stale README. |

## Findings

- P1 LOW, `includes/class-bluerails-bot-detector.php` `has_ai_utm_source()`: reads `$_SERVER['REQUEST_URI']` and uses `parse_str`, so `utm_source[]=x` (array) is correctly rejected by the `is_string` check and a value is trimmed and lowercased before the exact-list match; a host spoofed inside another parameter does not match. Behaviour verified by the test file. No defect; note: pages served from a full-page cache never reach this code, so the count is a floor (the backend copy says so).
- P2 LOW, `AI_UTM_SOURCES`: only `chatgpt.com` has an external (secondary) source; bare names `claude`, `copilot`, `gemini`, `perplexity` are guesses and a human can add the tag by hand. Disclosed in the PR body. Over-sending is harmless to the dashboard because the backend re-checks the same list.
- P3 LOW, `README.md` lines 26-33 (step 5): still ends "a low-coverage fallback signal for browser-extension agentic sessions ... whose UA carries no distinguishing token". That describes the old referrer path as an agent signal; these visits are now counted as people, not agents. Also `readme.txt` lines ~318 and ~353 (release checklist and old upgrade notice) still describe the referrer path as a "low-coverage fallback signal" without Copilot. Fix the README step 5 sentence; the readme.txt lines are history and can stay.
- P4 INFO, disclosure: `readme.txt` and the settings text say the User-Agent, Referer and page address are not stored for these visitors. That is a statement about the backend; I confirmed it in the backend diff (`recordPluginAiReferral` writes only org, date, domain, assistant, folder, count). Plugin still sends the full UA and path with query on the wire, and the text says so ("passed through unmodified").
- Version: 1.6.0 in the plugin header, `BLUERAILS_AGENT_TRAFFIC_VERSION` and `Stable tag` agree; changelog and upgrade notice present.

## Tests I ran (PHP, in the plugin worktree)

- `php tests/test-ai-referral.php` exit 0, 0 failures (21 PASS lines)
- `php tests/test-bot-signature-ordering.php` exit 0, 49 cases, 0 failures
- `php tests/test-markdown-accept.php` exit 0, 0 failures
- `php tests/test-signed-request-forwarding.php` exit 0, 0 failures
- `php bin/boot-test.php` exit 0, "plugin bootstrap chain ran with no fatal error"

Not run: the new test file against `origin/main` code (the PR claims 7 failures there), WordPress admin render, plugin check/phpcs.

Verdict: PASS
