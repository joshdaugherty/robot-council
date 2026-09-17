<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues an instruction to the whole fleet.
 *
 * A directive reaches every agent regardless of whose developer posted it, which is exactly why it
 * needs `coordinator:direct` and why that ability is the one thing enrollment can never request.
 * The route's middleware is what enforces it.
 */
final class PostDirectiveController
{
    /**
     * The longest directive body the feed accepts.
     */
    private const int MAX_BODY = 4000;

    /**
     * Record one directive.
     *
     * @param  Request  $request  The incoming request.
     * @param  FleetEvents  $events  The change feed.
     * @return JsonResponse The recorded event's ID and type.
     */
    public function __invoke(Request $request, FleetEvents $events): JsonResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'meta' => ['sometimes', 'array'],
        ]);

        $meta = $request->input('meta');

        $event = $events->record(
            FleetEventType::Directive,
            Principal::agentSession($request),
            $request->string('body')->value(),
            \is_array($meta) && $meta !== [] ? ['client' => $meta] : [],

            // True by construction: the route admits nobody without the ability
            withCoordinator: true
        );

        return new JsonResponse([
            'event_id' => $event->id,
            'type' => $event->type->value,
        ], Response::HTTP_CREATED);
    }
}
