<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the fleet's change feed: one ordered log that every coordination state change is written
 * to, in the same transaction as the change itself.
 *
 * Agents without a push connection read this by paging an ID cursor, so the order rows become
 * visible in has to match the order of their IDs. An insert that commits late would otherwise be
 * skipped by a reader that had already passed its ID.
 */
return new class extends Migration
{
    /**
     * Create the events table.
     */
    public function up(): void
    {
        // One row, locked with `select ... for update` by every writer before it inserts. A row
        // lock rather than a Postgres advisory key, because the ordering problem is not Postgres's
        // alone: InnoDB hands out `AUTO_INCREMENT` values at insert time too, and under
        // `innodb_autoinc_lock_mode=2` -- the MySQL 8 default -- two transactions can take 5 and 6
        // and commit 6 first. A row lock is transaction-scoped on every driver, releases on commit
        // and on rollback without a hook, and collides with nothing a host owns.
        Schema::create('robot_council_feed_lock', function (Blueprint $table): void {
            $table->id();
        });

        DB::table('robot_council_feed_lock')->insert(['id' => 1]);

        Schema::create('robot_council_events', function (Blueprint $table): void {
            $table->id();

            // Null for an event the service recorded rather than a session.
            //
            // **Deliberately not a foreign key**, which is the decision on robot-council/core#50.
            // On InnoDB, inserting a child row takes a shared lock on the referenced parent, so
            // every writer recording an event would reach for a session row *after* taking the feed
            // sentinel, while the presence sweep takes the session row first and the sentinel
            // second. That is a deadlock, and the worse half is that it is invisible: the lock is
            // implicit, nothing in the code says it is being taken, and every writer added later
            // inherits it. The rejected alternative was one global lock order maintained by every
            // author, which #50 rejected on the evidence that it had already failed once.
            //
            // Nothing decides anything from it. It is provenance a reader can follow back to a
            // session that may or may not still exist, and it carries no index, because nothing
            // filters events by it -- both #29's visibility rule and the GitHub login are read from
            // `user_id` below.
            //
            // That is not tidiness. Without the constraint nothing nulls this column when a session
            // row goes, and **session ids are reused**, so anything resolved by looking this id up
            // in the live session table would follow it to whoever holds it now.
            $table->foreignId('agent_session_id')->nullable();

            // The posting session's developer, denormalized at write time, exactly as
            // `robot_council_tasks.user_id` is and for the same reason -- it survives the session
            // row being deleted. Null for an event the service recorded rather than a session.
            //
            // **This is what makes #29's boundary safe without the foreign key.** The filter used
            // to ask `agent_session_id IN (the reader's live sessions)`, which re-binds a stored id
            // to whatever session holds it *now*. With no foreign key nulling it on delete, a
            // reused id re-points a dead session's narration at a live one: measured, Laravel's
            // SQLite `compileTruncate` issues `delete from sqlite_sequence` beside the row delete,
            // after which the next session takes id 1 again, and Postgres's is `truncate ... restart
            // identity`. One developer's restricted narration would then be served to another's
            // agent, labeled with that other developer's login. Recorded here at write time, the
            // rule is decided by who actually posted rather than by who holds the id later -- the
            // same principle as `posted_with_coordinator` two columns down.
            $table->string('user_id', 64)->nullable();

            // Likewise carried by `(type, id)` below rather than an index of its own
            $table->string('type', 64);

            // What a human reads. Narration and directives carry one; a state change may not.
            $table->text('body')->nullable();

            // Structured detail, including whatever the client sent under `meta.client`
            $table->json('meta')->nullable();

            // Recorded when the event is written, not read from the session afterwards: revoking
            // the coordinator's ability later must not retroactively hide what was said while it
            // was held, nor reveal what was said before it was granted
            $table->boolean('posted_with_coordinator')->default(false);

            // Only `created_at`. An event is a fact about a moment and is never edited. No index
            // on it: the feed is read by ID cursor and never by time, so an index here would be
            // write cost on the hot append path and nothing would read it.
            $table->timestamp('created_at')->nullable();

            // The two branches a reader's visibility is decided on, each with `id` beside it so a
            // branch can be walked in feed order without a sort. #48 decided these ship now, on
            // `agent_session_id`; #59 moved the first to `user_id` when the filter's branch moved.
            //
            // **No query in this package can use them today, and that is deliberate.**
            // `FleetFeed::after()` reads in two phases: a primary-key range scan picks a window of
            // at most `MAX_PAGE` ids, then the visibility filter runs as a residual predicate over
            // `whereKey($window)`. The filter never drives an access path, because the row set is
            // already pinned by the primary key -- measured with `EXPLAIN QUERY PLAN` on a seeded
            // SQLite fixture, where all four of the package's queries plan identically with these
            // indexes present and absent. What they are provisioned for is the `UNION ALL` of two
            // index-backed branches that robot-council/core#62 may adopt, which is why #62 lists
            // "neither" among its candidates. Until it decides, this is write cost paid forward.
            //
            // `posted_with_coordinator` gets none, and not because it is unselective: it is written
            // `true` in one place only, so it is rare rather than half, which is the distribution a
            // partial index suits. It stays a filter because it reaches the query only as one
            // disjunct of an `OR` over a window already pinned by primary key, where no index on it
            // could be reached at all.
            $table->index(['user_id', 'id']);
            $table->index(['type', 'id']);
        });
    }

    /**
     * Drop the events table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_events');
        Schema::dropIfExists('robot_council_feed_lock');
    }
};
