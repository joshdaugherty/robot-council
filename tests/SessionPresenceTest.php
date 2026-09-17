<?php

declare(strict_types=1);

/**
 * Which agent sessions are alive: what records contact, what the sweep changes, what it refuses to
 * change, and what ending a session releases.
 *
 * Time is frozen at a fixed instant rather than moved relative to a real clock. `last_seen_at` is
 * stored to the second, so a threshold crossed at x.8 seconds is a test that fails on one CI cell
 * in eight and reads as flake.
 *
 * @command  vendor/bin/pest --compact tests/SessionPresenceTest.php
 */

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Events\SessionGone;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\SessionPresence;
use RobotCouncil\Support\SessionReleases;

/**
 * The instant every test in this file starts from.
 */
const STARTED_AT = '2026-01-01 12:00:00';

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);

    $this->travelTo(Carbon::parse(STARTED_AT));
});

/**
 * How many events of one kind the feed holds.
 *
 * @param  FleetEventType  $type  The kind to count.
 * @return int The number recorded.
 */
function eventsOfType(FleetEventType $type): int
{
    return FleetEvent::query()->where('type', $type->value)->count();
}

it('records contact on every authenticated agent request', function (string $route, string $method): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    expect(dateValue($session->last_seen_at)->toDateTimeString())->toBe(STARTED_AT);

    $this->travelTo(Carbon::parse('2026-01-01 12:03:00'));

    $this->machine($token)->json($method, route($route))->assertOk();

    expect(dateValue($session->refresh()->last_seen_at)->toDateTimeString())->toBe('2026-01-01 12:03:00')
        ->and($session->status)->toBe(AgentSessionStatus::Active)

        // Contact on its own is not a change to report: the feed holds the session's enrollment
        // and nothing else
        ->and(FleetEvent::query()->count())->toBe(1);
})->with([
    'reading its own session' => ['robot-council.agent.session', 'GET'],
    'reading the feed' => ['robot-council.events.index', 'GET'],
    'the heartbeat' => ['robot-council.agent.heartbeat', 'POST'],
]);

it('states both thresholds as durations on the heartbeat', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    $response = $this->machine($token)->postJson(route('robot-council.agent.heartbeat'));

    $response->assertOk()->assertExactJson([
        'session_id' => 1,
        'status' => 'active',
        'stale_in' => 300,
        'gone_in' => 1800,
    ]);
});

it('refuses a heartbeat from anything but a live agent session', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    // An installation credential, a signed-in human, and nothing at all
    $this->machine($this->credential)->postJson(route('robot-council.agent.heartbeat'))->assertUnauthorized();
    $this->actingAs($this->developer, 'web')->postJson(route('robot-council.agent.heartbeat'))->assertUnauthorized();
    $this->postJson(route('robot-council.agent.heartbeat'))->assertUnauthorized();

    $this->markSessionGone($session);

    $this->machine($token)->postJson(route('robot-council.agent.heartbeat'))->assertUnauthorized();
});

it('marks a session stale once it has been quiet for the configured minutes', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    // One second short of the threshold, which is the boundary a `<` would get wrong
    $this->travelTo(Carbon::parse('2026-01-01 12:04:59'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active);

    $this->travelTo(Carbon::parse('2026-01-01 12:05:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 1, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Stale)

        // The contact time is what the next threshold is measured from, so the sweep must not
        // touch it: a transition that reset it would restart the clock deciding when it goes
        ->and(dateValue($session->last_seen_at)->toDateTimeString())->toBe(STARTED_AT)
        ->and(eventsOfType(FleetEventType::SessionStale))->toBe(1);
});

it('ends a session that has been quiet past the gone threshold, straight from active', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:30:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone)

        // One event for one change. A session silent past both thresholds is not marked stale on
        // the way past, which would put two changes in the feed for one thing happening.
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(1)
        ->and(eventsOfType(FleetEventType::SessionStale))->toBe(0)

        // Its tokens go with it, and the one it holds is refused
        ->and(PersonalAccessToken::query()->where('tokenable_type', new AgentSession()->getMorphClass())->count())->toBe(0);

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertUnauthorized();
});

it('brings a stale session back on its next request, once', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Stale);

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJsonPath('status', 'active');

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(dateValue($session->last_seen_at)->toDateTimeString())->toBe('2026-01-01 12:07:00')
        ->and(eventsOfType(FleetEventType::SessionResumed))->toBe(1);

    // A second request is contact, not a second change
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    expect(eventsOfType(FleetEventType::SessionResumed))->toBe(1);
});

it("leaves a session active when contact lands between the sweep's read and its write", function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    $injected = 0;

    // Fired after the sweep's read of the candidates has returned and before it writes anything,
    // which is the window a real request lands in. Filtered to selects against the sessions table:
    // a listener that fired on the first query of any kind would land somewhere else entirely,
    // because the rate limiter's cache store alone can issue a dozen queries first.
    DB::listen(function (QueryExecuted $query) use (&$injected, $session): void {
        if ($injected > 0 || ! str_contains($query->sql, 'robot_council_agent_sessions')) {
            return;
        }

        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $injected++;

        DB::table('robot_council_agent_sessions')
            ->where('id', $session->getKey())
            ->update(['last_seen_at' => Carbon::now()]);
    });

    $swept = $this->service(SessionPresence::class)->sweep();

    // The instrument fired: without this the assertions below would pass on a sweep that read
    // nothing, which is what a silently broken filter looks like
    expect($injected)->toBe(1)
        ->and($swept)->toBe(['stale' => 0, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(0);
});

it('ends that same session when nothing lands in between', function (): void {
    // The control for the injection test above: same fixture, same clock, no listener. Without it
    // an assertion that the session is still active proves nothing about the injection.
    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone);
});

it('dispatches SessionGone once for each session that goes, and never twice', function (): void {
    Event::fake([SessionGone::class]);

    [$first] = $this->startAgentSession($this->installation);
    [$second] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 2]);

    // A second sweep finds them already gone, so it changes nothing and says nothing
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0]);

    Event::assertDispatchedTimes(SessionGone::class, 2);

    foreach ([$first, $second] as $session) {
        Event::assertDispatched(
            SessionGone::class,
            fn (SessionGone $event): bool => $event->session->getKey() === $session->getKey()
                && $event->reason === SessionPresence::TIMEOUT
        );
    }

    expect(eventsOfType(FleetEventType::SessionGone))->toBe(2);
});

it('dispatches SessionGone only once the transaction that ended the session has committed', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $openTransactions = null;
    $statusWhenHeard = null;

    Event::listen(function (SessionGone $event) use (&$openTransactions, &$statusWhenHeard): void {
        $openTransactions = DB::transactionLevel();

        $statusWhenHeard = AgentSession::query()->whereKey($event->session->getKey())->value('status');
    });

    $this->service(SessionPresence::class)->end($session);

    // A listener that ran inside the transaction could release a claim that a rollback then
    // un-ended, and #25 and #26 register exactly that kind of listener
    expect($openTransactions)->toBe(0)
        ->and($statusWhenHeard)->toBe(AgentSessionStatus::Gone);
});

it('runs every registered release step on every sweep, including one that changed nothing', function (): void {
    $runs = 0;

    $this->service(SessionReleases::class)->register(function () use (&$runs): void {
        $runs++;
    });

    expect($this->service(SessionReleases::class)->count())->toBe(1);

    $swept = $this->service(SessionPresence::class)->sweep();
    $this->service(SessionPresence::class)->sweep();

    // Ran on both sweeps, and the sweeps had nothing to mark -- which is the case the steps exist
    // for: a release a missed `SessionGone` left undone is picked up by the next sweep, not by the
    // next session that happens to end.
    expect($runs)->toBe(2)
        ->and($swept)->toBe(['stale' => 0, 'gone' => 0]);
});

it('ends a session through the installation credential, and releases its tokens', function (): void {
    Event::fake([SessionGone::class]);

    [$session, $token] = $this->startAgentSession($this->installation);

    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertOk()
        ->assertExactJson(['session_id' => $session->getKey(), 'status' => 'gone']);

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(1);

    Event::assertDispatchedTimes(SessionGone::class, 1);

    Event::assertDispatched(
        SessionGone::class,
        fn (SessionGone $event): bool => $event->reason === SessionPresence::ENDED
    );

    // The session's own token stops working, and the installation cannot renew it back into service
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertStatus(409);
});

it('answers the same way when a session is ended twice, and dispatches nothing the second time', function (): void {
    Event::fake([SessionGone::class]);

    [$session] = $this->startAgentSession($this->installation);

    $route = route('robot-council.sessions.end', ['session' => $session->getKey()]);

    $this->machine($this->credential)->deleteJson($route)->assertOk();

    // A bridge that is already exiting has nothing useful to do with a 409, and the statement it
    // is making -- this session is over -- is true either way
    $this->machine($this->credential)->deleteJson($route)->assertOk()->assertJsonPath('status', 'gone');

    Event::assertDispatchedTimes(SessionGone::class, 1);

    expect(eventsOfType(FleetEventType::SessionGone))->toBe(1);
});

it("refuses to end another installation's session", function (): void {
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    [$session] = $this->startAgentSession($other);

    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertForbidden();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active)
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(0);

    // The installation that started it can
    $this->machine($this->installationCredential($other))
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertOk();
});

it('answers 404 for a session that cannot be ended because it does not exist', function (string $id): void {
    $this->machine($this->credential)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $id]))
        ->assertNotFound();
})->with([
    'a number nobody used' => ['987654'],

    // SQLite matches no rows and Postgres raises `22P02 invalid input syntax for bigint`, which
    // without the route constraint is a 500 on the database CI runs
    'not a number at all' => ['not-a-number'],
]);

it('refuses a session token on the end endpoint', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    // The credential that may end a session is the installation's, not the session's own
    $this->machine($token)
        ->deleteJson(route('robot-council.sessions.end', ['session' => $session->getKey()]))
        ->assertUnauthorized();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Active);
});

it('writes exactly one event for each status change across a whole session', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:06:00'));
    $this->service(SessionPresence::class)->sweep();

    $this->travelTo(Carbon::parse('2026-01-01 12:07:00'));
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    $this->travelTo(Carbon::parse('2026-01-01 12:13:00'));
    $this->service(SessionPresence::class)->sweep();

    $this->travelTo(Carbon::parse('2026-01-01 12:38:00'));
    $this->service(SessionPresence::class)->sweep();

    expect($session->refresh()->status)->toBe(AgentSessionStatus::Gone);

    $recorded = FleetEvent::query()->orderBy('id')->pluck('type')->all();

    expect($recorded)->toBe([
        FleetEventType::SessionEnrolled,
        FleetEventType::SessionStale,
        FleetEventType::SessionResumed,
        FleetEventType::SessionStale,
        FleetEventType::SessionGone,
    ]);
});

it('honors configured thresholds, and never lets gone come before stale', function (): void {
    config()->set('robot-council.presence.stale_after_minutes', 10);
    config()->set('robot-council.presence.gone_after_minutes', 2);

    [$session] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:09:00'));

    // Nine minutes is past the configured gone threshold and short of the stale one. Read as
    // written, the session would be gone without ever having been stale.
    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Active);

    $this->travelTo(Carbon::parse('2026-01-01 12:10:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 1])
        ->and($session->refresh()->status)->toBe(AgentSessionStatus::Gone);
});

it('leaves a session that has already gone alone', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->service(SessionPresence::class)->end($session);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect($this->service(SessionPresence::class)->sweep())->toBe(['stale' => 0, 'gone' => 0])
        ->and(eventsOfType(FleetEventType::SessionGone))->toBe(1);
});

it('reports what it marked, through the command', function (): void {
    [$stale] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:26:00'));

    [$fresh] = $this->startAgentSession($this->installation);

    $this->travelTo(Carbon::parse('2026-01-01 12:31:00'));

    expect(Artisan::call('robot-council:sweep-sessions'))->toBe(0)
        ->and(Artisan::output())->toContain('Marked 1 session(s) stale and 1 gone.')
        ->and($stale->refresh()->status)->toBe(AgentSessionStatus::Gone)
        ->and($fresh->refresh()->status)->toBe(AgentSessionStatus::Stale);
});

it('schedules the sweep every minute', function (): void {
    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:sweep-sessions'))
        ->values();

    expect($scheduled)->toHaveCount(1);

    $sweep = $scheduled->first();

    expect($sweep)->toBeInstanceOf(ScheduledEvent::class)
        ->and($sweep instanceof ScheduledEvent ? $sweep->expression : null)->toBe('* * * * *');
});

it('adds nothing to the schedule when the host turns the sweep off', function (): void {
    // Set before the application boots: the provider reads it as it registers the schedule, so a
    // value set in a test body would arrive after the decision it is meant to change
    $this->rebootWith('robot-council.schedule.sweep_sessions', false);

    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:sweep-sessions'));

    expect($scheduled)->toBeEmpty()

        // And the prune, which has its own switch, is still there
        ->and(collect($this->service(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'robot-council:prune-device-codes'))
        )->toHaveCount(1);
});
