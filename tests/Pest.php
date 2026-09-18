<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Laravel\Socialite\Two\User as GitHubAccount;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Every file under a directory with one suffix, at any depth.
 *
 * Recursive deliberately. `glob('<dir>/*.blade.php')` matches only the top level, and a guard built
 * on it goes on passing while a whole subdirectory is unexamined -- the failure looks like a clean
 * result, because one top-level file keeps the list non-empty forever.
 *
 * `FOLLOW_SYMLINKS` because without it `RecursiveDirectoryIterator::hasChildren()` defaults to
 * refusing links, so a symlinked directory is yielded as a leaf and then dropped by the suffix
 * test -- the same silent-skip shape the recursion was written to fix. The suffix is compared
 * case-insensitively, because macOS and Windows resolve `X.BLADE.PHP` while a byte-exact test
 * does not.
 *
 * An unreadable directory throws `UnexpectedValueException` rather than being skipped, and that is
 * deliberate: a guard that quietly walked past a directory it could not open would report clean.
 *
 * @param  string  $directory  The directory to walk.
 * @param  string  $suffix  The file suffix to keep.
 * @return list<string> Absolute paths.
 */
function filesUnder(string $directory, string $suffix): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $found = [];

    $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, $flags)) as $file) {
        if (str_ends_with(strtolower($file->getFilename()), strtolower($suffix))) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/**
 * Every Blade template under a directory, at any depth.
 *
 * @param  string  $directory  The directory to walk.
 * @return list<string> Absolute paths.
 */
function bladeTemplatesIn(string $directory): array
{
    return filesUnder($directory, '.blade.php');
}

/**
 * Every PHP source file under a directory, at any depth.
 *
 * @param  string  $directory  The directory to walk.
 * @return list<string> Absolute paths.
 */
function phpSourcesIn(string $directory): array
{
    return filesUnder($directory, '.php');
}

/**
 * Every construct in one Blade template that can put bytes into the document unescaped.
 *
 * Not only `{!! !!}`. Blade compiles three shapes that skip `e()`, and a guard that knew about one
 * of them reported clean on the other two -- measured against the real
 * `Illuminate\View\Compilers\BladeCompiler::compileString()` rather than reasoned about:
 *
 * - `{!! $x !!}`, which compiles to `<?php echo $x; ?>`.
 * - `@php echo $x; @endphp`, which compiles to exactly the same thing.
 * - A raw `<?php echo $x; ?>` or `<?= $x ?>`, likewise -- a one-line rewrite of the first that
 *   carries no visual warning at all.
 *
 * **The order here mirrors Blade's, and that is load-bearing.** `compileString()` calls
 * `storeUncompiledBlocks()` before `compileComments()`, so a `{{--` inside `@verbatim` or `@php` is
 * already a placeholder when the comment regex runs and cannot open a comment. Stripping comments
 * first instead, over the whole file, lets a stray `{{--` in a verbatim block swallow everything up
 * to the next real `--}}`: measured, a `{!! $evil !!}` between them went unreported while Blade
 * compiled it into a live echo. `@verbatim` is the standard escape for Alpine and Vue mustaches, so
 * a Livewire dashboard is a plausible place to meet one.
 *
 * A genuinely unclosed `{{--` needs no special handling. Blade's own `compileComments()` uses the
 * same non-greedy pattern over the whole string, so both strip the identical span and the echo
 * inside it really does not render -- verified in both directions.
 *
 * What this cannot see is `{{ $x }}` where `$x` is `Htmlable`, because `e()` returns `toHtml()`
 * unescaped for those. No pattern over a template can tell that apart; `tests/EscapingGuardTest.php`
 * carries a separate check that the package never constructs one.
 *
 * @param  string  $template  The template's contents.
 * @return list<string> One description per construct found.
 */
function rawOutputIn(string $template): array
{
    $findings = [];

    // Taken out first, exactly as Blade takes them out first. Their contents are reported rather
    // than discarded, because a `@php` block is one of the shapes being looked for.
    $withoutBlocks = preg_replace_callback(
        '/@verbatim(?<verbatim>.*?)@endverbatim|@php(?<php>.*?)@endphp/s',
        static function (array $match) use (&$findings): string {
            if (($match['php'] ?? '') !== '') {
                $findings[] = '@php block: '.trim((string) preg_replace('/\s+/', ' ', $match['php']));
            }

            return '';
        },
        $template
    ) ?? $template;

    $withoutComments = preg_replace('/\{\{--.*?--\}\}/s', '', $withoutBlocks) ?? $withoutBlocks;

    // Blade leaves a raw PHP tag alone, and it echoes whatever it is given
    if (preg_match('/<\?(?:php|=)/', $withoutComments) === 1) {
        $findings[] = 'a raw PHP tag';
    }

    preg_match_all('/\{!!\s*(?!\s*!!\})(.+?)!!\}/s', $withoutComments, $matches);

    foreach ($matches[1] as $expression) {
        $findings[] = 'unescaped echo: {!! '.trim((string) preg_replace('/\s+/', ' ', $expression)).' !!}';
    }

    return $findings;
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
