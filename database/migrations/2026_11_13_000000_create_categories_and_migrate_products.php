<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->engine = 'InnoDB'; $table->charset = 'utf8mb4'; $table->collation = 'utf8mb4_unicode_ci';
            $table->id(); $table->string('name', 100); $table->string('slug', 120)->charset('ascii')->collation('ascii_bin')->unique();
            $table->text('description')->nullable(); $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true); $table->timestamps(); $table->index(['is_active', 'sort_order', 'name']);
        });
        $now = now();
        DB::table('categories')->insert([
            ['name' => 'Simple Cake', 'slug' => 'simple', 'description' => 'Kue tart lembut berhias minimalis elegan untuk perayaan hangat keluarga.', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Cupcake', 'slug' => 'cupcake', 'description' => 'Porsi personal praktis dengan buttercream lembut dan aneka hiasan cantik.', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Bento Cake', 'slug' => 'bento', 'description' => 'Kue mini berukuran pas untuk kejutan manis dan momen personal.', 'sort_order' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Schema::table('products', function (Blueprint $table) { $table->unsignedBigInteger('category_id')->nullable()->after('id'); });
        DB::statement("UPDATE products INNER JOIN categories ON categories.slug = products.category SET products.category_id = categories.id");
        if (DB::table('products')->whereNull('category_id')->exists()) {
            throw new RuntimeException('Category migration stopped: one or more products have an unknown legacy category.');
        }
        DB::statement('ALTER TABLE products DROP CHECK products_category_check');
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->foreign('category_id', 'products_category_id_foreign')->references('id')->on('categories')->restrictOnDelete();
            $table->index(['category_id', 'is_active', 'id'], 'products_category_active_id_index');
            $table->dropIndex('products_category_is_active_id_index'); $table->dropColumn('category');
        });
    }
    public function down(): void
    {
        if (DB::table('categories')->whereNotIn('slug', ['simple', 'cupcake', 'bento'])->exists()) {
            throw new RuntimeException('Cannot roll back dynamic categories: custom categories exist. Preserve data with a forward migration instead.');
        }
        Schema::table('products', function (Blueprint $table) { $table->string('category', 32)->charset('ascii')->collation('ascii_bin')->nullable()->after('slug'); });
        DB::statement('UPDATE products INNER JOIN categories ON categories.id = products.category_id SET products.category = categories.slug');
        if (DB::table('products')->whereNull('category')->exists()) { throw new RuntimeException('Cannot restore legacy product categories because some category mappings are missing.'); }
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_active_id_index'); $table->dropForeign('products_category_id_foreign');
            $table->dropColumn('category_id'); $table->index(['category', 'is_active', 'id']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_category_check CHECK (BINARY category IN ('simple', 'cupcake', 'bento'))");
        Schema::dropIfExists('categories');
    }
};
