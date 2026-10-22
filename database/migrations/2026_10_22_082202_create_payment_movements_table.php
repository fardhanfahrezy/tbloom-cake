<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_movements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('kind', 16)->charset('ascii')->collation('ascii_bin');
            $table->string('source', 32)->charset('ascii')->collation('ascii_bin');
            $table->string('canonical_key', 191)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('amount');
            $table->dateTime('occurred_at');
            $table->dateTime('verified_at');
            $table->unsignedBigInteger('source_event_id')->nullable();
            $table->unsignedBigInteger('refund_transfer_id')->nullable()->unique();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('reconciliation_note')->nullable();
            $table->dateTime('created_at');
            $table->unique(['id', 'payment_id']);
            $table->unique(['payment_id', 'canonical_key']);
            $table->index(['payment_id', 'kind', 'id']);
            $table->foreign(['source_event_id', 'payment_id'], 'movements_event_payment_fk')->references(['id', 'payment_id'])->on('payment_events')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['refund_transfer_id', 'payment_id'], 'movements_transfer_payment_fk')->references(['id', 'payment_id'])->on('refund_transfers')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_movements
                ADD CONSTRAINT movements_kind_source_check CHECK (
                    (BINARY kind = 'receipt' AND BINARY source = 'gateway_receipt') OR
                    (BINARY kind = 'return' AND BINARY source IN ('manual_refund', 'provider_refund', 'reversal'))
                ),
                ADD CONSTRAINT movements_amount_check CHECK (amount BETWEEN 1 AND 9223372036854775807),
                ADD CONSTRAINT movements_identity_check CHECK (OCTET_LENGTH(canonical_key) > 0),
                ADD CONSTRAINT movements_provenance_check CHECK (
                    (BINARY source = 'manual_refund' AND refund_transfer_id IS NOT NULL) OR
                    (BINARY source <> 'manual_refund' AND refund_transfer_id IS NULL AND source_event_id IS NOT NULL)
                )
            SQL);
    }

    public function down(): void
    {
        if (DB::table('payment_movements')->exists()) {
            throw new RuntimeException('Cannot discard populated movement history; use a forward fix.');
        }

        Schema::dropIfExists('payment_movements');
    }
};
