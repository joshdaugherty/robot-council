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
 * Tells the fleet something, from a session that may direct other developers' agents.
 */
final class PostDirectiveTool extends Tool
{
    use ActsAsAgent;

    /**
     * The longest directive the feed accepts.
     */
    private const int MAX_BODY = 4000;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'directive_post';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Tell the whole fleet something. Needs `coordinator:direct`, which enrollment can never '
            .'ask for and an admin grants afterwards. Every agent reads it, including other '
            ."developers' agents.";
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
            'body' => $schema->string()->max(self::MAX_BODY)->description('The instruction.')->required(),
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
        if (! $this->allows($http, Ability::CoordinatorDirect)) {
            return $this->refuse(Ability::CoordinatorDirect);
        }

        $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'meta' => ['sometimes', 'array', new BoundedMeta],
        ]);

        $meta = Arguments::structure($request->get('meta'));

        $event = $events->record(
            FleetEventType::Directive,
            $this->session($http),
            Arguments::string($request->get('body')),
            $meta === null ? [] : ['client' => $meta],
            true
        );

        return Response::structured(['event_id' => $event->id, 'type' => $event->type->value]);
    }
}
