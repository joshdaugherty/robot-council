<?php

declare(strict_types=1);

/**
 * That a process being rate limited is not mistaken for a process that has stopped.
 *
 * This file exists because of a defect report that turned out to be wrong, and the reason it was
 * wrong is a property worth holding still. #52 argued that a process sustaining more than
 * `rate_limits.agent_per_session` requests a minute "gets nothing but 429s", records no contact --
 * `ThrottleRequests` is in the framework's middleware priority list and `EnsureAgentSession` is not,
 * so a refusal is answered before contact is recorded -- and is eventually marked gone with its
 * tasks and locks released while it is demonstrably alive.
 *
 * Every step of that is true except the first, and the first is load-bearing. Laravel's rate limiter
 * is a **fixed window**: `RateLimiter::hit()` sets the counter and a timer keyed to expire
 * `decaySeconds` later, so when the window lapses the counter is gone and the next
 * `agent_per_session` requests succeed. A process asking four times as often as the limit allows
 * does not get nothing; it gets its whole allowance at the top of every minute and refusals for the
 * rest of it. Those successes record contact, so the session never goes quiet.
 *
 * **The margin is one minute, and it is not an accident.** Successes recur at most 60 seconds apart
 * while a process keeps asking, and the soonest a session can be marked gone is two minutes:
 * `Credentials::rateLimit()` floors every limit at 1 through `bounded()`, and
 * `Credentials::goneAfterMinutes()` floors the threshold at `staleAfterMinutes() + 1`, which is
 * itself floored at 1. Both floors are what make the margin exist, and neither announces that this
 * is one of the things it is for.
 *
 * So these tests pin a property no other test asserts, against three ways it could be lost: a
 * limiter changed to a sliding window, a floor relaxed to admit a limit or a threshold of zero, or
 * contact moved behind something the refusal path skips.
 *
 * @command  vendor/bin/pest --compact tests/ThrottledPresenceTest.php
 */

use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\SessionPresence;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
});

it('leaves a session active through five minutes of asking four times as often as allowed', function (): void {
    config()->set('robot-council.rate_limits.agent_per_session', 3);

    // The tightest thresholds the package will read: one minute to stale, and two to gone, because
    // `goneAfterMinutes()` refuses to sit level with the stale threshold. Anything a host can
    // configure gives the session more room than this, so passing here passes everywhere.
    config()->set('robot-council.presence.stale_after_minutes', 1);
    config()->set('robot-council.presence.gone_after_minutes', 2);

    // Travel first, then start the session. A fixed past date would leave `last_seen_at` at the
    // real wall clock and therefore permanently ahead of every cutoff the travelled clock computes,
    // which is a sweep that cannot fire and a test that cannot fail. Measured: it did not.
    $start = Carbon::now()->startOfMinute();

    $this->travelTo($start);

    [$session, $token] = $this->startAgentSession($this->installation);

    $statuses = [];
    $presence = [];

    // One request every five seconds against a limit of three a minute, for five minutes
    for ($tick = 0; $tick < 60; $tick++) {
        $this->travelTo($start->copy()->addSeconds($tick * 5));

        $statuses[] = $this->machine($token)
            ->postJson(route('robot-council.agent.heartbeat'))
            ->getStatusCode();

        // The sweep runs every minute in production, so it runs every minute here
        if ($tick % 12 === 11) {
            $this->service(SessionPresence::class)->sweep();

            $presence[] = AgentSession::query()->whereKey($session->getKey())->sole()->status;
        }
    }

    // The refusals are real: without them this would prove only that the limit was never reached
    expect(array_count_values($statuses))->toBe([200 => 15, 429 => 45]);

    // The session was active at every sweep, not merely at the last one. A single end-state check
    // would pass on a session marked gone and then resumed, which is a different and worse story:
    // `SessionGone` fires on the transition, and whatever it released is already released.
    expect($presence)->toBe(array_fill(0, 5, AgentSessionStatus::Active));
});

it('records contact no more than a minute apart while a process is over the limit', function (): void {
    config()->set('robot-council.rate_limits.agent_per_session', 1);
    config()->set('robot-council.presence.stale_after_minutes', 1);
    config()->set('robot-council.presence.gone_after_minutes', 2);

    $start = Carbon::now()->startOfMinute();

    $this->travelTo($start);

    [$session, $token] = $this->startAgentSession($this->installation);

    $longestSilence = 0;

    for ($tick = 0; $tick < 60; $tick++) {
        $now = $start->copy()->addSeconds($tick * 5);

        $this->travelTo($now);

        $this->machine($token)->postJson(route('robot-council.agent.heartbeat'));

        $seen = AgentSession::query()->whereKey($session->getKey())->sole()->last_seen_at;

        $longestSilence = max($longestSilence, $seen->diffInSeconds($now));
    }

    // The number this holds still. At one request a minute allowed -- the floor `bounded()` puts
    // under any configured limit -- the fixed window still lets one through every 60 seconds, and
    // the soonest any host can have a session marked gone is 120. A sliding-window limiter would
    // push this past 120 and the assertion is what would say so.
    expect($longestSilence)->toBeLessThan(120);
});

it('has floors under the limit and the threshold that keep that margin from closing', function (): void {
    // The margin above is the gap between two floors, so a change that removed either one would
    // leave the tests above passing on their own configured values and the package unsafe on a
    // host's. Both floors are asserted here, at the values a host would have to set to close it.
    config()->set('robot-council.rate_limits.agent_per_session', 0);
    config()->set('robot-council.presence.stale_after_minutes', 0);
    config()->set('robot-council.presence.gone_after_minutes', 0);

    $credentials = $this->service(Credentials::class);

    expect($credentials->rateLimit('agent_per_session', 120))->toBe(120)
        ->and($credentials->staleAfterMinutes())->toBe(5)
        ->and($credentials->goneAfterMinutes())->toBe(30);

    // And with a threshold a host CAN set, the gone threshold still clears the one-minute margin
    config()->set('robot-council.presence.stale_after_minutes', 1);
    config()->set('robot-council.presence.gone_after_minutes', 1);

    expect($this->service(Credentials::class)->goneAfterMinutes())->toBe(2);
});
