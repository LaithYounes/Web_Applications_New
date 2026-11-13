<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();

        // This runs before RefreshDatabase can migrate or drop any tables.
        if (! $app->environment('testing')
            || $app->configurationIsCached()
            || $connection->getDriverName() !== 'sqlite'
            || $connection->getConfig('database') !== ':memory:'
            || ! empty($connection->getConfig('url'))
            || config('cache.default') !== 'array'
            || config('order_notifications.driver') !== 'disabled') {
            throw new \RuntimeException('Unsafe test environment: isolated SQLite and array cache are required.');
        }

        $databases = $connection->select('PRAGMA database_list');
        if ($connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || count($databases) !== 1
            || $databases[0]->name !== 'main'
            || $databases[0]->file !== '') {
            throw new \RuntimeException('The active test database must be an in-memory SQLite database.');
        }

        return $app;
    }
}
