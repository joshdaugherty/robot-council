<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table mapping host users to GitHub accounts. The package owns this table, because
 * the mapping decides who the access lists admit: a column on the host's own users table could be
 * written by the host's mass assignment, its own GitHub linking, a seeder, or an admin form, and
 * whoever wrote it would then hold an allowlisted identity.
 */
return new class extends Migration
{
    /**
     * Create the identities table.
     */
    public function up(): void
    {
        Schema::create('robot_council_github_identities', function (Blueprint $table): void {
            $table->id();

            // One identity per user, and per GitHub account. No foreign key: the host owns its
            // users table, including its name and the type of its key, and a user that has gone
            // is refused at sign-in rather than by the database.
            // The host's key as text, not a bigint. A host keyed by UUID or ULID is an ordinary
            // multi-tenant application, and this table holds one row per developer, so the usual
            // argument for a narrow integer index has nothing to weigh against here.
            $table->string('user_id', 64)->unique();
            $table->unsignedBigInteger('github_id')->unique();

            // What the account looked like at the last sign-in, for display only
            $table->string('github_login', 255);
            $table->string('avatar_url', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Drop the identities table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_github_identities');
    }
};
