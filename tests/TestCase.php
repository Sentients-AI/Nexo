<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\Support\Database\LockConnection;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            // Locks commit on mysql_locks, outside the per-test transaction,
            // so they would leak into the next test (ADR-0009 §3).
            if ($this->usesTestDatabase()) {
                LockConnection::clean($this->app);
            }

            // RefreshDatabase disconnects only the default connection, and the
            // cleanup above reconnects it. Close everything this application
            // opened; otherwise each test leaves connections open until PHP's
            // cycle collector frees the application, and four parallel
            // processes exhaust MySQL's max_connections.
            self::closeConnections($this->app);
        });
    }

    /**
     * Reboot the application presenting $environment as its runtime
     * environment, so environment gates (bootstrap/app.php) run for real.
     * The process stays APP_ENV=testing: configuration and .env files are
     * loaded, and guarded, as testing.
     */
    public function rebootSimulatingEnvironment(string $environment): void
    {
        $this->simulatedEnvironment = $environment;

        // The replaced application's connections would otherwise stay open.
        self::closeConnections($this->app);

        try {
            $this->refreshApplication();
        } finally {
            $this->simulatedEnvironment = null;
        }

        // A new application starts on the base database. Under --parallel,
        // re-run the per-test switch (Laravel's, then ours) so it uses this
        // worker's nexo_testing_test_<N>, as setUp() did for the first one.
        ParallelTesting::callSetUpTestCaseCallbacks($this);
    }

    private static function closeConnections(?Application $app): void
    {
        if ($app === null) {
            return;
        }

        $db = $app->make('db');

        foreach (array_keys($db->getConnections()) as $name) {
            $db->purge($name);
        }
    }

    private function usesTestDatabase(): bool
    {
        $traits = class_uses_recursive(static::class);

        foreach ([RefreshDatabase::class, DatabaseTruncation::class, DatabaseMigrations::class, DatabaseTransactions::class] as $trait) {
            if (isset($traits[$trait])) {
                return true;
            }
        }

        return false;
    }
}
