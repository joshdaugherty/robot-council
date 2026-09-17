<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * The states one agent process's session moves through. A session that has gone is final: its
 * tokens are refused and it is never renewed, so the process starts a new session instead.
 */
enum AgentSessionStatus: string
{
    /**
     * The process is running and its token renews.
     */
    case Active = 'active';

    /**
     * The process has ended, or the session was revoked. Its claims and locks are released.
     */
    case Gone = 'gone';
}
