<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Models\Task;

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

            // The same urgency, ascending, so one index serves both the ordering and the cursor.
            // `order by priority desc, id asc` matches no single-direction b-tree in either scan
            // direction, and `Blueprint::index()` cannot declare a direction -- so the index this
            // table used to carry could never serve the ordering it was added for.
            //
            // Written only by `Models\Task`'s `priority` mutator, which sets both columns in one
            // assignment, so they cannot be given disagreeing values through the model. The default
            // is the rank of priority 0 for the same reason: a row created without naming a
            // priority takes the column default for both, and the two defaults agree.
            //
            // **`Task::MAX_PRIORITY` is baked into every row and frozen into this default.** Raising
            // it needs a backfill: rows already written hold `old_max - priority`, this default
            // stays at the old value in an already-migrated database because nothing issues an
            // `ALTER`, and `TaskList` would convert an incoming cursor with the new constant. All
            // three would disagree silently, and the ordering would be wrong rather than broken.
            $table->unsignedTinyInteger('queue_rank')->default(Task::MAX_PRIORITY);

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

            // What listing reads, in its two shapes. Every column ascends, so both are walked
            // rather than sorted -- which `priority desc, id asc` could not be, in either scan
            // direction, whatever index it was given.
            //
            // **Two indexes, because `status` is optional on every surface.** `ListTasksController`
            // and `ListTasksTool` both take it `sometimes`, and `Livewire\TaskBoard` renders with
            // `status` null, so the unfiltered listing is the DEFAULT rather than an edge. Measured
            // on a 20,000-row SQLite fixture after `ANALYZE`: with only the composite below, the
            // unfiltered listing plans `SCAN | USE TEMP B-TREE FOR ORDER BY` and the unfiltered
            // cursor `SEARCH (ANY(status) AND queue_rank>?) | USE TEMP B-TREE FOR ORDER BY`, because
            // a leading column nothing constrains cannot be seeked. With `(queue_rank, id)` beside
            // it they become `SCAN USING INDEX` and `SEARCH (queue_rank>?)`, no sort -- and the
            // filtered plan is unchanged, so the second index does not displace the first.
            //
            // Postgres and MySQL are not measured. There is no Postgres locally and MySQL is #39.
            $table->index(['status', 'queue_rank', 'id']);
            $table->index(['queue_rank', 'id']);
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
