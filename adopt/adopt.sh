#!/usr/bin/env bash
# laravel-factory adopt: the one entry point for new AND existing Laravel apps.
# Idempotent: re-running updates factory-managed files and settings.
#
# Usage: adopt.sh [app-dir] [options]
#   --ref <tag>              factory version to pin callers to        (default: v1)
#   --local                  local files only; skip all GitHub settings
#   --deploy-url <url>       enable the Laravel Cloud deploy workflow (production URL)
#   --error-sensor <cmd>     enable the prod-error loop; <cmd> prints normalised error JSON
#   --force                  overwrite existing files that aren't factory-managed
#
# GitHub step reads secrets from the environment:
#   CLAUDE_CODE_OAUTH_TOKEN          from `claude setup-token` (Pro/Max subscription)
#   FACTORY_APP_ID                   the factory GitHub App's ID
#   FACTORY_APP_PRIVATE_KEY_FILE     path to the App's .pem private key
#   LARAVEL_CLOUD_DEPLOY_HOOK        with --deploy-url
#   ERROR_TRACKER_TOKEN              with --error-sensor
set -euo pipefail

factory=$(cd "$(dirname "$0")/.." && pwd)
tpl="$factory/adopt/templates"
marker="Managed by laravel-factory adopt.sh"

app=.
ref=v1
local_only=false
deploy_url=
error_sensor=
force=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) ref=$2; shift 2 ;;
    --local) local_only=true; shift ;;
    --deploy-url) deploy_url=$2; shift 2 ;;
    --error-sensor) error_sensor=$2; shift 2 ;;
    --force) force=true; shift ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    -*) echo "unknown option: $1" >&2; exit 2 ;;
    *) app=$1; shift ;;
  esac
done

cd "$app"
[[ -f artisan && -f composer.json ]] || { echo "Not a Laravel app: $(pwd)" >&2; exit 1; }
say() { printf '\033[1m==>\033[0m %s\n' "$*"; }
conflicts=()

# install <template> <dest>: fill placeholders, overwrite only factory-managed files.
install() {
  local src=$1 dest=$2
  if [[ -f "$dest" ]] && ! grep -q "$marker" "$dest" && [[ "$force" != true ]]; then
    conflicts+=("$dest")
    return
  fi
  mkdir -p "$(dirname "$dest")"
  # shellcheck disable=SC2016 # PHP code, not shell
  php -r 'echo strtr(file_get_contents($argv[1]), json_decode($argv[2], true));' "$src" "$placeholders" > "$dest"
}

# --- 1. Caller workflows ------------------------------------------------------
say "Workflows (factory $ref)"
e2e=false
compgen -G "playwright.config.*" > /dev/null && e2e=true
placeholders=$(jq -n --arg ref "$ref" --arg e2e "$e2e" --arg sensor "$error_sensor" \
  '{"__FACTORY_REF__": $ref, "__E2E__": $e2e, "__ERROR_SENSOR__": ($sensor | tojson)}')
for wf in "$tpl"/workflows/*.yml; do
  name=$(basename "$wf")
  [[ "$name" == factory-prod-error-loop.yml && -z "$error_sensor" ]] && continue
  [[ "$name" == factory-deploy.yml && -z "$deploy_url" ]] && continue
  install "$wf" ".github/workflows/$name"
done
# Laravel's skeleton ships a github-actions-only dependabot.yml; ours is a superset.
if [[ -f .github/dependabot.yml ]] && ! grep -q "$marker" .github/dependabot.yml \
   && [[ $(grep -c 'package-ecosystem' .github/dependabot.yml) -eq 1 ]] \
   && grep -q 'package-ecosystem: *"\{0,1\}github-actions' .github/dependabot.yml; then
  rm .github/dependabot.yml
fi
install "$tpl/dependabot.yml" .github/dependabot.yml

others=$(find .github/workflows -name '*.yml' ! -name 'factory-*' 2>/dev/null | sort)
if [[ -n "$others" ]]; then
  echo "   Other workflows present; check for duplicates of the factory's (tests, lint, deploy, security loop):"
  # shellcheck disable=SC2001 # indenting every line of a multi-line list
  sed 's/^/     /' <<<"$others"
fi

# --- 2. Claude Code settings + agent memory -------------------------------------
say "Claude Code plugin settings"
mkdir -p .claude
if [[ -f .claude/settings.json ]]; then
  jq -s '.[0] * .[1]' .claude/settings.json "$tpl/claude-settings.json" > .claude/settings.json.tmp
  mv .claude/settings.json.tmp .claude/settings.json
else
  cp "$tpl/claude-settings.json" .claude/settings.json
fi

# Laravel's skeleton ignores /.claude; the plugin settings must be committed.
if grep -qx '/.claude' .gitignore 2>/dev/null; then
  sed -i.bak 's#^/\.claude$#/.claude/*\
!/.claude/settings.json#' .gitignore && rm -f .gitignore.bak
fi

say "Agent memory"
mkdir -p .github/agent-memory
for f in "$tpl"/agent-memory/*; do
  [[ -f ".github/agent-memory/$(basename "$f")" ]] || cp "$f" .github/agent-memory/
done

# --- 3. Dev tools ---------------------------------------------------------------
say "Dev dependencies (Larastan, deprecation rules, Rector)"
want=(larastan/larastan phpstan/phpstan-deprecation-rules rector/rector driftingly/rector-laravel)
missing=()
for pkg in "${want[@]}"; do
  jq -e --arg p "$pkg" '(.require[$p] // .["require-dev"][$p]) != null' composer.json > /dev/null || missing+=("$pkg")
done
if [[ ${#missing[@]} -gt 0 ]]; then
  composer require --dev "${missing[@]}" --with-all-dependencies --no-interaction --no-progress
fi
[[ -f rector.php ]] || cp "$tpl/rector.php" rector.php

# --- 4. PHPStan config + baseline -----------------------------------------------
say "PHPStan"
if [[ ! -f phpstan.neon && ! -f phpstan.neon.dist ]]; then
  cat > phpstan.neon <<'NEON'
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
        - config/
        - database/
        - routes/
    level: 7
NEON
fi
neon=phpstan.neon
[[ -f phpstan.neon ]] || neon=phpstan.neon.dist
add_include() {
  grep -qF "$1" "$neon" && return
  if grep -q '^includes:' "$neon"; then
    sed -i.bak "/^includes:/a\\
    - $1
" "$neon" && rm -f "$neon.bak"
  else
    printf 'includes:\n    - %s\n\n%s\n' "$1" "$(cat "$neon")" > "$neon"
  fi
}
add_include vendor/phpstan/phpstan-deprecation-rules/rules.neon

phpstan() { vendor/bin/phpstan analyse --memory-limit=1G --no-progress "$@"; }
if [[ ! -f phpstan-baseline.php ]]; then
  # laravel/pao rewrites PHPStan's JSON when run under an AI agent: accept both shapes.
  # PHPStan exits 1 when it finds errors; only jq's verdict matters here.
  errors=$({ phpstan --error-format=json 2>/dev/null || true; } \
    | jq 'if .totals then .totals.file_errors + (.totals.errors // 0) else .errors end' 2>/dev/null || echo "?")
  if [[ "$errors" == "0" ]]; then
    echo "   0 errors: at the set point. No baseline; the lint dampener keeps it at zero."
  elif [[ "$errors" == "?" ]]; then
    echo "   Could not read PHPStan results; run it by hand before enabling the Larastan loop." >&2
  else
    phpstan --generate-baseline=phpstan-baseline.php > /dev/null
    add_include phpstan-baseline.php
    echo "   $errors errors baselined in phpstan-baseline.php; the Larastan loop will ratchet them to zero."
  fi
fi

# --- 5. GitHub ---------------------------------------------------------------------
if [[ "$local_only" == true ]]; then
  say "Skipping GitHub (--local)"
else
  say "GitHub"
  repo=$(gh repo view --json nameWithOwner --jq .nameWithOwner)
  need=(CLAUDE_CODE_OAUTH_TOKEN FACTORY_APP_ID FACTORY_APP_PRIVATE_KEY_FILE)
  [[ -n "$deploy_url" ]] && need+=(LARAVEL_CLOUD_DEPLOY_HOOK)
  [[ -n "$error_sensor" ]] && need+=(ERROR_TRACKER_TOKEN)
  unset_vars=()
  for v in "${need[@]}"; do [[ -n "${!v:-}" ]] || unset_vars+=("$v"); done
  if [[ ${#unset_vars[@]} -gt 0 ]]; then
    echo "Missing environment variables: ${unset_vars[*]} (or re-run with --local)" >&2
    exit 1
  fi

  gh secret set CLAUDE_CODE_OAUTH_TOKEN --repo "$repo" --body "$CLAUDE_CODE_OAUTH_TOKEN"
  for target in actions dependabot; do # dependabot-triggered runs only see Dependabot secrets
    gh secret set FACTORY_APP_ID --repo "$repo" --app "$target" --body "$FACTORY_APP_ID"
    gh secret set FACTORY_APP_PRIVATE_KEY --repo "$repo" --app "$target" < "$FACTORY_APP_PRIVATE_KEY_FILE"
  done
  if [[ -n "$deploy_url" ]]; then
    gh secret set LARAVEL_CLOUD_DEPLOY_HOOK --repo "$repo" --body "$LARAVEL_CLOUD_DEPLOY_HOOK"
    gh variable set PRODUCTION_URL --repo "$repo" --body "$deploy_url"
  fi
  [[ -n "$error_sensor" ]] && gh secret set ERROR_TRACKER_TOKEN --repo "$repo" --body "$ERROR_TRACKER_TOKEN"

  gh api -X PATCH "repos/$repo" -F allow_auto_merge=true -F delete_branch_on_merge=true > /dev/null
  # The security loop owns advisories; Dependabot security PRs would duplicate it.
  gh api -X DELETE "repos/$repo/automated-security-fixes" > /dev/null 2>&1 || true

  labels=(
    "security-loop|0E8A16" "security-loop-deferred|D93F0B" "larastan-loop|0E8A16" "larastan-loop-deferred|D93F0B"
    "needs-type-review|FBCA04" "mutation-loop|0E8A16" "prod-error-loop|0E8A16"
    "prod-error-deferred|D93F0B" "test-weakened|B60205" "dependency-fix-attempted|C5DEF5"
  )
  for l in "${labels[@]}"; do
    gh label create "${l%%|*}" --repo "$repo" --color "${l##*|}" --force > /dev/null
  done

  branch=$(gh repo view --json defaultBranchRef --jq .defaultBranchRef.name)
  checks='["ci / pest", "lint / quality"]'
  [[ "$e2e" == true ]] && checks='["ci / pest", "ci / e2e", "lint / quality"]'
  if gh api "repos/$repo/branches/$branch" > /dev/null 2>&1; then
    jq -n --argjson checks "$checks" '{
      required_status_checks: {strict: false, contexts: $checks},
      enforce_admins: false, required_pull_request_reviews: null, restrictions: null,
      allow_force_pushes: false, allow_deletions: false}' \
      | gh api -X PUT "repos/$repo/branches/$branch/protection" --input - > /dev/null
    echo "   Branch protection on $branch requires: $(jq -r 'join(", ")' <<<"$checks")"
  else
    echo "   $branch isn't on GitHub yet: push it, then re-run adopt.sh to protect it."
  fi
fi

# --- Summary ----------------------------------------------------------------------
if [[ ${#conflicts[@]} -gt 0 ]]; then
  echo
  echo "Not overwritten (exist and aren't factory-managed; re-run with --force to replace):"
  printf '  %s\n' "${conflicts[@]}"
fi
echo
say "Done. Review with: git status && git diff"
