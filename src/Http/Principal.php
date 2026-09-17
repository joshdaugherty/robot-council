<?php

declare(strict_types=1);

namespace RobotCouncil\Http;

use Illuminate\Http\Request;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RuntimeException;

/**
 * Where the principal middleware leaves what it authenticated, and how a controller reads it back.
 *
 * A controller takes the principal from here rather than asking the guard again, so there is one
 * place that decides what a request is allowed to be, and a route wired up without its middleware
 * fails loudly instead of running unauthenticated.
 */
final class Principal
{
    /**
     * The request attribute holding the authenticated installation.
     */
    public const string INSTALLATION = 'robot-council.installation';

    /**
     * The request attribute holding the authenticated agent session.
     */
    public const string AGENT_SESSION = 'robot-council.agent-session';

    /**
     * The installation this request authenticated as.
     *
     * @param  Request  $request  The request the middleware has already checked.
     * @return Installation The authenticated installation.
     *
     * @throws RuntimeException When the route did not run the installation middleware.
     */
    public static function installation(Request $request): Installation
    {
        $principal = $request->attributes->get(self::INSTALLATION);

        if (! $principal instanceof Installation) {
            throw new RuntimeException("This route needs robot-council's installation middleware.");
        }

        return $principal;
    }

    /**
     * The agent session this request authenticated as.
     *
     * @param  Request  $request  The request the middleware has already checked.
     * @return AgentSession The authenticated session.
     *
     * @throws RuntimeException When the route did not run the agent-session middleware.
     */
    public static function agentSession(Request $request): AgentSession
    {
        $principal = $request->attributes->get(self::AGENT_SESSION);

        if (! $principal instanceof AgentSession) {
            throw new RuntimeException("This route needs robot-council's agent-session middleware.");
        }

        return $principal;
    }
}
