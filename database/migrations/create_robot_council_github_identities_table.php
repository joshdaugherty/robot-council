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
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('github_id')->unique();

            // What the account looked like at the last sign-in, for display only
            $table->string('github_login');
            $table->string('avatar_url')->nullable();

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
