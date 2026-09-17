<?php

declare(strict_types=1);

namespace RobotCouncil\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Bounds the structured metadata a client may attach to an event.
 *
 * `body` was capped from the start; `meta` was not, and an `array` rule bounds nothing. A JSON
 * request body is not subject to `max_input_vars`, so the only ceiling was `post_max_size` --
 * megabytes per event, stored whole and returned whole. At the rate the per-session limit permits,
 * one agent could write hundreds of megabytes a minute into a table nothing prunes, and a single
 * page of the feed would then hydrate two hundred of them into one response.
 */
final class BoundedMeta implements ValidationRule
{
    /**
     * The largest encoded metadata one event may carry.
     */
    public const int MAX_BYTES = 4096;

    /**
     * How deeply it may nest.
     */
    public const int MAX_DEPTH = 8;

    /**
     * Refuse metadata too large or too deep to be worth storing.
     *
     * @param  string  $attribute  The field being validated.
     * @param  mixed  $value  What the client sent.
     * @param  Closure(string, string=): PotentiallyTranslatedString  $fail  How to report a refusal.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! \is_array($value)) {
            $fail('The :attribute field must be an object.');

            return;
        }

        // No `JSON_PARTIAL_OUTPUT_ON_ERROR`: it makes `json_encode` return partial output instead
        // of false, which is precisely the depth failure this is here to catch
        $encoded = json_encode($value, 0, self::MAX_DEPTH);

        // False once a value nests past the depth it was given, or cannot be encoded at all. Both
        // are the same refusal as being too large: not worth storing and serving to the fleet.
        if ($encoded === false) {
            $fail(sprintf('The :attribute field must not nest more than %d levels deep.', self::MAX_DEPTH));

            return;
        }

        if (\strlen($encoded) > self::MAX_BYTES) {
            $fail(sprintf('The :attribute field must not exceed %d bytes once encoded.', self::MAX_BYTES));
        }
    }
}
