<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Testing\ParallelTesting;
use Tests\Support\Database\GuardInput;
use Tests\Support\Database\LockConnection;
use Tests\Support\Database\TestDatabaseGuard;
use Tests\Support\Redis\GuardedRedisManager;

/**
 * The only way tests create the application.
 *
 * Laravel's parallel runner also uses this trait when it exists
 * (Illuminate\Testing\Concerns\RunsInParallel::createApplication()), so the
 * guard runs before its per-process callbacks, which can drop databases
 * (--recreate-databases, --drop-databases). Concurrency workers use it too.
 */
trait CreatesApplication
{
    /**
     * A runtime environment to present to the application instead of
     * "testing", for tests of environment-gated behaviour. Configuration is
     * still loaded, and guarded, as "testing"; see createApplication().
     */
    protected ?string $simulatedEnvironment = null;

    public function createApplication(): Application
    {
        $basePath = dirname(__DIR__);

        // G1, G2: before anything is loaded.
        TestDatabaseGuard::enforce(GuardInput::fromEnvironment($basePath));

        /** @var Application $app */
        $app = require $basePath.'/bootstrap/app.php';

        $loadedAs = null;
        $simulated = $this->simulatedEnvironment;

        // G1-G5 as soon as configuration is loaded, before any service
        // provider registers or boots, and before Laravel's parallel database
        // switch and RefreshDatabase.
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use (&$loadedAs, $simulated): void {
            TestDatabaseGuard::enforce(GuardInput::fromApplication($app));

            $loadedAs = $app['env'];

            if ($simulated !== null) {
                $app['env'] = $simulated;
                $app->make('config')->set('app.env', $simulated);
            }
        });

        $app->make(Kernel::class)->bootstrap();

        // Again on the final configuration, in case a provider changed it. G2
        // is judged on the environment the configuration was loaded as.
        $loadedAs = is_string($loadedAs) ? $loadedAs : null;

        TestDatabaseGuard::enforce(GuardInput::fromApplication($app)->withAppEnv($loadedAs));

        // Every Redis connection a test resolves, in any suite, proves its
        // identity before it is handed out (ADR-0022 §5, R6).
        GuardedRedisManager::install($app);

        // Under --parallel, Laravel's own set-up callback (registered while
        // the application booted, so it runs first) switches only the default
        // connection to nexo_testing_test_<N>. Follow it with the lock
        // connection and this worker's Redis prefix, nexo_testing:test_<N>:
        // (ADR-0022 §4), then guard the switched configuration.
        $app->make(ParallelTesting::class)->setUpTestCase(static function () use ($app, $loadedAs): void {
            $config = $app->make('config');
            $database = $config->get('database.connections.'.$config->get('database.default').'.database');

            if (is_string($database)) {
                LockConnection::pointAt($app, $database);
            }

            $token = $app->make(ParallelTesting::class)->token();

            if (is_string($token) && $token !== '') {
                GuardedRedisManager::pointAtWorker($app, $token);
            }

            TestDatabaseGuard::enforce(GuardInput::fromApplication($app)->withAppEnv($loadedAs));
        });

        return $app;
    }
}
