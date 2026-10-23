<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic payment seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic payment seeding only allows tbloom_testing.');
        }

        $pending = Payment::factory()->create();
        PaymentEvent::factory()->for($pending)->claimed()->create();

        $paid = Payment::factory()->for(Order::factory()->paid())->paid()->create([
            'snap_token' => '[TEST] synthetic Snap token',
        ]);
        PaymentEvent::factory()->for($paid)->processed()->create([
            'payload_redacted' => ['fixture' => 'synthetic', 'transaction_status' => 'settlement'],
        ]);

        Payment::factory()->for(Order::factory()->expired())->closedUnpaid()->create();
        PaymentEvent::factory()->unmatched()->create();
    }
}
