<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One enrollment request, from the moment a helper asks for a code until a developer decides it and
 * the helper exchanges it.
 *
 * Neither the `device_code` nor the verifier challenge is stored as sent: the table holds their
 * SHA-256 hashes, so reading the row gives an attacker nothing to exchange. `user_code` is stored
 * as sent, because a developer has to read it off one screen and type it into another.
 *
 * Everything the requester supplied -- `harness`, `machine_label`, and the abilities asked for --
 * is a claim, not a fact. The verification page says so, and the server computes what is granted.
 *
 * @property int $id
 * @property string $device_code_hash
 * @property string $challenge_hash
 * @property string $user_code
 * @property list<string> $requested_abilities
 * @property list<string>|null $granted_abilities
 * @property string $harness
 * @property string $machine_label
 * @property string|null $requested_ip
 * @property Carbon $expires_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $denied_at
 * @property Carbon|null $consumed_at
 * @property string|null $decided_by
 * @property Carbon $created_at
 */
#[Fillable([
    'device_code_hash',
    'challenge_hash',
    'user_code',
    'requested_abilities',
    'harness',
    'machine_label',
    'requested_ip',
    'expires_at',
])]
#[Table(name: 'robot_council_device_codes')]
final class DeviceCode extends Model
{
    /**
     * The attribute casts.
     *
     * Public rather than protected, because Pest's `strict()` preset forbids protected methods in
     * the package's namespaces, and PHP allows a subclass to widen a parent's visibility.
     *
     * @return array<string, string> The casts Eloquent applies to this model's attributes.
     */
    public function casts(): array
    {
        return [
            'requested_abilities' => 'array',
            'granted_abilities' => 'array',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Determine whether a developer has already approved or denied this code.
     *
     * @return bool True once either decision has been recorded.
     */
    public function isDecided(): bool
    {
        return $this->approved_at !== null || $this->denied_at !== null;
    }

    /**
     * How long ago the helper asked for this code.
     *
     * Shown on the verification page: a code a developer did not just request is the shape a
     * phishing attempt takes, where an attacker's code waits for somebody to approve it.
     *
     * @return int The code's age in seconds, never negative.
     */
    public function ageInSeconds(): int
    {
        return max(0, (int) $this->created_at->diffInSeconds(Carbon::now()));
    }
}
