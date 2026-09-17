<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * A host application's own personal access token model, of the kind
 * `Sanctum::usePersonalAccessTokenModel()` accepts: it implements `HasAbilities` and does not
 * extend Sanctum's class.
 *
 * It exists to pin one thing. The package asks "is this a stored token or the transient one a
 * browser session carries", and the tempting way to write that is `instanceof PersonalAccessToken`
 * -- which answers false for this perfectly valid token and would refuse every request such a host
 * made, with a bare 401 and nothing to read.
 *
 * @property array<int, string> $abilities
 */
#[Fillable(['name', 'token', 'abilities', 'expires_at'])]
#[Table(name: 'personal_access_tokens')]
class HostTokenModel extends Model implements HasAbilities
{
    /**
     * The model this token authenticates as.
     *
     * @return MorphTo<Model, $this> The token's owner.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable');
    }

    /**
     * Find the token matching a plaintext value, as Sanctum's guard does.
     *
     * @param  string  $token  The plaintext bearer token.
     * @return static|null The token, or null when there is none.
     */
    public static function findToken($token): ?self
    {
        $parts = explode('|', (string) $token, 2);

        $secret = $parts[1] ?? $parts[0];

        $instance = \count($parts) === 2
            ? static::query()->find($parts[0])
            : static::query()->where('token', hash('sha256', $secret))->first();

        if (! $instance instanceof static) {
            return null;
        }

        $stored = $instance->getAttribute('token');

        return \is_string($stored) && hash_equals($stored, hash('sha256', $secret)) ? $instance : null;
    }

    /**
     * Whether the token carries an ability.
     *
     * @param  string  $ability  The ability to check.
     * @return bool True when the token holds it.
     */
    public function can($ability)
    {
        return \in_array($ability, $this->abilities, true);
    }

    /**
     * Whether the token lacks an ability.
     *
     * @param  string  $ability  The ability to check.
     * @return bool True when the token does not hold it.
     */
    public function cant($ability)
    {
        return ! $this->can($ability);
    }

    /**
     * The attribute casts.
     *
     * @return array<string, string> The casts Eloquent applies.
     */
    protected function casts(): array
    {
        return ['abilities' => 'json', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
