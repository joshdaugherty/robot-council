<?php

declare(strict_types=1);

/**
 * The feed's ordering guarantee, which only a second connection can show.
 *
 * An agent without a push connection pages this feed by an ID cursor, so the order rows become
 * visible has to match the order of their IDs. Both Postgres and InnoDB draw the key at INSERT
 * time, outside the transaction's ordering, so two writers can take 5 and 6 and commit 6 first --
 * and a reader that polls in between passes 6 and never sees 5 again, permanently, because paging
 * is `id > cursor`.
 *
 * `FleetEvents::record()` takes a row lock on a single sentinel row before inserting. These tests
 * are built to fail against the two changes that would quietly remove the guarantee:
 *
 * 1. **Taking the lock after the insert.** Caught by the sequence: a key drawn before the lock is
 *    a key already drawn, and a sequence is not rolled back. The first test asserts no key moved.
 * 2. **Downgrading to a shared lock.** Caught by holding a shared lock on the other connection and
 *    requiring the writer to block on it -- two shared locks would not conflict, and the write
 *    would succeed.
 *
 * The `cross-connection` group runs only in CI's `postgres` job: SQLite serializes writers and
 * gives each connection its own in-memory database, so neither test can run there.
 *
 * @command  DB_CONNECTION=pgsql vendor/bin/pest --compact --group=cross-connection
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RobotCouncil\Models\FleetEvent;
use RobotCouncil\Models\FleetEventType;
use RobotCouncil\Support\FleetEvents;

/**
 * The last key the events table handed out.
 *
 * Read from the sequence rather than from the table, because that is the number that survives a
 * rollback, and surviving a rollback is exactly what makes an early-drawn key dangerous.
 *
 * @return int The sequence's last value.
 */
function lastEventKey(): int
{
    $sequence = (array) DB::selectOne("select pg_get_serial_sequence('robot_council_events', 'id') as name");

    $name = \is_string($sequence['name'] ?? null) ? $sequence['name'] : '';

    // The name comes from the server, not from input
    $value = (array) DB::selectOne(sprintf('select last_value from %s', $name));

    return \is_numeric($value['last_value'] ?? null) ? (int) $value['last_value'] : 0;
}

it('draws no key while another connection holds the feed', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        // The control. A normal write advances the sequence by exactly one, which proves the
        // sequence is the right thing to watch and that `record()` reaches an insert at all. If
        // this fails, every assertion below is meaningless rather than reassuring.
        $before = lastEventKey();

        $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'the control');

        expect(lastEventKey())->toBe($before + 1);

        $held = lastEventKey();

        // A SHARED lock, deliberately. An exclusive writer conflicts with it and must wait; two
        // shared locks would not conflict, so a package that downgraded its own lock would sail
        // through and fail this test.
        $other->beginTransaction();
        $other->select(sprintf('select * from %s where id = ? for share', FleetEvents::LOCK_TABLE), [FleetEvents::LOCK_ROW]);

        // Bounded, so a writer that blocks reports it rather than hanging the suite
        DB::statement("set lock_timeout = '750ms'");

        expect(fn () => $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'the blocked writer'))
            ->toThrow(QueryException::class);

        // The assertion the whole file exists for. The writer never reached its insert, so no key
        // was drawn -- and had the lock been taken after the insert, a key would have been drawn,
        // and would have stayed drawn through the rollback.
        expect(lastEventKey())->toBe($held)
            ->and(FleetEvent::query()->where('body', 'the blocked writer')->exists())->toBeFalse();
    } finally {
        DB::statement('set lock_timeout = default');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection');

it('writes again as soon as the other connection lets go', function (): void {
    $this->migrateUsersTableWithPackageColumns();

    $default = DB::getDefaultConnection();
    config()->set("database.connections.{$default}_other", config("database.connections.{$default}"));
    $other = DB::connection("{$default}_other");

    try {
        $other->beginTransaction();
        $other->select(sprintf('select * from %s where id = ? for update', FleetEvents::LOCK_TABLE), [FleetEvents::LOCK_ROW]);

        DB::statement("set lock_timeout = '500ms'");

        // Held: the writer cannot get in
        expect(fn () => $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'blocked'))
            ->toThrow(QueryException::class);

        // Released by a rollback, with no hook to forget, which is why the lock is a row rather
        // than something the package has to remember to let go of
        $other->rollBack();

        $event = $this->service(FleetEvents::class)->record(FleetEventType::Narration, null, 'unblocked');

        expect($event->body)->toBe('unblocked')
            ->and(FleetEvent::query()->where('body', 'blocked')->exists())->toBeFalse();
    } finally {
        DB::statement('set lock_timeout = default');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }

        DB::purge("{$default}_other");
    }
})->group('cross-connection');
