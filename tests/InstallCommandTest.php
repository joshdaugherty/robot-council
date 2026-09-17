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

    $this->loadLaravelMigrations();
    $this->loadMigrationsFrom($databasePath.'/migrations');

    expect(Schema::hasColumns('users', ['github_id', 'github_login', 'avatar_url']))->toBeTrue();

    // A GitHub-only developer has no password, and may have no email
    DB::table('users')->insert([
        'name' => 'octodev',
        'github_id' => 4242,
        'github_login' => 'octodev',
    ]);

    expect(DB::table('users')->count())->toBe(1);
});

it('keeps the email address unique after relaxing the column', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $this->loadLaravelMigrations();
    $this->loadMigrationsFrom($databasePath.'/migrations');

    DB::table('users')->insert(['name' => 'First', 'email' => 'shared@example.com', 'password' => 'hash']);

    // A second row with the same address must still be refused
    expect(fn () => DB::table('users')->insert(['name' => 'Second', 'email' => 'shared@example.com', 'password' => 'hash']))
        ->toThrow(QueryException::class);
});

it('keeps the GitHub ID unique', function (): void {
    $databasePath = $this->useTemporaryDatabasePath();

    expect(Artisan::call('robot-council:install'))->toBe(0);

    $this->loadLaravelMigrations();
    $this->loadMigrationsFrom($databasePath.'/migrations');

    DB::table('users')->insert(['name' => 'First', 'github_id' => 4242]);

    expect(fn () => DB::table('users')->insert(['name' => 'Second', 'github_id' => 4242]))
        ->toThrow(QueryException::class);
});
