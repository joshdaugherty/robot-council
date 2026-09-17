<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * What came of attempting a transition, in the four answers the API has for one.
 *
 * The store returns this rather than throwing, because three of the four are ordinary outcomes of a
 * race rather than errors: two agents claiming one task, a coordinator cancelling while its
 * claimant works, the presence sweep releasing underneath both. Only the caller knows whether that
 * is worth reporting.
 */
enum TaskOutcome
{
    /**
     * The write changed the row, and an event was recorded.
     */
    case Applied;

    /**
     * No such task.
     */
    case NotFound;

    /**
     * The task exists and is not in a status this transition starts from -- including because
     * somebody else got there first.
     */
    case Conflict;

    /**
     * The task exists and is in a workable status, but this session may not do this to it.
     */
    case Forbidden;

    /**
     * The HTTP status this outcome answers with.
     *
     * @return int The status code.
     */
    public function status(): int
    {
        return match ($this) {
            self::Applied => Response::HTTP_OK,
            self::NotFound => Response::HTTP_NOT_FOUND,
            self::Conflict => Response::HTTP_CONFLICT,
            self::Forbidden => Response::HTTP_FORBIDDEN,
        };
    }
}
