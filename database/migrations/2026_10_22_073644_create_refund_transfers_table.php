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
        Schema::create('refund_transfers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('refund_log_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('attempt_number');
            $table->unsignedBigInteger('amount');
            $table->string('status', 32)->charset('ascii')->collation('ascii_bin')->default('prepared');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('assignment_version');
            $table->string('idempotency_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->string('source_account_key', 64)->charset('ascii')->collation('ascii_bin');
            $table->text('bank_name');
            $table->text('account_number');
            $table->text('account_holder');
            $table->dateTime('bank_verified_at');
            $table->dateTime('prepared_at');
            $table->string('transfer_reference', 128)->charset('utf8mb4')->collation('utf8mb4_0900_bin')->nullable();
            $table->dateTime('transferred_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('active_refund_id')->nullable()->storedAs("CASE WHEN status IN ('prepared', 'unknown') THEN refund_log_id ELSE NULL END");
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign(['refund_log_id', 'payment_id'])->references(['id', 'payment_id'])->on('refund_logs')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'payment_id']);
            $table->unique(['id', 'refund_log_id']);
            $table->unique(['refund_log_id', 'attempt_number']);
            $table->unique(['refund_log_id', 'idempotency_key']);
            $table->unique(['source_account_key', 'transfer_reference']);
            $table->unique('active_refund_id');
            $table->index(['status', 'prepared_at', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refund_transfers
                ADD CONSTRAINT refund_transfers_status_check CHECK (BINARY status IN ('prepared', 'unknown', 'succeeded', 'failed_confirmed')),
                ADD CONSTRAINT refund_transfers_amount_check CHECK (amount BETWEEN 1 AND 9223372036854775807),
                ADD CONSTRAINT refund_transfers_success_check CHECK (status <> 'succeeded' OR (transfer_reference IS NOT NULL AND transferred_at IS NOT NULL AND verified_by IS NOT NULL AND verified_at IS NOT NULL AND completed_at IS NOT NULL)),
                ADD CONSTRAINT refund_transfers_failure_check CHECK (status <> 'failed_confirmed' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL AND verification_note IS NOT NULL))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('refund_transfers')->exists()) {
            throw new RuntimeException('Cannot discard populated refund transfer history; use a forward fix.');
        }

        Schema::dropIfExists('refund_transfers');
    }
};
