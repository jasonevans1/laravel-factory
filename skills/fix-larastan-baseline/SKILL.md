---
name: fix-larastan-baseline
description: Claude fallback for the Larastan ratchet loop. Fixes baselined PHPStan/Larastan errors in one target file after Rector has taken its deterministic pass. Use when the loop hands over a file's remaining baseline errors, or when asked to shrink phpstan-baseline.php for a file.
---

# Fix Larastan baseline errors

You are the fallback actuator in the Larastan ratchet loop. Rector already fixed
what it could. The errors left for the target file are listed in the file the
prompt points to. Fix **at most 15** of them, in the target file **only**.

## Rules (the ratchet and diff guard enforce them; breaking one discards your work)

- Change only the target file. Don't edit `phpstan.neon`, don't regenerate
  `phpstan-baseline.php` (the workflow does that), and don't touch tests.
- **Never** add `@phpstan-ignore`, `@phpstan-ignore-next-line` or
  `ignoreErrors`: that fails the PR.
- Don't widen types to `mixed` or paper over errors with inline
  `/** @var X $y */` casts. Both get the PR labelled `needs-type-review`. Fix
  the real type instead.
- Don't change behaviour. The full test suite must still pass.
- Do not commit or push.

## Common Laravel fixes

- Relations: `@return HasMany<Recipe, $this>` (Larastan generics)
- Nullable auth: guard `auth()->user()` / `$request->user()` before use
- Livewire/Volt: add return types to actions and computed properties
- Collections: `@return Collection<int, Recipe>`; `@param array{name: string, qty?: int} $data` for array shapes
- Deprecations (`*.deprecated`): switch to the replacement the message names

## Steps

1. Read the error list and the target file.
2. Fix the errors in order: deprecations first, then the rest, up to 15.
3. Check your work: `vendor/bin/phpstan analyse <target> --memory-limit=1G --error-format=raw`.
   Baselined errors are hidden, so an error you fixed simply disappears. Any
   *new* error means your change broke something.
4. Run the test suite, then `vendor/bin/pint <target>`.

## Final message

It becomes part of the PR body: one line per fixed error class, and anything
you deliberately left for next time.
