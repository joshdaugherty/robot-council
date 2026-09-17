<?php

declare(strict_types=1);

/**
 * Nothing written down during enrollment or session handling is a secret in plaintext.
 *
 * The flow is driven end to end -- request, page, approve, exchange, start, renew, agent route, and
 * the refusals -- while every log message and every rendered body is collected. The secrets are
 * then looked for in all of it at once, so a leak through any one of them fails here rather than
 * needing a test per surface.
 *
 * @command  vendor/bin/pest --compact tests/EnrollmentSecretsTest.php
 */

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $this->setAccessLists(developers: [4242]);

    $this->developer = $this->enrollDeveloper(4242);
});

it('writes no credential, device code, or verifier into a log or a rendered page', function (): void {
    $written = [];

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$written): void {
        $written[] = $logged->message.' '.json_encode($logged->context);
    });

    // 1. A helper asks for a code. The endpoint's own response carries the device code once, by
    // design, and is the one place that may; it is not collected here.
    $enrollment = requestDeviceCode($this);

    // 2. The developer opens the page, and approves
    $written[] = (string) $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->getContent();

    $written[] = (string) $this->actingAs($this->developer, 'web')
        ->post(route('robot-council.enroll.approve'), [
            'user_code' => $enrollment['record']->user_code,
            'confirmed' => '1',
        ])->getContent();

    // 3. The helper exchanges the code, and the page is opened again afterwards
    $exchange = $this->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->assertCreated();

    $credential = stringValue($exchange->json('credential'));

    $written[] = (string) $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show', ['user_code' => $enrollment['record']->user_code]))
        ->getContent();

    // 4. A session starts, renews, and reads itself
    $start = $this->machine($credential)->postJson(route('robot-council.sessions.start'))->assertCreated();

    $sessionToken = stringValue($start->json('token'));
    $sessionId = $start->json('session_id');

    $renewed = $this->machine($credential)
        ->postJson(route('robot-council.sessions.renew', ['session' => $sessionId]))
        ->assertOk();

    $renewedToken = stringValue($renewed->json('token'));

    $written[] = (string) $this->machine($renewedToken)
        ->getJson(route('robot-council.agent.session'))->assertOk()->getContent();

    // 5. The refusals, which are what actually log
    $written[] = (string) $this->machine($sessionToken)
        ->getJson(route('robot-council.agent.session'))->assertUnauthorized()->getContent();

    $this->setAccessLists(developers: []);

    $written[] = (string) $this->machine($renewedToken)
        ->getJson(route('robot-council.agent.session'))->assertForbidden()->getContent();

    $written[] = (string) $this->actingAs($this->developer, 'web')
        ->get(route('robot-council.enroll.show'))->getContent();

    $everythingWrittenDown = implode("\n", $written);

    // The secret half of each token, not the whole `id|secret` string: a leak of the half that
    // matters would otherwise satisfy a search for the whole
    expect($everythingWrittenDown)
        ->not->toContain($enrollment['device_code'])
        ->not->toContain($enrollment['verifier'])
        ->not->toContain(explode('|', $credential, 2)[1])
        ->not->toContain(explode('|', $sessionToken, 2)[1])
        ->not->toContain(explode('|', $renewedToken, 2)[1]);

    // The control: the collector is looking at something, and can find what is meant to be there
    expect($everythingWrittenDown)
        ->toContain($enrollment['record']->user_code)
        ->toContain('robot-council refused a signed-in account on neither access list.');
});

it('stores every credential as a hash, never as itself', function (): void {
    $enrollment = requestDeviceCode($this);

    $this->actingAs($this->developer, 'web')->post(route('robot-council.enroll.approve'), [
        'user_code' => $enrollment['record']->user_code,
        'confirmed' => '1',
    ]);

    $credential = stringValue($this->postJson(route('robot-council.device.token'), [
        'device_code' => $enrollment['device_code'],
        'code_verifier' => $enrollment['verifier'],
    ])->json('credential'));

    $sessionToken = stringValue($this->machine($credential)
        ->postJson(route('robot-council.sessions.start'))->json('token'));

    // Sanctum's stored token is the SHA-256 of the half after the pipe
    $rows = collect(DB::table('personal_access_tokens')->get())
        ->concat(DB::table('robot_council_device_codes')->get())
        ->map(fn (object $row): string => implode('|', array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', (array) $row)))
        ->implode("\n");

    expect($rows)
        ->not->toContain($enrollment['device_code'])
        ->not->toContain($enrollment['verifier'])
        ->not->toContain(explode('|', $credential, 2)[1])
        ->not->toContain(explode('|', $sessionToken, 2)[1])

        // The control: the hashes that stand in for them are there
        ->toContain(hash('sha256', explode('|', $credential, 2)[1]))
        ->toContain(hash('sha256', $enrollment['device_code']));
});
