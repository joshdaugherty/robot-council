<?php

declare(strict_types=1);

namespace RobotCouncil\Support\Contracts;

/**
 * Draws the short code a developer reads off one screen and types into another.
 *
 * It is a contract rather than a method on `DeviceCodes` so the collision loop can be shown to give
 * up: no fixture can saturate an alphabet of twenty to the eighth power, so the only way to reach
 * the bound is to substitute a generator that always collides. Binding an implementation is how a
 * test does that, which keeps the production generator free of any switch a host could flip.
 */
interface DrawsUserCodes
{
    /**
     * Draw one candidate user code, without regard to what is already stored.
     *
     * @return string A candidate code, which the caller checks for a collision.
     */
    public function draw(): string;
}
