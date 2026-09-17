<?php

namespace JoshDaugherty\RobotCouncil\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JoshDaugherty\RobotCouncil\RobotCouncil
 */
class RobotCouncil extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JoshDaugherty\RobotCouncil\RobotCouncil::class;
    }
}
