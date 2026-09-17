<?php

declare(strict_types=1);

/**
 * Smoke test for the package's wiring: the service provider is registered when the package boots
 * under Testbench.
 *
 * @command  vendor/bin/pest --compact tests/ExampleTest.php
 */

use RobotCouncil\RobotCouncilServiceProvider;

it('registers the package service provider', function (): void {
    expect(app()->getProvider(RobotCouncilServiceProvider::class))
        ->toBeInstanceOf(RobotCouncilServiceProvider::class);
});
