---
name: writing-release-notes
description: >-
  GitHub Release conventions for the `joshdaugherty/robot-council` Composer package: the
  em-dash release title (`vX.Y.Z — Theme`), a one-sentence milestone lead with an optional
  `**Breaking change**` callout, and a CLOSED, ordered heading vocabulary
  (`## Breaking changes`, `## What's new`, `## What's fixed`, `## Security`,
  `## Maintenance and tooling`) with one bullet per change formatted as
  `- <PR title> [#N](…/pull/N)` — using the API PR title (never a merge or squash commit
  subject), no `by @author`, inline code preserved. Covers the routing cascade and the bundled
  generator, semantic versioning for a library consumers resolve by tag (below 1.0 the
  minor is the Composer caret's breaking boundary), how the `Update Changelog` workflow turns a
  release into a `CHANGELOG.md` entry, and the retroactive-tag footer. Activate whenever drafting,
  rewriting, or critiquing a GitHub Release title or body, generating release notes, or cutting
  a tag for this repo.
---

# Writing Release Notes

House style for GitHub Releases in `joshdaugherty/robot-council`. It applies to the **whole release
range** — retroactive tags and new ones alike — so the releases page and `CHANGELOG.md` read as one
consistent changelog. The audience is someone deciding whether to take this version of the package;
lead with the theme, then bucket the changes.

## Versioning — what the number promises

This repo is a **Composer library**: applications require `joshdaugherty/robot-council` and Composer
resolves the constraint against its **git tags**. The tag is therefore a compatibility promise, not a
milestone marker. Use **semantic versioning**: `MAJOR.MINOR.PATCH`, where a patch never breaks a
consumer, a minor adds without breaking, and a major is the breaking boundary.

**Below 1.0 the breaking boundary moves to the minor**, because that is how Composer's caret reads it:
`^0.3` means `>=0.3.0 <0.4.0` (and `^0.3.2` means `>=0.3.2 <0.4.0`), while `^1.2` means
`>=1.2.0 <2.0.0`. So on `0.x`, a breaking change bumps the **minor**, and a patch must not break
anyone already on that `^0.x` line; from `1.0.0` on, a breaking change bumps the **major**. When to
cut a release, and when `1.0.0` happens, are not decided here.

## Title

`vX.Y.Z — <Theme>` — an **em dash** (`—`) with a space either side, never a hyphen, then a
concise Title-Case theme (no trailing period). Examples: `v0.2.0 — Council Configuration`,
`v0.3.0 — Config Publishing, Facade Helpers, and Migrations`.

The title is not cosmetic: the `Update Changelog` workflow uses the release **name** as the version
heading in `CHANGELOG.md` (see *Creating / editing releases* below).

## Body structure (in order)

1. **Milestone lead** — one sentence naming the release's theme.
2. **Breaking-change callout** (only when the release breaks something) — its **own paragraph**
   immediately after the lead, never appended to the lede sentence:
   `**Breaking change** — <impact and required action>.` State what a consumer must *do* on
   upgrade (republish or edit the config, run or adjust a migration, change a call site, update a
   published view), not the semver mechanics. The itemized detail with PR links goes in the
   `## Breaking changes` section below.
3. **Buckets** — a **CLOSED** set of `##` headings, each included **only when it has items**,
   always in this order:
   - `## Breaking changes` — anything a consumer must act on to upgrade: a removed or changed
     public class, method, or facade signature in `src/`; a renamed or removed config key; a
     migration or factory change; a published view change. Name the impact, not just the change.
   - `## What's new` — new features, commands, config options, facade methods, migrations, views,
     and integrations.
   - `## What's fixed` — bug fixes, regressions, correctness and performance fixes.
   - `## Security` — vulnerability fixes and hardening (XSS, SSRF, CSP/security headers, auth,
     egress, injection, sanitization).
   - `## Maintenance and tooling` — docs, CI, tests, refactors, dependency bumps, chores, and
     developer-experience / skills work.
4. **Footer** — retroactively-tagged releases only: `_Retroactively tagged at \`<sha>\` (<date>)._`
   Real-time releases omit it.

Do **not** invent headings outside this closed set. If something doesn't obviously fit, it is
*New*, *Fixed*, or *Maintenance and tooling* — decide by dominant intent.

## Line format

- One bullet per change: `- <PR title> [#N](https://github.com/joshdaugherty/robot-council/pull/N)`.
- **Use the PR title from the GitHub API** (`gh pr view N --json title`), **never the commit
  subject on `main`.** A merge commit's subject is a branch slug (`Merge pull request #N from
  joshdaugherty/<branch>`), and this repo's squash setting (`COMMIT_OR_PR_TITLE`) takes the
  *commit's* title when a PR has a single commit, so neither is reliably the PR title. For a change
  on `main` with no PR number in its subject — a direct commit, or each commit of a rebase merge —
  use the commit subject (strip any Conventional-Commit prefix and `[skip ci]` litter) and link the
  **short commit SHA**:
  `` - <title> [`a1b2c3d`](https://github.com/joshdaugherty/robot-council/commit/<sha>) ``.
  Every bullet is linked — `[#N]` for a PR, a backticked short SHA for a direct commit.
- **No `by @author`.** On a single-maintainer repository attribution is noise. GitHub's
  auto-generated notes add it, which is one reason not to use them.
- Preserve inline code in titles (class names, config keys, paths, package names).
- Don't restate the lead inside a bucket; don't add an `H1`.

## Prose style (titles, leads, and bullets)

- **Never use an ampersand (`&`)** — write "and". This applies to release titles, the lead, and
  every bullet.
- **Use the Oxford comma** — `config, migrations, and a facade helper`, not
  `config, migrations and a facade helper`.

## Routing (which bucket) — by title, closing-issue label, and diff shape

A change routes on its resolved title, the labels of the issue its PR closes (read from that issue,
not from the PR), and which paths its diff touches. A cascade, first match wins — the order is
what makes it correct:

1. **Breaking changes** — editorial call, per the bucket definition above; the generator cannot
   infer it, so it is passed in by flag.
2. **Security** — a `security` label, or a title mentioning `XSS`, `SSRF`, `CSP`, `HSTS`, `XXE`,
   `ReDoS`, egress, nonce, impersonation, sanitize, SSL verification, security header,
   `X-Powered-By`, password protection, internal-network, or "escape" of a script/HTML/JSON-LD sink.
3. **Maintenance and tooling** — a `documentation` (or `build`) label on the closing issue. Checked
   before the fix verbs, because a maintenance title can open with `Correct` or `Stop`.
4. **What's new** — the diff touches a published surface (`config/`, `database/`, `resources/`,
   `routes/`), whatever its title says. `src/` is deliberately not on that list: a change there
   routes on its title and on how test-heavy the diff is.
5. **What's fixed** — the title opens with `Fix`/`Resolve`/`Repair`/`Prevent`/`Guard`/`Restore`/
   `Correct`/`Harden`/`Stop`/`Avoid`.
6. **Maintenance and tooling** — the diff is confined to tooling (`.github/`, `.claude/`, `tests/`,
   `workbench/`, `composer.json`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`,
   top-level dotfiles, `CHANGELOG.md`, `README.md`, `LICENSE.md`); or it adds more lines under
   `tests/` than elsewhere; or the title opens with a maintenance verb (`Refactor`, `Bump`,
   `Document`, …) or names tests, coverage, mutation, a skill, or a worktree.
7. **What's new** — everything else.

## Generating the body — [`gen_release_notes.py`](gen_release_notes.py)

Don't hand-assemble the buckets — run the bundled generator. It reads first-parent git history
for a ref range, pulls each PR's title **live from the GitHub API** (`gh`), and applies every
rule above: prefix/`[skip ci]`/merge-hint stripping, acronym casing, the routing cascade, `&`→and
with the Oxford comma, `[#N]` PR links, and backticked-short-SHA links for direct commits. It skips
the workflow auto-commits `Fix styling` and `Update CHANGELOG`. It depends only on `git`, `gh`, and
Python 3 (3.9 or later) — no other setup.

The **editorial** parts it can't infer are passed as flags: the one-sentence `--lead`, the
`--breaking` callout impact, and any `--breaking-item` bullets (`--exclude` a PR itemized there
so it doesn't also auto-list in a bucket).

```
python3 .claude/skills/writing-release-notes/gen_release_notes.py <prev-tag> <new-tag> \
    --lead "One-sentence milestone theme." \
    --breaking "republish the config file and rename \`seats\` to \`members\`." \
    --breaking-item "Rename the \`seats\` config key to \`members\` [#12](https://github.com/joshdaugherty/robot-council/pull/12)." \
    --exclude 12 \
    > body.md
```

Then review `body.md` and create/edit the release with it (below). `--help` lists all flags;
`<prev-tag>` may be `-` for the repo root (first release). The routing is a heuristic: read every
bucket before publishing and move a bullet the cascade misfiled.

## Creating / editing releases with `gh`

Write the body to a file and pass `--notes-file` (never inline `--notes` — the bodies are dense
with backticks, `#`, and `—` that the shell mangles).

```
gh release create vX.Y.Z --title 'vX.Y.Z — <Theme>' --notes-file notes.md --verify-tag
gh release edit   vX.Y.Z --title 'vX.Y.Z — <Theme>' --notes-file notes.md
```

**What the `Update Changelog` workflow does with it.** It runs on the `release` event with type
`released` only. It adds the release as the newest entry in `CHANGELOG.md`, using the release
**name** as the version heading and the release **body** as its content, then commits
`Update CHANGELOG` directly to `main`. Three consequences follow:

- **A prerelease (`--prerelease`) does not update `CHANGELOG.md`.** Changing a prerelease to a full
  release later does fire `released`, and the entry is written then.
- **Get the title and body right before the release goes out.** Editing a published release's title
  or notes fires `edited`, not `released`, so `CHANGELOG.md` keeps what the release said at the time.
- **`main` moves on GitHub after a release**, so a local `main` is a commit behind until pulled.

Retroactive tags: create an **annotated** tag stamped with the target commit's date so
`git tag --sort=creatordate` orders correctly —
`GIT_COMMITTER_DATE="$(git log -1 --format=%cI <sha>)" git tag -a vX.Y.Z <sha> -m '…'`. GitHub's
`published_at` is still the publish time (not backdatable via any API); the API's `created_at`
already reflects the tagged commit's date.

## The DRY line

This is the standing statement of Release conventions. It composes with
[`writing-commits`](../writing-commits/SKILL.md) and
[`writing-pull-requests`](../writing-pull-requests/SKILL.md) (the PR titles this skill renders
come from those). The workflow's own behavior lives in `.github/workflows/update-changelog.yml`, and
the `gh` flags live in that tool; don't restate them.
