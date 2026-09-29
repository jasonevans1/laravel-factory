#!/usr/bin/env bash
# Security loop sensor + controller (fused, deterministic).
# Usage: security-loop-next.sh [audit.json]
# Set point: zero `composer audit` advisories. Accepted risks live in
# composer.json config.audit.ignore, which composer audit honours natively.
# Controller: highest severity first, ties broken by advisory count. Packages
# listed in $FACTORY_SKIP (space-separated; those with an open deferral issue)
# are passed over so one major-upgrade deferral can't stall the whole loop.
# Prints `Next: <package> ...` when there is work.
set -euo pipefail

if [[ -n "${1:-}" ]]; then
  json=$(cat "$1")
else
  json=$(composer audit --locked --format=json --no-interaction 2>/dev/null || true)
fi
total=$(jq '[.advisories | if type == "object" then .[][] else empty end] | length' <<<"$json")

if [[ "$total" -eq 0 ]]; then
  echo "Set point reached: 0 advisories."
  exit 0
fi

echo "Gap: $total advisories across $(jq '.advisories | length' <<<"$json") packages."
jq -r --arg skip "${FACTORY_SKIP:-}" '
  def rank: {"critical":0,"high":1,"medium":2,"low":3};
  def rankof: rank[.] // 4;
  ($skip | split(" ") | map(select(length > 0))) as $skipped
  | .advisories
  | to_entries
  | map(select(.key as $p | $skipped | index($p) | not))
  | map({
      package: .key,
      count: (.value | length),
      top_severity: (.value | map(.severity // "unknown") | sort_by(rankof) | .[0])
    })
  | sort_by([(.top_severity | rankof), -.count])
  | if length == 0 then "All remaining packages are deferred to manual review."
    else .[0] | "Next: \(.package)  (\(.count) advisories, top severity: \(.top_severity))" end
' <<<"$json"
