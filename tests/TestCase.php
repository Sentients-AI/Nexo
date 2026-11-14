<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

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
}
