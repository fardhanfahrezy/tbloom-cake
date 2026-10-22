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
        Schema::create('refund_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->unique();
            $table->string('reason_type', 32)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('basis_amount');
            $table->unsignedSmallInteger('percentage');
            $table->unsignedBigInteger('amount');
            $table->string('status', 32)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->text('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->text('account_holder')->nullable();
            $table->dateTime('bank_verified_at')->nullable();
            $table->foreignId('bank_verified_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('assignment_version')->default(0);
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('completed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign(['payment_id', 'order_id'])->references(['id', 'order_id'])->on('payments')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'payment_id']);
            $table->index(['status', 'created_at', 'id']);
            $table->index(['assigned_admin_id', 'status', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refund_logs
                ADD CONSTRAINT refund_logs_reason_check CHECK (reason_type IN ('customer_cancel', 'store_cancel', 'late_payment', 'amount_anomaly')),
                ADD CONSTRAINT refund_logs_percentage_check CHECK ((reason_type = 'customer_cancel' AND percentage = 80) OR (reason_type IN ('store_cancel', 'late_payment', 'amount_anomaly') AND percentage = 100)),
                ADD CONSTRAINT refund_logs_money_check CHECK (basis_amount BETWEEN 0 AND 9223372036854775807 AND amount BETWEEN 0 AND 9223372036854775807),
                ADD CONSTRAINT refund_logs_floor_check CHECK (amount = FLOOR(CAST(basis_amount AS DECIMAL(65,0)) * percentage / 100)),
                ADD CONSTRAINT refund_logs_status_check CHECK (status IN ('pending', 'processing', 'review', 'completed')),
                ADD CONSTRAINT refund_logs_bank_check CHECK ((bank_name IS NULL AND account_number IS NULL AND account_holder IS NULL) OR (bank_name IS NOT NULL AND account_number IS NOT NULL AND account_holder IS NOT NULL)),
                ADD CONSTRAINT refund_logs_bank_verification_check CHECK ((bank_verified_at IS NULL AND bank_verified_by IS NULL) OR (bank_verified_at IS NOT NULL AND bank_verified_by IS NOT NULL)),
                ADD CONSTRAINT refund_logs_completed_check CHECK (status <> 'completed' OR (completed_at IS NOT NULL AND completed_by IS NOT NULL))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('refund_logs')->exists()) {
            throw new RuntimeException('Cannot discard populated refund obligation history; use a forward fix.');
        }

        Schema::dropIfExists('refund_logs');
    }
};
