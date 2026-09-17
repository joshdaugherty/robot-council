<?php

declare(strict_types=1);

namespace RobotCouncil\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use RobotCouncil\RobotCouncilServiceProvider;

/**
 * Base test case: boots a Testbench application with the package's service provider registered.
 */
class TestCase extends Orchestra
{
    /**
     * Register the package's service provider with the Testbench application.
     *
     * @param  Application  $app  The Testbench application.
     * @return array<int, class-string> The service providers to register.
     */
    protected function getPackageProviders($app)
    {
        return [
            RobotCouncilServiceProvider::class,
        ];
    }
}
