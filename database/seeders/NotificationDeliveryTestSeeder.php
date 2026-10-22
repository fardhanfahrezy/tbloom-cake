<?php

namespace Database\Seeders;

use App\Models\NotificationDelivery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class NotificationDeliveryTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic delivery seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic delivery seeding only allows tbloom_testing.');
        }

        foreach (['pending', 'processing', 'sent', 'delivered', 'unknown', 'failed', 'suppressed'] as $state) {
            NotificationDelivery::factory()->$state()->create();
        }
    }
}
