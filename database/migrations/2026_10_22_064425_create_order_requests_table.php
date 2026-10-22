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
        Schema::create('order_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 16)->charset('ascii')->collation('ascii_bin');
            $table->string('decision_mode', 16)->charset('ascii')->collation('ascii_bin');
            $table->json('requested_changes')->nullable();
            $table->text('reason');
            $table->string('status', 16)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->dateTime('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->string('idempotency_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->char('request_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('active_order_id')->nullable()->storedAs("CASE WHEN status = 'pending' THEN order_id ELSE NULL END");
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign(['order_id', 'user_id'])->references(['id', 'user_id'])->on('orders')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('active_order_id');
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['order_id', 'submitted_at', 'id']);
            $table->index(['status', 'submitted_at', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_requests
                ADD CONSTRAINT order_requests_type_check CHECK (BINARY type IN ('cancellation', 'change')),
                ADD CONSTRAINT order_requests_mode_check CHECK (BINARY decision_mode IN ('automatic', 'manual')),
                ADD CONSTRAINT order_requests_status_check CHECK (BINARY status IN ('pending', 'applied', 'rejected')),
                ADD CONSTRAINT order_requests_changes_check CHECK (type <> 'change' OR requested_changes IS NOT NULL),
                ADD CONSTRAINT order_requests_applied_check CHECK (status <> 'applied' OR (applied_at IS NOT NULL AND reviewed_at IS NOT NULL)),
                ADD CONSTRAINT order_requests_rejected_check CHECK (status <> 'rejected' OR (reviewed_at IS NOT NULL AND decision_reason IS NOT NULL))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_requests')->exists()) {
            throw new RuntimeException('Cannot discard populated order request history; use a forward fix.');
        }

        Schema::dropIfExists('order_requests');
    }
};
