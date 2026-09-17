<?php

declare(strict_types=1);

/**
 * Starting and renewing the session an agent process runs under, and what each of the two Sanctum
 * guards refuses.
 *
 * The refusals are the point. Sanctum's guard tries the `web` guard before it looks at a bearer
 * token, and a guard with no provider accepts a token belonging to any model at all, so "a guard
 * returned somebody" is never the same question as "the right kind of principal is here".
 *
 * @command  vendor/bin/pest --compact tests/AgentSessionTest.php
 */

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Http\Middleware\EnsureAllowlistedDeveloper;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
    $this->installation = $this->approveInstallation($this->developer);
    $this->credential = $this->installationCredential($this->installation);

    // A human-facing route, to show a bearer token reaches nothing there
    Route::middleware(['web', EnsureAllowlistedDeveloper::class])
        ->get('/robot-council-test/developer-area', fn (): string => 'developer area');
});

it("starts a session carrying the installation's abilities", function (): void {
    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'), ['project_id' => 'uams-statamic']);

    $response->assertCreated()->assertJsonStructure(['session_id', 'token', 'abilities', 'expires_in']);

    expect($response->json('abilities'))->toBe([Ability::TasksCreate->value, Ability::EventsPost->value])
        ->and($response->json('expires_in'))->toBe(3600);

    $session = AgentSession::query()->sole();

    expect($session->installation_id)->toBe($this->installation->getKey())
        ->and($session->user_id)->toBe($this->developer->getKey())
        ->and($session->project_id)->toBe('uams-statamic')
        ->and($session->hasGone())->toBeFalse();

    // The session's token expires on the session's schedule, not the installation's
    $token = PersonalAccessToken::query()->where('tokenable_type', AgentSession::class)->sole();

    expect($token->abilities)->toBe([Ability::TasksCreate->value, Ability::EventsPost->value])
        ->and($token->expires_at?->timestamp)->toBe(now()->addMinutes(60)->timestamp);
});

it('authenticates an agent route as the session, not as the developer', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertOk()
        ->assertJson([
            'session_id' => $session->getKey(),
            'installation_id' => $this->installation->getKey(),
            'status' => 'active',
            'abilities' => [Ability::TasksCreate->value, Ability::EventsPost->value],
        ]);
});

it("replaces a session's token on renewal, and refuses the one it replaced", function (): void {
    [$session, $first] = $this->startAgentSession($this->installation);

    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]));

    $response->assertOk();

    $second = stringValue($response->json('token'));

    expect($second)->not->toBe($first);

    $this->machine($second)->getJson(route('robot-council.agent.session'))->assertOk();

    $this->machine($first)->getJson(route('robot-council.agent.session'))->assertUnauthorized();

    expect(PersonalAccessToken::query()->where('tokenable_type', AgentSession::class)->count())->toBe(1);
});

it('refuses to renew a session that has gone', function (): void {
    [$session] = $this->startAgentSession($this->installation);

    $this->markSessionGone($session);

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertStatus(409);
});

it("refuses to renew another installation's session", function (): void {
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    [$session] = $this->startAgentSession($other);

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertForbidden();

    // The other installation's own credential still renews it
    $this->machine($this->installationCredential($other))
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk();
});

it('answers 404 for a session that does not exist', function (): void {
    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => 987654]))
        ->assertNotFound();
});

it('refuses a session token on the session endpoints', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();

    $this->machine($token)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertUnauthorized();

    expect(AgentSession::query()->count())->toBe(1);
});

it('refuses an installation credential on the agent routes', function (): void {
    $this->machine($this->credential)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses a signed-in human on the machine routes', function (string $route): void {
    // Sanctum's guard finds this human on the `web` guard before it reads any bearer token, and
    // hands back a transient token whose `can()` answers true to every ability
    $this->actingAs($this->developer, 'web')
        ->postJson(route($route))
        ->assertUnauthorized();

    expect(AgentSession::query()->count())->toBe(0);
})->with([
    'starting a session' => ['robot-council.sessions.start'],
]);

it('refuses a signed-in human on an agent route', function (): void {
    $this->actingAs($this->developer, 'web')
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses a bearer token on a human route', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    // Nothing signs in: the human route reads the session guard, which a bearer token never sets
    $this->machine($token)
        ->get('/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));

    $this->machine($this->credential)
        ->get('/robot-council-test/developer-area')
        ->assertRedirect(route('robot-council.auth.redirect'));
});

it('refuses both credentials once the developer comes off the access list', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    $this->setAccessLists(developers: []);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertForbidden();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertForbidden();
});

it('refuses a token belonging to a session that has gone', function (): void {
    [$session, $token] = $this->startAgentSession($this->installation);

    $this->markSessionGone($session);

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses an installation credential past its maximum age', function (): void {
    [, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(now()->addDays(31));

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();

    // The session token expired on its own, much earlier
    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses an installation credential whose expiry configuration shrank underneath it', function (): void {
    // The token was minted with a month on it; the installation row is what is re-read
    $this->installation->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();
});

it('honors a shortened session lifetime', function (): void {
    config()->set('robot-council.credentials.session_ttl_minutes', 5);

    $response = $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'));

    expect($response->json('expires_in'))->toBe(300);

    [, $token] = $this->startAgentSession($this->installation);

    $this->travelTo(now()->addMinutes(6));

    $this->machine($token)
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it("refuses the package's tokens on a host route guarded by the host's own users provider", function (): void {
    // What a host application is told to do: give its `sanctum` guard a provider, so the guard's
    // default of accepting any token owner does not admit agents
    config()->set('auth.guards.sanctum.provider', 'users');

    Route::middleware(['api', 'auth:sanctum'])
        ->get('/host-api/me', fn (): string => "the host's own route");

    [, $token] = $this->startAgentSession($this->installation);

    $this->machine($token)->getJson('/host-api/me')->assertUnauthorized();
    $this->machine($this->credential)->getJson('/host-api/me')->assertUnauthorized();

    expect(config('auth.providers.users.model'))->toBe(User::class);
});

it('refuses a bearer token that was never issued', function (): void {
    $this->machine('1|not-a-token-anybody-minted')
        ->postJson(route('robot-council.sessions.start'))
        ->assertUnauthorized();

    $this->machine('1|not-a-token-anybody-minted')
        ->getJson(route('robot-council.agent.session'))
        ->assertUnauthorized();
});

it('refuses a request with no credential at all', function (): void {
    $this->postJson(route('robot-council.sessions.start'))->assertUnauthorized();
    $this->getJson(route('robot-council.agent.session'))->assertUnauthorized();
});

it('rate limits one installation starting sessions', function (): void {
    config()->set('robot-council.rate_limits.sessions_per_installation', 3);

    for ($start = 0; $start < 3; $start++) {
        $this->machine($this->credential)
            ->postJson(route('robot-council.sessions.start'))
            ->assertCreated();
    }

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.start'))
        ->assertStatus(429);

    // A second installation is unaffected, because the limit is keyed on the installation
    $other = $this->approveInstallation($this->developer, machineLabel: 'laptop');

    $this->machine($this->installationCredential($other))
        ->postJson(route('robot-council.sessions.start'))
        ->assertCreated();
});

it("keeps sessions apart: one installation cannot read another's", function (): void {
    $otherDeveloper = $this->enrollDeveloper(77, login: 'otherdev');
    $this->setAccessLists(developers: [4242, 77]);

    $otherInstallation = $this->approveInstallation($otherDeveloper, machineLabel: 'their-machine');

    [$mine] = $this->startAgentSession($this->installation);
    [$theirs] = $this->startAgentSession($otherInstallation);

    $this->machine($this->credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $theirs->getKey()]))
        ->assertForbidden();

    expect($mine->installation_id)->not->toBe($theirs->installation_id)
        ->and(Installation::query()->count())->toBe(2);
});
