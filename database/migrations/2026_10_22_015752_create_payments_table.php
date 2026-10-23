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
        Schema::create('payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('provider', 32)->charset('ascii')->collation('ascii_bin')->default('midtrans');
            $table->string('merchant_reference', 100)->charset('ascii')->collation('ascii_bin');
            $table->string('provider_transaction_id', 128)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedBigInteger('expected_amount');
            $table->char('currency', 3)->charset('ascii')->collation('ascii_bin')->default('IDR');
            $table->string('status', 32)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->string('provider_status', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('fraud_status', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->dateTime('provider_paid_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedTinyInteger('is_late')->nullable();
            $table->string('review_reason', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedInteger('reconciliation_attempts')->default(0);
            $table->dateTime('next_reconcile_at')->nullable();
            $table->string('closure_result', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->dateTime('closure_verified_at')->nullable();
            $table->unsignedBigInteger('closure_event_id')->nullable();
            $table->text('snap_token')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['provider', 'merchant_reference']);
            $table->unique(['provider', 'provider_transaction_id']);
            $table->unique(['id', 'order_id']);
            $table->index(['status', 'next_reconcile_at', 'id']);
            $table->index(['review_reason', 'next_reconcile_at', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_amount_check CHECK (expected_amount BETWEEN 1 AND 9223372036854775807),
                ADD CONSTRAINT payments_currency_check CHECK (currency = 'IDR'),
                ADD CONSTRAINT payments_status_check CHECK (BINARY status IN ('pending', 'paid', 'failed', 'expired', 'cancelled', 'partially_refunded', 'refunded')),
                ADD CONSTRAINT payments_late_check CHECK (is_late IS NULL OR is_late IN (0, 1)),
                ADD CONSTRAINT payments_closure_check CHECK ((closure_result IS NULL AND closure_verified_at IS NULL AND closure_event_id IS NULL) OR (closure_result IS NOT NULL AND closure_verified_at IS NOT NULL AND closure_event_id IS NOT NULL AND BINARY closure_result = 'unpaid_closed'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payments')->exists()) {
            throw new RuntimeException('Cannot discard populated payment history; use a forward fix.');
        }

        Schema::dropIfExists('payments');
    }
};
