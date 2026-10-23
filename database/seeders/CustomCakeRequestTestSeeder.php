<?php

namespace Database\Seeders;

use App\Models\CustomCakeRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class CustomCakeRequestTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic custom request seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic custom request seeding only allows tbloom_testing.');
        }

        CustomCakeRequest::factory()->create();
        CustomCakeRequest::factory()->contacted()->create();
        CustomCakeRequest::factory()->quoted()->withFondant()->create();
        CustomCakeRequest::factory()->accepted()->withEdiblePrint()->create();
        CustomCakeRequest::factory()->rejected()->create();
    }
}
