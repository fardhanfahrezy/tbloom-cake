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
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign(['closure_event_id', 'id'], 'payments_closure_event_payment_fk')
                ->references(['id', 'payment_id'])->on('payment_events')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payments')->exists() || DB::table('payment_events')->exists()) {
            throw new RuntimeException('Cannot remove closure protection from populated payment history; use a forward fix.');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_closure_event_payment_fk');
        });
    }
};
