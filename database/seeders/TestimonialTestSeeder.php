<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use App\Models\TestimonialPublicationStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class TestimonialTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic testimonials require the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic testimonials only allow tbloom_testing.');
        }

        foreach (TestimonialPublicationStatus::cases() as $status) {
            Testimonial::factory()->create(['publication_status' => $status]);
        }
    }
}
