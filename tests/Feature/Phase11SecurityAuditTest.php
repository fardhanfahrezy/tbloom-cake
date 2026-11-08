<?php

use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\RefundLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('testimonial photo_path rejects external urls protocol-relative paths and directory traversal per R1-AC19 and DBT-14', function (string $maliciousPath) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('staff.testimonials.store'), [
        'display_name' => 'Pelanggan Uji',
        'content' => 'Ulasan valid.',
        'rating' => 5,
        'publication_status' => 'published',
        'sort_order' => 1,
        'photo_path' => $maliciousPath,
    ])->assertSessionHasErrors(['photo_path']);
})->with([
    'external https url' => ['https://evil.example/tracker.png'],
    'protocol relative url' => ['//evil.example/tracker.png'],
    'parent traversal' => ['../storage/app/private_uploads/secret.png'],
    'backslash traversal' => ['..\\secret.png'],
    'javascript scheme' => ['javascript:alert(1).png'],
]);

test('staff dashboard escapes SQL LIKE wildcards in order_number and customer_contact filters', function () {
    $admin = User::factory()->admin()->create();

    $order = Order::factory()->paid()->create([
        'order_number' => 'ORD-20261107-SAFE01',
        'customer_name_snapshot' => 'Dewi Lestari',
        'email_snapshot' => 'dewi@example.test',
        'whatsapp_snapshot' => '+6281234560001',
    ]);
    OrderItem::factory()->for($order)->create();
    Payment::factory()->for($order)->paid()->create();

    $this->withoutVite()->actingAs($admin)->get('/staff?'.http_build_query([
        'order_number' => '%%%',
    ]))->assertOk()->assertDontSee('ORD-20261107-SAFE01');

    $this->withoutVite()->actingAs($admin)->get('/staff?'.http_build_query([
        'customer_contact' => '________',
    ]))->assertOk()->assertDontSee('ORD-20261107-SAFE01');
});

test('staff dashboard never leaks encrypted bank details snap tokens claim tokens or notification recipient addresses', function () {
    $owner = User::factory()->owner()->create();

    $order = Order::factory()->paid()->create([
        'order_number' => 'ORD-20261107-SEC001',
    ]);
    OrderItem::factory()->for($order)->create();
    $payment = Payment::factory()->for($order)->paid()->create([
        'snap_token' => 'SECRET-SNAP-TOKEN-998877',
        'review_reason' => 'late_payment_after_expiry',
    ]);

    RefundLog::factory()->for($order)->for($payment)->latePayment()->verifiedBank()->create([
        'bank_name' => 'BCA-SECRET-BANK',
        'account_number' => '99887766554433',
        'account_holder' => 'RAHASIA PEMILIK REKENING',
    ]);

    NotificationDelivery::factory()->unknown()->create([
        'entity_type' => 'order',
        'entity_id' => $order->id,
        'recipient_ciphertext' => [
            'channel' => 'email',
            'address' => 'rahasia-penerima@example.test',
        ],
    ]);
    NotificationDelivery::factory()->processing()->create([
        'entity_type' => 'order',
        'entity_id' => $order->id,
        'claim_token' => 'SECRET-CLAIM-TOKEN-112233',
    ]);

    $this->withoutVite()->actingAs($owner)->get('/staff')
        ->assertOk()
        ->assertSee('ORD-20261107-SEC001')
        ->assertDontSee('SECRET-SNAP-TOKEN-998877')
        ->assertDontSee('99887766554433')
        ->assertDontSee('RAHASIA PEMILIK REKENING')
        ->assertDontSee('rahasia-penerima@example.test')
        ->assertDontSee('SECRET-CLAIM-TOKEN-112233');
});
