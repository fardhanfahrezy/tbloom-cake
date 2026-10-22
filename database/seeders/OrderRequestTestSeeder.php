<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class OrderRequestTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic request seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic request seeding only allows tbloom_testing.');
        }

        OrderRequest::factory()->create();
        OrderRequest::factory()->change()->create();
        OrderRequest::factory()->for(Order::factory())->automaticCancellation()->create();
        OrderRequest::factory()->change()->applied()->create();
        OrderRequest::factory()->rejected()->create();
    }
}
