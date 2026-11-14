<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Database\LockConnection;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Locks commit on mysql_locks, outside the per-test transaction, so
        // they would leak into the next test (ADR-0009 §3).
        if ($this->usesTestDatabase()) {
            $this->beforeApplicationDestroyed(fn () => LockConnection::clean($this->app));
        }
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

        try {
            $this->refreshApplication();
        } finally {
            $this->simulatedEnvironment = null;
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
