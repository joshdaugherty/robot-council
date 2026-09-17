<?php

declare(strict_types=1);

use Laravel\Socialite\Two\User as GitHubAccount;
use RobotCouncil\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Build the account Socialite would return for a GitHub user, for `Socialite::fake()`.
 *
 * @param  int  $id  The account's numeric GitHub user ID.
 * @param  string|null  $login  The account's login, which Socialite maps to its nickname.
 * @param  string|null  $name  The account's display name, absent on many accounts.
 * @param  string|null  $email  The verified primary email, absent when the account exposes none.
 * @return GitHubAccount The account a faked provider hands the callback.
 */
function githubAccount(
    int $id,
    ?string $login = 'octodev',
    ?string $name = 'Octo Dev',
    ?string $email = 'octo@example.com'
): GitHubAccount {
    $account = new GitHubAccount;

    $account->map([
        'id' => (string) $id,
        'nickname' => $login,
        'name' => $name,
        'email' => $email,
        'avatar' => sprintf('https://avatars.example.com/u/%d', $id),
    ]);

    return $account;
}
