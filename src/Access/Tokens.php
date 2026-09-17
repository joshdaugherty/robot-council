<?php

declare(strict_types=1);

namespace RobotCouncil\Access;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\TransientToken;

/**
 * What a principal's current token is, and what it may do.
 *
 * These take the token as `HasAbilities`, the interface Sanctum actually sets, rather than as the
 * `PersonalAccessToken` that `HasApiTokens::currentAccessToken()` is typed to return. The
 * difference is the whole point: Sanctum's guard tries the `web` guard first, and hands a signed-in
 * human a `TransientToken`, whose `can()` answers true to every ability that was ever named. Static
 * analysis reads the generic default and concludes a stored token is the only possibility, so a
 * check written against that type reads as dead code while being the one thing standing between a
 * browser session and an agent's abilities. Taking the interface keeps the check honest to both.
 *
 * What counts as stored is "not the transient one", never "an instance of Sanctum's own model". A
 * host may call `Sanctum::usePersonalAccessTokenModel()` with any class implementing `HasAbilities`,
 * and testing for Sanctum's class would refuse that host's perfectly valid tokens on every request,
 * with a bare 401 and nothing to read.
 */
final class Tokens
{
    /**
     * Whether the token is a stored one rather than the transient token a browser session carries.
     *
     * @param  HasAbilities|null  $token  The principal's current token.
     * @return bool True only for a token that was issued, stored, and presented as a bearer token.
     */
    public static function isStored(?HasAbilities $token): bool
    {
        return $token instanceof HasAbilities && ! $token instanceof TransientToken;
    }

    /**
     * Whether the token is a stored one carrying a given ability.
     *
     * @param  HasAbilities|null  $token  The principal's current token.
     * @param  Ability  $ability  The ability the route needs.
     * @return bool True when a stored token holds the ability.
     */
    public static function allows(?HasAbilities $token, Ability $ability): bool
    {
        return $token instanceof HasAbilities && ! $token instanceof TransientToken && $token->can($ability->value);
    }

    /**
     * How many rows a token delete removed.
     *
     * Eloquent's relation `delete()` is typed as returning whatever the underlying builder does, so
     * the count arrives untyped. Anything but a whole number reads as none removed rather than
     * being cast, because a cast would turn an unexpected return into a plausible figure.
     *
     * @param  mixed  $result  What the delete returned.
     * @return int The number of tokens deleted.
     */
    public static function deleted(mixed $result): int
    {
        return \is_int($result) ? $result : 0;
    }

    /**
     * The abilities a stored token carries.
     *
     * @param  HasAbilities|null  $token  The principal's current token.
     * @return list<string> The abilities, or none at all for anything but a stored token.
     */
    public static function abilities(?HasAbilities $token): array
    {
        // A stored token is an Eloquent row whatever model a host configured, and the transient
        // one a browser session carries is not a model at all, so this is the same question
        // `isStored()` asks, in the form that also gives access to the column
        if (! $token instanceof Model) {
            return [];
        }

        $abilities = $token->getAttribute('abilities');

        if (! \is_array($abilities)) {
            return [];
        }

        return array_values(array_filter($abilities, is_string(...)));
    }
}
