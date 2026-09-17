<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Factory;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Support\HostUsers;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Finishes GitHub's OAuth flow: refuses accounts the allowlist does not name, then enrolls the
 * account and signs the developer in on the host application's `web` guard.
 */
final class GitHubCallbackController
{
    /**
     * Enroll the GitHub account that just authorized the application, and sign it in.
     *
     * @param  Request  $request  The callback request GitHub sent the visitor back with.
     * @param  Factory  $socialite  Socialite's provider factory.
     * @param  Allowlist  $allowlist  The configured access lists.
     * @param  HostUsers  $hostUsers  The host application's user records.
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @return RedirectResponse A redirect to wherever the developer was heading.
     *
     * @throws AccessDeniedHttpException When the GitHub account is on neither access list.
     * @throws ConflictHttpException When an existing user already holds the account's email.
     * @throws RuntimeException When the host application's user model or `web` guard cannot sign in.
     */
    public function __invoke(
        Request $request,
        Factory $socialite,
        Allowlist $allowlist,
        HostUsers $hostUsers,
        AuthFactory $auth
    ): RedirectResponse {
        // Read the GitHub account that authorized the application; Socialite validates the state
        $account = $socialite->driver('github')->user();
        $githubId = (int) $account->getId();

        // Refuse an unlisted account before reading or writing any user row
        if (! $allowlist->admits($githubId)) {
            throw new AccessDeniedHttpException('This GitHub account is not on the robot-council access list.');
        }

        $login = $account->getNickname() ?? (string) $githubId;
        $email = $account->getEmail();
        $user = $hostUsers->findByGithubId($githubId);

        // Never claim an existing account by email: only a GitHub ID establishes who someone is
        if (! $user instanceof Model && $email !== null && $hostUsers->findByEmail($email) instanceof Authenticatable) {
            throw new ConflictHttpException('Another account already uses this email address.');
        }

        // Refresh the details GitHub can change between sign-ins
        $attributes = [
            'github_id' => $githubId,
            'github_login' => $login,
            'avatar_url' => $account->getAvatar(),
            'name' => $account->getName() ?? $login,
        ];

        if (! $user instanceof Model) {
            // Record the email only when enrolling, so a later change cannot collide
            $attributes['email'] = $email;

            $user = $hostUsers->create($attributes);
        } else {
            $user->forceFill($attributes)->save();
        }

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException("The host application's user model must implement Authenticatable.");
        }

        // Sign the developer in without a remember-me cookie, then start a fresh session
        $guard = $auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The `web` guard must be stateful for robot-council sign-in.');
        }

        $guard->login($user);

        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
