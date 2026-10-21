<?php

use App\Models\Testimonial;
use App\Models\TestimonialPublicationStatus;
use Database\Seeders\TestimonialTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores draft testimonials with optional rating and photo', function () {
    $testimonial = Testimonial::factory()->create()->fresh();

    expect($testimonial->publication_status)->toBe(TestimonialPublicationStatus::Draft);
    expect($testimonial->rating)->toBeNull();
    expect($testimonial->photo_path)->toBeNull();
    expect($testimonial->sort_order)->toBe(0);
});

test('rejects invalid testimonial values in MySQL', function (array $overrides, int $errorCode) {
    Testimonial::factory()->create();
    $attributes = array_replace(Testimonial::factory()->make()->getAttributes(), [
        'created_at' => '2026-10-21 12:00:00',
        'updated_at' => '2026-10-21 12:00:00',
    ], $overrides);

    try {
        DB::table('testimonials')->insert($attributes);
        $this->fail('MySQL accepted invalid testimonial values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'rating zero' => [['rating' => 0], 3819],
    'rating six' => [['rating' => 6], 3819],
    'invalid publication status' => [['publication_status' => 'public'], 3819],
    'negative sort order' => [['sort_order' => -1], 1264],
    'missing creation time' => [['created_at' => null], 1048],
]);

test('stores both inclusive rating boundaries without changing publication state', function () {
    $first = Testimonial::factory()->create(['rating' => 1]);
    $last = Testimonial::factory()->create(['rating' => 5]);

    expect($first->fresh()->rating)->toBe(1);
    expect($last->fresh()->rating)->toBe(5);
    expect($last->fresh()->publication_status)->toBe(TestimonialPublicationStatus::Draft);
});

test('seeds synthetic publication examples only in the isolated database', function () {
    $this->seed(TestimonialTestSeeder::class);

    $this->assertDatabaseCount('testimonials', 3);
    expect(Testimonial::query()->orderBy('publication_status')->pluck('publication_status')->map(fn (TestimonialPublicationStatus $status): string => $status->value)->all())
        ->toBe(['archived', 'draft', 'published']);
    expect(Testimonial::query()->where('display_name', 'like', '[TEST]%')->count())->toBe(3);
});

test('refuses testimonial example seeding through the development connection', function () {
    config(['database.default' => 'mysql']);

    try {
        expect(fn () => app(TestimonialTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }

    $this->assertDatabaseCount('testimonials', 0);
});

test('refuses testimonial example seeding outside the test environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    try {
        expect(fn () => app(TestimonialTestSeeder::class)->run())->toThrow(LogicException::class);
        $this->assertDatabaseCount('testimonials', 0);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

test('refuses rollback that would discard publication history', function () {
    $testimonial = Testimonial::factory()->create();
    $migration = require database_path('migrations/2026_10_21_180529_create_testimonials_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');

    $this->assertModelExists($testimonial);
});
