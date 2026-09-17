<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;

/**
 * Performs the setup a host application needs: writes the migrations the package cannot load
 * itself, because they change or belong to tables the host owns. Run it once per installation and
 * commit what it writes. It recognizes its earlier output by file-name suffix, so a re-run writes
 * nothing -- including a re-run months later, when a fresh publish would otherwise arrive under a
 * new timestamp and leave the host with two copies of one table.
 */
#[Description('Write the migrations robot-council needs in this application')]
#[Signature('robot-council:install')]
final class InstallCommand extends Command
{
    /**
     * The suffix every generated users-columns migration carries.
     */
    private const string USERS_MIGRATION_SUFFIX = '_add_robot_council_columns_to_users_table.php';

    /**
     * The suffix Sanctum's own migration carries, whatever timestamp it is published under.
     */
    private const string SANCTUM_MIGRATION_SUFFIX = '_create_personal_access_tokens_table.php';

    /**
     * Write the package's migrations into the host application.
     *
     * @param  Filesystem  $files  The filesystem the command reads and writes through.
     * @param  Repository  $config  The host application's configuration repository.
     * @return int The command's exit code.
     */
    public function handle(Filesystem $files, Repository $config): int
    {
        $migrations = $this->laravel->databasePath('migrations');

        $files->ensureDirectoryExists($migrations);

        $this->writeUsersMigration($files, $migrations);

        if (! $this->publishSanctumMigration($files, $migrations)) {
            return self::FAILURE;
        }

        return $this->reportSanctumExpiration($config);
    }

    /**
     * Write the migration that relaxes the host's users table, unless an earlier run wrote it.
     *
     * @param  Filesystem  $files  The filesystem to read and write through.
     * @param  string  $migrations  The host application's migrations directory.
     */
    private function writeUsersMigration(Filesystem $files, string $migrations): void
    {
        if ($this->existing($files, $migrations, self::USERS_MIGRATION_SUFFIX) !== null) {
            $this->components->info(sprintf('The users columns migration already exists: %s.', $this->existing($files, $migrations, self::USERS_MIGRATION_SUFFIX)));

            return;
        }

        // Written under a fresh timestamp, so it runs after the host's own migrations
        $target = sprintf('%s/%s%s', $migrations, Carbon::now()->format('Y_m_d_His'), self::USERS_MIGRATION_SUFFIX);

        $files->put($target, $files->get($this->stubPath()));

        $this->components->info(sprintf('Wrote %s. Review it, commit it, and run `php artisan migrate`.', basename($target)));
        $this->components->warn('It makes `users.password` and `users.email` nullable, which rewrites those column definitions. Read it before migrating a database that has custom collations, defaults, or triggers on that table.');
    }

    /**
     * Copy Sanctum's tokens migration into the host application, unless it is already there.
     *
     * The host owns this table: its own API tokens live there too, and Sanctum publishes rather
     * than loads it. The check is by suffix rather than by name, because the Laravel skeleton sets
     * `database.migrations.update_date_on_publish`, so a publish rewrites the timestamp and
     * comparing names would add a second migration creating one table on every run.
     *
     * The file is copied rather than published through `vendor:publish`, which resolves its
     * destination from the path Sanctum's provider captured when it booted. That is the same
     * directory in an ordinary application and a different one wherever the database path moves
     * afterwards, and the difference is invisible: the publish reports success having written
     * somewhere this command does not look. Copying keeps one source of truth for where it lands.
     *
     * The name Sanctum ships with is kept, so a host that later runs
     * `vendor:publish --tag=sanctum-migrations` is told the file exists instead of getting a second
     * copy under a new timestamp.
     *
     * @param  Filesystem  $files  The filesystem to read and write through.
     * @param  string  $migrations  The host application's migrations directory.
     * @return bool False once a failure has been reported.
     */
    private function publishSanctumMigration(Filesystem $files, string $migrations): bool
    {
        $existing = $this->existing($files, $migrations, self::SANCTUM_MIGRATION_SUFFIX);

        if ($existing !== null) {
            $this->components->info(sprintf("Sanctum's tokens migration already exists: %s.", $existing));

            return true;
        }

        $source = $this->sanctumMigrationPath($files);

        if ($source === null) {
            $this->components->error("Could not find Sanctum's tokens migration in the installed package. Run `php artisan vendor:publish --tag=sanctum-migrations` by hand.");

            return false;
        }

        $files->copy($source, $migrations.'/'.basename($source));

        $this->components->info(sprintf('Wrote %s. Commit it, and run `php artisan migrate`.', basename($source)));

        return true;
    }

    /**
     * Where the installed Sanctum keeps its tokens migration.
     *
     * Resolved from the loaded class rather than from a written-out vendor path, so a package that
     * moved or renamed it is reported instead of quietly skipped.
     *
     * @param  Filesystem  $files  The filesystem to read through.
     * @return string|null The migration's absolute path, or null when it is not where it was.
     */
    private function sanctumMigrationPath(Filesystem $files): ?string
    {
        $installed = new ReflectionClass(Sanctum::class)->getFileName();

        if (! \is_string($installed)) {
            return null;
        }

        $matches = array_values(array_filter(
            $files->glob(\dirname($installed, 2).'/database/migrations/*'.self::SANCTUM_MIGRATION_SUFFIX),
            is_string(...)
        ));

        return $matches === [] ? null : $matches[0];
    }

    /**
     * Report a `sanctum.expiration` that would cut agent credentials off.
     *
     * Sanctum measures that setting from a token's `created_at`, so a non-null value expires a
     * renewed session token on the installation's schedule rather than on its own, and the agent
     * it belongs to stops working with no way to recover but re-enrollment.
     *
     * @param  Repository  $config  The host application's configuration repository.
     * @return int The command's exit code.
     */
    private function reportSanctumExpiration(Repository $config): int
    {
        if ($config->get('sanctum.expiration') === null) {
            return self::SUCCESS;
        }

        $this->components->error("Set `sanctum.expiration` to null. Sanctum measures it from a token's creation, so it cuts off renewed robot-council session tokens and the agents holding them, whatever their own expiry says.");

        return self::FAILURE;
    }

    /**
     * The name of the migration already in the host's migrations directory with a given suffix.
     *
     * @param  Filesystem  $files  The filesystem to read through.
     * @param  string  $migrations  The host application's migrations directory.
     * @param  string  $suffix  The file-name suffix to look for.
     * @return string|null The file's base name, or null when there is none.
     */
    private function existing(Filesystem $files, string $migrations, string $suffix): ?string
    {
        $matches = array_values(array_filter($files->glob($migrations.'/*'.$suffix), is_string(...)));

        return $matches === [] ? null : basename($matches[0]);
    }

    /**
     * The path to the users-columns migration stub the package ships.
     *
     * @return string The stub's absolute path.
     */
    private function stubPath(): string
    {
        return \dirname(__DIR__, 2).'/database/stubs/add_robot_council_columns_to_users_table.php.stub';
    }
}
