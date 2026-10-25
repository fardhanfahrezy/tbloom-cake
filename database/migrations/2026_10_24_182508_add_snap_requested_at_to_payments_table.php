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
            $table->dateTime('snap_requested_at')->nullable();
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_snap_requested_check CHECK (snap_requested_at IS NULL OR snap_requested_at >= created_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('snap_requested_at')->exists()) {
            throw new RuntimeException('Cannot discard durable Snap attempt history; use a forward fix.');
        }
        DB::statement('ALTER TABLE payments DROP CHECK payments_snap_requested_check');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('snap_requested_at'));
    }
};
