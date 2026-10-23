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
        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('order_number', 40)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('status', 32)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->string('customer_name_snapshot', 100);
            $table->string('email_snapshot', 254);
            $table->string('whatsapp_snapshot', 16);
            $table->date('pickup_date');
            $table->time('pickup_time');
            $table->string('pickup_method', 16)->charset('ascii')->collation('ascii_bin');
            $table->string('handover_note', 255)->nullable();
            $table->unsignedBigInteger('product_total');
            $table->dateTime('payment_expires_at');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('cancellation_reason')->nullable();
            $table->string('policy_version', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->text('policy_text_snapshot')->nullable();
            $table->dateTime('courier_terms_accepted_at')->nullable();
            $table->string('idempotency_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->char('request_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'status', 'id']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['status', 'payment_expires_at', 'id']);
            $table->index(['pickup_date', 'status', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_status_check CHECK (BINARY status IN ('pending', 'paid', 'completed', 'expired', 'cancelled')),
                ADD CONSTRAINT orders_pickup_method_check CHECK (BINARY pickup_method IN ('self_pickup', 'online_courier')),
                ADD CONSTRAINT orders_pickup_time_check CHECK (pickup_time BETWEEN '09:00:00' AND '21:00:00' AND SECOND(pickup_time) = 0),
                ADD CONSTRAINT orders_total_check CHECK (product_total BETWEEN 1 AND 9223372036854775807),
                ADD CONSTRAINT orders_deadline_check CHECK (payment_expires_at = created_at + INTERVAL 30 MINUTE),
                ADD CONSTRAINT orders_paid_time_check CHECK (status NOT IN ('paid', 'completed') OR paid_at IS NOT NULL),
                ADD CONSTRAINT orders_completed_check CHECK (status <> 'completed' OR (completed_at IS NOT NULL AND handed_over_by IS NOT NULL)),
                ADD CONSTRAINT orders_cancelled_check CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL),
                ADD CONSTRAINT orders_expired_check CHECK (status <> 'expired' OR expired_at IS NOT NULL),
                ADD CONSTRAINT orders_courier_terms_check CHECK (pickup_method <> 'online_courier' OR (policy_version IS NOT NULL AND policy_text_snapshot IS NOT NULL AND courier_terms_accepted_at IS NOT NULL))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->exists()) {
            throw new RuntimeException('Cannot discard populated order history; use a forward fix.');
        }

        Schema::dropIfExists('orders');
    }
};
