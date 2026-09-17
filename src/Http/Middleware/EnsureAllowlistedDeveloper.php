<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Support\HostUsers;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Guards the package's human-facing routes. It re-checks the signed-in developer against the
 * access lists on every request, so removing an ID from configuration locks that developer out on
 * their next request rather than whenever their session happens to end.
 */
final class EnsureAllowlistedDeveloper
{
    /**
     * @param  Allowlist  $allowlist  The configured access lists.
     * @param  HostUsers  $hostUsers  The host application's user records.
     * @param  AuthFactory  $auth  The host application's authentication factory.
     */
    public function __construct(
        private readonly Allowlist $allowlist,
        private readonly HostUsers $hostUsers,
        private readonly AuthFactory $auth
    ) {}

    /**
     * Admit an allowlisted developer, and turn anyone else away.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The pipeline's response, or a redirect to GitHub for a visitor.
     *
     * @throws AccessDeniedHttpException When the signed-in account is on neither access list.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Send a visitor who is not signed in to GitHub, remembering where they were heading
        if ($user === null) {
            return redirect()->guest(route('robot-council.auth.redirect'));
        }

        $githubId = $this->hostUsers->githubId($user);

        // Turn away a developer whose ID configuration no longer lists
        if ($githubId === null || ! $this->allowlist->admits($githubId)) {
            $this->endSession($request);

            throw new AccessDeniedHttpException('This account is not on the robot-council access list.');
        }

        return $next($request);
    }

    /**
     * Sign the user out and replace the session, so nothing of it survives the refusal.
     *
     * @param  Request  $request  The request whose session is ending.
     *
     * @throws RuntimeException When the host application's `web` guard cannot sign a user out.
     */
    private function endSession(Request $request): void
    {
        $guard = $this->auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The `web` guard must be stateful for robot-council to sign a developer out.');
        }

        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
