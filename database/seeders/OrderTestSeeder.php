<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\QuotaReleaseReason;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class OrderTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_testing') {
            throw new LogicException('Synthetic order seeding requires the isolated MySQL test connection.');
        }

        if (DB::selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('Synthetic order seeding only allows tbloom_testing.');
        }

        foreach ([Order::factory(), Order::factory()->paid(), Order::factory()->completed(), Order::factory()->expired(), Order::factory()->cancelled(), Order::factory()->onlineCourier()] as $factory) {
            $order = $factory->create();
            $item = OrderItem::factory()->for($order)
                ->for(ProductVariant::factory()->state(['price' => $order->product_total]), 'productVariant');

            if ($order->status === OrderStatus::Expired) {
                $item = $item->released(QuotaReleaseReason::Expired);
            } elseif ($order->status === OrderStatus::Cancelled) {
                $item = $item->released(QuotaReleaseReason::Cancelled);
            }

            $item->create();
        }
    }
}
