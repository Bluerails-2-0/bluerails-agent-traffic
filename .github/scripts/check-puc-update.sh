#!/usr/bin/env bash
# BLUE-1537: compares the vendored Plugin Update Checker (PUC) version against
# YahnisElsts/plugin-update-checker's latest GitHub release tag, and (on drift)
# re-vendors includes/plugin-update-checker/ wholesale from the release archive.
# Invoked by .github/workflows/check-puc-update.yml. Writes no_drift/latest_version
# to $GITHUB_OUTPUT; the caller opens the PR.
set -euo pipefail

VENDOR_DIR="includes/plugin-update-checker"
VENDOR_FILE="$VENDOR_DIR/plugin-update-checker.php"
REPO_SLUG="YahnisElsts/plugin-update-checker"

# Anchored to PUC's own docblock header line ("Plugin Update Checker Library X.Y")
# — not a loose scan of the file for anything version-shaped, which other
# vendored files (e.g. load-v5p7.php) could also match.
current_line=$(grep -m1 -E '^[[:space:]]*\*[[:space:]]*Plugin Update Checker Library [0-9]+\.[0-9]+' "$VENDOR_FILE") \
  || { echo "::error::no 'Plugin Update Checker Library X.Y' line found in $VENDOR_FILE — vendored layout may have changed"; exit 1; }
current_version=$(echo "$current_line" | sed -E 's/^[[:space:]]*\*[[:space:]]*Plugin Update Checker Library ([0-9]+\.[0-9]+).*/\1/')

# releases/latest excludes drafts/prereleases by GitHub API semantics.
latest_tag=$(gh api "repos/$REPO_SLUG/releases/latest" --jq '.tag_name')
latest_version="${latest_tag#v}" # strip the 'v' prefix upstream uses (v5.7), vendored string has none (5.7)

# Reject anything that isn't a bare X.Y version before it's used to build a
# branch name, a shell command, or a download URL — closes most of the
# injection surface as defense-in-depth, and stops a 3-component upstream tag
# (e.g. 5.7.1) from producing a permanent drift/PR loop.
if ! [[ "$latest_version" =~ ^[0-9]+\.[0-9]+$ ]]; then
  echo "::error::latest release tag '$latest_tag' does not match expected X.Y version shape — refusing to proceed"
  exit 1
fi

echo "vendored=$current_version latest=$latest_version"

if [ "$current_version" = "$latest_version" ]; then
  echo "no_drift=true" >>"$GITHUB_OUTPUT"
  echo "Vendored PUC ($current_version) matches latest release ($latest_version) — no action."
  exit 0
fi

echo "no_drift=false" >>"$GITHUB_OUTPUT"
echo "latest_version=$latest_version" >>"$GITHUB_OUTPUT"
echo "Drift detected: vendored=$current_version latest=$latest_version — re-vendoring."

work=$(mktemp -d)
curl -fsSL -o "$work/puc.zip" "https://github.com/$REPO_SLUG/archive/refs/tags/v${latest_version}.zip"
unzip -q "$work/puc.zip" -d "$work"
extracted="$work/plugin-update-checker-${latest_version}"
[ -d "$extracted" ] || { echo "::error::expected extracted dir $extracted not found in release archive"; exit 1; }

# Sanity tripwire: refuse to auto-PR a wildly different payload (e.g. a
# compromised upstream account shipping something unexpected). Current
# vendored tree is 118 files; [50,500] is a generous band around that.
new_count=$(find "$extracted" -type f | wc -l | tr -d ' ')
if [ "$new_count" -lt 50 ] || [ "$new_count" -gt 500 ]; then
  echo "::error::release v${latest_version} has $new_count files (expected roughly 50-500) — refusing to auto-vendor, needs a human look"
  exit 1
fi

rsync -a --delete "$extracted"/ "$VENDOR_DIR"/
