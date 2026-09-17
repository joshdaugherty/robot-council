<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * The kinds of thing the fleet's change feed records.
 *
 * Narration is what an agent says about its own work, and it is the only kind whose visibility
 * depends on who is reading (#29). Everything else describes a change to fleet state, or is a
 * directive, and reaches every reader.
 *
 * A type is stored as its string value, so a later release can add one without renumbering
 * anything, and a reader that does not recognize one can still page past it.
 */
enum FleetEventType: string
{
    /**
     * An agent talking about its own work. Visible to its own developer, and to everyone when the
     * session that posted it held the coordinator's ability at the time.
     */
    case Narration = 'narration';

    /**
     * An instruction to the fleet from a session holding `coordinator:direct`.
     */
    case Directive = 'directive';

    /**
     * A new agent session came into existence.
     */
    case SessionEnrolled = 'session.enrolled';

    /**
     * Whether an event of this type is only visible to some readers.
     *
     * @return bool True for narration, which #29 restricts, and false for everything else.
     */
    public function isRestricted(): bool
    {
        return $this === self::Narration;
    }
}
