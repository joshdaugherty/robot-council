<?php

declare(strict_types=1);

namespace RobotCouncil;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RobotCouncil\Access\Allowlist;
use RobotCouncil\Console\InstallCommand;
use RobotCouncil\Support\HostUsers;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the package with a Laravel application through `spatie/laravel-package-tools`: its
 * configuration, its console commands, its web routes, and the `robot-council-admin` ability.
 */
final class RobotCouncilServiceProvider extends PackageServiceProvider
{
    /**
     * The ability name that gates admin-only actions.
     */
    public const string ADMIN_ABILITY = 'robot-council-admin';

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
            ->hasCommand(InstallCommand::class);
    }

    /**
     * Register what needs the application's own bindings: the routes and the admin ability.
     */
    public function packageBooted(): void
    {
        $this->registerRoutes();
        $this->registerAbilities();
    }

    /**
     * Mount the package's web routes under the configured prefix and middleware group.
     *
     * @throws RuntimeException When the configured prefix or middleware group has the wrong type.
     */
    private function registerRoutes(): void
    {
        $config = $this->app->make(Repository::class);

        $prefix = $config->get('robot-council.routes.web_prefix', 'robot-council');
        $middleware = $config->get('robot-council.routes.web_middleware', ['web']);

        if (! \is_string($prefix) || ! \is_array($middleware)) {
            throw new RuntimeException('Set `robot-council.routes.web_prefix` to a string and `robot-council.routes.web_middleware` to an array.');
        }

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name('robot-council.')
            ->group(__DIR__.'/../routes/web.php');
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
}
