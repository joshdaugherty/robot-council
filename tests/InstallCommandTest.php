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

it("publishes Sanctum's migration once, even when a re-run would rename it", function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    // What the Laravel skeleton sets: a publish rewrites the timestamp, so a second run would
    // arrive under a new name and leave the host with two migrations creating one table
    config()->set('database.migrations.update_date_on_publish', true);

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $first = publishedSanctumMigrations($databasePath);

    $this->travel(2)->days();

    expect(Artisan::call('robot-council:install'))->toBe(0)
        ->and(publishedSanctumMigrations($databasePath))->toBe($first);
});

it('reports a sanctum expiration that would cut agent credentials off', function (): void {
    $this->useTemporaryDatabasePath();

    config()->set('sanctum.expiration', 60);

    expect(Artisan::call('robot-council:install'))->toBe(1)
        ->and(Artisan::output())->toContain('sanctum.expiration');
});

it('succeeds while sanctum expiration is null', function (): void {
    $this->useTemporaryDatabasePath();

    expect(config('sanctum.expiration'))->toBeNull()
        ->and(Artisan::call('robot-council:install'))->toBe(0);
});
