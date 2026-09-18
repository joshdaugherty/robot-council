<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RuntimeException;

/**
 * Writes the fleet's change feed.
 *
 * Every coordination state change is recorded through here, in the same transaction as the change
 * itself, so a change that rolls back leaves no event behind and an event never describes something
 * that did not happen.
 *
 * **Events have to commit in ID order.** An agent without a push connection reads this feed by
 * paging an ID cursor, so an insert that takes its ID early and commits late would be skipped
 * forever by a reader that had already passed it -- permanently, because paging is `id > cursor`.
 *
 * Both Postgres and MySQL make that gap real. Postgres draws sequence values outside the
 * transaction, and InnoDB hands out `AUTO_INCREMENT` values at insert time, so under
 * `innodb_autoinc_lock_mode=2` -- the MySQL 8 default -- two transactions can take 5 and 6 and
 * commit 6 first. SQLite serializes writers and cannot. Rather than one branch per driver, every
 * writer takes a row lock on a single sentinel row, which is transaction-scoped everywhere,
 * releases on commit and on rollback with no hook to forget, and collides with nothing a host owns.
 */
final class FleetEvents
{
    /**
     * The table holding the one row every writer locks before inserting.
     */
    public const string LOCK_TABLE = 'robot_council_feed_lock';

    /**
     * The row every writer locks. There is only ever one.
     */
    public const int LOCK_ROW = 1;

    /**
     * @param  SlackMirror  $slack  Whether, and where, to mirror an event to Slack.
     */
    public function __construct(private readonly SlackMirror $slack) {}

    /**
     * Record one event, and queue its Slack mirror once the surrounding work commits.
     *
     * @param  FleetEventType  $type  What happened.
     * @param  AgentSession|null  $session  The session responsible, when one was.
     * @param  string|null  $body  What a human reads.
     * @param  array<string, mixed>  $meta  Structured detail.
     * @param  bool  $withCoordinator  Whether the session held `coordinator:direct` as it posted.
     * @return FleetEvent The recorded event.
     */
    public function record(
        FleetEventType $type,
        ?AgentSession $session = null,
        ?string $body = null,
        array $meta = [],
        bool $withCoordinator = false
    ): FleetEvent {
        // A savepoint when a caller already has a transaction open, which is the ordinary case:
        // the event and the state change it records commit or roll back together. The advisory
        // lock below is scoped to the outermost transaction either way.
        return DB::transaction(function () use ($type, $session, $body, $meta, $withCoordinator): FleetEvent {
            $this->holdTheFeed();

            $event = FleetEvent::query()->create([
                'agent_session_id' => $session?->getKey(),
                'type' => $type,
                'body' => $body,
                'meta' => $meta === [] ? null : $meta,

                // Recorded now, never read back off the session: revoking the coordinator's
                // ability later must not hide what was said while it was held
                'posted_with_coordinator' => $withCoordinator,
            ]);

            $this->slack->mirror($event);

            return $event;
        });
    }

    /**
     * Take the feed's writer lock for the rest of the transaction.
     *
     * Taken **before** the insert, which is the whole point: an ID drawn before the lock is an ID
     * that can commit out of order, and a drawn ID is not given back by a rollback.
     *
     * SQLite compiles `for update` to nothing, which is correct rather than a gap: it takes a
     * write lock for the whole transaction on its own.
     *
     * **A missing sentinel row is fatal rather than ignored.** `first()` on a row that is not there
     * returns null and locks nothing, and the insert below would then go ahead unordered -- so a
     * host that truncated this table, or restored it without its one row, would get a feed whose
     * ordering had silently stopped holding. That was one skippable event before a session's
     * starting cursor was derived from this lock; now it is every fresh session's watermark.
     *
     * @throws RuntimeException When the sentinel row is missing, because the alternative is writing
     *                          a feed whose order nothing guarantees.
     */
    private function holdTheFeed(): void
    {
        $held = DB::table(self::LOCK_TABLE)->where('id', self::LOCK_ROW)->lockForUpdate()->first();

        if ($held === null) {
            throw new RuntimeException(sprintf(
                'robot-council: the feed lock row %s.%d is missing, so event ordering cannot be guaranteed. Re-run the package migrations.',
                self::LOCK_TABLE,
                self::LOCK_ROW
            ));
        }
    }
}
