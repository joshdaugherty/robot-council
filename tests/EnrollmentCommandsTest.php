<?php

declare(strict_types=1);

/**
 * The admin commands: granting and revoking one ability, revoking an installation or a session, and
 * pruning expired device codes.
 *
 * Each is checked by what happens to a live credential on the next request, not only by what the
 * rows say. A revocation that leaves a token working is not a revocation.
 *
 * @command  vendor/bin/pest --compact tests/EnrollmentCommandsTest.php
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer, [Ability::TasksCreate->value]);
    $this->credential = $this->installationCredential($this->installation);
});

it('grants the coordinator ability, which enrollment can never ask for', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::CoordinatorDirect->value,
    ]))->toBe(0);

    // The installation carries it, and so does the token that was already in flight
    expect($this->installation->refresh()->granted_abilities)
        ->toBe([Ability::TasksCreate->value, Ability::CoordinatorDirect->value]);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['abilities' => [Ability::TasksCreate->value, Ability::CoordinatorDirect->value]]);
});

it('revokes an ability from the tokens already in flight, not only from the next one', function (): void {
    Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::EventsPost->value,
    ]);

    [, $token] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:revoke-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::EventsPost->value,
    ]))->toBe(0);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson(['abilities' => [Ability::TasksCreate->value]]);

    expect($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value]);
});

it('refuses an ability outside the fixed list', function (string $command, string $ability): void {
    expect(Artisan::call($command, [
        'installation' => $this->installation->getKey(),
        'ability' => $ability,
    ]))->toBe(1)
        ->and($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value]);
})->with([
    'the wildcard, granted' => ['robot-council:grant-ability', '*'],
    'the wildcard, revoked' => ['robot-council:revoke-ability', '*'],
    'an invention' => ['robot-council:grant-ability', 'tasks:delete'],

    // An installation credential's own ability, which belongs to no session token
    'the installation credential' => ['robot-council:grant-ability', Ability::SessionsStart->value],
]);

it('refuses an installation that does not exist', function (string $command): void {
    expect(Artisan::call($command, ['installation' => 987654, 'ability' => Ability::EventsPost->value]))->toBe(1);
})->with(['robot-council:grant-ability', 'robot-council:revoke-ability']);

it('grants an ability the installation already holds without duplicating it', function (): void {
    Artisan::call('robot-council:grant-ability', [
        'installation' => $this->installation->getKey(),
        'ability' => Ability::TasksCreate->value,
    ]);

    expect($this->installation->refresh()->granted_abilities)->toBe([Ability::TasksCreate->value]);
});

it('revoking an installation stops its credential and every session token it issued', function (): void {
    [, $first] = $this->startAgentSession($this->installation);
    [, $second] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:revoke-installation', [
        'installation' => $this->installation->getKey(),
    ]))->toBe(0);

    $this->machine($this->credential)->postJson(route('robot-council.sessions.start'))->assertUnauthorized();
    $this->machine($first)->getJson(route('robot-council.agent.session'))->assertUnauthorized();
    $this->machine($second)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    expect($this->installation->refresh()->revoked_at)->not->toBeNull()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('revoking an installation leaves another installation alone', function (): void {
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');
    $otherCredential = $this->installationCredential($other);

    Artisan::call('robot-council:revoke-installation', ['installation' => $this->installation->getKey()]);

    $this->machine($otherCredential)->postJson(route('robot-council.sessions.start'))->assertCreated();
});

it('revoking a session ends only that session, and it can never be renewed', function (): void {
    [$revoked, $revokedToken] = $this->startAgentSession($this->installation);
    [, $keptToken] = $this->startAgentSession($this->installation);

    expect(Artisan::call('robot-council:revoke-session', ['session' => $revoked->getKey()]))->toBe(0);

    $this->machine($revokedToken)->getJson(route('robot-council.agent.session'))->assertUnauthorized();
    $this->machine($keptToken)->getJson(route('robot-council.agent.session'))->assertOk();

    // Marked gone as well, so the installation's credential cannot renew it back into service
    expect($revoked->refresh()->hasGone())->toBeTrue();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $revoked->getKey()]))
        ->assertStatus(409);
});

it('refuses a session that does not exist', function (): void {
    expect(Artisan::call('robot-council:revoke-session', ['session' => 987654]))->toBe(1);
});

it('prunes expired device codes and leaves live ones alone', function (): void {
    $live = requestDeviceCode($this);

    $this->travelTo(now()->addSeconds(601));

    $stillLive = requestDeviceCode($this);

    expect(DeviceCode::query()->count())->toBe(2)
        ->and(Artisan::call('robot-council:prune-device-codes'))->toBe(0)
        ->and(DeviceCode::query()->pluck('id')->all())->toBe([$stillLive['record']->id])
        ->and(DeviceCode::query()->whereKey($live['record']->id)->exists())->toBeFalse();
});

it('prunes an expired code whether or not it was decided', function (): void {
    $decided = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $decided['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    $this->travelTo(now()->addSeconds(601));

    Artisan::call('robot-council:prune-device-codes');

    expect(DeviceCode::query()->count())->toBe(0)

        // The installation the approval would have produced was never created, because nothing
        // exchanged the code before it expired
        ->and(Installation::query()->count())->toBe(1);
});

it('schedules the prune', function (): void {
    $scheduled = collect($this->service(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'robot-council:prune-device-codes'))
        ->values();

    expect($scheduled)->toHaveCount(1);

    $prune = $scheduled->first();

    expect($prune)->toBeInstanceOf(Event::class)
        ->and($prune instanceof Event ? $prune->expression : null)->toBe('0 * * * *');
});

it('ends a session through the store without touching its installation', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    Artisan::call('robot-council:revoke-session', ['session' => $session->getKey()]);

    expect($this->installation->refresh()->revoked_at)->toBeNull()
        ->and(AgentSession::query()->count())->toBe(1);

    // The installation can still start a new session, which is what the process does next
    $this->machine($this->credential)->postJson(route('robot-council.sessions.start'))->assertCreated();
});
