<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RobotCouncil\Http\Principal;
use RobotCouncil\Support\FleetFeed;

/**
 * Serves the change feed to one agent session, from an ID cursor.
 *
 * Paging by ID rather than by time is what makes this safe to read from a process that may sleep,
 * crash, and come back: the cursor is exact, and the recording helper guarantees events commit in
 * the order their IDs imply, so nothing can appear behind a cursor already passed.
 */
final class FleetFeedController
{
    /**
     * Read the events after a cursor.
     *
     * @param  Request  $request  The incoming request.
     * @param  FleetFeed  $feed  The feed reader, which applies the visibility rule.
     * @return JsonResponse The events, and the cursor to ask from next.
     */
    public function __invoke(Request $request, FleetFeed $feed): JsonResponse
    {
        $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.FleetFeed::MAX_PAGE],
        ]);

        $after = $request->integer('after');
        $limit = $request->integer('limit', FleetFeed::MAX_PAGE);

        $events = $feed->after(Principal::agentSession($request), $after, $limit);

        // The last ID returned, so a reader that saw nothing keeps the cursor it came with rather
        // than resetting to the start of the feed
        $last = $events === [] ? null : $events[\count($events) - 1]['id'];

        $cursor = \is_int($last) ? $last : $after;

        return new JsonResponse([
            'events' => $events,
            'cursor' => $cursor,
        ]);
    }
}
