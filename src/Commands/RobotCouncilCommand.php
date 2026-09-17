<?php

declare(strict_types=1);

namespace JoshDaugherty\RobotCouncil\Commands;

use Illuminate\Console\Command;

final class RobotCouncilCommand extends Command
{
    public $signature = 'robot-council';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
