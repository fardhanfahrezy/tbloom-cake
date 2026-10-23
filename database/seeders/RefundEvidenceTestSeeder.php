<?php

namespace Database\Seeders;

use App\Models\RefundEvidence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class RefundEvidenceTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic evidence seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic evidence seeding only allows tbloom_testing.');
        }

        RefundEvidence::factory()->create();
        RefundEvidence::factory()->image()->create();
    }
}
