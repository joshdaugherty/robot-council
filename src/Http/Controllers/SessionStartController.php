<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Credentials;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the session one agent process runs under. Reached with the installation credential, which
 * is the only thing that credential can do.
 */
final class SessionStartController
{
    /**
     * Create a session and issue its first token.
     *
     * @param  Request  $request  The incoming request.
     * @param  AgentSessions  $sessions  The session store.
     * @param  Credentials  $credentials  The configured lifetimes.
     * @return JsonResponse The session's ID and its token.
     */
    public function __invoke(Request $request, AgentSessions $sessions, Credentials $credentials): JsonResponse
    {
        $installation = Principal::installation($request);

        $request->validate([
            'project_id' => ['nullable', 'string', 'max:255'],
        ]);

        $projectId = $request->filled('project_id') ? $request->string('project_id')->value() : null;

        $issued = $sessions->start($installation, $projectId);

        return new JsonResponse([
            'session_id' => $issued->owner->getKey(),
            'token' => $issued->plainTextToken,
            // What the token actually carries, not what the installation looked like when the
            // request arrived: an admin may have narrowed it in between
            'abilities' => $issued->abilities,

            // A duration rather than an instant, so a helper on a machine whose clock is off still
            // renews in time
            'expires_in' => $credentials->sessionTtlMinutes() * 60,
        ], Response::HTTP_CREATED);
    }
}
