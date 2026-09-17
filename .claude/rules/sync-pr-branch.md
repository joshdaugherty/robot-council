# Rule — sync every PR branch from `origin/main` before validating it

Before a branch is validated and before its pull request is opened, bring it **current with `origin/main`**.

**Why this is a standing order.** A branch behind `origin/main` is validated against code that will not exist once it merges. If a change merged since then touched the same surface, or changed an input the branch's checks read, the merge produces a combined state that was **never tested together**. The failure is silent: the branch's own checks stay green because they never saw the interacting change, and nothing on `main` refuses the merge (see [`pre-merge-check`](pre-merge-check.md)).

## How to apply

1. **Author side: sync before running the gate and before opening the PR.** First pick up anything the style workflow pushed. It auto-commits "Fix styling" to a branch after any push touching `**.php`, so run `git fetch origin && git merge --ff-only origin/<branch>`. Then run `git merge origin/main`, and only after that run `composer test`, `composer analyse`, `vendor/bin/pint --test`, and `gh pr create`. A conflict found here is caught before review rather than after merge.

2. **Validator side: sync before validating**, then trial-merge and validate the merged state per [`pre-merge-check`](pre-merge-check.md).

3. **The exception: genuinely disjoint branches need not chase every advance of `main`. But judge disjointness by the inputs the checks READ, not only by the files the diff TOUCHES.** A branch is exempt only when it shares neither files nor inputs with the merges since its branch point. For this repository, the inputs are:

   | Shared input | What reads it |
   | --- | --- |
   | `composer.json` | every CI job and every local command, because it decides which Pest, PHPStan, Larastan, Pint, Laravel, and Testbench versions get installed |
   | *no committed `composer.lock`* | every CI job, which resolves dependencies fresh on each run (see below) |
   | `phpstan.neon.dist`, `phpstan-baseline.neon` | the `PHPStan` workflow, `composer analyse` |
   | `phpunit.xml.dist` | `run-tests`, `composer test` |
   | `tests/Pest.php`, `tests/TestCase.php` | every test: they bind the base test case and register the service provider |
   | `.github/workflows/*` | the checks themselves: triggers, path filters, matrix, flags |
   | `pint.json` *(does not exist yet)* | Pint. Today Pint runs the Laravel preset defaults, so the only input that moves is Pint's resolved version. Once a `pint.json` is committed, it is an interacting input like the rest. |

   **Checking takes one read**, and a path that does not exist yet is harmless after `--`:

   ```bash
   git log --oneline "$(git merge-base HEAD origin/main)"..origin/main -- \
     composer.json phpstan.neon.dist phpstan-baseline.neon phpunit.xml.dist \
     tests/Pest.php tests/TestCase.php .github/workflows pint.json
   ```

   **Syncing cannot fix the lockfile row.** `composer.lock` is gitignored, so being current with `main` pins the source but not the toolchain. The same commit resolves different Pest, PHPStan, and Laravel versions on different days, even when `main` has not moved. The `run-tests` matrix also resolves each PHP, Laravel, and OS combination twice, once with `prefer-lowest` (the floor of each constraint) and once with `prefer-stable`. A branch that was green last week can go red without a commit anywhere. When that happens, compare what the failing run resolved, shown in its `List Installed Dependencies` step (`composer show -D`, direct dependencies only), with your local `composer show -D`. Do not assume the branch caused it. See [`measurement-parity`](measurement-parity.md).

   **Measure this table per repository rather than inheriting it**, and re-measure it when a tool config or a lockfile is committed.

## The DRY line

This file owns bringing a branch current and the list of inputs its checks read. [`pre-merge-check`](pre-merge-check.md) owns the trial merge and validating the merged state. [`worktrees`](worktrees.md) changes *where* you branch, and this rule keeps that branch *current*. [`measurement-parity`](measurement-parity.md) owns comparing resolved dependency versions between two runs.
