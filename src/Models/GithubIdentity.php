<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps one host user to one GitHub account. The package owns this table rather than a column on
 * the host's users table, because the mapping is what the access lists are checked against: a
 * host-owned column could be written by mass assignment, a seeder, an admin form, or the host's
 * own GitHub linking, and whoever wrote it would hold an allowlisted identity.
 *
 * There is no foreign key to the host's users table, whose name and key type are the host's to
 * choose. An identity whose user has gone is refused at sign-in instead.
 *
 * @property int $user_id
 * @property int $github_id
 * @property string $github_login
 * @property string|null $avatar_url
 */
#[Fillable([
    'user_id',
    'github_id',
    'github_login',
    'avatar_url',
])]
#[Table(name: 'robot_council_github_identities')]
final class GithubIdentity extends Model
{
    /**
     * The attribute casts.
     *
     * Public rather than protected, which PHP allows a subclass to widen to, because Pest's
     * `strict()` preset forbids protected methods in the package's namespaces.
     *
     * @return array<string, string> The casts Eloquent applies to this model's attributes.
     */
    public function casts(): array
    {
        return [
            'user_id' => 'integer',
            'github_id' => 'integer',
        ];
    }
}
