#!/usr/bin/env bash
# Security loop actuator, deterministic part. Usage: security-fix.sh <package>
# Run from the app root with vendor/ installed.
#
#   1. composer update <pkg> -W                 (within constraints)
#   2. transitive: update the root deps that pull it in
#      direct:     widen to ^<current major>
#   3. still vulnerable, or any package crossed a major -> deferred
#   4. tests pass -> deterministic; tests fail -> needs-claude
#
# Outputs (stdout and $GITHUB_OUTPUT): resolved_by, old_version, new_version.
# Writes $FACTORY_OUT/summary.md (PR/issue body) and test-output.txt.
set -euo pipefail

pkg=${1:?usage: security-fix.sh <package>}
here=$(cd "$(dirname "$0")" && pwd)
out=${FACTORY_OUT:-/tmp/factory}
test_cmd=${FACTORY_TEST_CMD:-vendor/bin/pest}
mkdir -p "$out"

emit() { echo "$1=$2"; [[ -n "${GITHUB_OUTPUT:-}" ]] && echo "$1=$2" >> "$GITHUB_OUTPUT"; return 0; }
audit_json() { composer audit --locked --format=json --no-interaction 2>/dev/null || true; }
advisories() { audit_json | jq --arg p "$pkg" '.advisories | if type == "object" then .[$p] // [] else [] end'; }
version_of() { composer show --locked "$pkg" --format=json 2>/dev/null | jq -r '.versions[0] // "absent"'; }
restore() { cp "$out/composer.json.before" composer.json; cp "$out/composer.lock.before" composer.lock; }
defer() {
  restore
  {
    echo "## Security loop deferred: $pkg"
    echo
    echo "$1"
    echo
    echo "Advisories still open:"
    echo "$before_list"
    echo
    echo "resolved-by: deferred"
  } > "$out/summary.md"
  emit resolved_by deferred
  exit 0
}

cp composer.json "$out/composer.json.before"
cp composer.lock "$out/composer.lock.before"
old=$(version_of)
emit old_version "$old"

adv=$(advisories)
if [[ $(jq length <<<"$adv") -eq 0 ]]; then
  echo "No open advisories for $pkg."
  emit resolved_by none
  exit 0
fi
before_list=$(jq -r '.[] | "- \(.cve // .advisoryId): \(.title) (\(.severity // "unknown"))"' <<<"$adv")

# Composer >= 2.9 refuses insecure versions while resolving, so this fails when
# the constraint only allows vulnerable versions (e.g. an exact pin). That's
# not a dead end: the widen step below may still find a fix.
if ! composer update "$pkg" --with-all-dependencies --no-interaction --no-progress; then
  echo "composer update $pkg could not resolve within the current constraints; trying to widen."
  restore
fi

if [[ $(advisories | jq length) -gt 0 ]]; then
  read -r kind roots <<<"$(php "$here/composer-roots.php" "$pkg")"
  case "$kind" in
    transitive)
      # shellcheck disable=SC2086 # roots is a space-separated package list
      if [[ -n "$roots" ]]; then
        composer update $roots --with-all-dependencies --no-interaction --no-progress \
          || defer "composer update of $roots failed (dependency conflict)."
      fi
      ;;
    require|require-dev)
      major=$(sed -E 's/^v?([0-9]+)\.([0-9]+).*/\1 \2/' <<<"$old")
      read -r maj min <<<"$major"
      constraint="^$maj.0"
      [[ "$maj" == "0" ]] && constraint="^0.$min"
      dev_flag=()
      [[ "$kind" == "require-dev" ]] && dev_flag=(--dev)
      composer require ${dev_flag[@]+"${dev_flag[@]}"} "$pkg:$constraint" --with-all-dependencies --no-interaction --no-progress \
        || defer "composer could not resolve $pkg:$constraint."
      ;;
  esac
fi

if [[ $(advisories | jq length) -gt 0 ]]; then
  defer "No fixed version of $pkg is reachable within its current major version. A major upgrade needs manual review."
fi

if crossed=$(php "$here/lock-major-diff.php" "$out/composer.lock.before" composer.lock); then :; else
  defer "The fix would cross a major version, which needs manual review:
$crossed"
fi

new=$(version_of)
emit new_version "$new"

{
  echo "## Security fix: $pkg"
  echo
  echo "$old -> $new"
  echo
  echo "Advisories resolved:"
  echo "$before_list"
} > "$out/summary.md"

if $test_cmd > "$out/test-output.txt" 2>&1; then
  printf '\nTests: pass\n\nresolved-by: deterministic\n' >> "$out/summary.md"
  emit resolved_by deterministic
else
  printf '\nTests: failed after the upgrade; handed to Claude (dependency-fix skill).\n' >> "$out/summary.md"
  emit resolved_by needs-claude
fi
