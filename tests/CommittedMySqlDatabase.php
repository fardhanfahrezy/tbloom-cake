<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;

trait CommittedMySqlDatabase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (config('database.default') !== 'mysql_testing') {
            $this->markTestSkipped('Committed transactions require isolated MySQL.');
        }

        $this->beforeApplicationDestroyed(function (): void {
            $this->truncateTablesForAllConnections();
        });
    }
}
