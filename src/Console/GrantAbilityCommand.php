<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RobotCouncil\Console\Concerns\ManagesAbilities;
use RobotCouncil\Support\Installations;

/**
 * Adds one ability to an installation, and to the session tokens already in flight under it.
 *
 * This is the only way `coordinator:direct` is ever granted. It cannot be asked for at enrollment,
 * so promoting a machine to coordinate other developers' agents is always a deliberate act by an
 * admin, on a machine that was already approved.
 */
#[Description('Grant one ability to a robot-council installation')]
#[Signature("robot-council:grant-ability {installation : The installation's ID} {ability : The ability to grant}")]
final class GrantAbilityCommand extends Command
{
    use ManagesAbilities;

    /**
     * Add the ability.
     *
     * @param  Installations  $installations  The installation store.
     * @return int The command's exit code.
     */
    public function handle(Installations $installations): int
    {
        return $this->applyAbility($installations, granted: true);
    }
}
