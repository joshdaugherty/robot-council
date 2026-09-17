# robot-council

A **Laravel package** (`robot-council/core`), not an application. It was scaffolded from `spatie/package-skeleton-laravel` and has no features yet: `src/` holds only the service provider.

## Layout

- `src/` — namespace `RobotCouncil\`. `RobotCouncilServiceProvider` is built on `spatie/laravel-package-tools` and is auto-discovered by consuming apps through `extra.laravel` in `composer.json`. It registers only the package name so far: no config, migrations, views, commands, or facade.
- `tests/` — Pest on Orchestra Testbench. `tests/Pest.php` binds `tests/TestCase.php`, which registers the service provider; `tests/ArchTest.php` applies Pest's `php()`, `security()`, and `strict()` arch presets to the package's namespaces. Tests that read data a second database connection commits belong to the `cross-connection` group, which `phpunit.xml.dist` excludes from every run that does not name it; `tests/CrossConnectionTest.php` is the pattern.
- `.claude/rules/` loads into every session; `.claude/skills/` loads on demand.

## Commands

| Task | Command |
| --- | --- |
| Install | `composer install` |
| Tests | `composer test` (`vendor/bin/pest`); one file or test: `vendor/bin/pest --compact tests/ExampleTest.php --filter=...` |
| Cross-connection tests | Postgres only: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=<db> DB_USERNAME=<user> DB_PASSWORD=<password> vendor/bin/pest --group=cross-connection` |
| Coverage | `composer test-coverage` (needs PCOV or Xdebug; see the `pcov-setup` skill) |
| Mutation | `vendor/bin/pest --mutate --path=src --class="RobotCouncil\<Class>"` |
| Static analysis | `composer analyse` (PHPStan with Larastan and `pestphp/pest-plugin-phpstan`, level `max` with bleeding edge, no baseline); PHPStan and Rector both cover `src`, `tests`, and `rector.php` |
| Format | `vendor/bin/pint --dirty`; check only: `vendor/bin/pint --test` |
| Refactor | `composer refactor` (Rector; see `rector.php`); check only: `composer test:refactor` |

**There is no `php artisan`.** Testbench supplies a throwaway Laravel skeleton under `vendor/`, driven by `vendor/bin/testbench`. Its `make:*` generators write into that skeleton, not into this package, so create files by hand.

## Things that are easy to get wrong

- **Every API must exist in the lowest supported Laravel version.** `composer.json` admits Laravel `^13.23.0` (`illuminate/contracts`), but the development install resolves the newest. The floor is the lowest release CI can test: its `prefer-lowest` cells resolve `laravel/framework` v13.23.0, because `orchestra/testbench ^11.2.0` requires it. Move the constraint whenever that tested floor moves, for example after raising the Testbench constraint.
- **`composer.lock` is gitignored.** Every CI run and every fresh install resolves dependencies anew, so an unchanged branch can go red later. Compare resolved versions before blaming a diff (see `measurement-parity`).
- **CI is one workflow with one required check.** [`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every pull request and every push to `main`, with no path filters:
  - `tests` runs `vendor/bin/pest --ci` on ubuntu and windows × PHP 8.5 and 8.4 × Laravel 13 × `prefer-lowest` and `prefer-stable`, with `fail-fast: false`, on Pest 5 and PHPUnit 13.
  - `phpstan` runs PHPStan on PHP 8.5, and `pint` runs `vendor/bin/pint --test`, which **fails on a style problem instead of fixing it**. Run `vendor/bin/pint --dirty` before pushing.
  - `rector` runs `vendor/bin/rector --dry-run`, which fails when Rector would change a file. Run `composer refactor` before pushing, and review what it changed.
  - `postgres` runs on ubuntu with PHP 8.5 against a `postgres:17` service container. It runs `vendor/bin/pest --ci` with `DB_CONNECTION=pgsql`, then `vendor/bin/pest --ci --group=cross-connection`. No other run executes that group, and there is no Postgres locally unless you start one.
  - `ci-passed` succeeds only when every other job succeeded. It is the one check the `main` ruleset requires.
  - Nothing writes `CHANGELOG.md` automatically. A release adds its entry through an `Update CHANGELOG for vX.Y.Z` pull request before the tag (the `writing-release-notes` skill).
  - Dependabot opens weekly Composer and GitHub Actions update pull requests labeled `dependencies`. Nothing merges them automatically: take each through `pre-merge-check` like any other change.
- **`main` is guarded by a ruleset**: a pull request, a successful `ci-passed`, and a branch that is up to date with `main`. Enforcement holds only while the ruleset is `active` (`gh api repos/robot-council/core/rulesets`); `pre-merge-check` covers what no check can.
- **Package classes are `final`, with no `protected` methods.** Pest's `strict()` preset enforces it, so consumers cannot extend them; extension points have to be designed in. A method a parent declares `protected` is widened to `public`, with a per-file Rector skip (see `php-coding-standards`).
- **The repository belongs to the `robot-council` GitHub organization**, which enables the `Task`, `Bug`, and `Feature` issue types. It moved from `joshdaugherty/robot-council` on 2026-09-17, and old URLs redirect, so links in earlier issues, pull requests, and the `v0.1.0` release still resolve.
- **Never disclose an exploitable vulnerability in a public issue or PR.** Use a draft security advisory, per the `security-audit` skill.

## Where the conventions live

Rules (always loaded) — follow them; don't restate them:

- **Shipping:** `adversarial-review` (verify before a change ships or a claim is published), `pre-merge-check` (the judgment steps before merging), `sync-pr-branch` (bring a branch current, and the inputs its checks read), `closing-a-ticket` (what "done" means).
- **Evidence:** `an-empty-result-is-not-evidence`, `measurement-parity`.
- **Local processes and trees:** `long-running-commands`, `worktrees`.
- **GitHub:** `github-api-budget`, `filing-defects-across-repos`, `design-decision-forks`.
- **Prose:** `impersonal-voice-in-github-artifacts`, `no-emoji-in-durable-records`, `american-english-and-dictionary-overrides`.

Skills (activate when working in that area):

- **Code:** `laravel-best-practices`, `php-coding-standards`, `php-documentation`, `pest-testing`.
- **Tooling:** `pcov-setup`, `security-audit`, `wcag-contrast`.
- **Writing:** `writing-commits`, `writing-issues` (labels, templates, the `afk`/`hitl` convention), `writing-pull-requests`, `writing-release-notes`.
