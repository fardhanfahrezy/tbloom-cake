<?php

namespace Database\Seeders;

use App\Models\PaymentMovement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentMovementTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic movement seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic movement seeding only allows tbloom_testing.');
        }

        PaymentMovement::factory()->create();
        PaymentMovement::factory()->manualRefund()->create();
        PaymentMovement::factory()->providerRefund()->create();
        PaymentMovement::factory()->reversal()->create();
    }
}
