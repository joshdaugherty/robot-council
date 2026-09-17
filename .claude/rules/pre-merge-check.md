# Rule — a green check run is not a merge decision

The checks answer one question: **did the jobs pass on what they ran?** Merging asks a different one: **should this land on `main`, in the state it will actually land in?** Those come apart, and nothing in a green run says so. Before merging any pull request, work the nine steps below.

## Why this is a standing order

**Nothing on this repository stands between a branch and `main`.** Measured on 2026-09-17: `GET repos/joshdaugherty/robot-council/branches/main/protection` returns 404 "Branch not protected", and `rulesets` is `[]`. The repository is public, allows merge commits, squash, and rebase, and has `allow_auto_merge=false` and `delete_branch_on_merge=false`. There is no required check, no required review, and no refusal of force-pushes. **The only enforcement is this rule, followed voluntarily.**

**The checks also cover less than a green badge suggests:**

- **`run-tests` is path-filtered** (`**.php`, `phpunit.xml.dist`, `composer.json`, `composer.lock`, its own workflow). A pull request touching nothing else runs no tests, and a missing check is not a passing one.
- **`PHPStan` runs on `push` only.** It analyses the branch tip, never the merged state.
- **`Fix PHP code style issues` fixes instead of failing.** It auto-commits "Fix styling" to the pushed branch, so a style problem never shows red; the remote branch just moves ahead of your local one. Per GitHub's documentation, a push made with a workflow's `GITHUB_TOKEN` starts no new workflow runs, so that commit carries no checks.
- **`run-tests` is `fail-fast: true`.** One failing matrix cell cancels the rest, so read cells individually: a cancelled cell is neither a pass nor a fail.

## The check

Steps 1 to 3 are cheap and can invalidate the expensive ones, so do not start at step 4.

1. **The pull request is ready.** Read `draft` from the API. A draft is not merged, however green it is.

2. **The branch is current with a fresh base.** `git fetch origin`, then sync per [`sync-pr-branch`](sync-pr-branch.md). Work from `origin/<branch>`, not your local copy, because the style workflow may have committed to it since your last push.

3. **Trial-merge for real; do not read the `mergeable` flag instead.** GitHub computes it lazily and returns `null` while computing, which a truthiness check reads as "cannot merge". In `UAMS-Web/uams-statamic` (recorded 2026-09-08), two open, unconflicted pull requests read seconds apart returned `true` and `null`. Instead, in a worktree ([`worktrees`](worktrees.md)) detached at `origin/main`, run `git merge --no-ff origin/<branch>` and `composer install`, then validate **the merged state**.

4. **Run the gate on that merged state.**
   - **Locally:** run `composer test`, `composer analyse`, and `vendor/bin/pint --test`, and read each exit code before any pipe. This local `composer analyse` is the only PHPStan run that sees the merged state.
   - **On the pull request:** read the Actions checks job by job. Note which workflows ran, on which SHA, and with what conclusion. On `pull_request`, `run-tests` tests GitHub's merge commit as of when the run started, so it is stale if `main` has moved since.

5. **Check the acceptance criteria against the diff, one at a time**, not against what the body claims the diff does. Name and explain any criterion that will not be met, per [`closing-a-ticket`](closing-a-ticket.md).

6. **Account for companion work.** If the change needs a matching change elsewhere, such as in a consuming application or another repository, confirm that change exists and state the order the two land in.

7. **Adversarially review what is actually merging.** The [`adversarial-review`](adversarial-review.md) pass saw the branch, not the branch plus everything that has landed since. Read the diff **as it will exist on `main`**, and look for interactions with changes merged since, shared inputs, and conventions the branch now violates. If the base has not moved, say so; the earlier review then covers this step.

8. **Ask GitHub what this merge will close, and do not stop at the field that answers.**

   ```bash
   gh api graphql -f query='{repository(owner:"joshdaugherty",name:"robot-council"){
     pullRequest(number:N){closingIssuesReferences(first:10){nodes{number state title}}}}}'
   ```

   **That field is necessary but not sufficient.** Two code paths decide what closes. The pull-request linkage parses the **body as Markdown**. The push-time scan reads **the commit text that reaches `main` as plain text**, where Markdown constructs do not exist. Measured across `UAMS-Web` repositories (recorded 2026-09-16), each of these closed an issue on merge. A keyword inside a fenced block closed a second, unlisted issue while the field listed only the intended one. A keyword ending one line, with its reference starting the next, closed an issue while the field was empty. A keyword inside a code span closed its issue one second after the merge.

   **With this repository's defaults, the text that reaches `main` is not the body.** A squash commit carries the branch's commit messages (`squash_merge_commit_message=COMMIT_MESSAGES`) under a commit title or the PR title. A merge commit carries the PR title (`merge_commit_message=PR_TITLE`) and brings the branch commits along, and a rebase brings the branch commits. So scan the title and every branch commit message, plus the body if whoever merges pastes it into a squash message. Scan by hand, on joined text:

   ```bash
   { gh pr view N --json title,body --jq '.title, .body'; git log --format=%B origin/main..origin/<branch>; } | tr '\n' ' '
   ```

   Look for a closing keyword (`close`, `closes`, `closed`, `fix`, `fixes`, `fixed`, `resolve`, `resolves`, `resolved`) followed by an issue reference. Treat backticks, fences, and line breaks in between as if they were not there. **Rewording is the only remedy measured to hold.** Put the number first (`#N is not closed by this pull request`), then re-read the field **and** re-scan. Neither instrument covers both paths ([`an-empty-result-is-not-evidence`](an-empty-result-is-not-evidence.md)).

9. **Record the verdict on the pull request, green or red, in a comment.** Give the merged-state SHA, each local command's exit code, each Actions check's conclusion (and which did not run), the criteria status, and what step 8 found. A verdict that exists only in a terminal is not a record.

## The DRY line

This file owns **the decision to merge** and the order of the steps. Bringing a branch current and the inputs its checks read are covered by [`sync-pr-branch`](sync-pr-branch.md). Ship-time obligations are in [`closing-a-ticket`](closing-a-ticket.md), and the skeptical pass itself is in [`adversarial-review`](adversarial-review.md). Where the trial merge happens is [`worktrees`](worktrees.md). What an instrument can and cannot answer is [`an-empty-result-is-not-evidence`](an-empty-result-is-not-evidence.md). Changing branch protection is out of scope.
