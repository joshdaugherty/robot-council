<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\TaskList;

/**
 * Serves the fleet's tasks to one agent session.
 *
 * Every agent sees every task. A queue half the fleet is blind to is a queue that deadlocks, and an
 * agent cannot decide whether to claim work it cannot see. What narrows a task is claiming it, and
 * that is where #16's eligibility rule is enforced -- in the write, not here.
 */
final class ListTasksController
{
    /**
     * Read a page of tasks.
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskList  $tasks  The task reader.
     * @return JsonResponse The tasks, with their provenance.
     */
    public function __invoke(Request $request, TaskList $tasks): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(TaskStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.TaskList::MAX_PAGE],
        ]);

        $status = $request->filled('status')
            ? TaskStatus::tryFrom($request->string('status')->value())
            : null;

        return new JsonResponse([
            'tasks' => $tasks->page($status, $request->integer('limit', TaskList::MAX_PAGE)),
        ]);
    }
}
