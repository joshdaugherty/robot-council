<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Models\Lock;
use Throwable;

/**
 * Taking, extending, and giving up the fleet's named leases.
 *
 * **Every state change is one conditional update, and the count of changed rows is the decision.**
 * Acquiring names the states a lock may be taken from -- free, or held by a lease that has lapsed --
 * so two sessions reaching for one free name is settled by the write. Renewing and releasing name
 * the holder and an unexpired lease, so a session that was taken over cannot extend or release what
 * it no longer has.
 *
 * **A released lock keeps its row with no holder, and that is what makes the fence cheap.** Every
 * acquisition returns a number greater than any previously issued for that name, across releases as
 * well as takeovers, and a deleted row takes the only record of that name's last fence with it. The
 * number is incremented in the same statement that takes the lock, so it cannot be handed out twice.
 *
 * **A fence is what makes an advisory lease safe to act on.** Nothing here can stop a session that
 * lost its lease from carrying on, so the holder carries its fence into whatever it guards, and the
 * guarded thing refuses anything below the highest it has seen.
 *
 * **Lock order: agent sessions, then locks, then the feed sentinel**, the same order the rest of the
 * package takes. The release step locks the session row before the lock row; nothing else here
 * touches a session row, and the `holder_id` foreign key's implicit parent lock is taken after the
 * lock row only where no path holds the two the other way round.
 */
final class Locks
{
    /**
     * What a lock name may contain. Kept beside the controller's rule rather than only in it,
     * because this service is public and other slices call it directly.
     */
    public const string NAME = '/^[A-Za-z0-9._:\/-]+$/D';

    /**
     * How many times a contended write is retried before it is reported.
     */
    private const int ATTEMPTS = 3;

    /**
     * @param  Credentials  $credentials  The configured bounds.
     * @param  FleetEvents  $events  The change feed.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly FleetEvents $events
    ) {}

    /**
     * Take a lock, if it is free or its lease has lapsed.
     *
     * @param  AgentSession  $session  The session taking it.
     * @param  string  $name  The name to take.
     * @param  int  $ttl  How long to hold it, in seconds.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return array{outcome: Outcome, lock: Lock|null} What came of it, and the lock when it was taken.
     */
    public function acquire(AgentSession $session, string $name, int $ttl, bool $asCoordinator): array
    {
        // Checked here as well as in the controller, because this is a public method on an
        // injectable service and the drivers disagree about what an over-long name does: MySQL's
        // `insert ignore` silently truncates it -- so every later lookup by the full name misses,
        // leaving a junk row and a permanent conflict -- while Postgres raises and SQLite stores it.
        if ($name === '' || mb_strlen($name) > Lock::MAX_NAME || preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException('A lock name must be 1 to 191 characters of [A-Za-z0-9._:/-].');
        }

        // Retried, because the contended case is what this method is for. A deadlock or a lock-wait
        // timeout rolls the whole transaction back, so a retry starts from a clean slate.
        return DB::transaction(function () use ($session, $name, $ttl, $asCoordinator): array {
            $now = Carbon::now();

            // The session row first, which is the package's lock order and is also what makes the
            // cap below hold. Counting without it is a plain read against rows keyed by `name`,
            // and two acquisitions from one session never touch the same row -- so both would
            // count the same number and both would pass a cap neither was under.
            AgentSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

            // Counted inside the transaction, against live leases only: a session that holds
            // twenty names whose leases have all lapsed is holding nothing
            $held = Lock::query()
                ->where('holder_id', $session->getKey())
                ->where('expires_at', '>', $now)
                ->count();

            if ($held >= $this->credentials->locksPerSession()) {
                return ['outcome' => Outcome::Conflict, 'lock' => null];
            }

            // Only when the row is not there yet. Ignoring the unique conflict is what makes two
            // sessions creating one name at once a no-op rather than an error, but issuing it on
            // every acquisition is what makes the contended case deadlock: InnoDB gives a plain
            // insert that hits a duplicate key a SHARED lock on that record, so two acquisitions
            // of an existing name both take S and then both need X, and one of them is killed.
            // Checking first leaves that window only on a name's first-ever creation.
            //
            // The input was validated before any of this, because SQLite's `insert or ignore`
            // swallows a NOT NULL or CHECK violation too -- and MySQL's `insert ignore` swallows
            // truncation on top of that -- which would otherwise read as somebody else having won.
            if (! Lock::query()->where('name', $name)->exists()) {
                Lock::query()->insertOrIgnore([
                    'name' => $name,
                    'fence' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Locked, so the event's type and `taken_from` describe the row the update is about
            // to change rather than one that moved in between. Same row and the same order as the
            // update, so it adds no ordering edge.
            $before = Lock::query()->where('name', $name)->lockForUpdate()->first();

            $taken = Lock::query()
                ->where('name', $name)
                ->where(fn (Builder $free) => $free
                    ->whereNull('holder_id')
                    ->orWhere('expires_at', '<=', $now))
                ->update([
                    // Before `holder_id`, and the order matters: MySQL evaluates a SET clause left
                    // to right, so this has to read the old holder before the next line overwrites
                    // it. Postgres and SQLite evaluate every right-hand side against the pre-update
                    // row, so they agree either way.
                    'previous_holder_id' => DB::raw('holder_id'),
                    'holder_id' => $session->getKey(),

                    // In the same statement that takes it, so no two acquisitions can read the
                    // same number and hand it out twice
                    'fence' => DB::raw('fence + 1'),
                    'acquired_at' => $now,
                    'expires_at' => $now->copy()->addSeconds($ttl),
                    'updated_at' => $now,
                ]);

            if ($taken !== 1) {
                // Held, and the lease is still running. Whether it is this session's own hold or
                // another's, the answer is the same: it is not free to take.
                return ['outcome' => Outcome::Conflict, 'lock' => null];
            }

            $lock = Lock::query()->where('name', $name)->sole();

            $takenOver = $before instanceof Lock && $before->holder_id !== null;

            $this->events->record(
                $takenOver ? FleetEventType::LockTakenOver : FleetEventType::LockAcquired,
                $session,
                sprintf('%s %s.', $takenOver ? 'Took over' : 'Acquired', $name),
                array_filter([
                    'lock' => $name,
                    'fence' => $lock->fence,
                    'taken_from' => $takenOver ? $before->holder_id : null,
                ], static fn (mixed $value): bool => $value !== null),
                $asCoordinator
            );

            return ['outcome' => Outcome::Applied, 'lock' => $lock];
        }, self::ATTEMPTS);
    }

    /**
     * Extend a lease this session holds.
     *
     * @param  AgentSession  $session  The holder.
     * @param  string  $name  The name to extend.
     * @param  int  $ttl  How much longer to hold it, in seconds, from now.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return array{outcome: Outcome, lock: Lock|null} What came of it.
     */
    public function renew(AgentSession $session, string $name, int $ttl, bool $asCoordinator): array
    {
        return DB::transaction(function () use ($session, $name, $ttl, $asCoordinator): array {
            $now = Carbon::now();
            $until = $now->copy()->addSeconds($ttl);

            // A hold cannot be pushed past the ceiling measured from when it was first acquired,
            // so a session cannot keep one name forever by renewing it. Expressed as a condition
            // on `acquired_at` so it rides in the same write as everything else.
            $acquiredAfter = $until->copy()->subSeconds($this->credentials->lockMaxHoldSeconds());

            $renewed = Lock::query()
                ->where('name', $name)
                ->where('holder_id', $session->getKey())
                ->where('expires_at', '>', $now)
                ->where('acquired_at', '>=', $acquiredAfter)
                ->update(['expires_at' => $until, 'updated_at' => $now]);

            if ($renewed !== 1) {
                // A renewal can change nothing and still be right. MySQL's `update()` reports rows
                // it CHANGED rather than rows it matched -- Laravel sets no `MYSQL_ATTR_FOUND_ROWS`
                // and reads `PDOStatement::rowCount()` -- and these columns are second-precision,
                // so renewing within the same second as the last write is a no-op on a row that
                // already says what was asked for. Reading that as a lost lease would tell a
                // holder that still holds the lock to abandon whatever it was guarding.
                $already = Lock::query()->where('name', $name)->lockForUpdate()->first();

                $satisfied = $already instanceof Lock
                    && $already->holder_id === $session->getKey()
                    && $already->expires_at instanceof Carbon
                    && $already->expires_at->greaterThanOrEqualTo($until);

                if (! $satisfied) {
                    return ['outcome' => $this->diagnose($name, $session, $now), 'lock' => null];
                }

                return ['outcome' => Outcome::Applied, 'lock' => $already];
            }

            $lock = Lock::query()->where('name', $name)->sole();

            // The fence is untouched. A renewal is the same hold continuing, so anything guarding
            // it must not be told the lease is newer than it is.
            $this->events->record(
                FleetEventType::LockRenewed,
                $session,
                sprintf('Renewed %s.', $name),
                ['lock' => $name, 'fence' => $lock->fence],
                $asCoordinator
            );

            return ['outcome' => Outcome::Applied, 'lock' => $lock];
        });
    }

    /**
     * Give up a lease this session holds.
     *
     * @param  AgentSession  $session  The holder.
     * @param  string  $name  The name to give up.
     * @param  bool  $asCoordinator  Whether the session holds `coordinator:direct`.
     * @return Outcome What came of it.
     */
    public function release(AgentSession $session, string $name, bool $asCoordinator): Outcome
    {
        return DB::transaction(function () use ($session, $name, $asCoordinator): Outcome {
            $now = Carbon::now();

            $released = Lock::query()
                ->where('name', $name)
                ->where('holder_id', $session->getKey())
                ->where('expires_at', '>', $now)
                ->update(['holder_id' => null, 'expires_at' => null, 'updated_at' => $now]);

            if ($released !== 1) {
                return $this->diagnose($name, $session, $now);
            }

            $this->events->record(
                FleetEventType::LockReleased,
                $session,
                sprintf('Released %s.', $name),
                ['lock' => $name],
                $asCoordinator
            );

            return Outcome::Applied;
        });
    }

    /**
     * Take a lock away from whoever holds it.
     *
     * @param  AgentSession  $session  The coordinator doing it.
     * @param  string  $name  The name to free.
     * @return Outcome What came of it.
     */
    public function forceRelease(AgentSession $session, string $name): Outcome
    {
        return DB::transaction(function () use ($session, $name): Outcome {
            $now = Carbon::now();

            // Locked, so `taken_from` names the session the write is about to displace rather
            // than one that released voluntarily a moment earlier
            $lock = Lock::query()->where('name', $name)->lockForUpdate()->first();

            if (! $lock instanceof Lock) {
                return Outcome::NotFound;
            }

            $freed = Lock::query()
                ->where('name', $name)
                ->whereNotNull('holder_id')
                ->update(['holder_id' => null, 'expires_at' => null, 'updated_at' => $now]);

            if ($freed !== 1) {
                // Already free, which is what a second force release finds
                return Outcome::Conflict;
            }

            $this->events->record(
                FleetEventType::LockForceReleased,
                $session,
                sprintf('Force released %s.', $name),
                ['lock' => $name, 'taken_from' => $lock->holder_id],
                true
            );

            return Outcome::Applied;
        });
    }

    /**
     * Give back every lock a session that has gone was still holding.
     *
     * Registered on the presence sweep rather than driven by `Events\SessionGone`, for the reason
     * `SessionReleases` records: a signal can be missed, and a step that runs every sweep cannot.
     *
     * @return int How many locks were released.
     */
    public function releaseOrphaned(): int
    {
        $released = 0;
        $failed = null;

        $orphaned = Lock::query()
            ->whereNotNull('holder_id')
            ->whereIn('holder_id', AgentSession::query()
                ->select('id')
                ->where('status', AgentSessionStatus::Gone->value))
            ->orderBy('id')
            ->limit($this->credentials->maxPerSweep())
            ->get();

        foreach ($orphaned as $lock) {
            try {
                $released += $this->releaseOne($lock) ? 1 : 0;
            } catch (Throwable $failure) {
                // Isolated per lock, for the reason the task step records: the candidate read is
                // ordered by id with a limit, so one lock whose release fails deterministically
                // would be first on every sweep and hold every other orphan behind it
                Log::error(
                    sprintf('robot-council: releasing lock %s from its gone session failed.', $lock->name),
                    ['exception' => $failure]
                );

                $failed ??= $failure;
            }
        }

        if ($failed instanceof Throwable) {
            throw $failed;
        }

        return $released;
    }

    /**
     * Release one lock whose holder has gone, if its holder is still gone.
     *
     * @param  Lock  $lock  The lock to free.
     * @return bool True when this call released it.
     */
    private function releaseOne(Lock $lock): bool
    {
        return DB::transaction(function () use ($lock): bool {
            $holder = AgentSession::query()->whereKey($lock->holder_id)->lockForUpdate()->first();

            if (! $holder instanceof AgentSession || ! $holder->hasGone()) {
                return false;
            }

            $released = Lock::query()
                ->whereKey($lock->getKey())
                ->where('holder_id', $holder->getKey())
                ->update(['holder_id' => null, 'expires_at' => null, 'updated_at' => Carbon::now()]);

            if ($released !== 1) {
                return false;
            }

            // Attributed to no session: this is what the service observed, not what the session
            // that lost the lock had to say about it
            $this->events->record(
                FleetEventType::LockReleased,
                null,
                sprintf('Released %s: its session ended.', $lock->name),
                ['lock' => $lock->name, 'released_from' => $holder->getKey()]
            );

            return true;
        });
    }

    /**
     * Work out why a conditional write on a lock matched nothing.
     *
     * @param  string  $name  The name that was written.
     * @param  AgentSession  $session  The session that tried.
     * @param  Carbon  $now  The moment the write judged the lease at.
     * @return Outcome Why nothing happened.
     */
    private function diagnose(string $name, AgentSession $session, Carbon $now): Outcome
    {
        // Read unlocked first. A locking read that matches nothing takes a gap lock on InnoDB, and
        // this runs on an ordinary client mistake -- a renew or release of a name that was never
        // created -- with the gap chosen by whatever the caller sent.
        if (! Lock::query()->where('name', $name)->exists()) {
            return Outcome::NotFound;
        }

        $lock = Lock::query()->where('name', $name)->lockForUpdate()->first();

        if (! $lock instanceof Lock || $lock->acquired_at === null) {
            return Outcome::NotFound;
        }

        // A session this lock was taken from is not a stranger to it. 409 rather than 403,
        // because the useful thing to tell it is that what it held is gone -- which is what sends
        // it to acquire the name again rather than give up on it. Recorded by the takeover's own
        // write, so this costs no extra read and cannot disagree with what happened.
        if ($lock->previous_holder_id === $session->getKey()) {
            return Outcome::Conflict;
        }

        // Somebody else holds a running lease, and this session never held it: it may not touch it
        if ($lock->isHeldAt($now) && $lock->holder_id !== $session->getKey()) {
            return Outcome::Forbidden;
        }

        // Its own lease lapsed, or it was taken over and handed back, or the hold has run past its
        // ceiling. All of them are the same statement: what this session asked for is not available
        // any more, and the lock is not somebody else's to be refused from.
        return Outcome::Conflict;
    }
}
