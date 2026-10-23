<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $invalid = DB::table('users')
            ->whereNull('whatsapp_number')->orWhereNull('role')->orWhereNull('is_active')
            ->orWhereNull('created_at')->orWhereNull('updated_at')
            ->orWhereRaw("BINARY role NOT IN ('customer', 'admin', 'owner')")
            ->orWhereNotIn('is_active', [0, 1])
            ->orWhereRaw('CHAR_LENGTH(name) > 100 OR CHAR_LENGTH(email) > 254')
            ->orWhereRaw('BINARY email <> BINARY LOWER(TRIM(email))')
            ->orWhereRaw("whatsapp_number NOT REGEXP '^[+][1-9][0-9]{1,14}$'")
            ->exists();
        $duplicateContacts = DB::table('users')->select('whatsapp_number')
            ->groupBy('whatsapp_number')->havingRaw('COUNT(*) > 1')->exists();

        if ($invalid || $duplicateContacts) {
            throw new RuntimeException('R1 users require a verified backfill before contract; no data was rewritten.');
        }

        $previousTimezone = DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone;
        DB::statement("SET SESSION time_zone = '+00:00'");

        try {
            DB::statement(<<<'SQL'
            ALTER TABLE users
                MODIFY name VARCHAR(100) NOT NULL,
                MODIFY email VARCHAR(254) NOT NULL,
                MODIFY whatsapp_number VARCHAR(16) NOT NULL,
                MODIFY role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'customer',
                MODIFY is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
                MODIFY email_verified_at DATETIME NULL,
                MODIFY created_at DATETIME NOT NULL,
                MODIFY updated_at DATETIME NOT NULL,
                ADD UNIQUE INDEX users_whatsapp_number_unique (whatsapp_number),
                ADD INDEX users_role_active_id_index (role, is_active, id),
                ADD CONSTRAINT users_role_check CHECK (BINARY role IN ('customer', 'admin', 'owner')),
                ADD CONSTRAINT users_active_check CHECK (is_active IN (0, 1))
            SQL);
        } finally {
            DB::statement('SET SESSION time_zone = ?', [$previousTimezone]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE users
                DROP CHECK users_role_check,
                DROP CHECK users_active_check,
                DROP INDEX users_whatsapp_number_unique,
                DROP INDEX users_role_active_id_index,
                MODIFY name VARCHAR(255) NOT NULL,
                MODIFY email VARCHAR(255) NOT NULL,
                MODIFY whatsapp_number VARCHAR(16) NULL,
                MODIFY role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
                MODIFY is_active TINYINT UNSIGNED NULL DEFAULT NULL,
                MODIFY email_verified_at DATETIME NULL,
                MODIFY created_at DATETIME NULL,
                MODIFY updated_at DATETIME NULL
            SQL);
    }
};
