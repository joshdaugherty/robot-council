<?php

declare(strict_types=1);

/**
 * Exchanging an approved device code for an installation credential: what each unfinished state
 * answers, and what happens when two exchanges of one code overlap.
 *
 * @command  vendor/bin/pest --compact tests/DeviceTokenExchangeTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Models\Installation;
use RobotCouncil\Tests\TestCase;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

/**
 * Approve an enrollment as the developer would, through the page.
 *
 * @param  TestCase  $case  The test case making the request.
 * @param  User  $developer  The developer approving it.
 * @param  array{record: DeviceCode, device_code: string, verifier: string}  $enrollment  The request.
 */
function approveEnrollment(TestCase $case, User $developer, array $enrollment): void
{
    $case->actingAs($developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])
        ->assertRedirect();
}

/**
 * Poll the token endpoint as the helper would.
 *
 * @param  TestCase  $case  The test case making the request.
 * @param  array{record: DeviceCode, device_code: string, verifier: string}  $enrollment  The request.
 * @param  string|null  $verifier  A verifier other than the one the enrollment holds.
 * @return TestResponse<JsonResponse> The response.
 */
function exchange(TestCase $case, array $enrollment, ?string $verifier = null): TestResponse
{
    return $case->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $verifier ?? $enrollment['verifier'],
    ]);
}

it('issues a credential that can do nothing but start sessions', function (): void {
    $enrollment = requestDeviceCode($this, [Ability::TasksCreate->value, Ability::LocksAcquire->value]);
    approveEnrollment($this, $this->developer, $enrollment);

    $response = exchange($this, $enrollment);

    $response->assertCreated()
        ->assertJsonStructure(['installation_id', 'credential', 'granted_abilities', 'expires_at']);

    expect($response->json('granted_abilities'))->toBe(['tasks:create', 'locks:acquire']);

    $installation = Installation::query()->sole();

    expect($installation->user_id)->toBe($this->developer->getKey())
        ->and($installation->harness)->toBe('claude-code')
        ->and($installation->machine_label)->toBe('workbench-01')
        ->and($installation->granted_abilities)->toBe(['tasks:create', 'locks:acquire'])

        // The default maximum age, to the second
        ->and($installation->expires_at->timestamp)->toBe(now()->addDays(30)->timestamp);

    // The credential itself carries only the one ability; the rest ride on session tokens
    $credential = PersonalAccessToken::query()->sole();

    expect($credential->abilities)->toBe([Ability::SessionsStart->value])
        ->and($credential->expires_at?->toDateTimeString())->toBe($installation->expires_at->toDateTimeString());
});

it('answers authorization_pending until a developer decides', function (): void {
    $enrollment = requestDeviceCode($this);

    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'authorization_pending']);

    expect(Installation::query()->count())->toBe(0);
});

it('answers access_denied once a developer has denied it', function (): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.deny'), ['user_code' => $enrollment['record']->user_code]);

    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'access_denied']);
});

it('answers expired_token once the code has expired', function (): void {
    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    $this->travelTo(now()->addSeconds(601));

    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'expired_token']);

    expect(Installation::query()->count())->toBe(0);
});

it('answers expired_token on a second exchange of the same code', function (): void {
    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    exchange($this, $enrollment)->assertCreated();
    exchange($this, $enrollment)->assertStatus(400)->assertExactJson(['error' => 'expired_token']);

    expect(Installation::query()->count())->toBe(1);
});

it('answers invalid_grant for a code nobody issued', function (): void {
    $this->postJson(route('robot-council.device.token'), [
        'device_code' => bin2hex(random_bytes(32)),
        'code_verifier' => 'anything',
    ])->assertStatus(400)->assertExactJson(['error' => 'invalid_grant']);
});

it('answers invalid_grant in every state when the verifier is wrong', function (string $state): void {
    $enrollment = requestDeviceCode($this);

    if ($state === 'approved') {
        approveEnrollment($this, $this->developer, $enrollment);
    }

    if ($state === 'denied') {
        $this->actingAs($this->developer, 'web')
            ->post(route('robot-council.enroll.deny'), ['user_code' => $enrollment['record']->user_code]);
    }

    // Whoever holds the device code but not the verifier is told the same thing throughout, so
    // polling a stolen code cannot reveal that a developer has approved it
    exchange($this, $enrollment, verifier: 'not-the-verifier')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_grant']);

    expect(Installation::query()->count())->toBe(0);
})->with(['pending', 'approved', 'denied']);

it('mints one installation and one credential when two exchanges of a code overlap', function (): void {
    $enrollment = requestDeviceCode($this);
    approveEnrollment($this, $this->developer, $enrollment);

    $injected = null;
    $fired = false;

    // A second exchange lands immediately after the first exchange's first query against the
    // device codes table. Against a read then a write, that first query is the read, so the second
    // exchange finishes while the first is still holding a stale row, and both create an
    // installation. The filter is load-bearing: the rate limiter runs fourteen queries against the
    // `cache` table first, and injecting there would let the second exchange finish before the
    // first had touched anything.
    $case = $this;

    DB::listen(function (QueryExecuted $query) use (&$fired, &$injected, $enrollment, $case): void {
        if ($fired || ! str_contains($query->sql, 'robot_council_device_codes')) {
            return;
        }

        $fired = true;

        $injected = exchange($case, $enrollment);
    });

    $first = exchange($this, $enrollment);

    expect($fired)->toBeTrue()
        ->and($injected)->toBeInstanceOf(TestResponse::class)
        ->and(Installation::query()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(1);

    // Exactly one of the two walked away with a credential
    $statuses = [$first->getStatusCode(), $injected instanceof TestResponse ? $injected->getStatusCode() : 0];

    sort($statuses);

    expect($statuses)->toBe([201, 400]);
});

it('refuses a request missing the device code or the verifier', function (array $body, string $missing): void {
    $this->postJson(route('robot-council.device.token'), $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors($missing);
})->with([
    'no verifier' => [['device_code' => 'abc'], 'code_verifier'],
    'no device code' => [['code_verifier' => 'abc'], 'device_code'],
]);

it('rate limits a helper polling one code too hard', function (): void {
    config()->set('robot-council.rate_limits.device_token_per_code', 3);

    $enrollment = requestDeviceCode($this);

    for ($poll = 0; $poll < 3; $poll++) {
        exchange($this, $enrollment)->assertStatus(400);
    }

    exchange($this, $enrollment)->assertStatus(429);
});
