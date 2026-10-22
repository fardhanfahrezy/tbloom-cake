<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('provider', 32)->charset('ascii')->collation('ascii_bin');
            $table->string('provider_reference', 128)->charset('ascii')->collation('ascii_bin');
            $table->char('event_fingerprint', 64)->charset('ascii')->collation('ascii_bin');
            $table->json('payload_redacted');
            $table->dateTime('received_at');
            $table->dateTime('verified_at')->nullable();
            $table->string('processing_status', 32)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->char('claim_token', 36)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->dateTime('created_at');
            $table->unique(['provider', 'event_fingerprint']);
            $table->unique(['id', 'payment_id']);
            $table->index(['processing_status', 'next_attempt_at', 'id'], 'payment_events_status_due_id_index');
            $table->index(['processing_status', 'lease_until', 'id'], 'payment_events_status_lease_id_index');
            $table->index(['provider', 'provider_reference', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_events
                ADD CONSTRAINT payment_events_status_check CHECK (processing_status IN ('pending', 'processing', 'processed', 'review', 'failed')),
                ADD CONSTRAINT payment_events_claim_check CHECK ((claim_token IS NULL AND lease_until IS NULL) OR (claim_token IS NOT NULL AND lease_until IS NOT NULL)),
                ADD CONSTRAINT payment_events_processed_check CHECK (processing_status <> 'processed' OR (processed_at IS NOT NULL AND payment_id IS NOT NULL AND verified_at IS NOT NULL))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payment_events')->exists()) {
            throw new RuntimeException('Cannot discard populated payment event history; use a forward fix.');
        }

        Schema::dropIfExists('payment_events');
    }
};
