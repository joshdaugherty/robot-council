<?php

declare(strict_types=1);

/**
 * GitHub sign-in: which accounts the callback admits, what it records, and what it refuses.
 * GitHub's responses come from `Socialite::fake()`, except where the state parameter is the
 * subject, which needs the real provider.
 *
 * @command  vendor/bin/pest --compact tests/GitHubSignInTest.php
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use RobotCouncil\Models\GithubIdentity;
use Symfony\Component\HttpFoundation\Cookie;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();
});

it('sends the visitor to GitHub with a state parameter it can check later', function (): void {
    $location = $this->get(route('robot-council.auth.redirect'))->headers->get('Location') ?? '';

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://github.com/login/oauth/authorize')
        ->and($query['state'] ?? null)->toBe(session()->get('state'))
        ->and($query['state'] ?? null)->not->toBeEmpty();
});

it('refuses a callback whose state does not match the session', function (): void {
    $this->withoutExceptionHandling();

    expect(fn () => $this->withSession(['state' => 'the-state-we-issued'])
        ->get(route('robot-council.auth.callback', ['state' => 'a-forged-state', 'code' => 'irrelevant'])))
        ->toThrow(InvalidStateException::class);
});

it('signs in a developer on the access list and records the account', function (): void {
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();

    $identity = GithubIdentity::query()->sole();

    expect($identity->github_id)->toBe(4242)
        ->and($identity->github_login)->toBe('octodev')
        ->and($identity->avatar_url)->toBe('https://avatars.example.com/u/4242');

    // The host's users table carries no package columns
    $user = DB::table('users')->sole();

    expect($user->name)->toBe('octodev')
        ->and($user->email)->toBe('octo@example.com')
        ->and($identity->user_id)->toBe($user->id);
});

it('refuses an account on neither access list, creating nothing', function (): void {
    $this->setAccessLists(developers: [4242], admins: [99]);
    Socialite::fake('github', githubAccount(1234));

    $this->get(route('robot-council.auth.callback'))->assertForbidden();

    $this->assertGuest();

    expect(DB::table('users')->count())->toBe(0)
        ->and(GithubIdentity::query()->count())->toBe(0);
});

it('leaves an enrolled developer untouched once their ID comes off the list', function (): void {
    $this->setAccessLists(developers: [4242]);
    $this->enrollDeveloper(4242, login: 'octodev');

    // The developer leaves, and a later callback arrives for the same account
    $this->setAccessLists(developers: []);
    Socialite::fake('github', githubAccount(4242, login: 'renamed'));

    $this->get(route('robot-council.auth.callback'))->assertForbidden();

    expect(GithubIdentity::query()->sole()->github_login)->toBe('octodev')
        ->and(DB::table('users')->count())->toBe(1);
});

it('signs in an admin, who needs no separate developer entry', function (): void {
    $this->setAccessLists(admins: [77]);
    Socialite::fake('github', githubAccount(77));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();
});

it('updates the identity on a repeat sign-in, without a second user', function (): void {
    $this->setAccessLists(developers: [4242]);

    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    // Sign in again after the account renamed itself
    Socialite::fake('github', githubAccount(4242, login: 'octodev2'));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    expect(DB::table('users')->count())->toBe(1)
        ->and(GithubIdentity::query()->count())->toBe(1)
        ->and(GithubIdentity::query()->sole()->github_login)->toBe('octodev2');
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

    // An account that predates the package, with no identity of its own
    DB::table('users')->insert([
        'name' => 'Someone Else',
        'email' => 'taken@example.com',
        'password' => 'irrelevant-hash',
    ]);

    $this->get(route('robot-council.auth.callback'))->assertStatus(409);

    $this->assertGuest();

    expect(DB::table('users')->sole()->name)->toBe('Someone Else')
        ->and(GithubIdentity::query()->count())->toBe(0);
});

it('refuses an existing account that shares the email in another case', function (): void {
    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242, email: 'taken@example.com'));

    DB::table('users')->insert([
        'name' => 'Someone Else',
        'email' => 'Taken@Example.COM',
        'password' => 'irrelevant-hash',
    ]);

    $this->get(route('robot-council.auth.callback'))->assertStatus(409);

    expect(DB::table('users')->count())->toBe(1)
        ->and(GithubIdentity::query()->count())->toBe(0);
});

it('refuses a known identity whose user row has gone', function (): void {
    $this->setAccessLists(developers: [4242]);

    // An identity left behind by a user the host removed without cascading
    GithubIdentity::query()->create([
        'user_id' => 9999,
        'github_id' => 4242,
        'github_login' => 'octodev',
    ]);

    Socialite::fake('github', githubAccount(4242));

    $this->get(route('robot-council.auth.callback'))->assertStatus(409);

    $this->assertGuest();
});

it('signs the developer in without a remember-me cookie', function (): void {
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

it('signs in on the package guard even when the host defaults to another', function (): void {
    // A host application whose default guard is not `web`
    config()->set('auth.guards.api', ['driver' => 'token', 'provider' => 'users']);
    config()->set('auth.defaults.guard', 'api');

    $this->setAccessLists(developers: [4242]);
    Socialite::fake('github', githubAccount(4242));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    expect(Auth::guard('web')->check())->toBeTrue();
});
