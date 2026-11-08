<?php

use App\Models\Testimonial;
use App\Models\TestimonialPublicationStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('public home page displays only published testimonials ordered by sort order and never leaks draft or archived entries', function () {
    Testimonial::factory()->create([
        'display_name' => 'Siti Draft',
        'content' => 'Testimoni ini masih draft dan tidak boleh tampil.',
        'publication_status' => TestimonialPublicationStatus::Draft,
        'sort_order' => 1,
    ]);

    Testimonial::factory()->create([
        'display_name' => 'Rina Archived',
        'content' => 'Testimoni arsip tidak boleh tampil di publik.',
        'publication_status' => TestimonialPublicationStatus::Archived,
        'sort_order' => 2,
    ]);

    Testimonial::factory()->create([
        'display_name' => 'Dina Kedua',
        'content' => 'Bento cake sangat rapi dan sesuai pesanan.',
        'rating' => 5,
        'publication_status' => TestimonialPublicationStatus::Published,
        'sort_order' => 10,
    ]);

    Testimonial::factory()->create([
        'display_name' => 'Maya Pertama',
        'content' => 'Rasa coklatnya lembut, pengambilan tepat waktu.',
        'photo_path' => 'icon.PNG',
        'rating' => 4,
        'publication_status' => TestimonialPublicationStatus::Published,
        'sort_order' => 1,
    ]);

    $response = $this->withoutVite()->get('/');

    $response->assertOk()
        ->assertSeeInOrder(['Maya Pertama', 'Dina Kedua'])
        ->assertSee('Rasa coklatnya lembut, pengambilan tepat waktu.')
        ->assertSee('Bento cake sangat rapi dan sesuai pesanan.')
        ->assertSee('4/5')
        ->assertSee('5/5')
        ->assertSee('alt="Foto testimoni Maya Pertama"', false)
        ->assertDontSee('Siti Draft')
        ->assertDontSee('Testimoni ini masih draft dan tidak boleh tampil.')
        ->assertDontSee('Rina Archived')
        ->assertDontSee('Testimoni arsip tidak boleh tampil di publik.');
});

test('active admin and owner can create and update testimonials with audit logs while invalid ratings or long content are rejected', function () {
    $customer = User::factory()->create();
    $this->actingAs($customer)->get('/staff/testimonials')->assertForbidden();
    $this->actingAs($customer)->post('/staff/testimonials', [
        'display_name' => 'Unauthorized',
        'content' => 'Tidak diizinkan',
        'publication_status' => 'published',
        'sort_order' => 0,
    ])->assertForbidden();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/staff/testimonials', [
        'display_name' => 'Rating Invalid Rendah',
        'content' => 'Konten valid',
        'rating' => 0,
        'publication_status' => 'draft',
        'sort_order' => 0,
    ])->assertSessionHasErrors(['rating']);

    $this->actingAs($admin)->post('/staff/testimonials', [
        'display_name' => 'Rating Invalid Tinggi',
        'content' => 'Konten valid',
        'rating' => 6,
        'publication_status' => 'draft',
        'sort_order' => 0,
    ])->assertSessionHasErrors(['rating']);

    $this->actingAs($admin)->post('/staff/testimonials', [
        'display_name' => 'Konten Terlalu Panjang',
        'content' => str_repeat('a', 2001),
        'rating' => 5,
        'publication_status' => 'draft',
        'sort_order' => 0,
    ])->assertSessionHasErrors(['content']);

    $this->actingAs($admin)->post('/staff/testimonials', [
        'display_name' => 'Clara Pelanggan',
        'content' => 'Custom cake ulang tahun anak sangat memuaskan.',
        'rating' => 5,
        'publication_status' => 'draft',
        'sort_order' => 3,
    ])->assertRedirect(route('staff.testimonials.index'));

    $testimonial = Testimonial::query()->where('display_name', 'Clara Pelanggan')->firstOrFail();
    expect($testimonial->publication_status)->toBe(TestimonialPublicationStatus::Draft)
        ->and($testimonial->rating)->toBe(5)
        ->and($testimonial->sort_order)->toBe(3);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'testimonial.created',
        'entity_type' => 'testimonial',
        'entity_id' => $testimonial->id,
        'actor_user_id' => $admin->id,
    ]);

    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->put(route('staff.testimonials.update', $testimonial), [
        'display_name' => 'Clara Pelanggan Indramayu',
        'content' => 'Custom cake ulang tahun anak sangat memuaskan dan tepat waktu.',
        'rating' => 5,
        'publication_status' => 'published',
        'sort_order' => 1,
    ])->assertRedirect(route('staff.testimonials.index'));

    expect($testimonial->fresh()->publication_status)->toBe(TestimonialPublicationStatus::Published)
        ->and($testimonial->fresh()->display_name)->toBe('Clara Pelanggan Indramayu')
        ->and($testimonial->fresh()->sort_order)->toBe(1);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'testimonial.updated',
        'entity_type' => 'testimonial',
        'entity_id' => $testimonial->id,
        'actor_user_id' => $owner->id,
    ]);
});
