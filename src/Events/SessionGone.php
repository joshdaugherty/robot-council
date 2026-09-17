<?php

declare(strict_types=1);

namespace RobotCouncil\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RobotCouncil\Models\AgentSession;

/**
 * One agent session has ended, and whatever it held is to be released.
 *
 * Dispatched exactly once per session, by whichever path ended it: the process saying so, an admin
 * revoking it, or the presence sweep finding it silent past the configured threshold. What makes
 * that "exactly once" true is the conditional update behind it -- a session moves to `gone` only
 * from a row that was not already `gone`, so a second end, a retried request, and two sweeps
 * running at once all dispatch nothing.
 *
 * **It carries the session, and it is dispatched after the surrounding transaction commits.** A
 * listener that ran inside the transaction could release a claim that a rollback then un-ended.
 *
 * **Listen to this from a queued listener.** Laravel runs an after-commit callback outside any
 * try/catch and after `PDO::commit()`, so a synchronous listener that throws escapes the
 * transaction that ended the session with the row already durably written -- turning a committed
 * end into a 500 on the request that asked for it.
 */
final class SessionGone implements ShouldDispatchAfterCommit
{
    /**
     * @param  AgentSession  $session  The session that ended, as it stands now.
     * @param  string  $reason  What ended it: `ended`, `revoked`, or `timeout`.
     */
    public function __construct(
        public readonly AgentSession $session,
        public readonly string $reason
    ) {}
}
