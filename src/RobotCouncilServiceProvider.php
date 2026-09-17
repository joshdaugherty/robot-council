<?php

declare(strict_types=1);

namespace RobotCouncil;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the package with a Laravel application through `spatie/laravel-package-tools`.
 */
final class RobotCouncilServiceProvider extends PackageServiceProvider
{
    /**
     * Declare the package's name and the resources it registers.
     *
     * @param  Package  $package  The package definition to configure.
     */
    public function configurePackage(Package $package): void
    {
        $package->name('robot-council');
    }
}
