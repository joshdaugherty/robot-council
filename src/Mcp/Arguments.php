<?php

declare(strict_types=1);

namespace RobotCouncil\Mcp;

use RuntimeException;

/**
 * Narrowing for the arguments an MCP call arrives with.
 *
 * **A tool's schema is advertised, not enforced.** `laravel/mcp` serializes it into `tools/list` and
 * then calls `handle()` with whatever arrived -- `Server\ToolInvoker` has no validation step at all.
 * So every tool validates the arguments it acts on, the same way its REST endpoint does, and these
 * narrow what is left afterwards. A cast would turn an absent argument into `0` or `''` and carry
 * on; these refuse, so a malformed call cannot be mistaken for a call that meant zero.
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
        // `filter_var` rather than `is_int` plus `ctype_digit`, because Laravel's `integer` rule is
        // `filter_var($value, FILTER_VALIDATE_INT) !== false` and the two sets are not nested: a
        // JSON `12.0` decodes to a float, and `" 12"`, `"12 "`, `"+12"` and `true` all satisfy the
        // rule. Narrower here, every one of those passed its tool's validation and then threw --
        // and a throw out of a tool is reported to the host's log and returned as an internal
        // error, which is the thing validating the arguments was meant to stop. Matching the rule
        // exactly is what makes the seam closed by construction rather than by agreement.
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false) {
            throw new RuntimeException(sprintf('Expected a whole number, got %s.', get_debug_type($value)));
        }

        return $integer;
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
