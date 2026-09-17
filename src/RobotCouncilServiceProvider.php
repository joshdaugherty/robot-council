<?php

declare(strict_types=1);

namespace RobotCouncil;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
use RobotCouncil\Support\Contracts\DrawsUserCodes;
use RobotCouncil\Support\Credentials;
use RobotCouncil\Support\HostUsers;
use RobotCouncil\Support\UserCodes;
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
        $this->registerMorphAliases();
        $this->registerGuards();

        $this->app->bind(DrawsUserCodes::class, UserCodes::class);
    }

    /**
     * Give the package's token owners stable morph aliases.
     *
     * Two reasons, and the first one is fatal without this. A host that calls
     * `Relation::enforceMorphMap()` -- the usual convention in a large application that wants its
     * `*_type` columns to survive a namespace change -- makes `getMorphClass()` throw for any model
     * outside the map, so issuing any credential would die with `No morph map defined`. The second
     * is that a host adding these classes to its own map later would change what `tokenable_type`
     * holds and orphan every live token, so the package names them itself and keeps the name.
     *
     * Merged rather than set, so nothing a host already registered is lost.
     */
    private function registerMorphAliases(): void
    {
        Relation::morphMap([
            'robot-council-installation' => Installation::class,
            'robot-council-agent-session' => AgentSession::class,
        ], merge: true);
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

        $defaults = [
            'auth.providers.'.ApiGuards::INSTALLATION_PROVIDER => ['driver' => 'eloquent', 'model' => Installation::class],
            'auth.guards.'.ApiGuards::INSTALLATION => ['driver' => 'sanctum', 'provider' => ApiGuards::INSTALLATION_PROVIDER],
            'auth.providers.'.ApiGuards::AGENT_PROVIDER => ['driver' => 'eloquent', 'model' => AgentSession::class],
            'auth.guards.'.ApiGuards::AGENT => ['driver' => 'sanctum', 'provider' => ApiGuards::AGENT_PROVIDER],
        ];

        foreach ($defaults as $key => $default) {
            $configured = $config->get($key);

            // Merged the way Sanctum merges its own guard, so a host that has already pointed one
            // of these at another connection or model keeps what it set
            $config->set($key, \is_array($configured) ? array_merge($default, $configured) : $default);
        }
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
     * A host that has cached its routes already holds these, and re-registering them means parsing
     * and grouping them on every request only for `Router::setCompiledRoutes()` to discard the lot.
     *
     * A mistyped value falls back to the documented default rather than throwing. Throwing from a
     * service provider takes down every request AND every artisan command, including the
     * `config:clear` that would fix it, so the host would have to edit the file by hand.
     */
    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);

        $webPrefix = $this->routeString($config, 'web_prefix', 'robot-council');
        $webMiddleware = $this->routeMiddleware($config, 'web_middleware', ['web']);
        $apiPrefix = $this->routeString($config, 'api_prefix', 'robot-council/api');
        $apiMiddleware = $this->routeMiddleware($config, 'api_middleware', []);

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
     * Read one configured route prefix.
     *
     * @param  Repository  $config  The host application's configuration repository.
     * @param  string  $key  The key under `robot-council.routes`.
     * @param  string  $default  What to mount under when the value is unusable.
     * @return string The prefix to mount under.
     */
    private function routeString(Repository $config, string $key, string $default): string
    {
        $value = $config->get('robot-council.routes.'.$key, $default);

        if (\is_string($value)) {
            return $value;
        }

        Log::warning(sprintf('robot-council: `robot-council.routes.%s` must be a string; using `%s`.', $key, $default));

        return $default;
    }

    /**
     * Read one configured middleware group.
     *
     * @param  Repository  $config  The host application's configuration repository.
     * @param  string  $key  The key under `robot-council.routes`.
     * @param  list<string>  $default  What to run when the value is unusable.
     * @return array<int|string, mixed> The middleware to run.
     */
    private function routeMiddleware(Repository $config, string $key, array $default): array
    {
        $value = $config->get('robot-council.routes.'.$key, $default);

        if (\is_array($value)) {
            return $value;
        }

        Log::warning(sprintf('robot-council: `robot-council.routes.%s` must be an array; using the package default.', $key));

        return $default;
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
            $verifier = $request->input('code_verifier');

            // Keyed on the pair, not on the code alone. A thief holding a stolen device code but
            // not the verifier would otherwise share a bucket with the helper that owns it, and
            // could spend the allowance every minute until the code expired -- so a code that is
            // useless to them would still be useless to its owner. Hashed, so the cache holds
            // neither value even for the minute a bucket lives.
            $subject = \is_string($deviceCode) && \is_string($verifier)
                ? hash('sha256', $deviceCode.'|'.$verifier)
                : 'unreadable';

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

        // A host that runs no scheduler, or that prunes on its own terms, turns this off rather
        // than finding an entry in `schedule:list` it cannot remove
        if ($this->app->make(Repository::class)->get('robot-council.schedule.prune_device_codes', true) !== true) {
            return;
        }

        $this->app->booted(static function (): void {
            Schedule::command(PruneDeviceCodesCommand::class)->hourly();
        });
    }
}
