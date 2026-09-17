<?php

declare(strict_types=1);

/**
 * The feed's ordering guarantee, which only a second connection can show.
 *
 * An agent without a push connection pages this feed by an ID cursor, so the order rows become
 * visible has to match the order of their IDs. Postgres hands out sequence values outside the
 * transaction, so without a lock two concurrent writers can take IDs 5 and 6 and commit 6 first --
 * and a reader that polls in between passes 6, stores its cursor, and never sees 5 again.
 *
 * `FleetEvents` takes a transaction-scoped advisory lock before inserting, which serializes the
 * writers. This proves the lock is actually taken, by showing a second writer wait for the first.
 *
 * SQLite cannot show it: it serializes writers anyway, and each connection to the in-memory
 * `testing` database opens a separate database. Hence the `cross-connection` group, which only
 * CI's `postgres` job runs.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection
 */

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;

/**
 * Whether a connection can take the feed's writer lock right now.
 *
 * `pg_try_advisory_xact_lock` answers rather than blocking, which is what makes the lock observable
 * from one process instead of needing a second one to hang.
 *
 * @param  ConnectionInterface  $connection  The connection to ask on.
 * @return bool True when the lock was free and is now held by that connection's transaction.
 */
function tookTheFeedLock(ConnectionInterface $connection): bool
{
    $answer = $connection->selectOne('select pg_try_advisory_xact_lock(?) as got', [FleetEvents::FEED_LOCK_KEY]);

    return \is_object($answer) && (bool) ($answer->got ?? false);
}

it('makes a second writer wait while the first holds the feed', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        // The first writer opens a transaction, records an event, and does not commit
        DB::beginTransaction();

        $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'the first writer');

        // The lock is held for the rest of that transaction, so a second connection asking for it
        // cannot get it. `pg_try_advisory_xact_lock` answers rather than blocking, which is what
        // makes this testable without a second process.
        $other->beginTransaction();

        expect(tookTheFeedLock($other))->toBeFalse();

        $other->rollBack();

        // Once the first writer commits, the lock is released and the second can take it
        DB::commit();

        $other->beginTransaction();

        expect(tookTheFeedLock($other))->toBeTrue();

        $other->rollBack();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection');

it('holds the lock only for the transaction, so a rollback releases it', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        DB::beginTransaction();

        $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'never committed');

        // The state change this event belonged to failed
        DB::rollBack();

        $other->beginTransaction();

        // A rolled-back writer must not leave the feed locked against everyone else
        expect(tookTheFeedLock($other))->toBeTrue();

        $other->rollBack();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection');
