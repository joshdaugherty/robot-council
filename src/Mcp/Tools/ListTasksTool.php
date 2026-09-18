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
use RobotCouncil\Mcp\ActsAsAgent;
use RobotCouncil\Mcp\Arguments;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\TaskList;

/**
 * Reads the fleet's task queue.
 *
 * Needs no ability. Every agent sees that every task exists, because an agent cannot decide whether
 * to claim work it cannot see -- but what a task *says* reaches only the sessions that may act on
 * it, which the reader carries into the store rather than this tool deciding.
 */
final class ListTasksTool extends Tool
{
    use ActsAsAgent;

    /**
     * The tool's name.
     *
     * @return string The name.
     */
    public function name(): string
    {
        return 'task_list';
    }

    /**
     * What the tool does.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return 'Read the task queue, most urgent first. Page it with the `cursor` from the previous '
            .'call: a page is bounded and nothing prunes the table, so one call shows you the top of '
            .'the queue and not the whole of it. Tasks you may not claim come back with `readable` '
            .'false and no title, description, payload or result -- their words are not for you.';
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
            'status' => $schema->string()
                ->enum(TaskStatus::values(TaskStatus::cases()))
                ->description('Only tasks in this status. Omit for every status.'),
            'limit' => $schema->integer()
                ->description(sprintf('How many to return, up to %d.', TaskList::MAX_PAGE)),
            'after_priority' => $schema->integer()->description('The `cursor.priority` from the previous call.'),
            'after_id' => $schema->integer()->description('The `cursor.id` from the previous call.'),
        ];
    }

    /**
     * Read the page.
     *
     * @param  Request  $request  The tool call.
     * @param  HttpRequest  $http  The HTTP request it arrived on.
     * @param  TaskList  $tasks  The task reader.
     * @return ResponseFactory The page.
     */
    public function handle(Request $request, HttpRequest $http, TaskList $tasks): ResponseFactory
    {
        $status = $request->get('status');
        $afterId = $request->get('after_id');

        $page = $tasks->page(
            \is_string($status) ? TaskStatus::tryFrom($status) : null,
            \is_int($request->get('limit')) ? Arguments::integer($request->get('limit')) : TaskList::MAX_PAGE,
            $this->session($http),
            $this->allows($http, Ability::CoordinatorDirect),
            $afterId === null ? null : [
                'priority' => Arguments::integer($request->get('after_priority')),
                'id' => Arguments::integer($afterId),
            ]
        );

        return Response::structured(['tasks' => $page['tasks'], 'cursor' => $page['cursor']]);
    }
}
