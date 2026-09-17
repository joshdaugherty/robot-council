<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table holding one approved harness on one machine. A developer approves an
 * installation once, and it starts an agent session per process from then on.
 */
return new class extends Migration
{
    /**
     * Create the installations table.
     */
    public function up(): void
    {
        Schema::create('robot_council_installations', function (Blueprint $table): void {
            $table->id();

            // The developer who owns the installation. No foreign key: the host owns its users
            // table, including its name and the type of its key, and a developer whose row has
            // gone is refused on the next request rather than by the database.
            $table->unsignedBigInteger('user_id')->index();

            // What the requester claimed about itself, shown on the verification page as a claim
            $table->string('harness', 32);
            $table->string('machine_label', 64);

            // What the server granted, which is the requested abilities narrowed to the fixed
            // list. Session tokens carry these; the installation's own credential does not.
            $table->json('granted_abilities');

            // The developer who approved it, and the address the code was requested from
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('requested_ip', 45)->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Drop the installations table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_installations');
    }
};
