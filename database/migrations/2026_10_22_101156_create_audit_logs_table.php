<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_type', 16)->charset('ascii')->collation('ascii_bin');
            $table->string('entity_type', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 100)->charset('ascii')->collation('ascii_bin');
            $table->json('before_redacted')->nullable();
            $table->json('after_redacted')->nullable();
            $table->text('reason')->nullable();
            $table->char('correlation_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at');
            $table->index(['entity_type', 'entity_id', 'created_at', 'id'], 'audit_entity_time_index');
            $table->index(['actor_user_id', 'created_at', 'id'], 'audit_actor_time_index');
            $table->index(['correlation_id', 'id'], 'audit_correlation_index');
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT audit_actor_type_check CHECK (BINARY actor_type IN ('user', 'system', 'provider')),
                ADD CONSTRAINT audit_user_actor_check CHECK (actor_type <> 'user' OR actor_user_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->exists()) {
            throw new RuntimeException('Cannot discard populated audit history; use a forward fix.');
        }

        Schema::dropIfExists('audit_logs');
    }
};
