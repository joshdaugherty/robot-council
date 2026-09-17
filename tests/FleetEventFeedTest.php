<?php

declare(strict_types=1);

/**
 * The fleet's change feed: what an agent may write to it, and what it may read back.
 *
 * The visibility rule decided in #29 is the reason this file is long. Task, event, and directive
 * content is untrusted input to an agent that may have shell access, so whose words reach whom is a
 * security boundary rather than a preference.
 *
 * @command  vendor/bin/pest --compact tests/FleetEventFeedTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242, 77]);

    // Two developers, so "another developer's narration" is a thing that exists
    $this->mine = $this->enrollDeveloper(4242, login: 'octodev');
    $this->theirs = $this->enrollDeveloper(77, login: 'otherdev');
});

/**
 * Start a session for a developer, with the abilities its token should carry.
 *
 * @param  User  $developer  Whose session it is.
 * @param  list<string>  $abilities  What its token carries.
 * @return array{AgentSession, string} The session and its token.
 */
function sessionFor(TestCase $case, User $developer, array $abilities): array
{
    $installation = $case->approveInstallation($developer, $abilities, machineLabel: 'm-'.keyValue($developer->getKey()));

    return $case->startAgentSession($installation);
}

it('refuses narration from a session whose token lacks the ability', function (): void {
    [, $token] = sessionFor($this, $this->mine, [Ability::TasksCreate->value]);

    $this->machine($token)
        ->postJson(route('robot-council.events.store'), ['body' => 'working on it'])
        ->assertForbidden();

    expect(FleetEvent::query()->where('type', FleetEventType::Narration->value)->count())->toBe(0);
});

it('stores narration as narration, attributed to the session that posted it', function (): void {
    [$session, $token] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);

    $this->machine($token)
        ->postJson(route('robot-council.events.store'), [
            'body' => 'reading the migration',
            'meta' => ['file' => 'database/migrations/x.php'],

            // What an agent would send to dress its opinion as fleet state
            'type' => FleetEventType::SessionEnrolled->value,
            'posted_with_coordinator' => true,
            'agent_session_id' => 9999,
        ])
        ->assertCreated()
        ->assertJson(['type' => FleetEventType::Narration->value]);

    $event = FleetEvent::query()->where('type', FleetEventType::Narration->value)->sole();

    expect($event->type)->toBe(FleetEventType::Narration)
        ->and($event->agent_session_id)->toBe($session->id)
        ->and($event->posted_with_coordinator)->toBeFalse()

        // Anything the client sent is kept apart from anything the server derived
        ->and($event->meta)->toBe(['client' => ['file' => 'database/migrations/x.php']]);
});

it('refuses a narration body over the size limit', function (): void {
    [, $token] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);

    $this->machine($token)
        ->postJson(route('robot-council.events.store'), ['body' => str_repeat('a', 4001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('refuses a directive from a session without the coordinator ability', function (): void {
    [, $token] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertForbidden();

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->count())->toBe(0);
});

it('records a directive from a coordinator', function (): void {
    [, $token] = sessionFor($this, $this->mine, [Ability::CoordinatorDirect->value]);

    $this->machine($token)
        ->postJson(route('robot-council.directives.store'), ['body' => 'everyone stop'])
        ->assertCreated()
        ->assertJson(['type' => FleetEventType::Directive->value]);

    expect(FleetEvent::query()->where('type', FleetEventType::Directive->value)->sole()->posted_with_coordinator)
        ->toBeTrue();
});

it('writes one session.enrolled event when a session starts', function (): void {
    [$session] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);

    $enrolled = FleetEvent::query()->where('type', FleetEventType::SessionEnrolled->value)->get();

    expect($enrolled)->toHaveCount(1);

    $first = $enrolled->firstOrFail();

    expect($first->agent_session_id)->toBe($session->id)
        ->and($first->posted_with_coordinator)->toBeFalse();
});

it('pages the whole feed in ID order, exactly once, with provenance', function (): void {
    [$session, $token] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);

    foreach (range(1, 5) as $n) {
        $this->machine($token)
            ->postJson(route('robot-council.events.store'), ['body' => "step $n"])
            ->assertCreated();
    }

    $seen = [];
    $cursor = 0;

    // Two at a time, as a helper with a small window would
    do {
        $page = $this->machine($token)
            ->getJson(route('robot-council.events.index', ['after' => $cursor, 'limit' => 2]))
            ->assertOk();

        $events = arrayValue($page->json('events'));

        foreach ($events as $event) {
            $seen[] = intValue(arrayValue($event)['id']);
        }

        $cursor = $page->json('cursor');
    } while ($events !== []);

    // Every event once, in order, with nothing repeated across page boundaries
    expect($seen)->toBe(FleetEvent::query()->orderBy('id')->pluck('id')->all())
        ->and($seen)->toBe(array_values(array_unique($seen)));

    $first = arrayValue($this->machine($token)->getJson(route('robot-council.events.index'))->json('events.0'));

    expect(arrayValue($first['actor']))->toBe([
        'session_id' => $session->id,
        'github_login' => 'octodev',
        'coordinator_direct' => false,
    ]);
});

it("hides another developer's narration, and shows everything else", function (): void {
    [, $mine] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);
    [, $theirs] = sessionFor($this, $this->theirs, [Ability::EventsPost->value]);

    $this->machine($theirs)
        ->postJson(route('robot-council.events.store'), ['body' => 'their private narration'])
        ->assertCreated();

    $this->machine($mine)
        ->postJson(route('robot-council.events.store'), ['body' => 'my own narration'])
        ->assertCreated();

    $bodies = collect(arrayValue($this->machine($mine)->getJson(route('robot-council.events.index'))->json('events')))
        ->pluck('body')
        ->filter()
        ->all();

    expect($bodies)->toContain('my own narration')
        ->not->toContain('their private narration');

    // The control: their session exists and did post, so the absence above is the rule firing
    // rather than nothing having been written
    expect(FleetEvent::query()->where('body', 'their private narration')->count())->toBe(1);
});

it("shows another developer's narration when they held the coordinator ability", function (): void {
    [, $mine] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);
    [, $theirs] = sessionFor($this, $this->theirs, [Ability::EventsPost->value, Ability::CoordinatorDirect->value]);

    $this->machine($theirs)
        ->postJson(route('robot-council.events.store'), ['body' => 'coordinating from over here'])
        ->assertCreated();

    $events = collect(arrayValue($this->machine($mine)->getJson(route('robot-council.events.index'))->json('events')));

    $coordinated = arrayValue($events->firstWhere('body', 'coordinating from over here'));

    $actor = arrayValue($coordinated['actor']);

    expect($actor['github_login'])->toBe('otherdev')
        ->and($actor['coordinator_direct'])->toBeTrue()
        ->and($actor['session_id'])->toBeInt();
});

it('keeps showing narration posted while the ability was held, after it is revoked', function (): void {
    [, $mine] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);

    $theirInstallation = $this->approveInstallation(
        $this->theirs,
        [Ability::EventsPost->value, Ability::CoordinatorDirect->value],
        machineLabel: 'theirs'
    );

    [, $theirs] = $this->startAgentSession($theirInstallation);

    $this->machine($theirs)
        ->postJson(route('robot-council.events.store'), ['body' => 'said while coordinating'])
        ->assertCreated();

    // The admin takes the ability away, which rewrites the live session tokens
    Artisan::call('robot-council:revoke-ability', [
        'installation' => $theirInstallation->getKey(),
        'ability' => Ability::CoordinatorDirect->value,
    ]);

    $this->machine($theirs)
        ->postJson(route('robot-council.events.store'), ['body' => 'said after losing it'])
        ->assertCreated();

    $bodies = collect(arrayValue($this->machine($mine)->getJson(route('robot-council.events.index'))->json('events')))
        ->pluck('body')
        ->filter()
        ->all();

    // What was said while the ability was held stays visible; what came after does not. The flag
    // is recorded on the event, so revocation is not retroactive in either direction.
    expect($bodies)->toContain('said while coordinating')
        ->not->toContain('said after losing it');
});

it('shows every state change and directive to everyone', function (): void {
    [, $mine] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);
    [, $theirs] = sessionFor($this, $this->theirs, [Ability::CoordinatorDirect->value]);

    $this->machine($theirs)
        ->postJson(route('robot-council.directives.store'), ['body' => 'freeze the main branch'])
        ->assertCreated();

    $events = collect(arrayValue($this->machine($mine)->getJson(route('robot-council.events.index'))->json('events')));

    expect($events->pluck('body')->filter()->all())->toContain('freeze the main branch');

    // Their session starting is a state change, and it reaches this reader too, even though the
    // session belongs to another developer and held no coordinator ability
    expect($events->pluck('type')->all())->toContain(FleetEventType::SessionEnrolled->value);

    $foreign = $events->filter(function (mixed $event): bool {
        $event = arrayValue($event);

        return $event['type'] === FleetEventType::SessionEnrolled->value
            && arrayValue($event['actor'])['github_login'] === 'otherdev';
    });

    expect($foreign)->not->toBeEmpty();
});

it('rate limits one session without limiting another', function (): void {
    config()->set('robot-council.rate_limits.agent_per_session', 3);

    [, $mine] = sessionFor($this, $this->mine, [Ability::EventsPost->value]);
    [, $theirs] = sessionFor($this, $this->theirs, [Ability::EventsPost->value]);

    for ($post = 0; $post < 3; $post++) {
        $this->machine($mine)
            ->postJson(route('robot-council.events.store'), ['body' => "post $post"])
            ->assertCreated();
    }

    $this->machine($mine)
        ->postJson(route('robot-council.events.store'), ['body' => 'one too many'])
        ->assertStatus(429);

    // Keyed on the session, so the fleet does not share one allowance
    $this->machine($theirs)
        ->postJson(route('robot-council.events.store'), ['body' => 'unaffected'])
        ->assertCreated();
});
