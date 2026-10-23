<?php

namespace Database\Seeders;

use App\Models\RefundTransfer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class RefundTransferTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic transfer seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic transfer seeding only allows tbloom_testing.');
        }

        foreach ([RefundTransfer::factory(), RefundTransfer::factory()->unknown(), RefundTransfer::factory()->succeeded(), RefundTransfer::factory()->failedConfirmed()] as $factory) {
            $factory->create();
        }
    }
}
