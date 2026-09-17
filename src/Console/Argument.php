<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

/**
 * Reads a console argument as text.
 *
 * It takes `mixed` rather than reading the argument itself, and that is the point. What
 * `Illuminate\Console\Command::argument()` is inferred to return depends on whether the analyzer
 * could boot the application and read the command's signature, which differs between a developer's
 * machine and CI. A narrowing written against either answer is reported as dead code by the other.
 * Taking `mixed` is true in both.
 */
final class Argument
{
    /**
     * One console argument as a string.
     *
     * @param  mixed  $value  Whatever the argument came back as.
     * @return string The value as text, or an empty string for an argument that is not scalar.
     */
    public static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
