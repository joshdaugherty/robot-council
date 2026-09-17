<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * **Every step runs, and then the first failure is rethrown.** A step that throws still fails the
 * sweep, which is the intended direction -- a release that failed silently leaves a task claimed by
 * a process that no longer exists, and nothing else reports that. But it must not be able to
 * suppress a sibling: #25 releases tasks and #26 releases locks, and they are independent. A bare
 * loop would let a step that throws deterministically stop the one registered after it on every
 * sweep forever, so the locks of every session that ever went would be held with nothing to say so.
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
     * Run every registered step, in the order they were registered.
     *
     * @throws Throwable The first failure, once every step has had its turn.
     */
    public function run(): void
    {
        $failed = null;

        foreach ($this->steps as $position => $step) {
            try {
                $step();
            } catch (Throwable $failure) {
                // Logged per step, because only the first one is rethrown and a second subsystem
                // failing at the same time is the thing an operator most needs to know
                Log::error(
                    sprintf('robot-council: release step %d failed during the presence sweep.', $position),
                    ['exception' => $failure]
                );

                $failed ??= $failure;
            }
        }

        if ($failed instanceof Throwable) {
            throw $failed;
        }
    }
}
