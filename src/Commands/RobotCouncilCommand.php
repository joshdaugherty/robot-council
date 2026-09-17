<?php

namespace JoshDaugherty\RobotCouncil\Commands;

use Illuminate\Console\Command;

class RobotCouncilCommand extends Command
{
    public $signature = 'robot-council';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
