<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * The states one agent process's session moves through. A session that has gone is final: its
 * tokens are refused and it is never renewed, so the process starts a new session instead.
 *
 * Only `gone` is terminal. A session that stopped answering long enough to be marked stale is
 * back to active on its next request, because a harness that was busy, suspended, or on a flaky
 * connection is indistinguishable from one that stopped -- until enough time passes that the
 * difference no longer matters.
 */
enum AgentSessionStatus: string
{
    /**
     * The process is running and its token renews.
     */
    case Active = 'active';

    /**
     * Nothing has been heard from the process for a while. Its claims and locks are still its own,
     * and one request brings it back.
     */
    case Stale = 'stale';

    /**
     * The process has ended, or the session was revoked. Its claims and locks are released.
     */
    case Gone = 'gone';
}
