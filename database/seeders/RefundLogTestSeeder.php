<?php

namespace Database\Seeders;

use App\Models\RefundLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class RefundLogTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic obligation seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic obligation seeding only allows tbloom_testing.');
        }

        foreach ([RefundLog::factory(), RefundLog::factory()->storeCancellation(), RefundLog::factory()->latePayment(), RefundLog::factory()->amountAnomaly(), RefundLog::factory()->processing(), RefundLog::factory()->review(), RefundLog::factory()->completed()] as $factory) {
            $factory->create();
        }
    }
}
