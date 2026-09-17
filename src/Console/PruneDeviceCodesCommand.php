<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Support\DeviceCodes;

/**
 * Deletes device codes that have expired. The service provider schedules it hourly, and it is safe
 * to run by hand at any time: an unexpired code is never touched, whether or not it has been
 * decided, because a decided code is still waiting to be exchanged.
 */
#[Description('Delete expired robot-council device codes')]
#[Signature('robot-council:prune-device-codes')]
final class PruneDeviceCodesCommand extends Command
{
    /**
     * Delete the expired codes.
     *
     * @param  DeviceCodes  $deviceCodes  The device-code store.
     * @return int The command's exit code.
     */
    public function handle(DeviceCodes $deviceCodes): int
    {
        $deleted = $deviceCodes->prune();

        $this->components->info(sprintf('Deleted %d expired device code(s).', $deleted));

        return self::SUCCESS;
    }
}
