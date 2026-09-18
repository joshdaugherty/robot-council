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
            // The index this column needs is the composite declared at the foot of this table, not
            // a single-column one. `constrained()` emits a foreign key and no index, and only MySQL
            // creates one server-side, so without something here this constraint's own delete
            // cascade would be a full-table scan on Postgres and SQLite. A leftmost prefix serves
            // that, and serves MySQL's requirement that a foreign-key column be indexed, so a
            // standalone index beside the composite would be write cost on the hot append path for
            // a query neither engine would choose it for.
            $table->foreignId('agent_session_id')
                ->nullable()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

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
            // branch can be walked in feed order without a sort. #48 decided these ship now.
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
            $table->index(['agent_session_id', 'id']);
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
