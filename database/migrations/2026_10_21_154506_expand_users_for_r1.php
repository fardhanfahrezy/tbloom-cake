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
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_number', 16)->nullable();
            $table->string('role', 16)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedTinyInteger('is_active')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNotNull('whatsapp_number')->orWhereNotNull('role')->orWhereNotNull('is_active')->exists()) {
            throw new RuntimeException('Cannot discard populated R1 user identities; use a forward fix.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'role', 'is_active']);
        });
    }
};
