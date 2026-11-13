# Review: BLUE-0-release-zip-excludes

Branch: BLUE-0-release-zip-excludes
Implemented-By: orchestrator session (claude-opus-5-5)
Independent-Reviewer: agent-skills:code-reviewer (claude-sonnet-5-5)
Review-Status: INDEPENDENT

Scope: README.md "Cutting a release" commands (1 file, +8/-4, commit c9bbf51).

## Should this change exist?
Yes. The old rsync excluded only .git, .gitignore, README.md and REVIEW-*.md. Run in the current tree it produces 131 files, 7 more than the published v1.5.0 zip (124): bin/boot-test.php, 3 files in tests/, 2 in reviews/, plus .github content. Anyone following the old steps would ship dev-only files to customer sites.

## Checks run (this session)
- Downloaded v1.5.0 asset bluerails-agent-traffic-1.5.0.zip into a fresh temp dir. It has 124 files. Ran the new rsync on this worktree and listed files: 124, and `diff` of the two sorted file lists is empty. The author's claim is confirmed.
- Runtime references: grep of bluerails-agent-traffic.php and includes/ (outside plugin-update-checker's own README) finds no reference to bin/, tests/, reviews/ or .github. includes/ holds the 3 class files and plugin-update-checker. Only .github/workflows/boot-test.yml references bin/boot-test.php, and that runs in CI, not in the shipped plugin.
- No nested dirs named bin, tests, reviews or .github exist elsewhere in the tree, so the unanchored rsync patterns exclude nothing extra (e.g. nothing inside plugin-update-checker).
- Still shipped: readme.txt, LICENSE, assets/, includes/ (including plugin-update-checker/vendor), main PHP file. They appear in the 124-file list that matches the published zip.
- Update checker: bluerails-agent-traffic.php:67-68 calls enableReleaseAssets('/^bluerails-agent-traffic\.zip$/'). The stable-name zip is still produced and passed to `gh release create`, so the filter matches.
- Pasted sequence: `rm -rf /tmp/plugin-zip && mkdir -p ...` clears stale output. The subshell `cd /tmp/plugin-zip && zip ... && cp` is correct because cp uses relative names inside the subshell. The gh asset paths are now absolute under /tmp/plugin-zip, which fixes the old command's bug of passing `bluerails-agent-traffic.zip` relative to the repo checkout (the old cp wrote into the checkout). --target "$(git rev-parse HEAD)" still runs from the repo root, since the subshell does not change the outer cwd.
- Not checked: I did not run `gh release create` (instructed not to), and did not unzip-test the new zip on a WordPress install.

## Findings
- Minor: README.md line 336 (`mkdir -p /tmp/plugin-zip/bluerails-agent-traffic`) is left over and now redundant with the next line (`rm -rf ... && mkdir -p`). Delete the first one. Harmless as written.
- Suggestion: the rsync excludes are unanchored, so they match at any depth. Anchoring them (`--exclude='/bin'`, `/tests`, `/reviews`, `/.github`) would stop a future nested tests/ dir inside vendored code from being dropped silently. Not needed today (verified none exist).
- Suggestion: the fixed `/tmp/plugin-zip` path is fine for this single-maintainer flow; the `rm -rf` is safe because the path is a literal.

## Lens Coverage
| Lens | Applies | Finding |
| --- | --- | --- |
| Tech | APPLIES | Clean on correctness: file list matches v1.5.0 (124), no runtime dependency on excluded dirs, asset filter still matches. One redundant mkdir line (minor). |
| Product Manager | APPLIES | Clean: stops dev-only files reaching customer WordPress installs; no scope creep. |
| Product Designer | SKIP | No UI or rendered surface changed; docs-only. |
| Persona | APPLIES | Clean: the maintainer pasting the block gets the right zips at absolute paths; the old relative-path cp into the checkout is gone. |

Verdict: PASS
