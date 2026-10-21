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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('size_label', 100);
            $table->unsignedBigInteger('price');
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['product_id', 'size_label']);
            $table->unique(['product_id', 'id']);
            $table->index(['product_id', 'is_active', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_variants
                ADD CONSTRAINT product_variants_price_check CHECK (price > 0 AND price <= 9223372036854775807),
                ADD CONSTRAINT product_variants_active_check CHECK (is_active IN (0, 1))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('product_variants')->exists()) {
            throw new RuntimeException('Cannot discard populated variant history; use a forward fix.');
        }

        Schema::dropIfExists('product_variants');
    }
};
