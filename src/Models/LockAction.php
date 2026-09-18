<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use RobotCouncil\Access\Ability;

/**
 * What can be done to a lock, and what a session needs to do it.
 *
 * A URL segment rather than a request field, and the route's regex is built from `values()`, so an
 * action that does not exist is a 404 from the router. The lock's **name** is not in the path: a
 * Laravel route parameter does not match `/`, and a name like `branch:feature/foo` would never
 * reach a route that carried it.
 */
enum LockAction: string
{
    /**
     * Take a free name, or one whose lease has lapsed.
     */
    case Acquire = 'acquire';

    /**
     * Extend a lease this session holds.
     */
    case Renew = 'renew';

    /**
     * Give up a lease this session holds.
     */
    case Release = 'release';

    /**
     * Take a lock away from whoever holds it.
     */
    case ForceRelease = 'force-release';

    /**
     * The ability a session needs to do this.
     *
     * @return Ability The required ability.
     */
    public function ability(): Ability
    {
        return match ($this) {
            self::ForceRelease => Ability::CoordinatorDirect,
            self::Acquire, self::Renew, self::Release => Ability::LocksAcquire,
        };
    }

    /**
     * Whether this action takes a lease duration.
     *
     * @return bool True for the two that set an expiry.
     */
    public function takesATtl(): bool
    {
        return $this === self::Acquire || $this === self::Renew;
    }

    /**
     * Every action's URL segment.
     *
     * @return list<string> The values, in declaration order.
     */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
