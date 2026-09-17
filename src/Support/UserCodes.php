<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Support\Contracts\DrawsUserCodes;

/**
 * Draws user codes from the RFC 8628 section 6.1 alphabet: capital letters with the vowels and the
 * characters that look like digits removed, so nothing a developer reads off one screen can be
 * mistyped into another as something else.
 */
final class UserCodes implements DrawsUserCodes
{
    /**
     * The alphabet a user code is drawn from.
     */
    public const string ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    /**
     * How many characters a user code carries.
     */
    public const int LENGTH = 8;

    /**
     * Draw one candidate user code.
     *
     * `random_int` rather than `rand` or `str_shuffle`: the code is the whole of what a developer
     * checks before approving a machine, so a predictable one is a phishable one.
     *
     * @return string An eight-character code.
     */
    public function draw(): string
    {
        $highest = \strlen(self::ALPHABET) - 1;

        $code = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $code .= self::ALPHABET[random_int(0, $highest)];
        }

        return $code;
    }
}
