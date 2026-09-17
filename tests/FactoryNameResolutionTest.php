<?php

declare(strict_types=1);

/**
 * The factory-name resolver the base test case registers: a model with no package factory fails
 * with a named error instead of returning a class that does not exist.
 *
 * @command  vendor/bin/pest --compact tests/FactoryNameResolutionTest.php
 */

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

it('fails loudly when a model has no package factory', function (): void {
    $model = new class extends Model {};

    expect(fn () => Factory::resolveFactoryName($model::class))
        ->toThrow(LogicException::class, 'No factory class');
});
