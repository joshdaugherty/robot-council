<?php

declare(strict_types=1);

/**
 * GitHub sign-in: which accounts the callback admits, what it records on the host's users table,
 * and what it refuses. GitHub's responses come from `Socialite::fake()`.
 *
 * @command  vendor/bin/pest --compact tests/GitHubSignInTest.php
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Cookie;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
});

it('redirects a visitor to GitHub', function (): void {
    Socialite::fake('github');

    $this->get(route('robot-council.auth.redirect'))
        ->assertRedirect('https://socialite.fake/github/authorize');
});

it('signs in a developer on the access list and records the account', function (): void {
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242));

    $response = $this->get(route('robot-council.auth.callback'));

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    $user = DB::table('users')->sole();

    expect($user->github_id)->toEqual(4242)
        ->and($user->github_login)->toBe('octodev')
        ->and($user->name)->toBe('Octo Dev')
        ->and($user->email)->toBe('octo@example.com')
        ->and($user->avatar_url)->toBe('https://avatars.example.com/u/4242');
});

it('refuses an account on neither access list, creating no user', function (): void {
    $this->setAccessLists(developers: [4242], admins: [99]);
    Socialite::fake('github', githubAccount(1234));

    $this->get(route('robot-council.auth.callback'))->assertForbidden();

    $this->assertGuest();
    expect(DB::table('users')->count())->toBe(0);
});

it('signs in an admin, who needs no separate developer entry', function (): void {
    $this->setAccessLists(admins: [77]);
    Socialite::fake('github', githubAccount(77));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();
});

it('updates the existing row on a repeat sign-in', function (): void {
    $this->setAccessLists(developers: [4242]);

    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    // Sign in again after the account renamed itself and changed its display name
    Socialite::fake('github', githubAccount(4242, login: 'octodev2', name: 'Octo Dev II'));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    expect(DB::table('users')->count())->toBe(1);

    $user = DB::table('users')->sole();

    expect($user->github_login)->toBe('octodev2')
        ->and($user->name)->toBe('Octo Dev II');
});

it('signs in an account with no display name and no verified email', function (): void {
    $this->setAccessLists(developers: [555]);
    Socialite::fake('github', githubAccount(555, login: 'quietdev', name: null, email: null));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();

    $user = DB::table('users')->sole();

    expect($user->name)->toBe('quietdev')
        ->and($user->email)->toBeNull();
});

it('refuses to claim an existing account that shares the email address', function (): void {
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242, email: 'taken@example.com'));

    // An account that predates the package, with no GitHub ID of its own
    DB::table('users')->insert([
        'name' => 'Someone Else',
        'email' => 'taken@example.com',
        'password' => 'irrelevant-hash',
    ]);

    $this->get(route('robot-council.auth.callback'))->assertStatus(409);

    $this->assertGuest();

    $user = DB::table('users')->sole();

    expect($user->name)->toBe('Someone Else')
        ->and($user->github_id)->toBeNull();
});

it('signs the developer in on the web guard without a remember-me cookie', function (): void {
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242));

    $response = $this->get(route('robot-council.auth.callback'));

    // The session and CSRF cookies are expected; a remember-me cookie is not
    $remembered = array_values(array_filter(
        $response->headers->getCookies(),
        fn (Cookie $cookie): bool => str_starts_with($cookie->getName(), 'remember_')
    ));

    expect(Auth::guard('web')->check())->toBeTrue()
        ->and($remembered)->toBeEmpty();
});
