<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\Credentials;

/**
 * What a process with nothing to say sends so the fleet knows it is still there.
 *
 * The contact itself is recorded by `EnsureAgentSession`, which is what makes presence honest: a
 * session that reads the feed, posts narration, or claims a task is as present as one that
 * heartbeats, and a harness that does its work without ever calling this is never marked gone for
 * it. This endpoint exists for the process that has gone quiet for a legitimate reason -- a long
 * build, a human thinking -- and for nothing else.
 *
 * It answers with the thresholds as durations rather than with a deadline, so a bridge on a machine
 * whose clock is wrong still picks a cadence that works, and so a host that changes the thresholds
 * does not have to tell anybody.
 */
final class AgentHeartbeatController
{
    /**
     * Record contact, and say how long the silence may last.
     *
     * @param  Request  $request  The incoming request.
     * @param  Credentials  $credentials  The configured thresholds.
     * @return JsonResponse The session's presence, and the two thresholds in seconds.
     */
    public function __invoke(Request $request, Credentials $credentials): JsonResponse
    {
        $session = Principal::agentSession($request);

        return new JsonResponse([
            'session_id' => $session->getKey(),
            'status' => $session->status->value,

            // Measured from the contact this request just made, not from when it was issued
            'stale_in' => $credentials->staleAfterMinutes() * 60,
            'gone_in' => $credentials->goneAfterMinutes() * 60,
        ]);
    }
}
