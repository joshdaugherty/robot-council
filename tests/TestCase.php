<?php

namespace JoshDaugherty\RobotCouncil\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use JoshDaugherty\RobotCouncil\RobotCouncilServiceProvider;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(self::factoryNameFor(...));
    }

    /**
     * Resolve the package factory class for a model, in place of Laravel's application guesser.
     *
     * @param  class-string<Model>  $modelName  The model whose factory is being resolved.
     * @return class-string<Factory<Model>> The package factory class for that model.
     *
     * @throws LogicException When no factory class exists for the model.
     */
    private static function factoryNameFor(string $modelName): string
    {
        // Build the factory class name from the model's base name
        $factory = 'JoshDaugherty\\RobotCouncil\\Database\\Factories\\'.class_basename($modelName).'Factory';

        // Check that the name refers to a real factory class
        if (! is_subclass_of($factory, Factory::class)) {
            throw new LogicException(\sprintf('No factory class [%s] exists for model [%s].', $factory, $modelName));
        }

        return $factory;
    }

    protected function getPackageProviders($app)
    {
        return [
            RobotCouncilServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }
}
