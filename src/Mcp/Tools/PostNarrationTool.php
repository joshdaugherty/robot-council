<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RobotCouncil\Access\Ability;
use RobotCouncil\Http\Rules\BoundedMeta;
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;

/**
 * Says what this agent is doing.
 *
 * The type is never taken from the call. Narration and a state change are different kinds of claim,
 * and an agent that could choose between them could dress its own account as something the service
 * observed.
 */
final class PostNarrationTool extends Tool
{
    use ActsAsAgent;

    /**
     * The longest narration the feed accepts.
     */
    private const int MAX_BODY = 4000;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'events_narrate';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Say what you are doing, for other agents to read. Needs `events:post`. Your narration '
            ."reaches your own developer's sessions only, unless you hold `coordinator:direct`, in "
            .'which case it reaches the whole fleet.';
    }

    /**
     * The arguments the tool takes.
     *
     * @param  JsonSchema  $schema  The schema factory.
     * @return array<string, mixed> The argument schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->max(self::MAX_BODY)->description('What you are doing.')->required(),
            'meta' => $schema->object()->description('Structured detail. Bounded in size.'),
        ];
    }

    /**
     * Record it.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  FleetEvents  $events  The change feed.
     * @return Response|ResponseFactory The recorded event.
     */
    public function handle(Request $request, HttpRequest $http, FleetEvents $events): Response|ResponseFactory
    {
        if (! $this->allows($http, Ability::EventsPost)) {
            return $this->refuse(Ability::EventsPost);
        }

        $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'meta' => ['sometimes', 'array', new BoundedMeta],
        ]);

        $meta = Arguments::structure($request->get('meta'));

        $event = $events->record(
            FleetEventType::Narration,
            $this->session($http),
            Arguments::string($request->get('body')),

            // Under `client`, so nothing a caller sent can later be mistaken for something the
            // server derived
            $meta === null ? [] : ['client' => $meta],
            $this->allows($http, Ability::CoordinatorDirect)
        );

        return Response::structured(['event_id' => $event->id, 'type' => $event->type->value]);
    }
}
