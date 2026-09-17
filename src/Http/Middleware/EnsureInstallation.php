<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\ApiGuards;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\HostUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Guards the session endpoints, which only an installation credential may reach.
 *
 * Nothing here is inferred from the fact that a guard returned somebody. Sanctum's guard tries the
 * `web` guard before it looks at the bearer token, so a signed-in human who opens one of these URLs
 * in a browser authenticates as that human -- and, when the host's user model uses `HasApiTokens`,
 * carries a `TransientToken` whose `can()` answers true to every ability. The principal's type and
 * its token's type are therefore both checked, and so are the installation's standing and the
 * developer's place on the access lists, on every request.
 */
final class EnsureInstallation
{
    /**
     * @param  AuthFactory  $auth  The host application's authentication factory.
     * @param  Allowlist  $allowlist  The configured access lists.
     * @param  HostUsers  $hostUsers  The developer's GitHub identity.
     */
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Allowlist $allowlist,
        private readonly HostUsers $hostUsers
    ) {}

    /**
     * Admit a live installation credential, and turn everything else away.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The pipeline's response.
     *
     * @throws UnauthorizedHttpException When the credential is missing, of the wrong kind, or spent.
     * @throws AccessDeniedHttpException When the developer is on neither access list.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $installation = $this->auth->guard(ApiGuards::INSTALLATION)->user();

        if (! $installation instanceof Installation) {
            throw new UnauthorizedHttpException('Bearer');
        }

        // A real, stored token carrying the one ability an installation credential has, never
        // the transient token a browser session carries, whose `can()` answers true to everything
        if (! Tokens::allows($installation->currentAccessToken(), Ability::SessionsStart)) {
            throw new UnauthorizedHttpException('Bearer');
        }

        // Re-read rather than trusted from the token, so revoking an installation or shortening
        // the configured maximum age takes effect on the next request
        if (! $installation->isUsable()) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $githubId = $this->hostUsers->githubIdForKey($installation->user_id);

        if ($githubId === null || ! $this->allowlist->admits($githubId)) {
            throw new AccessDeniedHttpException;
        }

        $request->attributes->set(Principal::INSTALLATION, $installation);

        return $next($request);
    }
}
