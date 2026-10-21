<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class CatalogTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic catalog seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic catalog seeding only allows tbloom_testing.');
        }

        foreach (ProductCategory::cases() as $category) {
            Product::factory()
                ->has(ProductVariant::factory()->count(2), 'variants')
                ->has(ProductImage::factory(), 'images')
                ->create([
                    'name' => '[TEST] '.$category->value,
                    'category' => $category,
                    'sales_unit_label' => 'unit uji',
                ]);
        }
    }
}
