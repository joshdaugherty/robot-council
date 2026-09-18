<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp;

use RuntimeException;

/**
 * Narrowing for the arguments an MCP call arrives with.
 *
 * A tool's arguments are validated against its schema before `handle()` runs, so by the time these
 * are reached the shape is already right. What they add is a type the analyzer can see: `Request`
 * returns `mixed`, and a cast would turn an absent argument into `0` or `''` and carry on rather
 * than saying so.
 */
final class Arguments
{
    /**
     * One whole number from a tool call.
     *
     * @param  mixed  $value  The argument.
     * @return int The value.
     *
     * @throws RuntimeException When it is not a whole number.
     */
    public static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf('Expected a whole number, got %s.', get_debug_type($value)));
    }

    /**
     * One string from a tool call.
     *
     * @param  mixed  $value  The argument.
     * @return string The value.
     *
     * @throws RuntimeException When it is not a string.
     */
    public static function string(mixed $value): string
    {
        if (! \is_string($value)) {
            throw new RuntimeException(sprintf('Expected a string, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * One structure a client attached, or nothing.
     *
     * @param  mixed  $value  The argument.
     * @return array<array-key, mixed>|null The value, or null when it is absent or empty.
     */
    public static function structure(mixed $value): ?array
    {
        return \is_array($value) && $value !== [] ? $value : null;
    }
}
