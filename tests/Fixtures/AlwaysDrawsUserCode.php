<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

use RobotCouncil\Support\Contracts\DrawsUserCodes;

/**
 * A generator that always draws the same code, so the collision loop can be driven to its bound.
 * No fixture can saturate an alphabet of twenty to the eighth power, so this is the only way to
 * reach the branch that gives up.
 */
final class AlwaysDrawsUserCode implements DrawsUserCodes
{
    /**
     * @param  string  $code  The code to draw every time.
     */
    public function __construct(private readonly string $code) {}

    /**
     * Draw the one code this generator was built with.
     *
     * @return string The code.
     */
    public function draw(): string
    {
        return $this->code;
    }
}
