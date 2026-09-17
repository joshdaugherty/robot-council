<?php

declare(strict_types=1);

/**
 * The access lists as a live gate: the human-route middleware and the `robot-council-admin`
 * ability both read configuration on every request, so removing an ID takes effect immediately.
 *
 * @command  vendor/bin/pest --compact tests/AllowlistAccessTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\RobotCouncilServiceProvider;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    // A route standing in for the human-facing pages later slices add
    Route::middleware(['web', EnsureAllowlistedDeveloper::class])
        ->get('/robot-council-test/developer-area', fn (): string => 'developer area');
});

/**
 * Create a host user enrolled for a GitHub account.
 *
 * @param  int  $githubId  The account's numeric GitHub user ID.
 * @return User The saved user.
 */
function enrolledUser(int $githubId): User
{
    $user = new User;

    $user->forceFill([
        'name' => 'Octo Dev',
        'email' => sprintf('octo+%d@example.com', $githubId),
        'github_id' => $githubId,
        'github_login' => 'octodev',
    ])->save();

    return $user;
}

it('admits a developer on the access list', function (): void {
    $this->setAccessLists(developers: [4242]);

    $this->actingAs(enrolledUser(4242))
        ->get('/robot-council-test/developer-area')
        ->assertOk()
        ->assertSee('developer area');
});

it('refuses a developer removed from the access list and ends the session', function (): void {
    $this->setAccessLists(developers: [4242]);
    $user = enrolledUser(4242);

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertOk();

    // The developer leaves, and their ID comes off the list
    $this->setAccessLists(developers: []);

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertForbidden();

    $this->assertGuest();
});

it('refuses a signed-in user that has no GitHub account recorded', function (): void {
    $this->setAccessLists(developers: [4242]);

    $user = new User;
    $user->forceFill(['name' => 'Local Admin', 'email' => 'local@example.com'])->save();

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertForbidden();
});

it('sends a visitor who is not signed in to GitHub', function (): void {
    $this->get('/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('grants the admin ability only while the ID is listed as an admin', function (): void {
    $this->setAccessLists(developers: [4242], admins: [4242]);
    $user = enrolledUser(4242);

    expect(Gate::forUser($user)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeTrue();

    // Demote the developer, who keeps developer access
    $this->setAccessLists(developers: [4242], admins: []);

    expect(Gate::forUser($user)->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeFalse();

    $this->actingAs($user)->get('/robot-council-test/developer-area')->assertOk();
});

it('denies the admin ability to a developer who was never an admin', function (): void {
    $this->setAccessLists(developers: [4242], admins: [99]);

    expect(Gate::forUser(enrolledUser(4242))->allows(RobotCouncilServiceProvider::ADMIN_ABILITY))->toBeFalse();
});
