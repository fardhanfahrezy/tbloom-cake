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
        Schema::create('refund_evidence', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('refund_log_id');
            $table->unsignedBigInteger('refund_transfer_id');
            $table->string('private_path', 255)->charset('ascii')->collation('ascii_bin')->unique();
            $table->text('original_name_ciphertext');
            $table->string('mime_type', 100)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('created_at');
            $table->foreign(['refund_transfer_id', 'refund_log_id'])->references(['id', 'refund_log_id'])->on('refund_transfers')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['refund_transfer_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refund_evidence
                ADD CONSTRAINT refund_evidence_size_check CHECK (size_bytes BETWEEN 1 AND 5242880),
                ADD CONSTRAINT refund_evidence_mime_check CHECK (BINARY mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('refund_evidence')->exists()) {
            throw new RuntimeException('Cannot discard populated refund evidence history; use a forward fix.');
        }

        Schema::dropIfExists('refund_evidence');
    }
};
