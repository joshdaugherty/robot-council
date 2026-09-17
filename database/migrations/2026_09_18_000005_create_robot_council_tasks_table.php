<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table holding the unit of work agents hand each other.
 *
 * Every transition is one conditional update against this table, so the columns a transition tests
 * -- `status`, `claimed_by`, and the two that decide claim eligibility -- all live on the row rather
 * than behind a join. That is what lets the write be the whole decision.
 */
return new class extends Migration
{
    /**
     * Create the tasks table.
     */
    public function up(): void
    {
        Schema::create('robot_council_tasks', function (Blueprint $table): void {
            $table->id();

            // Stored and nothing more, per #25. Self-referential, so a parent that is deleted
            // leaves its children rather than taking them.
            $table->foreignId('parent_task_id')
                ->nullable()
                ->index()
                ->constrained('robot_council_tasks')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status', 16);

            // Higher is more urgent. A small range rather than an open integer, because it is
            // sorted on and a client that sends the largest integer it can would otherwise pin
            // itself to the top of every other developer's queue.
            $table->unsignedTinyInteger('priority')->default(0);

            $table->json('payload')->nullable();
            $table->json('result')->nullable();

            // The session holding it now. Indexed explicitly: `constrained()` emits a foreign key
            // and no index on Postgres and SQLite, and the release step queries this column.
            $table->foreignId('claimed_by')
                ->nullable()
                ->index()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            $table->dateTime('claimed_at')->nullable();

            // Provenance, recorded when the task is created and never rewritten
            $table->foreignId('created_by')
                ->nullable()
                ->index()
                ->constrained('robot_council_agent_sessions')
                ->nullOnDelete();

            // The creating session's developer, denormalized so the claim's conditional update can
            // carry #16's eligibility rule without a join -- and so it survives the creating
            // session's row being deleted, which would otherwise take the rule's other half with it
            $table->string('user_id', 64)->index();

            // Whether the creating session held `coordinator:direct` at the time. Read at creation
            // and stored, so revoking it later cannot retroactively narrow who may claim the task.
            $table->boolean('created_with_coordinator')->default(false);

            // Which repository or workspace the task belongs to, when the creator says
            $table->string('project_id')->nullable();

            $table->timestamps();

            // What listing reads: a status filter, then the queue's own order
            $table->index(['status', 'priority', 'id']);
        });
    }

    /**
     * Drop the tasks table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_tasks');
    }
};
