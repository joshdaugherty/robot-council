<?php

declare(strict_types=1);

/**
 * The unit of work agents hand each other: who may move a task, from where to where, and what a
 * race between two of them settles on.
 *
 * The transition table below is written out by hand from #25 rather than read from
 * `TaskTransition`. A dataset generated from the enum would agree with the enum however wrong both
 * were, which is the one thing a table this mechanical needs protecting against.
 *
 * @command  vendor/bin/pest --compact tests/TaskLifecycleTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Task;
use RobotCouncil\Models\TaskStatus;
use RobotCouncil\Models\TaskTransition;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\SessionReleases;
use RobotCouncil\Support\TaskOutcome;
use RobotCouncil\Support\Tasks;
use RobotCouncil\Tests\TestCase;

/**
 * The transition table from #25: which statuses each transition may start from.
 */
const STARTS_FROM = [
    'claim' => ['pending'],
    'start' => ['claimed', 'blocked'],
    'block' => ['claimed', 'in_progress'],
    'complete' => ['claimed', 'in_progress'],
    'fail' => ['claimed', 'in_progress', 'blocked'],
    'release' => ['claimed', 'in_progress', 'blocked'],
    'reassign' => ['claimed', 'in_progress', 'blocked'],
    'cancel' => ['pending', 'claimed', 'in_progress', 'blocked'],
];

/**
 * Where each transition leaves the task.
 */
const LANDS_ON = [
    'claim' => 'claimed',
    'start' => 'in_progress',
    'block' => 'blocked',
    'complete' => 'done',
    'fail' => 'failed',
    'release' => 'pending',
    'reassign' => 'claimed',
    'cancel' => 'cancelled',
];

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer, [
        Ability::TasksCreate->value,
        Ability::TasksClaim->value,
    ]);

    [$this->session, $this->token] = $this->startAgentSession($this->installation);
});

/**
 * A second session under the same developer, for the cases that need two agents.
 *
 * @param  TestCase  $case  The test case.
 * @return array{AgentSession, string} The session and its token.
 */
function anotherAgent(TestCase $case): array
{
    return $case->startAgentSession($case->installation);
}

/**
 * A session holding `coordinator:direct`, under a second developer.
 *
 * Deliberately another developer's: a coordinator that could only direct its own developer's agents
 * would make every cross-developer assertion in this file pass for the wrong reason.
 *
 * @param  TestCase  $case  The test case.
 * @return array{AgentSession, string} The session and its token.
 */
function coordinator(TestCase $case): array
{
    // Memoized: several tests reach a coordinator twice -- once to set a task up, once to act --
    // and enrolling the same developer again collides on the users table's unique email
    if (isset($case->coordinatorSession)) {
        return [$case->coordinatorSession, $case->coordinatorToken];
    }

    $other = $case->enrollDeveloper(77, login: 'coordinator');

    $installation = $case->approveInstallation($other, [
        Ability::CoordinatorDirect->value,
        Ability::TasksCreate->value,
    ], machineLabel: 'coordinator-box');

    [$case->coordinatorSession, $case->coordinatorToken] = $case->startAgentSession($installation);

    return [$case->coordinatorSession, $case->coordinatorToken];
}

/**
 * File one task through the endpoint.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $token  The token to file it with.
 * @param  array<string, mixed>  $overrides  Fields to replace in the request body.
 * @return int The task's ID.
 */
function fileTask(TestCase $case, string $token, array $overrides = []): int
{
    $response = $case->machine($token)->postJson(route('robot-council.tasks.store'), [
        'title' => 'Rebuild the index',
        ...$overrides,
    ]);

    $response->assertCreated();

    return intValue($response->json('task_id'));
}

/**
 * Drive a task into one status, using only the API.
 *
 * @param  TestCase  $case  The test case.
 * @param  string  $status  The status to reach.
 * @return int The task's ID.
 */
function taskInStatus(TestCase $case, string $status): int
{
    $task = fileTask($case, $case->token);

    $move = function (string $transition, string $token) use ($case, $task): void {
        $case->machine($token)
            ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]))
            ->assertOk();
    };

    if ($status === 'pending') {
        return $task;
    }

    if ($status === 'cancelled') {
        [, $coordinator] = coordinator($case);

        $case->machine($coordinator)
            ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'cancel']))
            ->assertOk();

        return $task;
    }

    $move('claim', $case->token);

    match ($status) {
        'claimed' => null,
        'in_progress' => $move('start', $case->token),
        'blocked' => $move('block', $case->token),
        'done' => $move('complete', $case->token),
        'failed' => $move('fail', $case->token),
        default => throw new RuntimeException(sprintf('No route to %s.', $status)),
    };

    return $task;
}

/**
 * How many of the feed's events are about a task.
 *
 * @return int The count.
 */
function taskEvents(): int
{
    return FleetEvent::query()->where('type', 'like', 'task.%')->count();
}

/**
 * Attempt a transition as whoever it needs.
 *
 * @param  TestCase  $case  The test case.
 * @param  int  $task  The task to move.
 * @param  string  $transition  The transition to attempt.
 * @return TestResponse<JsonResponse> The response.
 */
function attempt(TestCase $case, int $task, string $transition): TestResponse
{
    $body = [];
    $token = $case->token;

    if (\in_array($transition, ['reassign', 'cancel'], true)) {
        [, $token] = coordinator($case);
    }

    if ($transition === 'reassign') {
        [$assignee] = anotherAgent($case);
        $body['session_id'] = $assignee->getKey();
    }

    return $case->machine($token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]), $body);
}

dataset('possible transitions', function (): Generator {
    foreach (STARTS_FROM as $transition => $statuses) {
        foreach ($statuses as $status) {
            yield sprintf('%s from %s', $transition, $status) => [$transition, $status];
        }
    }
});

dataset('impossible transitions', function (): Generator {
    foreach (STARTS_FROM as $transition => $statuses) {
        foreach (['pending', 'claimed', 'in_progress', 'blocked', 'done', 'failed', 'cancelled'] as $status) {
            if (! \in_array($status, $statuses, true)) {
                yield sprintf('%s from %s', $transition, $status) => [$transition, $status];
            }
        }
    }
});

it('agrees with the transition table in #25', function (): void {
    // The table above is the specification, and the enum is the implementation of it. Everything
    // else in this file drives the API from the table, so without this the two could disagree and
    // every behavioral test would still pass -- against whatever the enum happened to say.
    foreach (TaskTransition::cases() as $transition) {
        expect(TaskStatus::values($transition->startsFrom()))->toBe(STARTS_FROM[$transition->value])
            ->and($transition->to()->value)->toBe(LANDS_ON[$transition->value]);
    }

    expect(array_keys(STARTS_FROM))->toBe(TaskTransition::values())
        ->and(array_keys(LANDS_ON))->toBe(TaskTransition::values());
});

it('moves a task from every status the transition starts from', function (string $transition, string $status): void {
    $task = taskInStatus($this, $status);

    // Only the task's own events: setting up the actor for a coordinator transition starts a
    // session, and a session enrolling is an event too
    $before = taskEvents();

    attempt($this, $task, $transition)
        ->assertOk()
        ->assertJsonPath('applied', true)
        ->assertJsonPath('status', LANDS_ON[$transition]);

    expect(Task::query()->findOrFail($task)->status->value)->toBe(LANDS_ON[$transition])
        ->and(taskEvents())->toBe($before + 1);
})->with('possible transitions');

it('refuses a transition from every status it cannot start from', function (string $transition, string $status): void {
    $task = taskInStatus($this, $status);

    $before = taskEvents();

    attempt($this, $task, $transition)
        ->assertStatus(409)
        ->assertJsonPath('applied', false)
        ->assertJsonPath('status', null);

    // Unchanged, and silent: a conflict is not a thing that happened to the fleet
    expect(Task::query()->findOrFail($task)->status->value)->toBe($status)
        ->and(taskEvents())->toBe($before);
})->with('impossible transitions');

it('leaves a cancelled task beyond its former claimant', function (string $transition): void {
    $task = taskInStatus($this, 'claimed');

    [, $coordinator] = coordinator($this);

    $this->machine($coordinator)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'cancel']))
        ->assertOk();

    // The claimant still holds the claim on the row, so this is a conflict rather than a
    // permission problem -- and the difference matters to an agent deciding whether to retry
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]))
        ->assertStatus(409);
})->with(['start', 'complete']);

it('gives one task to exactly one of two agents claiming it at once', function (): void {
    $task = fileTask($this, $this->token);

    [$rival] = anotherAgent($this);

    $injected = 0;
    $rivalOutcome = null;

    // Anchored on the first query the claiming request makes against the tasks table, which is the
    // window the criterion names. That anchor is what gives this test its teeth: under the
    // implementation that ships, the first such query IS the conditional update, so the rival
    // arrives after the task is already taken. Under a read-then-write claim the first query is
    // the SELECT, and the rival lands in the gap before the write -- which is exactly when both
    // claims succeed. Anchoring anywhere earlier, such as on the guard's own session read, passes
    // under both implementations and proves nothing.
    //
    // The rival claims through the store rather than the endpoint: a `Route` instance is shared by
    // every request in the process and `bind()` writes the current request's parameters onto it,
    // so a nested HTTP request from here rebinds the outer request's own route parameters.
    DB::listen(function (QueryExecuted $query) use (&$injected, &$rivalOutcome, $rival, $task): void {
        if ($injected > 0 || ! str_contains($query->sql, 'robot_council_tasks')) {
            return;
        }

        $injected++;

        $rivalOutcome = $this->service(Tasks::class)->transition($task, TaskTransition::Claim, $rival, false);
    });

    $mine = $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']));

    expect($injected)->toBe(1);

    $winners = ($mine->status() === 200 ? 1 : 0) + ($rivalOutcome === TaskOutcome::Applied ? 1 : 0);

    $held = Task::query()->findOrFail($task);

    // One grant, one event, and a claimant that is whichever session actually got it
    expect($winners)->toBe(1)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskClaimed->value)->count())->toBe(1)
        ->and($held->status)->toBe(TaskStatus::Claimed)
        ->and($held->claimed_by)->toBe($mine->status() === 200 ? $this->session->getKey() : $rival->getKey());
});

it('settles a release racing a reassignment on exactly one outcome', function (): void {
    $task = taskInStatus($this, 'claimed');

    [$assignee] = anotherAgent($this);
    [$coordinatorSession] = coordinator($this);

    $injected = 0;

    // Through the store, for the reason the claim race records: a nested HTTP request would
    // rebind the outer request's route parameters on the shared `Route` instance
    $reassigned = null;

    DB::listen(function (QueryExecuted $query) use (&$injected, &$reassigned, $coordinatorSession, $task, $assignee): void {
        if ($injected > 0 || ! str_contains($query->sql, 'robot_council_tasks')) {
            return;
        }

        $injected++;

        $reassigned = $this->service(Tasks::class)
            ->transition($task, TaskTransition::Reassign, $coordinatorSession, true, $assignee);
    });

    $release = $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'release']));

    expect($injected)->toBe(1);

    $final = Task::query()->findOrFail($task);

    // Exactly one of the two, which is the criterion. Both applying would mean a reassignment that
    // handed the task on and a release that gave it back, with the feed claiming both happened.
    $applied = ($release->status() === 200 ? 1 : 0) + ($reassigned === TaskOutcome::Applied ? 1 : 0);

    expect($applied)->toBe(1);

    // Either the reassignment stood and the release found a task it no longer held, or the release
    // landed first. What is not allowed is a task claimed by nobody while still reading as claimed,
    // or one whose claimant is the session that released it.
    if ($release->status() === 200) {
        expect($final->status)->toBe(TaskStatus::Pending)
            ->and($final->claimed_by)->toBeNull();
    } else {
        // 403 rather than 409: the task is still in a status a release starts from, and what
        // stopped this one is that the session no longer holds it
        $release->assertForbidden();

        expect($final->status)->toBe(TaskStatus::Claimed)
            ->and($final->claimed_by)->toBe($assignee->getKey());
    }
});

it('refuses a transition to a session that does not hold the task', function (): void {
    $task = taskInStatus($this, 'claimed');

    [, $otherToken] = anotherAgent($this);

    $this->machine($otherToken)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'start']))
        ->assertForbidden();

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Claimed);
});

it("refuses a coordinator's transition to a session without the ability", function (string $transition): void {
    $task = taskInStatus($this, 'claimed');

    // This session holds `tasks:claim` and holds the task itself, and still may not do these
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => $transition]), [
            'session_id' => $this->session->getKey(),
        ])
        ->assertForbidden();

    expect(FleetEvent::query()->where('type', 'like', 'task.%')->count())->toBe(2);
})->with(['reassign', 'cancel']);

it('refuses a claim from a session without tasks:claim', function (): void {
    $task = fileTask($this, $this->token);

    $narrow = $this->approveInstallation($this->developer, [Ability::EventsPost->value], machineLabel: 'narrow');

    [, $narrowToken] = $this->startAgentSession($narrow);

    $this->machine($narrowToken)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertForbidden();

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);
});

it('answers 404 for a task that does not exist', function (string $id): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $id, 'transition' => 'claim']))
        ->assertNotFound();
})->with([
    'a number nobody used' => ['987654'],

    // Postgres raises `22003 value out of range` for a number no bigint holds, where SQLite
    // quietly matches none
    'larger than a bigint' => ['99999999999999999999999'],
]);

it('answers 404 for a transition that does not exist', function (): void {
    $task = fileTask($this, $this->token);

    // Refused by the router's own constraint, which is built from the enum, so nothing reaches a
    // controller that would have to decide what an unknown verb means
    $this->machine($this->token)
        ->postJson(sprintf('/robot-council/api/tasks/%d/annihilate', $task))
        ->assertNotFound();
});

it('refuses a reassignment to a session that cannot be worked', function (array $body): void {
    $task = taskInStatus($this, 'claimed');

    [, $coordinatorToken] = coordinator($this);

    if (($body['session_id'] ?? null) === 'gone') {
        [$dead] = anotherAgent($this);
        $this->service(SessionPresence::class)->end($dead);
        $body['session_id'] = $dead->getKey();
    }

    $this->machine($coordinatorToken)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'reassign']), $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('session_id');

    expect(Task::query()->findOrFail($task)->claimed_by)->toBe($this->session->getKey());
})->with([
    'a session that has gone' => [['session_id' => 'gone']],
    'a session nobody started' => [['session_id' => 987654]],
    'no session at all' => [[]],
]);

it('reassigns to a stale session, which is quiet rather than stopped', function (): void {
    $task = taskInStatus($this, 'claimed');

    [$assignee] = anotherAgent($this);

    $this->travelTo(now()->addMinutes(6));
    $this->service(SessionPresence::class)->sweep();

    expect($assignee->refresh()->hasGoneQuiet())->toBeTrue();

    [, $coordinatorToken] = coordinator($this);

    // Refusing a stale session would make an agent unable to receive work for as long as its build
    // runs, and the sweep releases the task soon enough if it really has died
    $this->machine($coordinatorToken)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'reassign']),
        ['session_id' => $assignee->getKey()]
    )->assertOk();

    expect(Task::query()->findOrFail($task)->claimed_by)->toBe($assignee->getKey());
});

it('refuses anything over its size limit', function (array $body, string $field): void {
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.store'), ['title' => 'Fine', ...$body])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(Task::query()->count())->toBe(0);
})->with([
    'a title past 255 characters' => [['title' => str_repeat('t', 256)], 'title'],
    'a description past its limit' => [['description' => str_repeat('d', Task::MAX_DESCRIPTION + 1)], 'description'],
    'a payload past its byte limit' => [['payload' => ['blob' => str_repeat('p', 5000)]], 'payload'],
    'a priority above the range' => [['priority' => Task::MAX_PRIORITY + 1], 'priority'],
]);

it('refuses a result past its byte limit', function (): void {
    $task = taskInStatus($this, 'claimed');

    $this->machine($this->token)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'complete']),
        ['result' => ['blob' => str_repeat('r', 5000)]]
    )->assertStatus(422)->assertJsonValidationErrors('result');

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Claimed);
});

it('stores what an agent reports about a task it finished', function (): void {
    $task = taskInStatus($this, 'claimed');

    $this->machine($this->token)->postJson(
        route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'complete']),
        ['result' => ['commit' => 'abc1234', 'files' => 3]]
    )->assertOk();

    // Hand-encoded in the store, because `Eloquent\Builder::update()` applies no casts: a raw
    // array would reach the column as the string `Array`
    expect(Task::query()->findOrFail($task)->result)->toBe(['commit' => 'abc1234', 'files' => 3]);
});

it('gives back every task a session was holding when it went', function (string $status): void {
    $task = taskInStatus($this, $status);

    $this->service(SessionPresence::class)->end($this->session);

    $before = FleetEvent::query()->count();

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0]);

    $released = Task::query()->findOrFail($task);

    expect($released->status)->toBe(TaskStatus::Pending)
        ->and($released->claimed_by)->toBeNull()
        ->and($released->claimed_at)->toBeNull()
        ->and(FleetEvent::query()->count())->toBe($before + 1);

    $event = FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->sole();

    // Attributed to no session: this is what the service observed, not what the session that lost
    // the task had to say about it
    expect($event->agent_session_id)->toBeNull()
        ->and($event->meta)->toBe([
            'task_id' => $task,
            'to' => 'pending',
            'released_from' => $this->session->getKey(),
        ]);
})->with(['claimed', 'in_progress', 'blocked']);

it('releases nothing held by a session that has not gone', function (string $presence): void {
    $task = taskInStatus($this, 'claimed');

    if ($presence === 'stale') {
        $this->travelTo(now()->addMinutes(6));
        $this->service(SessionPresence::class)->sweep();

        expect($this->session->refresh()->hasGoneQuiet())->toBeTrue();
    }

    $this->service(SessionPresence::class)->sweep();

    // A stale session still holds everything it claimed. That is the whole reason the state exists.
    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Claimed)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->count())->toBe(0);
})->with(['active', 'stale']);

it('releases the tasks on the next sweep when a release step throws', function (): void {
    $task = taskInStatus($this, 'claimed');

    $this->service(SessionPresence::class)->end($this->session);

    // Registered after the package's own step, so the package's runs first and this one fails the
    // sweep afterwards. Every step still runs, and the first failure is what is rethrown.
    $failures = 0;

    $this->service(SessionReleases::class)->register(function () use (&$failures): void {
        $failures++;

        if ($failures === 1) {
            throw new RuntimeException('the lock release failed');
        }
    });

    expect(fn (): array => $this->service(SessionPresence::class)->sweep())
        ->toThrow(RuntimeException::class, 'the lock release failed');

    // The task was already released, because the package's step ran before the one that threw
    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);

    // And a second sweep is clean, which is what "the next sweep releases the tasks" needs: the
    // step finds what is still held rather than replaying what it did last time
    $this->service(SessionPresence::class)->sweep();

    expect(FleetEvent::query()->where('type', FleetEventType::TaskReleased->value)->count())->toBe(1)
        ->and($failures)->toBe(2);
});

it('serves every task with the provenance the fleet decides trust on', function (): void {
    $task = taskInStatus($this, 'claimed');

    $response = $this->machine($this->token)->getJson(route('robot-council.tasks.index'));

    $response->assertOk()->assertJsonPath('tasks.0.id', $task);

    expect(arrayValue($response->json('tasks.0.created_by')))->toBe([
        'session_id' => $this->session->getKey(),
        'github_login' => 'octodev',
        'coordinator_direct' => false,
    ])
        ->and(arrayValue($response->json('tasks.0.claimed_by')))->toBe([
            'session_id' => $this->session->getKey(),
            'github_login' => 'octodev',
        ]);
});

it('lists tasks filtered by status, most urgent first', function (): void {
    $low = fileTask($this, $this->token, ['title' => 'Low', 'priority' => 1]);
    $high = fileTask($this, $this->token, ['title' => 'High', 'priority' => 9]);
    $claimed = taskInStatus($this, 'claimed');

    $pending = $this->machine($this->token)
        ->getJson(route('robot-council.tasks.index', ['status' => 'pending']))
        ->assertOk();

    expect(array_column(arrayValue($pending->json('tasks')), 'id'))->toBe([$high, $low]);

    $held = $this->machine($this->token)
        ->getJson(route('robot-council.tasks.index', ['status' => 'claimed']))
        ->assertOk();

    expect(array_column(arrayValue($held->json('tasks')), 'id'))->toBe([$claimed]);
});

it("refuses a claim on another developer's task", function (): void {
    // Filed by the coordinator's developer, by a session that does NOT hold the ability: a session
    // may hold `tasks:create` without holding `coordinator:direct`
    $other = $this->enrollDeveloper(99, login: 'thirddev');
    $this->setAccessLists(developers: [4242, 77, 99]);

    $theirs = $this->approveInstallation($other, [Ability::TasksCreate->value], machineLabel: 'theirs');

    [, $theirToken] = $this->startAgentSession($theirs);

    $task = fileTask($this, $theirToken);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertForbidden();

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending)
        ->and(FleetEvent::query()->where('type', FleetEventType::TaskClaimed->value)->count())->toBe(0);
});

it("admits a claim on another developer's task when a coordinator created it", function (): void {
    [, $coordinatorToken] = coordinator($this);

    $task = fileTask($this, $coordinatorToken);

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();

    expect(Task::query()->findOrFail($task)->claimed_by)->toBe($this->session->getKey());
});

it('leaves a coordinator-created task claimable after the ability is revoked', function (): void {
    [$coordinatorSession, $coordinatorToken] = coordinator($this);

    $task = fileTask($this, $coordinatorToken);

    // Revoked on the installation, which rewrites the tokens already in flight
    Artisan::call('robot-council:revoke-ability', [
        'installation' => $coordinatorSession->installation_id,
        'ability' => Ability::CoordinatorDirect->value,
    ]);

    // What was open to the fleet stays open. The alternative is a task that silently becomes
    // unclaimable by everyone it was filed for, weeks after it was written.
    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'claim']))
        ->assertOk();

    expect(Task::query()->findOrFail($task)->created_with_coordinator)->toBeTrue();
});

it('records the creating session, its developer, and what it held', function (): void {
    [$coordinatorSession, $coordinatorToken] = coordinator($this);

    $task = Task::query()->findOrFail(fileTask($this, $coordinatorToken, ['project_id' => 'uams-statamic']));

    expect($task->created_by)->toBe($coordinatorSession->getKey())
        ->and($task->user_id)->toBe($coordinatorSession->user_id)
        ->and($task->created_with_coordinator)->toBeTrue()
        ->and($task->project_id)->toBe('uams-statamic')
        ->and($task->claimed_by)->toBeNull();

    $event = FleetEvent::query()->where('type', FleetEventType::TaskCreated->value)->sole();

    expect($event->agent_session_id)->toBe($coordinatorSession->getKey())
        ->and($event->posted_with_coordinator)->toBeTrue();
});

it('refuses an agent route to a task endpoint without a session', function (): void {
    $this->getJson(route('robot-council.tasks.index'))->assertUnauthorized();
    $this->postJson(route('robot-council.tasks.store'), ['title' => 'x'])->assertUnauthorized();
});

it('clears the claim and its time on every release', function (): void {
    $task = taskInStatus($this, 'in_progress');

    expect(dateValue(Task::query()->findOrFail($task)->claimed_at))->not->toBeNull();

    $this->machine($this->token)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'release']))
        ->assertOk();

    $released = Task::query()->findOrFail($task);

    expect($released->claimed_by)->toBeNull()
        ->and($released->claimed_at)->toBeNull()
        ->and($released->status)->toBe(TaskStatus::Pending);
});

it('lets a coordinator release a task it does not hold', function (): void {
    $task = taskInStatus($this, 'in_progress');

    [, $coordinatorToken] = coordinator($this);

    // The one transition a coordinator may make without holding the claim. It takes a stuck task
    // back; it does not get to report an outcome it never observed.
    $this->machine($coordinatorToken)
        ->postJson(route('robot-council.tasks.transition', ['task' => $task, 'transition' => 'release']))
        ->assertOk();

    expect(Task::query()->findOrFail($task)->status)->toBe(TaskStatus::Pending);

    foreach (['complete', 'fail'] as $forbidden) {
        $again = taskInStatus($this, 'in_progress');

        $this->machine($coordinatorToken)
            ->postJson(route('robot-council.tasks.transition', ['task' => $again, 'transition' => $forbidden]))
            ->assertForbidden();
    }
});

it('stamps the claim time when a task is taken', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01 12:00:00'));

    $task = taskInStatus($this, 'claimed');

    expect(dateValue(Task::query()->findOrFail($task)->claimed_at)->toDateTimeString())
        ->toBe('2026-01-01 12:00:00');
});
