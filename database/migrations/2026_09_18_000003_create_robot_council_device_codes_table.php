<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the table holding enrollment requests in flight. Rows live for minutes and a scheduled
 * command prunes them, so nothing here is a long-term record.
 */
return new class extends Migration
{
    /**
     * Create the device codes table.
     */
    public function up(): void
    {
        Schema::create('robot_council_device_codes', function (Blueprint $table): void {
            $table->id();

            // SHA-256 of what the helper holds, never the value itself. Reading this table gives
            // an attacker nothing it can exchange, and the exchange looks a row up by hash.
            $table->string('device_code_hash', 64)->unique();
            $table->string('challenge_hash', 64);

            // The one value a human reads off one screen and types into another, so it is stored
            // as sent. It is short-lived, and useless without the device code and the verifier.
            $table->string('user_code', 16)->index();

            // What the requester asked for, and what the server decided to grant
            $table->json('requested_abilities');
            $table->json('granted_abilities')->nullable();

            // Claims the requester made about itself, shown on the verification page as claims
            $table->string('harness', 32);
            $table->string('machine_label', 64);
            $table->string('requested_ip', 45)->nullable();

            // Every decision is a conditional update over these four columns, so a second
            // decision and a second exchange both match no rows
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('consumed_at')->nullable();

            $table->unsignedBigInteger('decided_by')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Drop the device codes table.
     */
    public function down(): void
    {
        Schema::dropIfExists('robot_council_device_codes');
    }
};
