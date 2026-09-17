<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records what an agent says about its own work.
 *
 * The type is never taken from the request. Narration and a state change are different kinds of
 * claim -- one is an agent's account of itself, the other is something the service observed -- and
 * an agent that could choose between them could dress its own opinion as fleet state.
 */
final class PostNarrationController
{
    /**
     * The longest narration body the feed accepts.
     */
    private const int MAX_BODY = 4000;

    /**
     * Record one piece of narration.
     *
     * @param  Request  $request  The incoming request.
     * @param  FleetEvents  $events  The change feed.
     * @return JsonResponse The recorded event's ID and type.
     */
    public function __invoke(Request $request, FleetEvents $events): JsonResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'meta' => ['sometimes', 'array', new BoundedMeta],
        ]);

        $session = Principal::agentSession($request);

        $meta = $request->input('meta');

        $event = $events->record(
            // Always narration, whatever a `type` field in the request said
            FleetEventType::Narration,
            $session,
            $request->string('body')->value(),

            // Kept apart under `client`, so nothing a caller sends can be mistaken later for
            // something the server derived
            \is_array($meta) && $meta !== [] ? ['client' => $meta] : [],

            // Read from the token as it posts, and stored on the event. Revoking the ability later
            // must not hide what was said while it was held.
            Tokens::allows($session->currentAccessToken(), Ability::CoordinatorDirect)
        );

        return new JsonResponse([
            'event_id' => $event->id,
            'type' => $event->type->value,
        ], Response::HTTP_CREATED);
    }
}
