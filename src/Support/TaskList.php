<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;

/**
 * Reads tasks for an agent session.
 *
 * **Every agent sees every task.** Unlike narration, which #29 restricts by reader, a task is a
 * piece of fleet state: an agent cannot decide whether to claim work it cannot see, and a queue
 * half the fleet is blind to is a queue that deadlocks. What narrows a task is claiming it, and
 * `Support\Tasks` carries #16's eligibility rule in the write that does so.
 *
 * Provenance travels with every task, derived on read and never taken from what a creator claimed.
 * It is what lets an agent decide how much to trust a task's instructions -- task content is
 * untrusted input to something that may have shell access.
 */
final class TaskList
{
    /**
     * The most tasks one read returns, whatever the caller asks for.
     */
    public const int MAX_PAGE = 100;

    /**
     * @param  AgentLogins  $logins  Who each session belongs to.
     */
    public function __construct(private readonly AgentLogins $logins) {}

    /**
     * Read a page of tasks, newest priority first.
     *
     * @param  TaskStatus|null  $status  The status to filter by, or null for every status.
     * @param  int  $limit  How many to return.
     * @return list<array<string, mixed>> The tasks, with their provenance.
     */
    public function page(?TaskStatus $status, int $limit): array
    {
        $tasks = Task::query()
            ->when($status instanceof TaskStatus, fn ($query) => $query->where('status', $status?->value))

            // The queue's order: the most urgent first, and among equals the oldest, so a task
            // nobody claims does not sink under everything filed after it
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->get();

        $logins = $this->logins->forSessions([
            ...$tasks->pluck('created_by')->all(),
            ...$tasks->pluck('claimed_by')->all(),
        ]);

        return array_values(array_map(fn (Task $task): array => $this->describe($task, $logins), $tasks->all()));
    }

    /**
     * One task as an agent sees it.
     *
     * @param  Task  $task  The task.
     * @param  array<int, string>  $logins  GitHub logins, keyed by agent session ID.
     * @return array<string, mixed> The task.
     */
    private function describe(Task $task, array $logins): array
    {
        return [
            'id' => $task->id,
            'parent_task_id' => $task->parent_task_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority,
            'payload' => $task->payload,
            'result' => $task->result,
            'project_id' => $task->project_id,
            'created_at' => $task->created_at?->toIso8601String(),
            'claimed_at' => $task->claimed_at?->toIso8601String(),

            // Derived by the server on every read. `coordinator_direct` is what the creating
            // session held at the time, which is also what decides who may claim this.
            'created_by' => $this->actor($task->created_by, $logins, $task->created_with_coordinator),
            'claimed_by' => $this->actor($task->claimed_by, $logins, null),
        ];
    }

    /**
     * One session, as provenance.
     *
     * @param  int|null  $sessionId  The session, when there is one.
     * @param  array<int, string>  $logins  GitHub logins, keyed by agent session ID.
     * @param  bool|null  $coordinator  Whether the session held `coordinator:direct`, where that is
     *                                  recorded.
     * @return array<string, mixed>|null The actor, or null where there is none.
     */
    private function actor(?int $sessionId, array $logins, ?bool $coordinator): ?array
    {
        if ($sessionId === null) {
            return null;
        }

        $actor = [
            'session_id' => $sessionId,
            'github_login' => $logins[$sessionId] ?? null,
        ];

        if ($coordinator !== null) {
            $actor['coordinator_direct'] = $coordinator;
        }

        return $actor;
    }

    /**
     * Whether a session may be handed a task.
     *
     * A session that has gone is refused: it will never make another request, so a task assigned to
     * one would sit held until the sweep released it. A `stale` session is accepted, because it is
     * a process that has been quiet rather than one that has stopped -- refusing it would make an
     * agent unable to receive work for as long as its build runs, and if it really has died the
     * sweep releases the task soon enough.
     *
     * @param  AgentSession|null  $session  The proposed assignee.
     * @return bool True when the session can be handed work.
     */
    public static function canBeAssigned(?AgentSession $session): bool
    {
        return $session instanceof AgentSession && ! $session->hasGone();
    }
}
