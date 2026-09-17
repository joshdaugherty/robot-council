<?php

namespace JoshDaugherty\RobotCouncil;

use JoshDaugherty\RobotCouncil\Commands\RobotCouncilCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RobotCouncilServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('robot-council')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_robot_council_table')
            ->hasCommand(RobotCouncilCommand::class);
    }
}
