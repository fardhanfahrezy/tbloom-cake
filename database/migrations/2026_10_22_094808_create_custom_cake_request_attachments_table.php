<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_cake_request_attachments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('custom_cake_request_id');
            $table->string('private_path', 255)->charset('ascii')->collation('ascii_bin')->unique();
            $table->text('original_name_ciphertext');
            $table->string('mime_type', 100)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('uploaded_by');
            $table->dateTime('created_at');
            $table->foreign('custom_cake_request_id', 'custom_attachments_request_fk')->references('id')->on('custom_cake_requests')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('uploaded_by', 'custom_attachments_uploader_fk')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['custom_cake_request_id', 'id'], 'custom_attachments_request_id_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE custom_cake_request_attachments
                ADD CONSTRAINT custom_attachments_size_check CHECK (size_bytes BETWEEN 1 AND 5242880),
                ADD CONSTRAINT custom_attachments_mime_check CHECK (BINARY mime_type IN ('image/jpeg', 'image/png', 'image/webp'))
            SQL);
    }

    public function down(): void
    {
        if (DB::table('custom_cake_request_attachments')->exists()) {
            throw new RuntimeException('Cannot discard populated custom attachment history; use a forward fix.');
        }

        Schema::dropIfExists('custom_cake_request_attachments');
    }
};
