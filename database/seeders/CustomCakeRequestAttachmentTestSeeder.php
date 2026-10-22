<?php

namespace Database\Seeders;

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class CustomCakeRequestAttachmentTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic custom attachment seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic custom attachment seeding only allows tbloom_testing.');
        }

        $request = CustomCakeRequest::factory()->accepted()->create();
        CustomCakeRequestAttachment::factory()->count(3)->for($request, 'request')->sequence(
            ['mime_type' => 'image/jpeg', 'original_name_ciphertext' => '[TEST] synthetic-reference.jpeg'],
            ['mime_type' => 'image/png', 'original_name_ciphertext' => '[TEST] synthetic-reference.png'],
            ['mime_type' => 'image/webp', 'original_name_ciphertext' => '[TEST] synthetic-reference.webp'],
        )->create();
    }
}
