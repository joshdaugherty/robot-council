<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\ApiGuards;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\HostUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Guards the agent routes, which only a live agent session's token may reach.
 *
 * The checks mirror `EnsureInstallation`, for the same reason: Sanctum's `web` fallback means a
 * guard returning somebody says nothing about what that somebody is. A session also inherits its
 * installation's standing, so revoking the installation stops every process running under it
 * without touching the sessions themselves.
 */
final class EnsureAgentSession
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
     * Admit a live agent session, and turn everything else away.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The pipeline's response.
     *
     * @throws UnauthorizedHttpException When the token is missing, of the wrong kind, or spent.
     * @throws AccessDeniedHttpException When the developer is on neither access list.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->auth->guard(ApiGuards::AGENT)->user();

        if (! $session instanceof AgentSession) {
            throw new UnauthorizedHttpException('Bearer');
        }

        if (! Tokens::isStored($session->currentAccessToken())) {
            throw new UnauthorizedHttpException('Bearer');
        }

        // A session that has gone is final: its claims and locks were released, so nothing it
        // sends afterwards can be acted on
        if ($session->hasGone()) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $installation = $session->installation()->first();

        if (! $installation instanceof Installation || ! $installation->isUsable()) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $githubId = $this->hostUsers->githubIdForKey($session->user_id);

        if ($githubId === null || ! $this->allowlist->admits($githubId)) {
            throw new AccessDeniedHttpException;
        }

        $request->attributes->set(Principal::AGENT_SESSION, $session);

        return $next($request);
    }
}
