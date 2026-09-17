<?php

declare(strict_types=1);

/**
 * Smoke tests for the package's wiring: the service provider boots under Testbench, binds the
 * class behind the facade, and loads the package config.
 *
 * @command  vendor/bin/pest --compact tests/ExampleTest.php
 */

use JoshDaugherty\RobotCouncil\Facades\RobotCouncil as RobotCouncilFacade;
use JoshDaugherty\RobotCouncil\RobotCouncil;

it('resolves the package class through the facade', function (): void {
    expect(RobotCouncilFacade::getFacadeRoot())->toBeInstanceOf(RobotCouncil::class);
});

it('loads the package config', function (): void {
    expect(config('robot-council'))->toBeArray();
});
