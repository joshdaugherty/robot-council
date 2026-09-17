<?php

declare(strict_types=1);

namespace RobotCouncil\Console\Concerns;

use RobotCouncil\Models\Installation;

/**
 * Reading the installation argument every installation command takes.
 *
 * A trait rather than a class the commands call: `$this->components`, the styled output they report
 * through, is protected on `Illuminate\Console\Command`, so only code composed into the command can
 * reach it. A shared parent is not available either -- Pest's `strict()` preset forbids abstract
 * classes in the package's namespaces, because a consumer must not be able to extend one.
 */
trait ManagesInstallations
{
    /**
     * Read the installation argument.
     *
     * @return Installation|null The installation, or null once the refusal has been reported.
     */
    private function installationArgument(): ?Installation
    {
        $id = $this->argument('installation');

        $installation = Installation::query()->whereKey($id)->first();

        if (! $installation instanceof Installation) {
            $this->components->error(sprintf('No installation with ID %s.', $id));

            return null;
        }

        return $installation;
    }
}
