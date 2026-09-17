<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Factory;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\Guard;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Support\HostUsers;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Finishes GitHub's OAuth flow: refuses accounts the access lists do not name, then records the
 * account's identity and signs the developer in on the configured guard.
 */
final class GitHubCallbackController
{
    /**
     * Enroll the GitHub account that just authorized the application, and sign it in.
     *
     * @param  Request  $request  The callback request GitHub sent the visitor back with.
     * @param  Factory  $socialite  Socialite's provider factory.
     * @param  Allowlist  $allowlist  The configured access lists.
     * @param  HostUsers  $hostUsers  The developer's host user row and GitHub identity.
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Guard  $guard  The configured guard's name.
     * @return RedirectResponse A redirect to wherever the developer was heading.
     *
     * @throws AccessDeniedHttpException When the GitHub account is on neither access list.
     * @throws ConflictHttpException When another user holds the account's email address, or the
     *                               user row behind a known identity is gone.
     * @throws RuntimeException When the host application's user model or guard cannot sign in.
     */
    public function __invoke(
        Request $request,
        Factory $socialite,
        Allowlist $allowlist,
        HostUsers $hostUsers,
        AuthFactory $auth,
        Guard $guard
    ): RedirectResponse {
        // Read the GitHub account that authorized the application; Socialite validates the state
        $account = $socialite->driver('github')->user();
        $githubId = (int) $account->getId();

        // Refuse an unlisted account before reading or writing any row
        if (! $allowlist->admits($githubId)) {
            Log::warning('robot-council refused a GitHub account on neither access list.', [
                'github_id' => $githubId,
            ]);

            throw new AccessDeniedHttpException;
        }

        $login = $account->getNickname() ?? (string) $githubId;
        $identity = $hostUsers->findIdentity($githubId);

        // Sign a returning developer into the user their identity points at
        if (! $identity instanceof GithubIdentity) {
            $user = $this->enroll($hostUsers, $githubId, $login, $account->getEmail());
        } else {
            $user = $hostUsers->findUserFor($identity);

            if (! $user instanceof Model) {
                Log::warning('robot-council found no user row behind a known GitHub identity.', [
                    'github_id' => $githubId,
                ]);

                throw new ConflictHttpException;
            }
        }

        // Refresh what GitHub can change between sign-ins, on the package's own table
        $hostUsers->recordIdentity($user, $githubId, $login, $account->getAvatar());

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException("The host application's user model must implement Authenticatable.");
        }

        // Sign the developer in without a remember-me cookie, then start a fresh session
        $sessionGuard = $auth->guard($guard->name());

        if (! $sessionGuard instanceof StatefulGuard) {
            throw new RuntimeException(sprintf('The `%s` guard must be stateful for robot-council sign-in.', $guard->name()));
        }

        $sessionGuard->login($user);

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * Create the host user for a developer signing in for the first time.
     *
     * @param  HostUsers  $hostUsers  The developer's host user row and GitHub identity.
     * @param  int  $githubId  The account's numeric GitHub user ID.
     * @param  string  $login  The account's login, used as the display name.
     * @param  string|null  $email  The account's verified primary email, when it exposes one.
     * @return Model The saved user.
     *
     * @throws ConflictHttpException When another user already holds that email address.
     */
    private function enroll(HostUsers $hostUsers, int $githubId, string $login, ?string $email): Model
    {
        // Never claim an existing account by email: only a recorded identity says who someone is
        if ($email !== null && $hostUsers->findByEmail($email) instanceof Model) {
            Log::warning('robot-council refused a GitHub account whose email another user holds.', [
                'github_id' => $githubId,
            ]);

            throw new ConflictHttpException;
        }

        try {
            return $hostUsers->create([
                'name' => $login,
                'email' => $email,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two callbacks raced: whichever lost reads what the winner wrote
            $identity = $hostUsers->findIdentity($githubId);
            $user = $identity instanceof GithubIdentity ? $hostUsers->findUserFor($identity) : null;

            if (! $user instanceof Model) {
                throw new ConflictHttpException;
            }

            return $user;
        }
    }
}
