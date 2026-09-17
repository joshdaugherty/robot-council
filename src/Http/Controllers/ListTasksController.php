<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Http\Principal;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Support\TaskList;

/**
 * Serves the fleet's tasks to one agent session.
 *
 * Every agent sees that every task exists: an agent cannot decide whether to claim work it cannot
 * see, and a queue half the fleet is blind to is a queue that deadlocks. What a task *says* is
 * narrower, and `TaskList` decides that -- on the rule #29 already settled for narration.
 *
 * **Read this with the cursor, not with the first page.** A page is bounded, nothing prunes the
 * table, and the ordering puts the most urgent first, so the tail of the queue is only reachable by
 * walking. A reader that asks once and stops sees the top of the queue and nothing else.
 */
final class ListTasksController
{
    /**
     * Read a page of tasks.
     *
     * @param  Request  $request  The incoming request.
     * @param  TaskList  $tasks  The task reader.
     * @return JsonResponse The tasks, and the cursor to ask from next.
     */
    public function __invoke(Request $request, TaskList $tasks): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(TaskStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.TaskList::MAX_PAGE],

            // Both halves of the cursor or neither: the ordering is a pair, and half of it names
            // no position at all
            // Not `sometimes`, which skips a field that is absent -- and absent is precisely when
            // `required_with` has to fire
            'after_priority' => ['nullable', 'required_with:after_id', 'integer', 'between:0,'.Task::MAX_PRIORITY],
            'after_id' => ['nullable', 'required_with:after_priority', 'integer', 'min:1'],
        ]);

        $session = Principal::agentSession($request);

        $status = $request->filled('status')
            ? TaskStatus::tryFrom($request->string('status')->value())
            : null;

        $page = $tasks->page(
            $status,
            $request->integer('limit', TaskList::MAX_PAGE),
            $session,

            // A coordinator reads every task's content, the same way it reads every session's
            // narration: it is the role that acts across developers
            Tokens::allows($session->currentAccessToken(), Ability::CoordinatorDirect),
            $request->filled('after_id')
                ? ['priority' => $request->integer('after_priority'), 'id' => $request->integer('after_id')]
                : null
        );

        return new JsonResponse([
            'tasks' => $page['tasks'],

            // Null on the last page. A reader that gets fewer tasks than it asked for has reached
            // the end; a reader that gets a full page asks again from here.
            'cursor' => $page['cursor'],
        ]);
    }
}
