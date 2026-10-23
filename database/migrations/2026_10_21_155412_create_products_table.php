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
        Schema::create('products', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 191)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('category', 32)->charset('ascii')->collation('ascii_bin');
            $table->text('description')->nullable();
            $table->string('sales_unit_label', 32);
            $table->unsignedInteger('default_daily_quota')->default(5);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['category', 'is_active', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_category_check CHECK (BINARY category IN ('simple', 'cupcake', 'bento')),
                ADD CONSTRAINT products_active_check CHECK (is_active IN (0, 1))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('products')->exists()) {
            throw new RuntimeException('Cannot discard populated catalog history; use a forward fix.');
        }

        Schema::dropIfExists('products');
    }
};
