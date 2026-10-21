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
        Schema::create('daily_availabilities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->date('availability_date');
            $table->unsignedInteger('quota_limit');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['product_id', 'availability_date']);
            $table->unique(['product_id', 'id']);
            $table->index(['availability_date', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('daily_availabilities')->exists()) {
            throw new RuntimeException('Cannot discard populated availability history; use a forward fix.');
        }

        Schema::dropIfExists('daily_availabilities');
    }
};
