<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Laravel\Socialite\Two\User as GitHubAccount;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Every unescaped echo in one Blade template, as the expressions they render.
 *
 * `{!! $x !!}` writes bytes straight into the document where `{{ $x }}` runs them through
 * `htmlspecialchars`, so this is the construct that turns a value written by another developer's
 * agent into markup on this developer's screen.
 *
 * Two things are deliberately not matched, both of which a naive search reports. A Blade comment is
 * stripped before compilation, so an echo inside one renders nothing. And `{!! !!}` with nothing
 * between the braces is how prose refers to the construct -- this package's own verification page
 * contains that exact sentence, and a check that counted it would report the file it was written to
 * protect. `tests/EscapingGuardTest.php` exercises both on every run.
 *
 * @param  string  $template  The template's contents.
 * @return list<string> The echoed expressions, in the order they appear.
 */
function unescapedEchoes(string $template): array
{
    $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $template) ?? $template;

    preg_match_all('/\{!!\s*(?!\s*!!\})(.+?)!!\}/s', $withoutComments, $matches);

    return array_map(
        static fn (string $expression): string => trim((string) preg_replace('/\s+/', ' ', $expression)),
        $matches[1]
    );
}

/**
 * Start an enrollment through the real endpoint, and hand back everything a helper would hold.
 *
 * Going through the endpoint rather than writing a row keeps the fixtures honest: the hashes the
 * exchange looks a code up by are the ones the endpoint wrote, not ones a test computed to match.
 *
 * @param  TestCase  $case  The test case making the request.
 * @param  list<string>  $requestedAbilities  The abilities to ask for.
 * @param  string  $verifier  The secret the helper keeps, whose hash is sent as the challenge.
 * @param  array<string, mixed>  $overrides  Fields to replace in the request body.
 * @return array{record: DeviceCode, device_code: string, verifier: string, response: array<string, mixed>} What the helper holds, and what it was told.
 */
function requestDeviceCode(
    TestCase $case,
    array $requestedAbilities = [],
    string $verifier = 'a-verifier-only-the-helper-holds-and-nobody-else-at-all',
    array $overrides = []
): array {
    $requestedAbilities = $requestedAbilities === []
        ? [Ability::TasksCreate->value, Ability::EventsPost->value]
        : $requestedAbilities;

    $response = $case->postJson(route('robot-council.device.code'), [
        'harness' => 'claude-code',
        'machine_label' => 'workbench-01',
        'requested_abilities' => $requestedAbilities,
        'code_challenge' => hash('sha256', $verifier),
        ...$overrides,
    ]);

    $response->assertCreated();

    $deviceCode = stringValue($response->json('device_code'));

    /** @var array<string, mixed> $body */
    $body = (array) $response->json();

    return [
        'record' => DeviceCode::query()->where('device_code_hash', hash('sha256', $deviceCode))->sole(),
        'device_code' => $deviceCode,
        'verifier' => $verifier,
        'response' => $body,
    ];
}

/**
 * Build the account Socialite would return for a GitHub user, for `Socialite::fake()`.
 *
 * @param  int  $id  The account's numeric GitHub user ID.
 * @param  string|null  $login  The account's login, which Socialite maps to its nickname.
 * @param  string|null  $name  The account's display name, absent on many accounts.
 * @param  string|null  $email  The verified primary email, absent when the account exposes none.
 * @return GitHubAccount The account a faked provider hands the callback.
 */
function githubAccount(
    int $id,
    ?string $login = 'octodev',
    ?string $name = 'Octo Dev',
    ?string $email = 'octo@example.com'
): GitHubAccount {
    $account = new GitHubAccount;

    $account->map([
        'id' => (string) $id,
        'nickname' => $login,
        'name' => $name,
        'email' => $email,
        'avatar' => sprintf('https://avatars.example.com/u/%d', $id),
    ]);

    return $account;
}

/**
 * Narrow a value a test read off an Eloquent model, which arrives untyped when the analyzer cannot
 * infer the column.
 *
 * @param  mixed  $value  The value to narrow.
 * @return CarbonInterface The value, as a date.
 *
 * @throws RuntimeException When the value is not a date.
 */
function dateValue(mixed $value): CarbonInterface
{
    if (! $value instanceof CarbonInterface) {
        throw new RuntimeException(sprintf('Expected a date, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * Narrow a whole number a test read out of JSON, which arrives untyped.
 *
 * @param  mixed  $value  The value to narrow.
 * @return int The value, as an integer.
 *
 * @throws RuntimeException When the value is not an integer.
 */
function intValue(mixed $value): int
{
    if (! is_int($value)) {
        throw new RuntimeException(sprintf('Expected an integer, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * Narrow a value a test read out of JSON that should be a list or a map.
 *
 * @param  mixed  $value  The value to narrow.
 * @return array<int|string, mixed> The value, as an array.
 *
 * @throws RuntimeException When the value is not an array.
 */
function arrayValue(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException(sprintf('Expected an array, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * Narrow a host user key a test read off a model, which `getKey()` returns untyped.
 *
 * Deliberately not `RobotCouncil\Support\HostKey`, which is the production narrowing: a test that
 * computed its expected value with the code under test would agree with it however wrong both were.
 *
 * @param  mixed  $value  The key to narrow.
 * @return string The key as text.
 *
 * @throws RuntimeException When the key is neither an integer nor a string.
 */
function keyValue(mixed $value): string
{
    if (! is_int($value) && ! is_string($value)) {
        throw new RuntimeException(sprintf('Expected a host user key, got %s.', get_debug_type($value)));
    }

    return (string) $value;
}

/**
 * Narrow a value a test read out of JSON, which arrives untyped.
 *
 * A cast would turn an absent key into an empty string and carry on, so an assertion written
 * against it would pass for the wrong reason. This refuses instead.
 *
 * @param  mixed  $value  The value to narrow.
 * @return string The value, as a string.
 *
 * @throws RuntimeException When the value is not a string.
 */
function stringValue(mixed $value): string
{
    if (! is_string($value)) {
        throw new RuntimeException(sprintf('Expected a string, got %s.', get_debug_type($value)));
    }

    return $value;
}
