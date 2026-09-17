# robot-council

A **Laravel package** (`joshdaugherty/robot-council`), not an application. It was scaffolded from `spatie/package-skeleton-laravel` and has no features yet: the classes under `src/` are the skeleton's placeholders.

## Layout

- `src/` — namespace `JoshDaugherty\RobotCouncil\`. `RobotCouncilServiceProvider` is built on `spatie/laravel-package-tools` and is auto-discovered by consuming apps through `extra.laravel` in `composer.json`, along with the `RobotCouncil` facade alias.
- `config/robot-council.php`, `database/migrations/*.php.stub`, `database/factories/`, `resources/views/` — what the provider can publish to a consuming app.
- `tests/` — Pest on Orchestra Testbench. `tests/Pest.php` binds `tests/TestCase.php`, which registers the service provider; `tests/ArchTest.php` forbids `dd`, `dump`, and `ray`.
- `.claude/rules/` loads into every session; `.claude/skills/` loads on demand.

## Commands

| Task | Command |
| --- | --- |
| Install | `composer install` |
| Tests | `composer test` (`vendor/bin/pest`); one file or test: `vendor/bin/pest --compact tests/ExampleTest.php --filter=...` |
| Coverage | `composer test-coverage` (needs PCOV or Xdebug; see the `pcov-setup` skill) |
| Mutation | `vendor/bin/pest --mutate --path=src --class="JoshDaugherty\RobotCouncil\<Class>"` |
| Static analysis | `composer analyse` (PHPStan with Larastan, level 5, `phpstan-baseline.neon`) |
| Format | `vendor/bin/pint --dirty`; check only: `vendor/bin/pint --test` |
| Refactor | `composer refactor` (Rector; see `rector.php`); check only: `composer test:refactor` |

**There is no `php artisan`.** Testbench supplies a throwaway Laravel skeleton under `vendor/`, driven by `vendor/bin/testbench`. Its `make:*` generators write into that skeleton, not into this package, so create files by hand.

## Things that are easy to get wrong

- **Every API must exist in the lowest supported Laravel version.** `composer.json` admits Laravel `^13.20.0` (`illuminate/contracts`), but the development install resolves the newest. The floor is the lowest release CI can test: its `prefer-lowest` cells resolve `laravel/framework` v13.20.0, because Pest 5 with `pestphp/pest-plugin-laravel` 5.0.0 resolves nothing lower. Move the constraint whenever that tested floor moves, for example after raising a Pest constraint.
- **`composer.lock` is gitignored.** Every CI run and every fresh install resolves dependencies anew, so an unchanged branch can go red later. Compare resolved versions before blaming a diff (see `measurement-parity`).
- **CI is one workflow with one required check.** [`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every pull request and every push to `main`, with no path filters:
  - `tests` runs `vendor/bin/pest --ci` on ubuntu and windows × PHP 8.5 and 8.4 × Laravel 13 × `prefer-lowest` and `prefer-stable`, with `fail-fast: false`, on Pest 5 and PHPUnit 13.
  - `phpstan` runs PHPStan on PHP 8.5, and `pint` runs `vendor/bin/pint --test`, which **fails on a style problem instead of fixing it**. Run `vendor/bin/pint --dirty` before pushing.
  - `rector` runs `vendor/bin/rector --dry-run`, which fails when Rector would change a file. Run `composer refactor` before pushing, and review what it changed.
  - `ci-passed` succeeds only when every other job succeeded. It is the one check the `main` ruleset requires.
  - Nothing writes `CHANGELOG.md` automatically. A release adds its entry through an `Update CHANGELOG for vX.Y.Z` pull request before the tag (the `writing-release-notes` skill).
  - Dependabot opens weekly Composer and GitHub Actions update pull requests labeled `dependencies`. Nothing merges them automatically: take each through `pre-merge-check` like any other change.
- **`main` is guarded by a ruleset**: a pull request, a successful `ci-passed`, and a branch that is up to date with `main`. Enforcement holds only while the ruleset is `active` (`gh api repos/joshdaugherty/robot-council/rulesets`); `pre-merge-check` covers what no check can.
- **The repository is owned by a personal account**, so GitHub issue types are unavailable. The issue templates declare them for the day it moves to an organization.
- **Never disclose an exploitable vulnerability in a public issue or PR.** Use a draft security advisory, per the `security-audit` skill.

## Where the conventions live

Rules (always loaded) — follow them; don't restate them:

- **Shipping:** `adversarial-review` (verify before a change ships or a claim is published), `pre-merge-check` (the nine steps before merging), `sync-pr-branch` (bring a branch current, and the inputs its checks read), `closing-a-ticket` (what "done" means).
- **Evidence:** `an-empty-result-is-not-evidence`, `measurement-parity`.
- **Local processes and trees:** `long-running-commands`, `worktrees`.
- **GitHub:** `github-api-budget`, `filing-defects-across-repos`, `design-decision-forks`.
- **Prose:** `impersonal-voice-in-github-artifacts`, `no-emoji-in-durable-records`, `american-english-and-dictionary-overrides`.

Skills (activate when working in that area):

- **Code:** `laravel-best-practices`, `php-coding-standards`, `php-documentation`, `pest-testing`.
- **Tooling:** `pcov-setup`, `security-audit`, `wcag-contrast`.
- **Writing:** `writing-commits`, `writing-issues` (labels, templates, the `afk`/`hitl` convention), `writing-pull-requests`, `writing-release-notes`.
