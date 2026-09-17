<?php

declare(strict_types=1);

namespace RobotCouncil\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use RobotCouncil\Access\Ability;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\GithubIdentity;
use RobotCouncil\Models\Installation;
use RobotCouncil\RobotCouncilServiceProvider;
use RobotCouncil\Support\AgentSessions;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\Installations;
use RuntimeException;

use function Orchestra\Testbench\default_migration_path;

/**
 * Base test case: boots a Testbench application with the package's service provider registered,
 * and provides the fixtures the suite shares — a users table carrying the package's columns,
 * throwaway directories, the access lists, and enrolled installations and agent sessions.
 *
 * The fixture helpers are public rather than protected. Pest exposes shared test helpers as global
 * functions, which are not bound to the test case and cannot reach a protected method.
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
            // A host application discovers these through Composer; Testbench does not
            SocialiteServiceProvider::class,
            SanctumServiceProvider::class,
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
        // Sessions and cookies are encrypted, so the application needs a key of its own. Not
        // Testbench's `.env`, which only exists once something has copied `.env.example` over.
        $app['config']->set('app.key', 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmFAckfSECXIvk=');

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
     * Drop whatever the last test left behind, then migrate Laravel's tables, Sanctum's, the
     * package's own, and any extra paths. A shared database keeps its rows between tests, unlike
     * SQLite's in-memory one, so every database test starts from here.
     *
     * @param  string  ...$paths  Extra migration directories to run, in migration-name order.
     */
    protected function migrateFresh(string ...$paths): void
    {
        Artisan::call('migrate:fresh', [
            '--path' => [
                default_migration_path(),

                // A host application publishes this one with `robot-council:install`
                $this->sanctumMigrationPath(),
                __DIR__.'/../database/migrations',
                ...$paths,
            ],
            '--realpath' => true,
        ]);
    }

    /**
     * Where Sanctum keeps the migration a host publishes.
     *
     * Resolved from the installed class rather than written out, so moving or renaming the vendor
     * directory fails here instead of silently migrating one table fewer.
     *
     * @return string The absolute path to Sanctum's migrations directory.
     */
    protected function sanctumMigrationPath(): string
    {
        return \dirname((string) new ReflectionClass(Sanctum::class)->getFileName(), 2).'/database/migrations';
    }

    /**
     * Migrate everything, including the users-table change `robot-council:install` writes.
     *
     * @return string The directory holding the copy of the install stub that ran.
     */
    public function migrateUsersTableWithPackageColumns(): string
    {
        // Run the stub itself, so the suite exercises what `robot-council:install` writes
        $directory = $this->temporaryDirectory('migrations');

        File::copy(
            __DIR__.'/../database/stubs/add_robot_council_columns_to_users_table.php.stub',
            $directory.'/2026_01_01_000000_add_robot_council_columns_to_users_table.php'
        );

        $this->migrateFresh($directory);

        return $directory;
    }

    /**
     * Enroll a developer: a host user row, and the package's identity for a GitHub account.
     *
     * @param  int  $githubId  The developer's numeric GitHub user ID.
     * @param  string  $login  The GitHub login to record.
     * @param  string|null  $email  The email for the user row, defaulted from the ID.
     * @return User The saved user.
     */
    public function enrollDeveloper(int $githubId, string $login = 'octodev', ?string $email = null): User
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
    public function setAccessLists(array $developers = [], array $admins = []): void
    {
        config()->set('robot-council.access.developers', implode(',', $developers));
        config()->set('robot-council.access.admins', implode(',', $admins));
    }

    /**
     * Create an approved installation for a developer, as the device-code flow would.
     *
     * @param  User  $user  The developer who approved it.
     * @param  list<string>  $abilities  The abilities its session tokens carry.
     * @param  string  $machineLabel  The label the requester claimed.
     * @return Installation The saved installation.
     */
    public function approveInstallation(User $user, array $abilities = [], string $machineLabel = 'workbench'): Installation
    {
        $abilities = $abilities === [] ? [Ability::TasksCreate->value, Ability::EventsPost->value] : $abilities;

        return Installation::query()->create([
            'user_id' => $user->getKey(),
            'harness' => 'claude-code',
            'machine_label' => $machineLabel,
            'granted_abilities' => $abilities,
            'approved_by' => $user->getKey(),
            'requested_ip' => '203.0.113.10',
            'expires_at' => $this->credentials()->installationExpiry(),
        ]);
    }

    /**
     * Issue an installation's credential, exactly as the token endpoint does.
     *
     * @param  Installation  $installation  The installation to credential.
     * @return string The plaintext credential.
     */
    public function installationCredential(Installation $installation): string
    {
        return $installation->createToken(
            Installations::CREDENTIAL_NAME,
            [Ability::SessionsStart->value],
            $installation->expires_at
        )->plainTextToken;
    }

    /**
     * Start an agent session under an installation, as the session endpoint does.
     *
     * @param  Installation  $installation  The installation to start it under.
     * @return array{AgentSession, string} The session and its plaintext token.
     */
    public function startAgentSession(Installation $installation): array
    {
        $issued = $this->service(AgentSessions::class)->start($installation, null);

        return [$issued->owner, $issued->plainTextToken];
    }

    /**
     * The headers a machine sends: a bearer token, and a request for JSON.
     *
     * @param  string  $token  The plaintext bearer token.
     * @return array<string, string> The headers.
     */
    protected function bearer(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Make the next request as a machine holding this token.
     *
     * The forgotten guards are load-bearing. `Illuminate\Auth\RequestGuard::user()` caches the
     * principal it resolved, and one test process keeps one application across every request it
     * makes, so a second request in the same test would otherwise be answered as whoever the first
     * one authenticated -- a revoked token would keep working, and another installation's
     * credential would arrive as this one's. A real request boots its own application, and Octane
     * flushes the same state between requests.
     *
     * @param  string  $token  The plaintext bearer token.
     * @return $this The test case, with the machine's headers set.
     */
    public function machine(string $token): static
    {
        $this->app?->make('auth')->forgetGuards();

        return $this->withHeaders($this->bearer($token));
    }

    /**
     * Mark an agent session as gone, without going through revocation.
     *
     * @param  AgentSession  $session  The session to end.
     */
    protected function markSessionGone(AgentSession $session): void
    {
        $session->forceFill(['status' => AgentSessionStatus::Gone])->save();
    }

    /**
     * The booted application.
     *
     * @return Application The container, which is only null before a test boots one.
     *
     * @throws RuntimeException When the application has not booted.
     */
    public function container(): Application
    {
        $app = $this->app;

        if ($app === null) {
            throw new RuntimeException('The application was not booted.');
        }

        return $app;
    }

    /**
     * Resolve a service out of the booted application.
     *
     * @template TService of object
     *
     * @param  class-string<TService>  $abstract  The class to resolve.
     * @return TService The resolved service.
     *
     * @throws RuntimeException When the application has not booted.
     */
    public function service(string $abstract): object
    {
        $service = $this->container()->make($abstract);

        if (! $service instanceof $abstract) {
            throw new RuntimeException(sprintf('The container returned something other than %s.', $abstract));
        }

        return $service;
    }

    /**
     * The configured lifetimes.
     *
     * @return Credentials The credentials reader.
     */
    protected function credentials(): Credentials
    {
        return $this->service(Credentials::class);
    }
}
