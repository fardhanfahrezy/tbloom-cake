<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('event_key', 128)->charset('ascii')->collation('ascii_bin');
            $table->text('recipient_ciphertext');
            $table->char('recipient_key', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('template_key', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('entity_type', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('entity_id');
            $table->json('payload_minimal');
            $table->string('status', 32)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->string('provider_message_id', 128)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->char('claim_token', 36)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['event_key', 'recipient_key', 'template_key'], 'notification_delivery_identity_unique');
            $table->index(['status', 'next_attempt_at', 'id'], 'notification_next_attempt_index');
            $table->index(['status', 'lease_until', 'id'], 'notification_lease_index');
            $table->index(['entity_type', 'entity_id', 'id'], 'notification_entity_index');
            $table->index('provider_message_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE notification_deliveries
                ADD CONSTRAINT notification_status_check CHECK (BINARY status IN ('pending', 'processing', 'sent', 'delivered', 'unknown', 'failed', 'suppressed')),
                ADD CONSTRAINT notification_claim_pair_check CHECK ((claim_token IS NULL AND lease_until IS NULL) OR (claim_token IS NOT NULL AND lease_until IS NOT NULL)),
                ADD CONSTRAINT notification_sent_check CHECK (status <> 'sent' OR sent_at IS NOT NULL),
                ADD CONSTRAINT notification_delivered_check CHECK (status <> 'delivered' OR (sent_at IS NOT NULL AND delivered_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        if (DB::table('notification_deliveries')->exists()) {
            throw new RuntimeException('Cannot discard populated delivery history; use a forward fix.');
        }

        Schema::dropIfExists('notification_deliveries');
    }
};
