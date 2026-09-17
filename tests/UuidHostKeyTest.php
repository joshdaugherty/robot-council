<?php

declare(strict_types=1);

/**
 * The package against a host application whose users table is keyed by UUID, which is the ordinary
 * shape of a multi-tenant application and is not what Testbench gives by default.
 *
 * The whole flow runs here rather than one endpoint, because the host's key travels through all of
 * it: sign-in writes it to the identities table, an approval writes it to a device code, an exchange
 * copies it to an installation, starting a session copies it again, and every agent request reads it
 * back to re-check the access lists. A cast anywhere on that path would be invisible at the ends.
 *
 * @command  vendor/bin/pest --compact tests/UuidHostKeyTest.php
 */

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\HostKey;
use RobotCouncil\Tests\Fixtures\UuidHostUser;

beforeEach(function (): void {
    $this->migrateFresh(__DIR__.'/Fixtures/migrations');

    config()->set('auth.providers.users.model', UuidHostUser::class);

    $this->setAccessLists(developers: [4242]);
});

it('signs a developer in and records the UUID the host gave them', function (): void {
    Socialite::fake('github', githubAccount(4242));

    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $this->assertAuthenticated();

    $identity = GithubIdentity::query()->sole();

    expect($identity->user_id)->toBe(UuidHostUser::FIXED_ID)

        // What a stray `(int)` would have stored instead: a number that looks like a key
        ->and($identity->user_id)->not->toBe('12345678')
        ->and(DB::table('users')->sole()->id)->toBe(UuidHostUser::FIXED_ID);
});

it('carries the UUID through enrollment, a session, and the allowlist re-check', function (): void {
    // 1. The developer signs in
    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $developer = UuidHostUser::query()->sole();

    // 2. A machine asks for a code, and the developer approves it
    $enrollment = requestDeviceCode($this, [Ability::TasksCreate->value]);

    $this->actingAs($developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $enrollment['record']->user_code,
        'confirmed' => '1',
    ])->assertRedirect();

    expect(DeviceCode::query()->sole()->decided_by)->toBe(UuidHostUser::FIXED_ID);

    // 3. The machine exchanges the code
    $credential = stringValue($this->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->assertCreated()->json('credential'));

    $installation = Installation::query()->sole();

    expect($installation->user_id)->toBe(UuidHostUser::FIXED_ID)
        ->and($installation->approved_by)->toBe(UuidHostUser::FIXED_ID);

    // The helper runs on the machine being enrolled, not in the developer's browser. Without
    // ending the session here the request would arrive carrying it, and Sanctum's guard -- which
    // tries the `web` guard before it reads a bearer token -- would answer as the human and be
    // refused by the principal middleware. That is the right refusal, and it is tested elsewhere;
    // here it would only be hiding the thing this test is about.
    $this->flushSession();

    // 4. A process starts a session, which copies the key again
    $started = $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    expect(AgentSession::query()->sole()->user_id)->toBe(UuidHostUser::FIXED_ID);

    $token = stringValue($started->json('token'));

    // 5. An agent route reads the key back to find the identity and check the access lists
    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertOk();

    // 6. And the same read is what locks the developer out when their ID comes off the list
    $this->setAccessLists(developers: []);

    $this->machine($token)->getJson(route('robot-council.agent.session'))->assertForbidden();
    $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertForbidden();
});

it('renews a session under a UUID-keyed host', function (): void {
    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    $developer = UuidHostUser::query()->sole();

    $installation = $this->approveInstallation($developer);
    $credential = $this->installationCredential($installation);

    [$session] = $this->startAgentSession($installation);

    // The enrolled machine has no browser session; see the note in the enrollment test
    $this->flushSession();

    $renewed = $this->machine($credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $session->getKey()]))
        ->assertOk();

    $this->machine(stringValue($renewed->json('token')))
        ->getJson(route('robot-council.agent.session'))
        ->assertOk();
});

it('still refuses a second identity for one UUID-keyed user', function (): void {
    Socialite::fake('github', githubAccount(4242));
    $this->get(route('robot-council.auth.callback'))->assertRedirect('/');

    // The unique index has to hold for a string key exactly as it did for an integer one
    expect(fn () => GithubIdentity::query()->create([
        'user_id' => UuidHostUser::FIXED_ID,
        'github_id' => 99,
        'github_login' => 'someone-else',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('reads a host key as text, and refuses what it cannot store', function (): void {
    expect(HostKey::from(42))->toBe('42')
        ->and(HostKey::from(UuidHostUser::FIXED_ID))->toBe(UuidHostUser::FIXED_ID)
        ->and(HostKey::tryFrom(null))->toBeNull()
        ->and(HostKey::tryFrom(''))->toBeNull()
        ->and(HostKey::tryFrom([1]))->toBeNull()
        ->and(fn (): string => HostKey::from(null))->toThrow(RuntimeException::class);
});
