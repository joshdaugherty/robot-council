<?php

declare(strict_types=1);

namespace RobotCouncil\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;

/**
 * A host application's user model keyed by UUID, as `HasUuids` gives it.
 *
 * Its key is fixed, and fixed to a UUID that begins with digits. That is the point: `(int)` turns
 * `12345678-90ab-...` into `12345678`, a number that looks entirely plausible in a column and in an
 * error message, so a stray cast anywhere in the package would file a developer under a key that is
 * neither theirs nor obviously wrong. A UUID beginning with a letter would make such a cast produce
 * `0` and announce itself.
 */
#[Table(name: 'users')]
class UuidHostUser extends User
{
    use HasUuids;

    /**
     * The key every user created in a test carries, so assertions can name it.
     */
    public const string FIXED_ID = '12345678-90ab-4cde-8f01-234567890abc';

    /**
     * The key to give a new user.
     *
     * @return string The fixed UUID.
     */
    public function newUniqueId(): string
    {
        return self::FIXED_ID;
    }
}
