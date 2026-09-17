<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Requires the authenticated agent session's token to carry one named ability.
 *
 * It runs after `EnsureAgentSession`, which is what establishes that there is a session and that
 * its token is a stored one rather than the transient token a browser session carries. This asks
 * only the next question: may this token do this.
 */
final class RequireAbility
{
    /**
     * Admit a session whose token carries the ability, and turn away one that does not.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @param  string  $ability  The ability the route needs.
     * @return Response The pipeline's response.
     *
     * @throws RuntimeException When the route named an ability that does not exist.
     * @throws AccessDeniedHttpException When the session's token does not carry it.
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $required = Ability::tryFrom($ability);

        // A typo in a route definition is a bug in this package, not a refusal to report to a
        // caller: answering 403 would make an unreachable route look like a permissions problem
        if ($required === null) {
            throw new RuntimeException(sprintf('`%s` is not an ability robot-council defines.', $ability));
        }

        $session = Principal::agentSession($request);

        if (! Tokens::allows($session->currentAccessToken(), $required)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
