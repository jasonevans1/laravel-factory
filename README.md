# laravel-factory

A control-loop software factory for Laravel apps. Each loop has a **set point**,
a **sensor**, a **controller** and an **actuator**, and opens small reviewable PRs.

**Deterministic first, Claude as fallback.** Every actuator runs a deterministic
tool first. Claude is called only when that tool can't finish the job, or when
the task is inherently generative (writing a test). Sensors, controllers,
ratchets, gates and the push/PR step are always scripts. Claude never decides
whether its own work succeeded.

## Loops

| Loop | Set point | Deterministic actuator | Claude | PR auto-merge |
|---|---|---|---|---|
| `security-loop` | 0 `composer audit` advisories | `composer update` → widen within major → tests | only if tests fail after the upgrade | lockfile-only fixes, once trusted |
| `larastan-loop` | empty `phpstan-baseline.php` | Rector on the target file | only for errors Rector left | unflagged PRs, once trusted |
| Dependabot + `dependency-fix` | dependencies current (minor/patch) | Dependabot grouped PRs | only when a Dependabot PR's CI fails | Dependabot patch/minor; Claude fixes never |
| `mutation-loop` | escaped mutants shrink | none possible | required: writes tests (tests/ only) | never |
| `prod-error-loop` | 0 unresolved app errors | none possible | required: repro test, then fix | never |

Every PR has a common set of dampeners and gates (`lint.yml`, `tests.yml`): Pint, PHPStan
(new errors fail), composer-audit and runtime-deprecation warnings, and the
**test-integrity gate**. The gate labels and comments when a diff removes tests
or assertions, adds skips, or rewrites expected values. On loop commits made by
Claude it runs in strict mode and blocks the PR.

"Once trusted" means that loop's last 5 merged PRs were merged unchanged: every
commit is by the factory bot.

## How a loop run is bounded

- **One open PR per loop** (label-based). Scheduled runs no-op while one is open.
- **Staleness alarm:** an open loop PR older than 7 days fails the job loudly,
  so a stalled loop can't go quiet (yeschef #28 did, for 8 weeks).
- **Credential boundary:** the Claude step's environment holds only
  `CLAUDE_CODE_OAUTH_TOKEN`. The app is checked out with
  `persist-credentials: false`, so there's no GitHub token and no API key, and
  WebFetch/WebSearch are disabled. Claude doesn't commit; the workflow commits
  and verifies. Push and PR go through a separate step as the factory GitHub App.
- **Protected paths:** a plugin hook blocks Claude's edits to paths the loop
  forbids (for example `composer.lock`, or `app/` in the mutation loop).
  `scripts/diff-guard.php` is the authority, because it also catches edits made
  through Bash.
- **Untrusted input:** production error data reaches the agent only through
  `scripts/prod-error-next.php`, which keeps whitelisted fields that pass strict
  validation and drops everything else.
- **Subscription quota:** Claude runs on your Pro/Max token (`claude setup-token`),
  never an API key. Each run has a wall-clock timeout, and schedules are
  staggered overnight.

## Layout

```
.github/workflows/   reusable workflows (on: workflow_call) + self-test.yml
.github/actions/     setup-app, loop-gate, app-auth, claude, open-pr
scripts/             deterministic sensors, controllers, ratchets, guards
skills/              Claude Code plugin skills (the fallback actuators)
hooks/               protect-paths PreToolUse hook
adopt/adopt.sh       the one entry point for new and existing apps
adopt/templates/     caller workflows, dependabot.yml, rector.php, settings, agent memory
tests/run.sh         self-tests for every script (fixtures in tests/fixtures)
```

## One-time setup

1. **Publish this repo** as a public `jasonevans1/laravel-factory`, so callers
   can `uses:` its workflows and check it out without a token. Tag `v1`:
   `git tag v1 && git push origin main v1`. For later releases, move `v1` for
   compatible changes, or tag `v2` and re-run `adopt.sh --ref v2`.
2. **Create the factory GitHub App** (Settings → Developer settings → GitHub Apps):
   - Permissions: Contents RW, Pull requests RW, Issues RW, Metadata R,
     Workflows RW (only if loops may touch workflow files; otherwise leave it off).
   - No webhook. Install it on each app repo.
   - Note the App ID and download a private key (`.pem`).
3. **Claude token:** run `claude setup-token` locally and keep the token for adopt.

## Adopting an app

```bash
# New app
laravel new myapp --livewire --pest --npm --git && cd myapp

# New or existing app: local files first, review, commit
~/projects/laravel-factory/adopt/adopt.sh --local [--deploy-url https://myapp.com]
git add -A && git commit -m "Adopt laravel-factory"

# Then GitHub settings (secrets, auto-merge, branch protection, labels)
git push -u origin main
CLAUDE_CODE_OAUTH_TOKEN=... FACTORY_APP_ID=... FACTORY_APP_PRIVATE_KEY_FILE=app.pem \
LARAVEL_CLOUD_DEPLOY_HOOK=... \
  ~/projects/laravel-factory/adopt/adopt.sh [--deploy-url https://myapp.com]
```

What adopt does:
- installs Larastan, deprecation rules and Rector;
- adds the deprecation rules to `phpstan.neon`;
- if PHPStan reports errors, baselines them so the Larastan loop can ratchet
  them down (with zero errors, the lint dampener keeps it that way);
- writes caller workflows pinned to the factory version, plus `dependabot.yml`;
- enables the plugin in `.claude/settings.json`;
- creates `.github/agent-memory/`;
- on GitHub: sets secrets (App secrets for Dependabot too), turns on
  auto-merge, turns off Dependabot security PRs (the security loop owns
  those), and sets branch protection and labels.

Re-running is safe. Files that exist and aren't factory-managed are reported,
never overwritten (use `--force` to replace them). Existing workflows are
listed so you can remove the duplicates.

Prod-error loop: pass `--error-sensor '<command>'`. The command must print the
tracker's unresolved groups as JSON (the shape is documented in
`scripts/prod-error-next.php`), and gets `ERROR_TRACKER_TOKEN` in its
environment.

## Development

```bash
tests/run.sh                      # script self-tests
actionlint && shellcheck scripts/*.sh hooks/*.sh adopt/adopt.sh tests/run.sh
claude plugin validate --strict . # plugin + marketplace manifests
```

## Known limits (deliberate)

- `deploy.yml` polls `/up`, which can't tell the old release from the new one.
  To tighten it, expose the commit on `/up` or poll the Cloud API.
- The test-integrity gate is heuristic (regex counts, rewritten assertion
  lines). It's a reviewer's prompt, not proof. Mutation testing is the stronger
  oracle.
- Pest's `--mutate` has no JSON report, so `scripts/mutation.php` parses the
  text output (both human and laravel/pao formats, pinned by fixtures). Per-class
  runs omit `--everything`, because it overrides `--class`.
- Runtime deprecations are only seen in booted-app tests
  (`LOG_DEPRECATIONS_WHILE_TESTING`). Static ones come from
  phpstan-deprecation-rules through the Larastan loop.
