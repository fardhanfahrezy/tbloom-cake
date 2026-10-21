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
        Schema::create('order_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('daily_availability_id');
            $table->string('product_name_snapshot', 150);
            $table->string('size_snapshot', 100);
            $table->string('sales_unit_snapshot', 32);
            $table->string('flavor_code', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('flavor_label_snapshot', 100);
            $table->string('filling_code', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('filling_label_snapshot', 100);
            $table->string('cover_color', 100);
            $table->string('inscription', 150)->default('');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_total');
            $table->dateTime('quota_released_at')->nullable();
            $table->string('quota_release_reason', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->dateTime('created_at');
            $table->foreign(['product_id', 'product_variant_id'], 'order_items_product_variant_fk')
                ->references(['product_id', 'id'])->on('product_variants')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['product_id', 'daily_availability_id'], 'order_items_product_availability_fk')
                ->references(['product_id', 'id'])->on('daily_availabilities')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['daily_availability_id', 'quota_released_at', 'order_id'], 'order_items_availability_release_order_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0),
                ADD CONSTRAINT order_items_unit_price_check CHECK (unit_price BETWEEN 1 AND 9223372036854775807),
                ADD CONSTRAINT order_items_total_check CHECK (line_total BETWEEN 1 AND 9223372036854775807 AND line_total = CAST(unit_price AS DECIMAL(65, 0)) * quantity),
                ADD CONSTRAINT order_items_release_check CHECK ((quota_released_at IS NULL AND quota_release_reason IS NULL) OR (quota_released_at IS NOT NULL AND quota_release_reason IS NOT NULL AND quota_release_reason IN ('expired', 'cancelled')))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('order_items')->exists()) {
            throw new RuntimeException('Cannot discard populated allocation history; use a forward fix.');
        }

        Schema::dropIfExists('order_items');
    }
};
