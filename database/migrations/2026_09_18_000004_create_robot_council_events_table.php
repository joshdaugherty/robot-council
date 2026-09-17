<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('robot_council_events', function (Blueprint $table): void {
            $table->id();

            // Null for an event the service recorded rather than a session
            $table->foreignId('agent_session_id')
                ->nullable()
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

            // Only `created_at`. An event is a fact about a moment and is never edited.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Drop the events table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_events');
    }
};
