# robot-council

A **Laravel package** (`robot-council/core`), not an application. It is the core of a fleet coordination service for AI coding agents, designed in [robot-council/core#14](https://github.com/robot-council/core/issues/14) and installed into a host Laravel application. GitHub sign-in and agent enrollment are the slices that exist.

## Layout

- `src/` — namespace `RobotCouncil\`. `RobotCouncilServiceProvider` is built on `spatie/laravel-package-tools` and is auto-discovered by consuming apps through `extra.laravel` in `composer.json`. It registers the config file, the views, the console commands, the two Sanctum guards, the rate limits, the web and machine routes, the `robot-council-admin` ability, and the hourly device-code prune. Under it: `Access\` (the allowlist, the guard names, the fixed ability list, and what a token is), `Console\`, `Http\Controllers\`, `Http\Middleware\`, `Models\` (the package's own tables), and `Support\` (host user records, lifetimes, and the device-code, installation, and session stores).
- **The guard is configuration, never the host's default.** `robot-council.auth.guard` (default `web`) is what signs a developer in, checks them, and signs them out. A host whose default guard is another one would otherwise loop through sign-in forever.
- **A Sanctum guard returning somebody says nothing about what they are.** `Guard::__invoke()` tries the `web` guard before it reads a bearer token, so a signed-in human reaching a machine route arrives as that human, carrying a `TransientToken` whose `can()` answers true to every ability. `EnsureInstallation` and `EnsureAgentSession` therefore check the principal's class and its token's class, never just that one resolved. `Access\Tokens` holds that check, taking the token as `HasAbilities` because the type `currentAccessToken()` is declared to return makes the check read as dead code.
- **A route's limiter is declared BEFORE its principal middleware, and that declaration is what decides the order.** `Router::sortMiddleware()` reorders only middleware that are themselves in the framework's priority list, *relative to each other*. `ThrottleRequests` is in that list and neither `EnsureAgentSession` nor `EnsureInstallation` is, so with one member present nothing moves. Declared after the guard, the limiter never runs for a request the guard refuses: measured with `agent_per_session` at 2, six unauthenticated requests returned `401,401,401,401,401,401` and six authenticated ones `200,200,429,429,429,429`, so an unauthenticated flood was unlimited while the limiter demonstrably worked. Because the limiter now runs first, one keyed on the principal must resolve it through the guard rather than read what the middleware left on the request, and must key by `ip:` when none resolves.
- `config/robot-council.php` — the access lists, the route prefixes, the credential lifetimes, and the rate limits, published to the host application. It is the only place `env()` may be called, which `phpstan.neon.dist` tells Larastan through `configDirectories`.
- `routes/web.php` and `routes/api.php` — the human-facing and machine-facing routes, each mounted by the provider under its configured prefix and middleware group, both with the `robot-council.` name prefix. Approve and deny accept POST only.
- `resources/views/enroll.blade.php` — the verification page, registered by `hasViews()` under the `robot-council::` namespace. Everything a requester supplied is printed as a claim and escaped; there is no `{!! !!}` in it and there should never be.
- **The change feed is written through `Support\FleetEvents`, never by inserting a row.** Every writer locks one sentinel row in `robot_council_feed_lock` **before** inserting, because both Postgres and InnoDB draw the key at insert time: two writers can take IDs 5 and 6 and commit 6 first, and a reader paging `id > cursor` passes 6 and never sees 5 again. A row lock rather than `pg_advisory_xact_lock`, because the problem is not Postgres's alone, a row lock is transaction-scoped on every driver, and it collides with nothing a host owns. The order matters: a key drawn before the lock is a key already drawn, and a sequence is not rolled back.
- **A queued job dispatched from inside a transaction must not be allowed to throw.** `DatabaseTransactionRecord::executeCallbacks()` has no try/catch and Laravel runs it *after* the commit, so anything thrown there escapes `DB::transaction()` with the row already durably written. `SlackMirror` therefore queues from its own `DB::afterCommit()` callback with the try/catch inside it: an unreachable queue must not turn a committed enrollment into a 500.
- **Presence is written by `Support\SessionPresence`, and every write is conditional on the ROW.** A
  status is never decided from the model instance: Sanctum's guard materializes the session several
  queries before the middleware runs, so a sweep committing `stale` in between would be overwritten
  by a contact write that left the status alone -- and a `stale` row with a fresh contact time is
  unreachable by both sweep passes, so a live process would report `stale` to the whole fleet until
  it died. Each write names the statuses it accepts and, where a clock decides it, the contact time
  its read saw; the count of changed rows is the decision. That is also what makes `Events\SessionGone`
  fire exactly once however a session ended, and what makes two sweeps at once safe, so the sweep
  takes no overlap lock -- one would fail worse than the problem, holding for its whole expiry after
  a killed run and marking nothing gone meanwhile.
- **Lock order is `robot_council_installations`, then `robot_council_agent_sessions`, then the feed
  sentinel, then `personal_access_tokens`.** Every path that touches more than one takes them in that
  order. Two paths taking the same two rows in opposite orders deadlock on every engine that locks
  rows, which is all of them but SQLite -- and SQLite serializes writers, so no test in this suite can
  show it. `AgentSessions::renew()` and `Installations::revoke()` both had to be reordered for this.
  The one ordering still inverted is MySQL's foreign-key check on `robot_council_events`, which takes
  a shared lock on the parent session row while the sentinel is held; that is an open fork.
- **Whatever is displayed to agents is charset-limited at the edge.** `harness`, `machine_label`, and `project_id` all reach other developers' agents, and event content is untrusted input to something that may have shell access. `meta` is bounded by `Http\Rules\BoundedMeta` for the same reason an `array` rule bounds nothing.
- **Who sees which event is decided in `Support\FleetFeed`, and it is a security boundary.** Narration reaches only its own developer's sessions and sessions that held `coordinator:direct` when they posted; state changes and directives reach everyone. Whether the ability was held is recorded on the event at write time, so revoking it later is not retroactive.
- **Nothing reads from Slack, and a test enforces it.** The package's only outbound HTTP call is one POST in `Jobs\MirrorEventToSlack`. A read would let a coordination decision depend on Slack being up and honest.
- **Read what Rector does to a queued job.** It renamed a private `retryAfter()` helper to `backoff()`, which is a framework hook, so `Illuminate\Queue\Queue` began calling it with a signature it does not have; it also rewrote `public int $tries` into `#[Tries(5)]`. Neither is announced. Do not name anything on a job `retryAfter` or `backoff`.
- `database/migrations/` — the package's own tables, loaded by the provider so `php artisan migrate` picks them up. `robot_council_agent_sessions.last_seen_at` is a non-nullable `dateTime` rather than a `timestamp`: MySQL gives the first NOT NULL `TIMESTAMP` column an implicit `ON UPDATE CURRENT_TIMESTAMP` while `explicit_defaults_for_timestamp` is off, so marking a session stale would restart the clock deciding when it goes, and nullable would exempt a row from both cutoffs forever. `robot_council_github_identities` maps a host user to a GitHub account, and the package owns it because that mapping is what the access lists are checked against. `robot_council_installations`, `robot_council_agent_sessions`, and `robot_council_device_codes` hold enrollment.
- `database/stubs/` — migrations `robot-council:install` writes into the host application, because they change tables the host owns. Only nullability on `users.password` and `users.email`, skipped when already nullable. The suite runs the stub itself, so it is covered. The command also copies Sanctum's `personal_access_tokens` migration, guarding on the file-name suffix rather than the name, because a publish rewrites the timestamp.
- `resources/css/` and `resources/dist/` — the dashboard stylesheet's source and its compiled
  artifact. `package.json` drives the build; see the note below on why the artifact and its lockfile
  are committed.
- `src/Livewire/` — the dashboard's Livewire components, mounted by `routes/web.php` behind the
  allowlist gate. Testbench registers no provider it is not told about, so `tests/TestCase.php`
  lists Livewire's, Mary's and Blade Heroicons' providers by hand exactly as it does Socialite's.
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
| Static analysis | `composer analyse` (PHPStan with Larastan and `pestphp/pest-plugin-phpstan`, level `max` with bleeding edge, no baseline); PHPStan and Rector both cover `config`, `routes`, `src`, `tests`, and `rector.php` |
| Format | `vendor/bin/pint --dirty`; check only: `vendor/bin/pint --test` |
| Refactor | `composer refactor` (Rector; see `rector.php`); check only: `composer test:refactor` |

**There is no `php artisan`.** Testbench supplies a throwaway Laravel skeleton under `vendor/`, driven by `vendor/bin/testbench`. Its `make:*` generators write into that skeleton, not into this package, so create files by hand.

## Things that are easy to get wrong

- **Every API must exist in the lowest supported Laravel version.** `composer.json` admits Laravel `^13.23.0` (`illuminate/contracts`), but the development install resolves the newest. The floor is the lowest release CI can test: its `prefer-lowest` cells resolve `laravel/framework` v13.23.0, because `orchestra/testbench ^11.2.0` requires it. Move the constraint whenever that tested floor moves, for example after raising the Testbench constraint.
- **Anything written into `vendor/orchestra/testbench-core/laravel/` changes what the tools see, and CI has none of it.** That skeleton is Testbench's throwaway application, and a `vendor/bin/testbench` run or a test that publishes into it leaves files behind that a fresh CI install does not have. Two costs found so far: a `.env` copied from `.env.example` supplied an `APP_KEY` the suite was relying on, and leftover `*_create_personal_access_tokens_table.php` files under its `database/migrations/` let Larastan infer `PersonalAccessToken`'s columns, so `composer analyse` passed locally and failed in CI on `Access to an undefined property`. To reproduce a CI-only analysis failure, empty that directory and delete `build/phpstan` before running. Do not write narrowing that only one side asks for: `Command::argument()` and package view strings are inferred differently depending on whether the analyzer could boot the application, so a check written for one side is reported as dead code by the other. Take `mixed` and narrow inside a helper, as `Access\Tokens` and `Console\Argument` do.
- **The dashboard stylesheet is a committed build artifact, and `package-lock.json` is committed
  with it.** `resources/dist/dashboard.css` is compiled by `npm run build` from
  `resources/css/dashboard.css`, and a consuming application runs no asset build -- the decision on
  #30. Tailwind emits only the classes it finds by scanning, so every directory holding markup this
  package renders must be named in an `@source`, **including Mary's components under `vendor/`**,
  which Tailwind skips by default because it honors `.gitignore` and `/vendor` is ignored here. The
  artifact goes stale silently: a view added without a rebuild renders with the previous build's
  classes and nothing reports it. Measured while building #72 -- the committed file was missing
  `.card-body`, `.card-title`, `.antialiased` and `.bg-base-200`, every one a class the new layout
  used, and the page would have rendered half-styled. `npm run check` rebuilds and compares; read
  its exit code, because piping it through `tail` discards the `cmp` status. **`composer.lock` is
  gitignored and `package-lock.json` is not**, and that asymmetry is deliberate: the first is a
  library's dependency resolution, which CI should re-resolve, and the second is a build toolchain,
  whose drift would change the bytes a consumer receives. A CI check that the artifact matches its
  sources is #66.

- **SQLite does not enforce a `varchar` length and Postgres does**, so a fixture that writes an
  overlong value passes every local run and fails only in the `postgres` job. `$table->string('x', 32)`
  is a hard limit there: Postgres answers `SQLSTATE[22001] value too long for type character
  varying(32)` where SQLite stores the value whole. Measured on a test that wrote a 34-character
  payload into `robot_council_device_codes.harness`, which is `varchar(32)`. A test that writes past
  validation on purpose -- which is how the escaping guards prove the page rather than the validator
  -- has no rule to keep it inside the column, so it has to assert the bound itself.

- **Restoring a Blade view does not undo it: the compiled view wins on mtime.** Blade recompiles only
  when the source is newer than its cache under
  `vendor/orchestra/testbench-core/laravel/storage/framework/views/`, and a `cp` restore writes an
  mtime a second *older* than the compile that the planted run produced. Measured while
  mutation-controlling `resources/views/enroll.blade.php`: with the source byte-identical to `HEAD`
  and `cmp` confirming it, the cached compile still held `$code->machine_label` with no `e()`
  wrapper, so three rows of the new guard and the unrelated
  `DeviceVerificationTest > it escapes what the requester supplied` all failed against a view nobody
  had changed. The restore reads as complete and the next run tests the planted version. **Any
  mutation control that edits a Blade view has to clear that directory afterwards**, and a failure in
  a view test that `git diff` cannot explain is this until proven otherwise.

- **The local suite and CI run on different cache stores, and nothing records it.** A local
  checkout may have `vendor/orchestra/testbench-core/laravel/.env` with `CACHE_STORE=database`,
  copied there by a `vendor/bin/testbench` run; a fresh CI install has no `.env`, so `cache.default`
  falls back to `array`. Rate limiting is the visible difference: on a database store the limiter
  issues a dozen queries before the route's own first query, which changes where a `DB::listen`
  injection lands. Name the store alongside any result that depends on query order.
- **The suite's summary and its exit code are different answers, and CI reads the exit code.**
  `phpunit.xml.dist` sets `failOnWarning`, `failOnRisky`, `failOnEmptyTestSuite` and
  `beStrictAboutOutputDuringTests`, so a run can print `Tests: 388 passed` and still exit 1 with no
  failure shown anywhere -- not in the summary, and not in `build/report.junit.xml`, which records
  neither warnings nor risky tests. One `use SomeGlobalClass;` in a test file with no namespace does
  it: PHP warns that the statement has no effect, and the run fails. Read `$?`, and never take a
  green reading from a command whose output went through a pipe, which throws the status away. To
  find what a silent failure was, run with `--log-events-text` and grep for `Triggered`.
- **`Builder::update()` returns rows CHANGED, not rows matched, on MySQL.** Laravel sets no
  `MYSQL_ATTR_FOUND_ROWS` (zero occurrences in the framework) and reads `PDOStatement::rowCount()`,
  so a conditional update whose `where` matched a row that already says what was asked for reports
  **0** there and **1** on SQLite and Postgres. Every store in this package decides with
  `$changed !== 1`, so any write that can legitimately be a no-op needs a second look before it is
  read as a lost race: `Locks::renew()` inside one second is the case that bites, and its regression
  test cannot fail on SQLite for the same reason.
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
- **`assert()` is unavailable in `src/`.** Pest's `security()` preset bans it, so narrow types with `if` / `throw` instead (see `php-coding-standards`).
- **Neither `Installation` nor `AgentSession` extends `Illuminate\Foundation\Auth\User`.** Each is a plain model with the `Authenticatable` trait. Sanctum decides whether a token belongs on a guard with `$tokenable instanceof $model`, reading the guard's provider model, so a host that names the framework's base user as its own users model would otherwise find an agent session to be an instance of it and admit the token on its own `auth:sanctum` routes. Testbench's default users model is exactly that class.
- **A test that makes two authenticated requests must forget the guards between them.** `Illuminate\Auth\RequestGuard::user()` caches the principal it resolved, and one test process keeps one application, so the second request is otherwise answered as whoever the first authenticated: a revoked token keeps working and another installation's credential arrives as this one's. `TestCase::machine()` does it; a real request boots its own application, and Octane flushes the same state.
- **`Model::preventLazyLoading()` only ever fires on a query that returned more than one row.**
  `Builder::hydrate()` sets the flag `if (count($items) > 1)`, so a model loaded with `first()` can
  never raise `LazyLoadingViolationException` whatever a host configured. A claim that some
  single-model path 500s under strict mode is wrong, and a test written to prove one passes for the
  wrong reason. The only queries here that hydrate several are the presence sweep's chunk reads.
- **A route constraint bounds the character set and not the magnitude.** `whereNumber` is `[0-9]+`,
  which admits a number no bigint can hold: Postgres answers `22003 value out of range` -- a 500 --
  where SQLite quietly matches no rows. Use `RobotCouncilServiceProvider::ROUTE_ID`, which is
  `[0-9]{1,18}`, wherever a route takes one of the package's own IDs.
- **`laravel/socialite` caps Guzzle at 7 for host applications.** Socialite v5.31.0 requires `league/oauth1-client ^1.11`, which allows only Guzzle 6 or 7, while Laravel 13 allows `^7.8.2 || ^8.0`. Installing this package therefore resolves Guzzle 7 in the host application, until Socialite allows `league/oauth1-client` 2.x.
- **Tests boot Socialite's provider by hand.** A host application discovers it through Composer, while Testbench registers only what `tests/TestCase.php` lists.
- **The repository belongs to the `robot-council` GitHub organization**, which enables the `Task`, `Bug`, and `Feature` issue types. It moved from `joshdaugherty/robot-council` on 2026-09-17, and old URLs redirect, so links in earlier issues, pull requests, and the `v0.1.0` release still resolve.
- **The package registers morph aliases for its token owners.** `robot-council-installation` and
  `robot-council-agent-session`, merged into `Relation::morphMap()` at register time. Without them a
  host that calls `Relation::enforceMorphMap()` cannot issue any credential, because `getMorphClass()`
  throws for a model outside the map; and the names are the package's own so that a host adding these
  classes to its map later cannot change what `tokenable_type` holds and orphan live tokens. A test
  asserting `tokenable_type` must use the alias, not the class name.
- **A service provider must not throw.** It runs for every request and every artisan command,
  including the `config:clear` that would fix a mistyped value, so `registerRoutes()` logs a warning
  and falls back to the documented default instead.
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
