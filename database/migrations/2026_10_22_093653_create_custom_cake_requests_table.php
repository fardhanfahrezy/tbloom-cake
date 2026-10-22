<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_cake_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('request_number', 40)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('customer_name_snapshot', 100);
            $table->string('whatsapp_snapshot', 16)->charset('ascii')->collation('ascii_bin');
            $table->date('pickup_date');
            $table->string('size', 100);
            $table->string('flavor_code', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('filling_code', 64)->charset('ascii')->collation('ascii_bin');
            $table->json('option_labels_snapshot');
            $table->string('color', 100);
            $table->string('inscription', 150)->nullable();
            $table->unsignedTinyInteger('edible_print')->default(0);
            $table->unsignedTinyInteger('fondant')->default(0);
            $table->text('fondant_details')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->string('status', 16)->charset('ascii')->collation('ascii_bin')->default('submitted');
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('status_reason')->nullable();
            $table->string('idempotency_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->char('request_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['user_id', 'idempotency_key'], 'custom_requests_user_key_unique');
            $table->index(['user_id', 'created_at', 'id'], 'custom_requests_user_created_id_index');
            $table->index(['status', 'created_at', 'id'], 'custom_requests_status_created_id_index');
            $table->index(['pickup_date', 'status', 'id'], 'custom_requests_pickup_status_id_index');
            $table->index(['assigned_admin_id', 'status', 'id'], 'custom_requests_assignee_status_id_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE custom_cake_requests
                ADD CONSTRAINT custom_requests_status_check CHECK (BINARY status IN ('submitted', 'contacted', 'quoted', 'accepted', 'rejected')),
                ADD CONSTRAINT custom_requests_edible_check CHECK (edible_print IN (0, 1)),
                ADD CONSTRAINT custom_requests_fondant_check CHECK (fondant IN (0, 1)),
                ADD CONSTRAINT custom_requests_fondant_details_check CHECK (fondant = 0 OR (fondant_details IS NOT NULL AND CHAR_LENGTH(TRIM(fondant_details)) > 0)),
                ADD CONSTRAINT custom_requests_rejection_check CHECK (BINARY status <> 'rejected' OR (status_reason IS NOT NULL AND CHAR_LENGTH(TRIM(status_reason)) > 0))
            SQL);
    }

    public function down(): void
    {
        if (DB::table('custom_cake_requests')->exists()) {
            throw new RuntimeException('Cannot discard populated custom request history; use a forward fix.');
        }

        Schema::dropIfExists('custom_cake_requests');
    }
};
