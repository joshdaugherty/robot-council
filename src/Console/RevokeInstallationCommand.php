<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Console\Concerns\ManagesInstallations;
use RobotCouncil\Models\Installation;
use RobotCouncil\Support\Installations;

/**
 * Revokes an installation: its credential and every session token it issued stop working on the
 * next request. This is what a lost or stolen machine calls for, and it does not wait for the
 * installation's maximum age to run out.
 */
#[Description('Revoke a robot-council installation and every session token it issued')]
#[Signature("robot-council:revoke-installation {installation : The installation's ID}")]
final class RevokeInstallationCommand extends Command
{
    use ManagesInstallations;

    /**
     * Revoke the installation.
     *
     * @param  Installations  $installations  The installation store.
     * @return int The command's exit code.
     */
    public function handle(Installations $installations): int
    {
        $installation = $this->installationArgument();

        if (! $installation instanceof Installation) {
            return self::FAILURE;
        }

        $deleted = $installations->revoke($installation);

        $this->components->info(sprintf(
            'Revoked installation %d (%s on %s). %d token(s) deleted.',
            $installation->id,
            $installation->harness,
            $installation->machine_label,
            $deleted
        ));

        return self::SUCCESS;
    }
}
