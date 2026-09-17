<?php

declare(strict_types=1);

namespace JoshDaugherty\RobotCouncil\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JoshDaugherty\RobotCouncil\RobotCouncil
 */
final class RobotCouncil extends Facade
{
    public static function getFacadeAccessor(): string
    {
        return \JoshDaugherty\RobotCouncil\RobotCouncil::class;
    }
}
