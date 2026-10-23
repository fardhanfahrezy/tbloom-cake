<?php

use Illuminate\Support\Facades\DB;

test('uses independent UTC READ COMMITTED connections to the isolated MySQL schema', function () {
    $primary = DB::connection();
    $secondary = DB::build(config('database.connections.mysql_testing'));

    try {
        $first = $primary->selectOne('SELECT DATABASE() AS schema_name, CONNECTION_ID() AS connection_id, @@session.time_zone AS timezone, @@transaction_isolation AS isolation_level, @@collation_connection AS collation_name');
        $second = $secondary->selectOne('SELECT DATABASE() AS schema_name, CONNECTION_ID() AS connection_id, @@session.time_zone AS timezone, @@transaction_isolation AS isolation_level');

        expect($first->schema_name)->toBe('tbloom_testing');
        expect($second->schema_name)->toBe('tbloom_testing');
        expect($first->connection_id)->not->toBe($second->connection_id);
        expect($first->timezone)->toBe('+00:00');
        expect($second->timezone)->toBe('+00:00');
        expect($first->isolation_level)->toBe('READ-COMMITTED');
        expect($second->isolation_level)->toBe('READ-COMMITTED');
        expect($first->collation_name)->toBe('utf8mb4_unicode_ci');
    } finally {
        $secondary->disconnect();
    }
})->skip(fn (): bool => config('database.default') !== 'mysql_testing', 'Run with --configuration phpunit.mysql.xml; SQLite is not MySQL proof.');
