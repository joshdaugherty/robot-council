<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An advisory lease over a name.
 *
 * Advisory is the whole of it: nothing in the package enforces what a lock guards. It is a claim
 * one session makes that the fleet can see and that expires on its own, so a holder that stopped
 * answering cannot block everyone else past its lease.
 *
 * A row with a null `holder_id` is a free name that has been held before, and it is kept for the
 * fence it carries.
 *
 * @property int $id
 * @property string $name
 * @property int|null $holder_id
 * @property int $fence
 * @property Carbon|null $acquired_at
 * @property Carbon|null $expires_at
 * @property-read AgentSession|null $holder
 */
#[Fillable([
    'name',
    'holder_id',
    'fence',
    'acquired_at',
    'expires_at',
])]
#[Table(name: 'robot_council_locks')]
final class Lock extends Model
{
    /**
     * The longest name a lock may carry.
     */
    public const int MAX_NAME = 191;

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
            'holder_id' => 'integer',
            'fence' => 'integer',
            'acquired_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The session holding this lock, while one does.
     *
     * @return BelongsTo<AgentSession, $this> The holder.
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'holder_id');
    }

    /**
     * Whether the lease is still running.
     *
     * @param  Carbon  $now  The moment to judge it at.
     * @return bool True while somebody holds it and the lease has not lapsed.
     */
    public function isHeldAt(Carbon $now): bool
    {
        return $this->holder_id !== null
            && $this->expires_at instanceof Carbon
            && $this->expires_at->greaterThan($now);
    }
}
