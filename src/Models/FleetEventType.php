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

    /**
     * The types whose visibility depends on who is reading.
     *
     * The feed's query is built from this rather than from a hardcoded comparison, so adding a
     * restricted type is a matter of declaring it restricted. The other way round fails open: the
     * new type would be served to every reader, and the one place a reviewer looks to confirm the
     * boundary would be the place that does not enforce it.
     *
     * @return list<string> The restricted types, as stored.
     */
    public static function restrictedValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isRestricted())
        ));
    }
}
