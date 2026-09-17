<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;

/**
 * Creating tasks and moving them between states.
 *
 * **Every transition is one conditional update, and the count of changed rows is the decision.**
 * The statuses the transition may start from, the claimant it requires, and -- for a claim -- #16's
 * eligibility rule all go into the same `where`. So two agents claiming one task is settled by the
 * database rather than by whoever read first, and a read-then-write implementation that looked
 * correct would hand the same task to both.
 *
 * A failed write costs one extra read, and only a failed one. The write cannot say *why* it matched
 * nothing -- a missing task, a status that moved, a session that does not hold the task, and a task
 * belonging to another developer all look identical to `update()` -- so the diagnosis happens
 * afterwards, off the hot path, and answers 404, 409, or 403.
 *
 * **Lock order.** A transition takes the task row and then the feed sentinel. The release step
 * takes the session row first, so the package's order is installations, agent sessions, tasks, the
 * feed sentinel, tokens. Nothing here may take them in another order.
 */
final class Tasks
{
    /**
     * @param  FleetEvents  $events  The change feed.
     * @param  Credentials  $credentials  The configured bounds.
     */
    public function __construct(
        private readonly FleetEvents $events,
        private readonly Credentials $credentials
    ) {}

    /**
     * Create a task, pending and unclaimed.
     *
     * @param  AgentSession  $session  The session creating it.
     * @param  array<string, mixed>  $attributes  The validated fields from the request.
     * @param  bool  $withCoordinator  Whether the session held `coordinator:direct` as it created.
     * @return Task The created task.
     */
    public function create(AgentSession $session, array $attributes, bool $withCoordinator): Task
    {
        return DB::transaction(function () use ($session, $attributes, $withCoordinator): Task {
            $task = Task::query()->create([
                ...$attributes,
                'status' => TaskStatus::Pending,
                'created_by' => $session->getKey(),

                // Copied from the session rather than taken from the request, so nothing can
                // create a task on another developer's behalf and make it claimable by them
                'user_id' => $session->user_id,

                // Read from the token as it creates, and stored. Revoking the ability afterwards
                // must not narrow who may claim a task that was already open to the fleet.
                'created_with_coordinator' => $withCoordinator,
            ]);

            $this->events->record(
                FleetEventType::TaskCreated,
                $session,
                sprintf('Task #%d created: %s', $task->id, $task->title),
                ['task_id' => $task->id, 'project_id' => $task->project_id],
                $withCoordinator
            );

            return $task;
        });
    }

    /**
     * Attempt one transition.
     *
     * @param  int  $taskId  The task to move.
     * @param  TaskTransition  $transition  What to do to it.
     * @param  AgentSession  $actor  The session attempting it.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @param  AgentSession|null  $assignee  Who to hand it to, for a reassignment.
     * @param  array<array-key, mixed>|null  $result  What the agent reports, for a completion.
     * @return TaskOutcome What came of it.
     */
    public function transition(
        int $taskId,
        TaskTransition $transition,
        AgentSession $actor,
        bool $asCoordinator,
        ?AgentSession $assignee = null,
        ?array $result = null
    ): TaskOutcome {
        $holder = $transition->takesTheClaim()
            ? ($assignee ?? $actor)
            : null;

        return DB::transaction(function () use ($taskId, $transition, $actor, $asCoordinator, $holder, $result): TaskOutcome {
            $changed = $this->write($taskId, $transition, $actor, $asCoordinator, $holder, $result);

            if ($changed !== 1) {
                return $this->diagnose($taskId, $transition, $actor, $asCoordinator);
            }

            $this->events->record(
                $transition->event(),
                $actor,
                sprintf('Task #%d %s.', $taskId, $transition->reads()),
                array_filter([
                    'task_id' => $taskId,
                    'to' => $transition->to()->value,
                    'assigned_to' => $holder?->getKey(),
                ], static fn (mixed $value): bool => $value !== null),
                $asCoordinator
            );

            return TaskOutcome::Applied;
        });
    }

    /**
     * Give back the tasks every session that has gone was still holding.
     *
     * Registered on the presence sweep rather than driven by `Events\SessionGone`, so a signal that
     * was missed -- a worker that died, a listener that threw -- costs one sweep interval rather
     * than leaving a task claimed by a process that no longer exists for good. It is idempotent for
     * the same reason: it finds what is still held, whatever released the rest.
     *
     * @return int How many tasks were released.
     */
    public function releaseOrphaned(): int
    {
        $released = 0;

        $orphaned = Task::query()
            ->whereIn('status', TaskStatus::values(TaskStatus::held()))
            ->whereIn('claimed_by', AgentSession::query()
                ->select('id')
                ->where('status', AgentSessionStatus::Gone->value))
            ->orderBy('id')
            ->limit($this->credentials->maxPerSweep())
            ->get();

        foreach ($orphaned as $task) {
            $released += $this->releaseOne($task) ? 1 : 0;
        }

        return $released;
    }

    /**
     * Release one task whose session has gone, if its session is still gone.
     *
     * The session row is locked and re-read inside the transaction, which is what stops a release
     * racing the session coming back: a `gone` session never does, but the lock is what makes that
     * a property of the write rather than of the read that chose this task a moment earlier.
     *
     * @param  Task  $task  The task to give back.
     * @return bool True when this call released it.
     */
    private function releaseOne(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            $session = AgentSession::query()->whereKey($task->claimed_by)->lockForUpdate()->first();

            if (! $session instanceof AgentSession || ! $session->hasGone()) {
                return false;
            }

            $changed = Task::query()
                ->whereKey($task->getKey())
                ->whereIn('status', TaskStatus::values(TaskStatus::held()))
                ->where('claimed_by', $session->getKey())
                ->update([
                    'status' => TaskStatus::Pending->value,
                    'claimed_by' => null,
                    'claimed_at' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($changed !== 1) {
                return false;
            }

            // Attributed to no session: the fleet is being told what the service observed, not
            // what the session that lost the task had to say about it
            $this->events->record(
                FleetEventType::TaskReleased,
                null,
                sprintf('Task #%d released: its session ended.', $task->id),
                ['task_id' => $task->id, 'to' => TaskStatus::Pending->value, 'released_from' => $session->getKey()]
            );

            return true;
        });
    }

    /**
     * The one statement that decides a transition.
     *
     * @param  int  $taskId  The task to move.
     * @param  TaskTransition  $transition  What to do to it.
     * @param  AgentSession  $actor  The session attempting it.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @param  AgentSession|null  $holder  Who ends up holding it, when anybody does.
     * @param  array<array-key, mixed>|null  $result  What the agent reports, for a completion.
     * @return int How many rows changed, which is one or none.
     */
    private function write(
        int $taskId,
        TaskTransition $transition,
        AgentSession $actor,
        bool $asCoordinator,
        ?AgentSession $holder,
        ?array $result
    ): int {
        $query = Task::query()
            ->whereKey($taskId)
            ->whereIn('status', TaskStatus::values($transition->startsFrom()));

        // A claimant's transition names the claimant in the write. A coordinator releasing does
        // not, which is the whole point of the override.
        if ($transition->needsTheClaim() && (! $asCoordinator || ! $transition->coordinatorMayOverride())) {
            $query->where('claimed_by', $actor->getKey());
        }

        if ($transition === TaskTransition::Claim) {
            // #16, carried in the write rather than checked before it, so eligibility costs no
            // read and cannot be decided against a row that changed in between
            $query->where(function (Builder $eligible) use ($actor): void {
                $eligible->where('user_id', $actor->user_id)
                    ->orWhere('created_with_coordinator', true);
            });
        }

        $values = [
            'status' => $transition->to()->value,
            'updated_at' => Carbon::now(),
        ];

        if ($transition->takesTheClaim()) {
            $values['claimed_by'] = $holder?->getKey();
            $values['claimed_at'] = Carbon::now();
        }

        if ($transition->takesAResult() && $result !== null) {
            // Hand-encoded: `Eloquent\Builder::update()` applies no casts, so the model's `array`
            // cast on this column never runs and a raw array would be stored as `Array`
            $values['result'] = json_encode($result);
        }

        if ($transition->to() === TaskStatus::Pending) {
            // A release, including the sweep's, gives the task back with no trace of who held it
            $values['claimed_by'] = null;
            $values['claimed_at'] = null;
        }

        return $query->update($values);
    }

    /**
     * Work out why a write matched nothing.
     *
     * Ordered deliberately. A status the transition cannot start from is a conflict even when the
     * caller also does not hold the task, because the task being finished, cancelled, or already
     * taken is the more useful thing to be told -- and because a terminal task has no claimant to
     * not be.
     *
     * @param  int  $taskId  The task that was not moved.
     * @param  TaskTransition  $transition  What was attempted.
     * @param  AgentSession  $actor  The session that attempted it.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return TaskOutcome Why nothing happened.
     */
    private function diagnose(int $taskId, TaskTransition $transition, AgentSession $actor, bool $asCoordinator): TaskOutcome
    {
        $task = Task::query()->whereKey($taskId)->first();

        if (! $task instanceof Task) {
            return TaskOutcome::NotFound;
        }

        if (! \in_array($task->status, $transition->startsFrom(), true)) {
            return TaskOutcome::Conflict;
        }

        if ($transition === TaskTransition::Claim && ! $task->isClaimableBy($actor)) {
            return TaskOutcome::Forbidden;
        }

        $mustHold = $transition->needsTheClaim() && (! $asCoordinator || ! $transition->coordinatorMayOverride());

        if ($mustHold && $task->claimed_by !== $actor->getKey()) {
            return TaskOutcome::Forbidden;
        }

        // Everything the write tested still holds, so the row moved between the write and this
        // read. Whoever moved it got there first.
        return TaskOutcome::Conflict;
    }
}
