<?php

declare(strict_types=1);

namespace RobotCouncil;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Access\ApiGuards;
use RobotCouncil\Access\Guard;
use RobotCouncil\Console\GrantAbilityCommand;
use RobotCouncil\Console\InstallCommand;
use RobotCouncil\Console\PruneDeviceCodesCommand;
use RobotCouncil\Console\RevokeAbilityCommand;
use RobotCouncil\Console\RevokeInstallationCommand;
use RobotCouncil\Console\RevokeSessionCommand;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\HostUsers;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the package with a Laravel application through `spatie/laravel-package-tools`: its
 * configuration and views, its console commands, its web and machine routes, its two Sanctum
 * guards, its rate limits, and the `robot-council-admin` ability.
 */
final class RobotCouncilServiceProvider extends PackageServiceProvider
{
    /**
     * The ability name that gates admin-only actions.
     */
    public const string ADMIN_ABILITY = 'robot-council-admin';

    /**
     * The named rate limit on the unauthenticated device-code endpoint.
     */
    public const string DEVICE_CODE_LIMITER = 'robot-council-device-code';

    /**
     * The named rate limit on the unauthenticated token endpoint, which helpers poll.
     */
    public const string DEVICE_TOKEN_LIMITER = 'robot-council-device-token';

    /**
     * The named rate limit on a developer's approve and deny posts.
     */
    public const string VERIFICATION_LIMITER = 'robot-council-verification';

    /**
     * The named rate limit on starting and renewing agent sessions.
     */
    public const string SESSIONS_LIMITER = 'robot-council-sessions';

    /**
     * Declare the package's name and the resources it registers.
     *
     * @param  Package  $package  The package definition to configure.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('robot-council')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([
                InstallCommand::class,
                GrantAbilityCommand::class,
                RevokeAbilityCommand::class,
                RevokeInstallationCommand::class,
                RevokeSessionCommand::class,
                PruneDeviceCodesCommand::class,
            ]);
    }

    /**
     * Register what has to exist before the authentication factory resolves.
     */
    public function packageRegistered(): void
    {
        $this->registerGuards();
    }

    /**
     * Register what needs the application's own bindings.
     */
    public function packageBooted(): void
    {
        $this->registerMigrations();
        $this->registerRateLimits();
        $this->registerRoutes();
        $this->registerAbilities();
        $this->registerSchedule();
    }

    /**
     * Define the two Sanctum guards the package authenticates machines on.
     *
     * Each has an authentication provider naming one model, which is what makes the guards refuse
     * each other's tokens: Sanctum reads `auth.providers.<provider>.model` and requires the token's
     * owner to be an instance of it. A host that leaves `auth.guards.sanctum.provider` null keeps
     * Sanctum's own default, which accepts any owner, so the host sets that itself.
     *
     * Written into the host's configuration rather than published, because a guard the package
     * cannot find is a guard that fails open into whatever the host's default guard admits.
     */
    private function registerGuards(): void
    {
        $config = $this->app->make(Repository::class);

        $config->set('auth.providers.'.ApiGuards::INSTALLATION_PROVIDER, [
            'driver' => 'eloquent',
            'model' => Installation::class,
        ]);

        $config->set('auth.guards.'.ApiGuards::INSTALLATION, [
            'driver' => 'sanctum',
            'provider' => ApiGuards::INSTALLATION_PROVIDER,
        ]);

        $config->set('auth.providers.'.ApiGuards::AGENT_PROVIDER, [
            'driver' => 'eloquent',
            'model' => AgentSession::class,
        ]);

        $config->set('auth.guards.'.ApiGuards::AGENT, [
            'driver' => 'sanctum',
            'provider' => ApiGuards::AGENT_PROVIDER,
        ]);
    }

    /**
     * Load the migrations for the package's own tables.
     *
     * They are loaded rather than published, so `php artisan migrate` picks up an upgrade and a
     * host cannot end up running both a published copy and the package's own. The migrations that
     * change or belong to the host's tables are the opposite case, and `robot-council:install`
     * writes them.
     */
    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Mount the package's routes under their configured prefixes and middleware groups.
     *
     * @throws RuntimeException When a configured prefix or middleware group has the wrong type.
     */
    private function registerRoutes(): void
    {
        $config = $this->app->make(Repository::class);

        $webPrefix = $config->get('robot-council.routes.web_prefix', 'robot-council');
        $webMiddleware = $config->get('robot-council.routes.web_middleware', ['web']);
        $apiPrefix = $config->get('robot-council.routes.api_prefix', 'robot-council/api');
        $apiMiddleware = $config->get('robot-council.routes.api_middleware', ['api']);

        if (! \is_string($webPrefix) || ! \is_array($webMiddleware) || ! \is_string($apiPrefix) || ! \is_array($apiMiddleware)) {
            throw new RuntimeException('Set `robot-council.routes.web_prefix` and `.api_prefix` to strings, and `.web_middleware` and `.api_middleware` to arrays.');
        }

        Route::middleware($webMiddleware)
            ->prefix($webPrefix)
            ->name('robot-council.')
            ->group(__DIR__.'/../routes/web.php');

        Route::middleware($apiMiddleware)
            ->prefix($apiPrefix)
            ->name('robot-council.')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Define the rate limits the routes name.
     *
     * Each is keyed on the subject it is protecting rather than on the address a request came
     * from, where there is one: a helper polling the token endpoint every few seconds is ordinary
     * traffic, and thousands of device codes from one address are not.
     */
    private function registerRateLimits(): void
    {
        $credentials = $this->app->make(Credentials::class);
        $guard = $this->app->make(Guard::class);

        RateLimiter::for(self::DEVICE_CODE_LIMITER, static fn (Request $request): Limit => Limit::perMinute(
            $credentials->rateLimit('device_code_per_ip', 10)
        )->by('ip:'.$request->ip()));

        RateLimiter::for(self::DEVICE_TOKEN_LIMITER, static function (Request $request) use ($credentials): array {
            $deviceCode = $request->input('device_code');

            // Hashed, so the limiter's cache keys hold no device code even for the seconds they
            // live, and an array or an object in the field cannot reach the cache key at all
            $subject = \is_string($deviceCode) ? hash('sha256', $deviceCode) : 'unreadable';

            return [
                Limit::perMinute($credentials->rateLimit('device_token_per_ip', 120))->by('ip:'.$request->ip()),
                Limit::perMinute($credentials->rateLimit('device_token_per_code', 30))->by('code:'.$subject),
            ];
        });

        RateLimiter::for(self::VERIFICATION_LIMITER, static function (Request $request) use ($credentials, $guard): Limit {
            $key = $request->user($guard->name())?->getAuthIdentifier();

            return Limit::perMinute($credentials->rateLimit('verification_per_user', 20))
                ->by('user:'.(\is_scalar($key) ? (string) $key : 'ip:'.$request->ip()));
        });

        RateLimiter::for(self::SESSIONS_LIMITER, static function (Request $request) use ($credentials): Limit {
            // Resolved through the guard rather than read from what `EnsureInstallation` leaves on
            // the request. `ThrottleRequests` is in the framework's middleware priority list and
            // this middleware is not, so the router sorts the limiter ahead of it whatever order
            // the route declares, and the request attribute is not set yet. Keyed on the address
            // when no installation resolves, which is what an unauthenticated flood looks like.
            $installation = $request->user(ApiGuards::INSTALLATION);

            $subject = $installation instanceof Installation
                ? (string) $installation->id
                : 'ip:'.$request->ip();

            return Limit::perMinute($credentials->rateLimit('sessions_per_installation', 60))
                ->by('installation:'.$subject);
        });
    }

    /**
     * Define the admin ability, reading the access lists on every check.
     */
    private function registerAbilities(): void
    {
        Gate::define(self::ADMIN_ABILITY, function (Authenticatable $user): bool {
            $githubId = $this->app->make(HostUsers::class)->githubId($user);

            return $githubId !== null && $this->app->make(Allowlist::class)->isAdmin($githubId);
        });
    }

    /**
     * Schedule the prune of expired device codes.
     *
     * Registered after the application has booted, because the scheduler is not bound until then,
     * and only in console, where the schedule is read.
     */
    private function registerSchedule(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(static function (): void {
            Schedule::command(PruneDeviceCodesCommand::class)->hourly();
        });
    }
}
