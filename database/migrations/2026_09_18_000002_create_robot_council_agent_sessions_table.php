<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RobotCouncil\Support\ProjectId;

/**
 * Creates the table holding one running agent process. A session is what a bearer token
 * authenticates as, so claims, locks, and presence belong to the process rather than to the
 * developer who owns it.
 */
return new class extends Migration
{
    /**
     * Create the agent sessions table.
     */
    public function up(): void
    {
        Schema::create('robot_council_agent_sessions', function (Blueprint $table): void {
            $table->id();

            // Both tables are the package's own, so this constraint is safe to hold
            $table->foreignId('installation_id')
                ->constrained('robot_council_installations')
                ->cascadeOnDelete();

            // Denormalized from the installation so an agent route can check the allowlist
            // without a join, and kept as a plain column for the reason given in the
            // installations migration
            // The host's key as text, not a bigint. A host keyed by UUID or ULID is an ordinary
            // multi-tenant application, and this table holds one row per developer, so the usual
            // argument for a narrow integer index has nothing to weigh against here.
            $table->string('user_id', 64)->index();

            $table->string('status', 16);

            // `dateTime`, never `timestamp`, and never nullable. MySQL gives the first NOT NULL
            // `TIMESTAMP` column in a table an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP` while `explicit_defaults_for_timestamp` is off, which is the
            // default on MySQL 5.7 and MariaDB before 10.10 -- so marking a session stale would
            // silently move the contact time the sweep measures against, and restart the clock
            // that decides when it goes. Nullable would be worse: a row with no contact time
            // matches neither cutoff and could never go stale or gone at all.
            $table->dateTime('last_seen_at');

            // Which repository or workspace the process is working in, when it says
            $table->string('project_id', ProjectId::MAX)->nullable();

            $table->timestamps();

            // What the presence sweep reads on every run: the sessions in a given state that have
            // not been heard from since a cutoff. Composite rather than one index on `status`,
            // because the leftmost column serves a status-only lookup as well.
            $table->index(['status', 'last_seen_at']);
        });
    }

    /**
     * Drop the agent sessions table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_agent_sessions');
    }
};
