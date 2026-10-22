<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuditLogTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic audit seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic audit seeding only allows tbloom_testing.');
        }

        AuditLog::factory()->create();
        AuditLog::factory()->byUser(User::factory()->owner()->create())->create();
        AuditLog::factory()->create(['actor_type' => 'provider']);
    }
}
