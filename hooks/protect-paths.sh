#!/usr/bin/env bash
# PreToolUse guard for unattended loop runs. The loop workflow sets
# FACTORY_PROTECTED_PATHS (comma-separated path prefixes); edits to those paths
# are blocked with exit 2 so Claude sees why and works elsewhere. Unset (every
# interactive session) = no-op.
# This is the early warning. The authority is the deterministic diff guard that
# runs after Claude, which also catches edits made through Bash.
set -euo pipefail

[[ -z "${FACTORY_PROTECTED_PATHS:-}" ]] && exit 0

input=$(cat)
file=$(jq -r '.tool_input.file_path // .tool_input.notebook_path // empty' <<<"$input")
[[ -z "$file" ]] && exit 0

root=${CLAUDE_PROJECT_DIR:-$PWD}
rel=${file#"$root"/}

IFS=',' read -ra prefixes <<<"$FACTORY_PROTECTED_PATHS"
for prefix in "${prefixes[@]}"; do
  [[ -z "$prefix" ]] && continue
  if [[ "$rel" == "$prefix"* ]]; then
    echo "laravel-factory: '$rel' is protected in this loop run ($FACTORY_PROTECTED_PATHS). Change something else; the post-check would reject this edit anyway." >&2
    exit 2
  fi
done
exit 0
