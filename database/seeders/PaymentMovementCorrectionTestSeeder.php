<?php

namespace Database\Seeders;

use App\Models\PaymentMovementCorrection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentMovementCorrectionTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic correction seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic correction seeding only allows tbloom_testing.');
        }

        $first = PaymentMovementCorrection::factory()->create();
        PaymentMovementCorrection::factory()->after($first)->voided()->create();
    }
}
