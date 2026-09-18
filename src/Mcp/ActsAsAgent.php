<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Response;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;

/**
 * What every one of this package's MCP tools shares: the session it is acting as, the abilities
 * that session holds, and the shape of a refusal.
 *
 * A trait rather than a base class, because Pest's `strict()` preset requires every class in the
 * package's namespaces to be final, and a shared parent cannot be. The same reason the console
 * commands share `Console\Concerns\ManagesInstallations`.
 *
 * **A tool is the same action as its REST endpoint, not a second implementation of it.** Each one
 * calls into the same store, so a rule enforced in a conditional update is enforced identically
 * here, and a slice that changes the rule changes both surfaces at once. What a tool adds is the
 * description an agent reads and the argument schema it is held to.
 *
 * **An ability refused is a tool error, not a result.** An MCP client cannot tell a result that
 * happens to describe a failure from one that describes success -- the model reads both as the
 * tool's output -- so anything the service refused has to come back marked as an error.
 */
trait ActsAsAgent
{
    /**
     * The session this call authenticated as.
     *
     * Read from the request attribute the agent middleware set, not from the guard, for the reason
     * `Http\Principal` exists: one place decides what a request is allowed to be, and a route wired
     * up without that middleware fails loudly rather than running unauthenticated.
     *
     * @param  HttpRequest  $request  The HTTP request the MCP call arrived on.
     * @return AgentSession The authenticated session.
     */
    public function session(HttpRequest $request): AgentSession
    {
        return Principal::agentSession($request);
    }

    /**
     * Whether the calling session's token carries an ability.
     *
     * @param  HttpRequest  $request  The HTTP request the MCP call arrived on.
     * @param  Ability  $ability  The ability to check.
     * @return bool True when the token holds it.
     */
    public function allows(HttpRequest $request, Ability $ability): bool
    {
        return Tokens::allows($this->session($request)->currentAccessToken(), $ability);
    }

    /**
     * Refuse a call the session's token does not carry the ability for.
     *
     * @param  Ability  $ability  The ability that was missing.
     * @return Response The refusal.
     */
    public function refuse(Ability $ability): Response
    {
        return Response::error(sprintf(
            'This session does not hold `%s`. An admin grants abilities on the installation.',
            $ability->value
        ));
    }
}
