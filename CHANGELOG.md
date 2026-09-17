# Changelog

All notable changes to `robot-council` will be documented in this file.

## v0.1.0 — Composer Package Shell (2026-09-17)

A pre-release shell of the Composer package: it requires PHP 8.4 or later and Laravel 13.23 or later, registers its service provider, and has no features yet.

### What's new
- Scaffold Laravel package from Spatie skeleton [`40ea724`](https://github.com/joshdaugherty/robot-council/commit/40ea724ee3061df309c29279ad338e525e5484e6)

### Maintenance and tooling
- Fix release-notes routing of CLAUDE.md edits and PR title recasing [#12](https://github.com/joshdaugherty/robot-council/pull/12)
- Remove unused skeleton leftovers and dev dependencies [#9](https://github.com/joshdaugherty/robot-council/pull/9)
- Adopt Pest's php, security, and strict arch presets and its Rector rules [#8](https://github.com/joshdaugherty/robot-council/pull/8)
- Run PHPStan and Rector against tests and rector.php [#7](https://github.com/joshdaugherty/robot-council/pull/7)
- Raise dependency floors to their latest stable releases [#6](https://github.com/joshdaugherty/robot-council/pull/6)
- Raise PHPStan to level max with bleeding edge [#5](https://github.com/joshdaugherty/robot-council/pull/5)
- Add Rector with the Laravel rule sets to the required check [#4](https://github.com/joshdaugherty/robot-council/pull/4)
- Upgrade to Pest 5 and PHPUnit 13, and require Laravel 13 [#3](https://github.com/joshdaugherty/robot-council/pull/3)
- Require one CI check on every pull request and cut releases through a changelog PR [#2](https://github.com/joshdaugherty/robot-council/pull/2)
- Add Claude Code conventions; drop PHP 8.3 and Laravel 11 support [#1](https://github.com/joshdaugherty/robot-council/pull/1)
