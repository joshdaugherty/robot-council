<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Support\SessionPresence;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ends one of an installation's sessions, so what the process held is released at the moment its
 * harness exits rather than after the gone threshold has passed.
 *
 * Reached with the installation credential rather than the session's own token, because the session
 * whose token it is may be the thing that has just died. The bridge holds the installation
 * credential for exactly this: it outlives any one process.
 *
 * **Ending is idempotent, and answers 200 either way.** A bridge that sends this on the way out has
 * no good response to a 409 -- it is already exiting -- and the honest statement is that the session
 * is gone, which is true whether this request or the sweep got there first. Renewal is the opposite
 * case and does answer 409, because the caller there is asking for something it cannot have.
 */
final class SessionEndController
{
    /**
     * End the session.
     *
     * @param  Request  $request  The incoming request.
     * @param  string  $session  The session's ID, from the route.
     * @param  SessionPresence  $presence  The presence store.
     * @return JsonResponse The session, once it has gone.
     *
     * @throws NotFoundHttpException When no such session exists.
     * @throws AccessDeniedHttpException When the session belongs to another installation.
     */
    public function __invoke(Request $request, string $session, SessionPresence $presence): JsonResponse
    {
        $installation = Principal::installation($request);

        $record = AgentSession::query()->whereKey($session)->first();

        if (! $record instanceof AgentSession) {
            throw new NotFoundHttpException;
        }

        // An installation ends only what it started. Checked before anything is written, so a
        // credential probing for other installations' session IDs learns nothing from the timing
        // and changes nothing by asking.
        if ($record->installation_id !== $installation->getKey()) {
            throw new AccessDeniedHttpException;
        }

        $presence->end($record);

        return new JsonResponse([
            'session_id' => $record->getKey(),
            'status' => $record->status->value,
        ], Response::HTTP_OK);
    }
}
