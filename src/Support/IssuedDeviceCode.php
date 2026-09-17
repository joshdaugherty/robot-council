<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\DeviceCode;

/**
 * A device code as it exists for the one response that carries it: the stored row, whose columns
 * hold only hashes, beside the plaintext the helper has to keep. Nothing persists the plaintext,
 * and nothing reads it back afterwards.
 */
final class IssuedDeviceCode
{
    /**
     * @param  DeviceCode  $record  The stored request, holding hashes rather than secrets.
     * @param  string  $deviceCode  The plaintext device code, returned to the helper once.
     */
    public function __construct(
        public readonly DeviceCode $record,
        public readonly string $deviceCode
    ) {}
}
