<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

test('rejects development and remote databases before any destructive test setup', function (array $overrides) {
    $configuration = array_replace([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'tbloom_testing',
    ], $overrides);

    expect(fn () => TestCase::assertSafeDatabaseConfiguration($configuration))
        ->toThrow(LogicException::class);
})->with([
    'development database' => [['database' => 'tbloom']],
    'other existing database' => [['database' => 'tbloom-contoh']],
    'remote host' => [['host' => 'production.example.com']],
    'connection URL' => [['url' => 'mysql://localhost/tbloom']],
    'read override' => [['read' => ['database' => 'tbloom']]],
    'write override' => [['write' => ['database' => 'tbloom']]],
    'socket override' => [['unix_socket' => '/run/mysql.sock']],
    'sqlite file' => [['driver' => 'sqlite', 'database' => 'database/database.sqlite']],
    'unsupported driver' => [['driver' => 'pgsql']],
]);

test('allows only isolated local MySQL or in-memory SQLite', function (array $configuration) {
    expect(fn () => TestCase::assertSafeDatabaseConfiguration($configuration))
        ->not->toThrow(LogicException::class);
})->with([
    'local MySQL' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'tbloom_testing']],
    'memory SQLite' => [['driver' => 'sqlite', 'database' => ':memory:']],
]);

test('refuses an unsafe database during application bootstrap before the test body runs', function () {
    $process = new Process([
        PHP_BINARY,
        base_path('vendor/bin/pest'),
        '--configuration', base_path('phpunit.xml'),
        '--compact', base_path('tests/Feature/ExampleTest.php'),
    ], base_path(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'tbloom', 'APP_ENV' => 'testing']);

    $process->run();

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getOutput().$process->getErrorOutput())
        ->toContain('Tests only allow SQLite memory or local tbloom_testing.');
});
