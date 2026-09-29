---
name: kill-mutants
description: Actuator for the mutation-score ratchet loop. Writes Pest tests that kill surviving mutants in one target class, without changing application code. Use when the loop hands over a class's surviving mutants, or when asked to improve mutation score / kill mutants for a class.
---

# Kill surviving mutants

A surviving mutant is a small change to the source (a removed method call, a
flipped condition, an emptied array) that **no test noticed**. Each one marks a
behaviour that isn't pinned down. Your job is to write tests that pin those
behaviours down, so the mutated code fails.

## Rules (the diff guard and ratchet enforce them; breaking one discards your work)

- Change files under `tests/` **only**. Application code is frozen. If a mutant
  can only be killed by changing the source, skip it and say so.
- Don't delete or weaken existing tests: strict test-integrity gate.
- Every new test must assert **observable behaviour**: a return value, a
  database row, a response status or content, a dispatched event. Never write
  `expect(true)->toBeTrue()`, and never assert on implementation details just
  to trip the mutant.
- The ratchet re-runs mutation testing on the class. Every mutant that was
  killed before must stay killed, and at least one new mutant must die.
- Do not commit or push.

## Steps

1. Read the survivors list and the mutation diffs the prompt points to. Each
   entry gives the file, line, mutator and the exact code change.
2. Group survivors by behaviour. Several mutants on one validation-rules array
   usually fall to one well-chosen test per rule.
3. Uncovered mutants (`UNCOVERED`) need a test that executes the code at all.
   Untested ones (`UNTESTED`) are executed but not asserted on.
4. Put tests next to existing ones for the class (`tests/Feature` or
   `tests/Unit`), in the same style: Pest, factories, `RefreshDatabase` if the
   neighbours use it.
5. Check one mutant: `vendor/bin/pest --mutate --class='<Class>'`.
   It should report fewer survivors. Run the full suite before finishing.

## Final message

The number of survivors killed, and one line per behaviour now under test. List
the mutants you deliberately skipped (equivalent mutants, or ones that need a
source change) so they can go on `.github/agent-memory/mutation-skip.txt`.
