<?php

declare(strict_types=1);

/**
 * `robot-council:install`: what it writes into the host application, that a re-run writes nothing,
 * and that the migration it writes runs cleanly.
 *
 * @command  vendor/bin/pest --compact tests/InstallCommandTest.php
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's tokens migrations the command has published into a database path.
 *
 * @param  string  $databasePath  The application's database path for this test.
 * @return list<string> Absolute paths of the migrations found, in glob order.
 */
function publishedSanctumMigrations(string $databasePath): array
{
    $found = File::glob($databasePath.'/migrations/*_create_personal_access_tokens_table.php');

    return array_values(array_filter($found, is_string(...)));
}

/**
 * The users-columns migrations the command has written into a database path.
 *
 * @param  string  $databasePath  The application's database path for this test.
 * @return list<string> Absolute paths of the migrations found, in glob order.
 */
function writtenUsersMigrations(string $databasePath): array
{
    $found = File::glob($databasePath.'/migrations/*_add_robot_council_columns_to_users_table.php');

    return array_values(array_filter($found, is_string(...)));
}

it('writes the users columns migration', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $written = writtenUsersMigrations($databasePath);

    expect($written)->toHaveCount(1);

    // The file is the shipped stub, byte for byte
    expect(File::get($written[0]))
        ->toBe(File::get(__DIR__.'/../database/stubs/add_robot_council_columns_to_users_table.php.stub'));

    // It landed in the throwaway path, not in the Testbench skeleton under vendor/
    expect(\dirname($written[0], 2))->toBe($databasePath);
});

it('writes nothing on a second run, even days later', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $first = writtenUsersMigrations($databasePath);

    $this->travel(2)->days();

    expect(Artisan::call('robot-council:install'))->toBe(0)
        ->and(writtenUsersMigrations($databasePath))->toBe($first);
});

it('writes a migration that runs cleanly on a fresh application', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $this->migrateFresh($databasePath.'/migrations');

    // A GitHub-only developer has no password, and may have no email
    DB::table('users')->insert(['name' => 'octodev']);

    expect(DB::table('users')->count())->toBe(1);

    // The package adds no columns of its own to the host's table
    expect(Schema::hasColumn('users', 'github_id'))->toBeFalse();
});

it('keeps the email address unique after relaxing the column', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $this->migrateFresh($databasePath.'/migrations');

    DB::table('users')->insert(['name' => 'First', 'email' => 'shared@example.com', 'password' => 'hash']);

    // A second row with the same address must still be refused
    expect(fn () => DB::table('users')->insert(['name' => 'Second', 'email' => 'shared@example.com', 'password' => 'hash']))
        ->toThrow(QueryException::class);
});

it('leaves a column that already admits null alone', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    // Running the stub twice is a no-op, because both columns are nullable after the first
    $this->migrateFresh($databasePath.'/migrations');
    $this->migrateFresh($databasePath.'/migrations');

    DB::table('users')->insert(['name' => 'octodev']);

    expect(DB::table('users')->count())->toBe(1);
});

it("publishes Sanctum's tokens migration, which the host owns", function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $published = publishedSanctumMigrations($databasePath);

    expect($published)->toHaveCount(1)
        ->and(File::get($published[0]))->toContain('personal_access_tokens')
        ->and(\dirname($published[0], 2))->toBe($databasePath);
});

it("leaves Sanctum's tokens migration alone whatever name the host published it under", function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    File::ensureDirectoryExists($databasePath.'/migrations');

    // The host ran `vendor:publish` itself, under the rewritten timestamp the Laravel skeleton
    // produces. Matching on the name rather than the suffix would add a second migration creating
    // one table, which fails on the next `php artisan migrate`.
    $existing = $databasePath.'/migrations/2026_09_18_120000_create_personal_access_tokens_table.php';

    File::put($existing, "<?php // the host's own copy\n");

    expect(Artisan::call('robot-council:install'))->toBe(0)
        ->and(publishedSanctumMigrations($databasePath))->toBe([$existing])
        ->and(File::get($existing))->toBe("<?php // the host's own copy\n")
        ->and(Artisan::output())->toContain('already exists');
});

it("writes Sanctum's migration once, and nothing on a second run days later", function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $first = publishedSanctumMigrations($databasePath);

    expect($first)->toHaveCount(1);

    $this->travel(2)->days();

    expect(Artisan::call('robot-council:install'))->toBe(0)
        ->and(publishedSanctumMigrations($databasePath))->toBe($first);
});

it('refuses, and writes nothing, while sanctum expiration would retire a credential early', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    // An hour, against an installation that is meant to live thirty days
    config()->set('sanctum.expiration', 60);

    expect(Artisan::call('robot-council:install'))->toBe(1)
        ->and(Artisan::output())->toContain('sanctum.expiration');

    // Nothing was written, so a deploy that stops here has not half-installed the package and the
    // re-run after the configuration is fixed starts from the same place
    expect(writtenUsersMigrations($databasePath))->toBeEmpty()
        ->and(publishedSanctumMigrations($databasePath))->toBeEmpty();
});

it('accepts a sanctum expiration long enough to outlive an installation', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    // The setting is global to every guard on Sanctum's driver, so a host keeping one for its own
    // tokens is fine as long as it outlasts `installation_max_age_days`
    config()->set('sanctum.expiration', 30 * 24 * 60);

    expect(Artisan::call('robot-council:install'))->toBe(0)
        ->and(writtenUsersMigrations($databasePath))->toHaveCount(1);
});

it('measures the sanctum expiration it needs against the configured installation life', function (): void {
    $this->useTemporaryDatabasePath();

    config()->set('robot-council.credentials.installation_max_age_days', 1);
    config()->set('sanctum.expiration', 2 * 24 * 60);

    // Two days of expiry is plenty for an installation that lives one
    expect(Artisan::call('robot-council:install'))->toBe(0);

    config()->set('robot-council.credentials.installation_max_age_days', 7);

    // The same setting is now too short, and the message says what it would have to be
    expect(Artisan::call('robot-council:install'))->toBe(1)
        ->and(Artisan::output())->toContain((string) (7 * 24 * 60));
});

it('succeeds while sanctum expiration is null', function (): void {
    $this->useTemporaryDatabasePath();

    expect(config('sanctum.expiration'))->toBeNull()
        ->and(Artisan::call('robot-council:install'))->toBe(0);
});
