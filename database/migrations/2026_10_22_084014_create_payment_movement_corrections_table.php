<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_movement_corrections', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('payment_movement_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('previous_correction_id')->nullable();
            $table->unsignedBigInteger('effective_amount');
            $table->dateTime('effective_occurred_at');
            $table->dateTime('verified_at');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('source_event_id')->nullable();
            $table->unsignedBigInteger('refund_transfer_id')->nullable();
            $table->text('reason');
            $table->string('idempotency_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->char('request_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at');
            $table->unsignedBigInteger('initial_movement_id')->nullable()->storedAs('CASE WHEN previous_correction_id IS NULL THEN payment_movement_id ELSE NULL END');
            $table->unique(['id', 'payment_movement_id', 'payment_id'], 'corrections_id_movement_payment_unique');
            $table->unique(['payment_movement_id', 'revision'], 'corrections_movement_revision_unique');
            $table->unique('previous_correction_id', 'corrections_previous_unique');
            $table->unique('initial_movement_id', 'corrections_initial_unique');
            $table->unique(['payment_id', 'idempotency_key'], 'corrections_payment_key_unique');
            $table->foreign(['payment_movement_id', 'payment_id'], 'corrections_movement_payment_fk')->references(['id', 'payment_id'])->on('payment_movements')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['source_event_id', 'payment_id'], 'corrections_event_payment_fk')->references(['id', 'payment_id'])->on('payment_events')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['refund_transfer_id', 'payment_id'], 'corrections_transfer_payment_fk')->references(['id', 'payment_id'])->on('refund_transfers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['previous_correction_id', 'payment_movement_id', 'payment_id'], 'corrections_previous_root_payment_fk')->references(['id', 'payment_movement_id', 'payment_id'])->on('payment_movement_corrections')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_movement_corrections
                ADD CONSTRAINT corrections_revision_check CHECK (
                    (revision = 1 AND previous_correction_id IS NULL) OR
                    (revision > 1 AND previous_correction_id IS NOT NULL)
                ),
                ADD CONSTRAINT corrections_amount_check CHECK (effective_amount <= 9223372036854775807),
                ADD CONSTRAINT corrections_evidence_check CHECK (source_event_id IS NOT NULL OR refund_transfer_id IS NOT NULL),
                ADD CONSTRAINT corrections_reason_check CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
                ADD CONSTRAINT corrections_key_check CHECK (OCTET_LENGTH(idempotency_key) > 0)
            SQL);
    }

    public function down(): void
    {
        if (DB::table('payment_movement_corrections')->exists()) {
            throw new RuntimeException('Cannot discard populated correction history; use a forward fix.');
        }

        Schema::dropIfExists('payment_movement_corrections');
    }
};
