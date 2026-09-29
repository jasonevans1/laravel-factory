---
name: dependency-fix
description: Claude fallback for the security loop and the dependency-fix loop. A dependency upgrade is already applied and the test suite fails; adapt the application code to the new version. Use when a loop hands over a failing upgrade, or when asked to fix code broken by a composer/npm upgrade.
---

# Dependency fix

You are the fallback actuator in a control loop. A deterministic script has
already upgraded a dependency and run the tests. They failed. Your job is to
make the **application** work with the new version.

## Rules (the post-check enforces them; breaking one discards your work)

- Do **not** edit `composer.json`, `composer.lock`, `package.json`,
  `package-lock.json`, `phpstan.neon` or any baseline. The version is decided;
  you adapt to it.
- Do **not** weaken tests: no deleted tests, fewer assertions, new `skip()` or
  `todo()`, or changed expected values. Updating a test only because the
  library's API changed (a renamed method in a mock, for example) is allowed.
  The test-integrity gate runs in strict mode, so keep those edits minimal and
  obviously mechanical.
- Do not commit or push. The workflow commits and verifies.

## Steps

1. Read the failure: the test output or CI log the prompt points to. Find the
   first real error, not the cascade after it.
2. Read the upgrade: `git log -1 -p -- composer.lock` (or `package-lock.json`)
   shows the old and new versions. Check the package's changelog or UPGRADE
   notes in `vendor/<package>/` (for example `CHANGELOG.md` or `UPGRADE*.md`)
   for the breaking change.
3. Fix the call sites in `app/`, `config/`, `routes/`, `resources/` or
   `database/`. Prefer the new API the changelog recommends over shims.
4. Run the test command from the prompt. Iterate until it passes.
5. Run `vendor/bin/pint --dirty`.

## Final message

It becomes the PR body, so keep it short:

```
{package} {old} -> {new}
Breaking change: {one line}
Fix: {what you changed, one line per file}
Tests: pass
```

If you could not make the tests pass, say so plainly and list what you tried.
The workflow will not open a PR.
