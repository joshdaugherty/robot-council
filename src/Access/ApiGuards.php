<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

/**
 * The names of the two Sanctum guards the package registers, and of the authentication providers
 * behind them. Each provider names one model, which is what makes a guard refuse the other's
 * tokens: Sanctum's `Guard::hasValidProvider()` reads `auth.providers.<provider>.model` and
 * requires the token's owner to be an instance of it.
 */
final class ApiGuards
{
    /**
     * The guard that authenticates an installation credential, on the session endpoints.
     */
    public const string INSTALLATION = 'robot-council-installation';

    /**
     * The guard that authenticates an agent session's token, on the agent routes.
     */
    public const string AGENT = 'robot-council-agent';

    /**
     * The authentication provider whose model is the installation.
     */
    public const string INSTALLATION_PROVIDER = 'robot-council-installations';

    /**
     * The authentication provider whose model is the agent session.
     */
    public const string AGENT_PROVIDER = 'robot-council-agent-sessions';
}
