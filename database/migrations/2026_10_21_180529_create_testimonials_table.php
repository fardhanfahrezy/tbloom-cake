<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('display_name', 100);
            $table->text('content');
            $table->string('photo_path', 255)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('publication_status', 16)->charset('ascii')->collation('ascii_bin')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['publication_status', 'sort_order', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE testimonials
                ADD CONSTRAINT testimonials_publication_check CHECK (BINARY publication_status IN ('draft', 'published', 'archived')),
                ADD CONSTRAINT testimonials_rating_check CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('testimonials')->exists()) {
            throw new RuntimeException('Cannot discard populated publication history; use a forward fix.');
        }

        Schema::dropIfExists('testimonials');
    }
};
