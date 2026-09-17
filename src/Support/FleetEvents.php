<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;

/**
 * Writes the fleet's change feed.
 *
 * Every coordination state change is recorded through here, in the same transaction as the change
 * itself, so a change that rolls back leaves no event behind and an event never describes something
 * that did not happen.
 *
 * **Events have to commit in ID order.** An agent without a push connection reads this feed by
 * paging an ID cursor, so an insert that takes its ID early and commits late would be skipped
 * forever by a reader that had already passed it. Postgres hands out sequence values outside the
 * transaction, which makes that gap real; SQLite serializes writers and does not.
 */
final class FleetEvents
{
    /**
     * The advisory lock every writer takes before inserting, so one feed has one writer at a time.
     *
     * The number is arbitrary and only has to be stable: it is the RFC the enrollment flow follows,
     * which makes it recognizable in `pg_locks` rather than looking like a stray constant.
     */
    public const int FEED_LOCK_KEY = 8628;

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
     * `pg_advisory_xact_lock` releases when the transaction ends, committed or rolled back, so
     * nothing here can leak a lock. On any other driver this does nothing, because no other driver
     * the package supports lets a later ID commit first.
     */
    private function holdTheFeed(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('select pg_advisory_xact_lock(?)', [self::FEED_LOCK_KEY]);
    }
}
