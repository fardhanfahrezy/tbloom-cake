<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing') || $app->configurationIsCached()) {
            throw new LogicException('Tests require an uncached testing environment.');
        }

        $connection = $app['db']->connection();
        self::assertSafeDatabaseConfiguration($connection->getConfig());

        if ($connection->getDriverName() === 'mysql'
            && $connection->selectOne('SELECT DATABASE() AS schema_name')->schema_name !== 'tbloom_testing') {
            throw new LogicException('The connected database is not tbloom_testing.');
        }

        return $app;
    }

    /**
     * @param  array{driver?: string, database?: string, host?: string|list<string>, url?: ?string, read?: array<string, mixed>, write?: array<string, mixed>, unix_socket?: ?string}  $configuration
     */
    public static function assertSafeDatabaseConfiguration(array $configuration): void
    {
        foreach (['url', 'read', 'write', 'unix_socket'] as $override) {
            if (! empty($configuration[$override])) {
                throw new LogicException('Test database connection overrides are forbidden.');
            }
        }

        if (($configuration['driver'] ?? null) === 'sqlite'
            && ($configuration['database'] ?? null) === ':memory:') {
            return;
        }

        if (($configuration['driver'] ?? null) !== 'mysql'
            || ($configuration['database'] ?? null) !== 'tbloom_testing'
            || ! in_array($configuration['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new LogicException('Tests only allow SQLite memory or local tbloom_testing.');
        }
    }
}
