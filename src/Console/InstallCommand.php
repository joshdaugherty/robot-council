<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

/**
 * Performs the setup a host application needs: writes the migrations the package cannot load
 * itself, because they change tables the host owns. Run it once per installation and commit what
 * it writes. It recognizes its earlier output by file-name suffix, so a re-run writes nothing.
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
     * Write the package's migrations into the host application.
     *
     * @param  Filesystem  $files  The filesystem the command reads and writes through.
     * @return int The command's exit code.
     */
    public function handle(Filesystem $files): int
    {
        $migrations = $this->laravel->databasePath('migrations');

        // Check whether an earlier run already wrote the users-columns migration
        $existing = array_values(array_filter(
            $files->glob($migrations.'/*'.self::USERS_MIGRATION_SUFFIX),
            is_string(...)
        ));

        if ($existing !== []) {
            $this->components->info(sprintf('The users columns migration already exists: %s.', basename($existing[0])));

            return self::SUCCESS;
        }

        // Write the migration under a fresh timestamp, so it runs after the host's own migrations
        $files->ensureDirectoryExists($migrations);

        $target = sprintf('%s/%s%s', $migrations, Carbon::now()->format('Y_m_d_His'), self::USERS_MIGRATION_SUFFIX);

        $files->put($target, $files->get($this->stubPath()));

        $this->components->info(sprintf('Wrote %s. Review it, commit it, and run `php artisan migrate`.', basename($target)));
        $this->components->warn('It makes `users.password` and `users.email` nullable, which rewrites those column definitions. Read it before migrating a database that has custom collations, defaults, or triggers on that table.');

        return self::SUCCESS;
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
