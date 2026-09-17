<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RuntimeException;

/**
 * The one place a host application's user key is narrowed.
 *
 * The package stores that key as a string, so an integer, a UUID, and a ULID host all work: every
 * table holding it has one row per developer, or per installation, or per running process, so none
 * of them is large enough for the usual argument against a string key to apply.
 *
 * Narrowing happens here rather than at each call site because the cast that must never happen is
 * `(int)`. A UUID beginning with digits casts to a plausible-looking number instead of failing, so
 * a stray cast would not announce itself -- it would quietly file a developer under somebody else's
 * key.
 */
final class HostKey
{
    /**
     * A host user key as the package stores it.
     *
     * @param  mixed  $key  Whatever the host's model or guard returned.
     * @return string The key as text.
     *
     * @throws RuntimeException When the key is neither an integer nor a non-empty string.
     */
    public static function from(mixed $key): string
    {
        $value = self::tryFrom($key);

        if ($value === null) {
            throw new RuntimeException(sprintf('robot-council stores a host user key as text, and this one is %s.', get_debug_type($key)));
        }

        return $value;
    }

    /**
     * A host user key, or null when there is nothing usable to store.
     *
     * @param  mixed  $key  Whatever the host's model or guard returned.
     * @return string|null The key as text, or null.
     */
    public static function tryFrom(mixed $key): ?string
    {
        if (\is_int($key)) {
            return (string) $key;
        }

        return \is_string($key) && $key !== '' ? $key : null;
    }
}
