<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the framework's integer-keyed users table with a UUID-keyed one, which is what a
 * multi-tenant host application ordinarily has.
 *
 * It runs after the default migrations rather than instead of them, so the rest of the schema is
 * whatever a real application would have.
 */
return new class extends Migration
{
    /**
     * Recreate the users table with a UUID primary key.
     */
    public function up(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');

            // Nullable for the same reason `robot-council:install` relaxes them: a developer who
            // signs in with GitHub has no password, and may expose no email
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Drop the users table.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
