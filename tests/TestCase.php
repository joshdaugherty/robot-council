<?php

declare(strict_types=1);

namespace RobotCouncil\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\RobotCouncilServiceProvider;

/**
 * Base test case: boots a Testbench application with the package's service provider registered,
 * and provides the fixtures the suite shares — a users table carrying the package's columns,
 * throwaway directories, and the access lists.
 */
class TestCase extends Orchestra
{
    /**
     * Directories created for the current test, removed when it finishes.
     *
     * @var list<string> Absolute paths outside the repository.
     */
    private array $temporaryDirectories = [];

    /**
     * Register the package's service provider with the Testbench application.
     *
     * @param  Application  $app  The Testbench application.
     * @return array<int, class-string> The service providers to register.
     */
    protected function getPackageProviders($app)
    {
        return [
            // A host application discovers Socialite's provider through Composer; Testbench does not
            SocialiteServiceProvider::class,
            RobotCouncilServiceProvider::class,
        ];
    }

    /**
     * Supply the GitHub OAuth credentials a host application configures.
     *
     * @param  Application  $app  The Testbench application.
     */
    protected function defineEnvironment($app)
    {
        // The cookie session handler has no request in tests, so sessions live in memory
        $app['config']->set('session.driver', 'array');

        $app['config']->set('services.github', [
            'client_id' => 'github-client-id',
            'client_secret' => 'github-client-secret',
            'redirect' => 'http://localhost/robot-council/auth/github/callback',
        ]);
    }

    /**
     * Remove the directories the test created.
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    /**
     * Create a throwaway directory outside the repository, removed when the test finishes.
     *
     * @param  string  $name  A short label describing what the directory holds.
     * @return string The directory's absolute path.
     */
    protected function temporaryDirectory(string $name): string
    {
        $directory = sprintf('%s/robot-council-%s-%s', sys_get_temp_dir(), $name, Str::random(8));

        File::ensureDirectoryExists($directory);

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /**
     * Point the application's database path at a throwaway directory, never at vendor/.
     *
     * @return string The directory the application now treats as its database path.
     */
    protected function useTemporaryDatabasePath(): string
    {
        $directory = $this->temporaryDirectory('database');

        $this->app?->useDatabasePath($directory);

        return $directory;
    }

    /**
     * Migrate Laravel's own tables, the package's own tables, and the users-table change the
     * install command writes.
     */
    protected function migrateUsersTableWithPackageColumns(): void
    {
        $this->loadLaravelMigrations();

        // Run the stub itself, so the suite exercises what `robot-council:install` writes
        $directory = $this->temporaryDirectory('migrations');

        File::copy(
            __DIR__.'/../database/stubs/add_robot_council_columns_to_users_table.php.stub',
            $directory.'/2026_01_01_000000_add_robot_council_columns_to_users_table.php'
        );

        $this->loadMigrationsFrom($directory);

        // Run the package's own migrations, which its service provider loads
        Artisan::call('migrate');
    }

    /**
     * Enroll a developer: a host user row, and the package's identity for a GitHub account.
     *
     * @param  int  $githubId  The developer's numeric GitHub user ID.
     * @param  string  $login  The GitHub login to record.
     * @param  string|null  $email  The email for the user row, defaulted from the ID.
     * @return User The saved user.
     */
    protected function enrollDeveloper(int $githubId, string $login = 'octodev', ?string $email = null): User
    {
        $user = new User;

        $user->forceFill([
            'name' => $login,
            'email' => $email ?? sprintf('octo+%d@example.com', $githubId),
        ])->save();

        GithubIdentity::query()->create([
            'user_id' => $user->getKey(),
            'github_id' => $githubId,
            'github_login' => $login,
        ]);

        return $user;
    }

    /**
     * Set the access lists, in the comma-separated form the environment supplies.
     *
     * @param  list<int>  $developers  GitHub user IDs allowed to sign in.
     * @param  list<int>  $admins  GitHub user IDs that also hold admin rights.
     */
    protected function setAccessLists(array $developers = [], array $admins = []): void
    {
        config()->set('robot-council.access.developers', implode(',', $developers));
        config()->set('robot-council.access.admins', implode(',', $admins));
    }
}
