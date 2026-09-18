<?php

declare(strict_types=1);

/**
 * Who is alive in the fleet, and what they are holding.
 *
 * @command  vendor/bin/pest --compact tests/FleetPresenceTest.php
 */

use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RobotCouncil\Access\Ability;
use RobotCouncil\Livewire\FleetPresence;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Lock;
use RobotCouncil\Support\Locks;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);

    $this->installation = $this->approveInstallation($this->developer, [
        Ability::LocksAcquire->value,
    ], machineLabel: 'workbench-01');

    [$this->session, $this->token] = $this->startAgentSession($this->installation);

    $this->actingAs($this->developer, 'web');
});

it('lists a session with its developer, machine, harness and contact time', function (): void {
    Livewire::test(FleetPresence::class)
        ->assertSee('octodev')
        ->assertSee('workbench-01')
        ->assertSee('claude-code')
        ->assertSeeHtml('<span class="badge badge-sm">'.AgentSessionStatus::Active->value.'</span>');
});

it('tells a stale session from a live one, and a gone one from both', function (string $status): void {
    // Read from the row rather than derived from the contact time. #24 made the row the decision,
    // and a view that re-derived it would disagree with the sweep for as long as the sweep had not
    // run -- showing `stale` while every conditional update still treated the session as active.
    $this->session->forceFill(['status' => $status])->save();

    Livewire::test(FleetPresence::class)
        ->assertSeeHtml('<span class="badge badge-sm">'.$status.'</span>');
})->with([
    'active' => AgentSessionStatus::Active->value,
    'stale' => AgentSessionStatus::Stale->value,
    'gone' => AgentSessionStatus::Gone->value,
]);

it('keeps a gone session on the page rather than hiding it', function (): void {
    // A session that has ended is exactly what a developer is looking for when a task sits held and
    // nothing moves, which is why #24 keeps the row rather than deleting it.
    $this->session->forceFill(['status' => AgentSessionStatus::Gone->value])->save();

    Livewire::test(FleetPresence::class)->assertSee('workbench-01');
});

it('lists a held lock with its holder, fence and lease', function (): void {
    app(Locks::class)->acquire($this->session, 'deploy', 60, asCoordinator: false);

    Livewire::test(FleetPresence::class)
        ->assertSee('deploy')
        ->assertSee('octodev')
        ->assertSeeHtml('<td>1</td>')
        ->assertSee('expires');
});

it('shows a lapsed lease as lapsed rather than hiding the row', function (): void {
    app(Locks::class)->acquire($this->session, 'deploy', 60, asCoordinator: false);

    // Past the lease without releasing it: the row still names a holder, which is the state a
    // developer is looking for and the one a filtered list would conceal
    Lock::query()->where('name', 'deploy')->update(['expires_at' => Carbon::now()->subMinute()]);

    $rendered = Livewire::test(FleetPresence::class);

    $rendered->assertSee('deploy')->assertSee('lapsed')->assertDontSee('expires in');
});

it('renders a hostile machine label as text', function (): void {
    // Written past the endpoint's validation deliberately: `machine_label` is charset-limited and
    // this string cannot arrive through the API, so the page's escaping is its own guarantee.
    $this->installation->forceFill(['machine_label' => '<script>alert(1)</script>'])->save();

    $html = Livewire::test(FleetPresence::class)->html();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('polls on the interval the dashboard resolved', function (): void {
    Livewire::test(FleetPresence::class)->assertSeeHtml('wire:poll.5s');

    Livewire::test(FleetPresence::class, ['pollSeconds' => 30])->assertSeeHtml('wire:poll.30s');
});

it('will not let a client change the polling interval', function (): void {
    Livewire::test(FleetPresence::class)->set('pollSeconds', 0);
})->throws(CannotUpdateLockedPropertyException::class);

it('shows presence through the gate, and refuses a stranger', function (): void {
    // `Livewire::test()` runs no HTTP middleware, so every case above would pass with the
    // `actingAs` in `beforeEach` deleted. This one goes through the route.
    $this->get(route('robot-council.dashboard'))->assertOk()->assertSee('workbench-01');

    $stranger = $this->enrollDeveloper(9999, login: 'stranger');

    $this->actingAs($stranger, 'web')
        ->get(route('robot-council.dashboard'))
        ->assertForbidden()
        ->assertDontSee('workbench-01');
});
