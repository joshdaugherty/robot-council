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

            // Null for an event the service recorded rather than a session
            // Indexed explicitly. `constrained()` emits a foreign key and no index, and only
            // MySQL creates one for the constraint server-side -- so on Postgres and SQLite both
            // the feed's visibility filter and this constraint's own delete cascade would be
            // full-table scans.
            $table->foreignId('agent_session_id')
                ->nullable()
                ->index()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            $table->string('type', 64)->index();

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
