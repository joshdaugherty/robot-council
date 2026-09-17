<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Closure;

/**
 * The steps the presence sweep runs after it has finished marking sessions, and the package's
 * extension point for releasing what a session that has gone was holding.
 *
 * **A step runs on every sweep, not once per session that went gone.** That is deliberate: the
 * per-session signal is `Events\SessionGone`, and a signal can be missed -- a queue worker that
 * died mid-job, a listener that threw, a release that raced a claim being taken. A step that sweeps
 * for orphans on a schedule cannot be missed in the same way, so the two together mean a lock
 * outlives its session by at most one sweep interval rather than forever.
 *
 * Registered rather than discovered, because the package's classes are `final` and a host cannot
 * subclass the sweep. It is a singleton, so a step registered from a service provider is there for
 * every sweep the process runs.
 *
 * A step that throws stops the sweep, and that is the intended direction: a release that silently
 * failed leaves a task claimed by a process that no longer exists, which nothing else reports.
 */
final class SessionReleases
{
    /**
     * The registered steps, in the order they were registered.
     *
     * @var list<Closure(): void>
     */
    private array $steps = [];

    /**
     * Register a step to run at the end of every sweep.
     *
     * @param  Closure(): void  $step  What to release.
     */
    public function register(Closure $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Run every registered step.
     */
    public function run(): void
    {
        foreach ($this->steps as $step) {
            $step();
        }
    }

    /**
     * How many steps are registered.
     *
     * @return int The number of steps a sweep will run.
     */
    public function count(): int
    {
        return \count($this->steps);
    }
}
