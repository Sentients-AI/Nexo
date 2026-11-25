<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Application\ReactivateVendor;
use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Application\SuspendVendor;
use App\Modules\Vendors\Infrastructure\Console\CreateVendorCommand;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * L9: the rule reports every marked line of its violation fixture, nothing
 * in its allowed fixture (including the documented blind spots), and
 * nothing in the four orchestrators or vendors:create as they are.
 * Temporary copies prove the allowed callers are exact: another Vendors
 * Application class, an Infrastructure class, another method of an
 * orchestrator, and an orchestrator calling another operation are each
 * reported.
 *
 * @extends RuleTestCase<VendorOrchestrationRule>
 */
final class VendorOrchestrationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new VendorOrchestrationRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        // Larastan types app() and the container calls the rule inspects.
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // As in VendorStatusWriteRuleTest: boot Larastan's application outside
        // any test, so its global handlers do not make a test risky.
        self::getContainer();
    }

    public function test_it_reports_every_bypass_of_the_orchestrators(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/L9/Bypasses.php';
        $expected = [];

        foreach (file($fixture) ?: [] as $index => $line) {
            if (! str_contains($line, '// violation')) {
                continue;
            }

            $expected[] = [self::message($line), $index + 1];
        }

        self::assertCount(13, $expected, 'The fixture marks 13 violations.');

        $this->analyse([$fixture], $expected);
    }

    public function test_it_ignores_other_calls_and_its_documented_blind_spots(): void
    {
        $this->analyse([dirname(__DIR__).'/Fixtures/L9Allowed/OtherCalls.php'], []);
    }

    public function test_it_lets_each_orchestrator_call_its_own_operation(): void
    {
        $this->analyse(array_map(
            fn (string $class): string => (new \ReflectionClass($class))->getFileName() ?: '',
            [RegisterVendor::class, ApproveVendor::class, SuspendVendor::class, ReactivateVendor::class, CreateVendorCommand::class],
        ), []);
    }

    public function test_it_reports_another_vendors_application_class(): void
    {
        $this->assertReportedInCopyOf(RegisterVendor::class, [
            'final readonly class RegisterVendor' => 'final readonly class ImportVendors',
            '            $vendor = $this->createVendor->handle($name, $slug);' => '            $vendor = $this->createVendor->handle($name, $slug); // reported',
        ], 'App\Modules\Vendors\Application\RegisterVendor', 'App\Modules\Vendors\Application\CreateVendor', 'handle');
    }

    public function test_it_reports_an_infrastructure_class(): void
    {
        $this->assertReportedInCopyOf(CreateVendorCommand::class, [
            'use App\Modules\Vendors\Application\RegisterVendor;' => "use App\\Modules\\Vendors\\Application\\CreateVendor;\nuse App\\Modules\\Vendors\\Application\\RegisterVendor;",
            'public function handle(RegisterVendor $registerVendor): int' => 'public function handle(RegisterVendor $registerVendor, CreateVendor $createVendor): int',
            '        try {' => "        \$createVendor->handle('Acme', 'acme'); // reported\n\n        try {",
        ], 'App\Modules\Vendors\Application\RegisterVendor', 'App\Modules\Vendors\Application\CreateVendor', 'handle');
    }

    public function test_it_reports_an_orchestrator_calling_another_operation(): void
    {
        $this->assertReportedInCopyOf(ApproveVendor::class, [
            '            $this->lifecycle->approve($vendor);' => '            $this->lifecycle->suspend($vendor); // reported',
        ], 'App\Modules\Vendors\Application\SuspendVendor', 'App\Modules\Vendors\Domain\VendorLifecycle', 'suspend');
    }

    public function test_it_reports_an_orchestrator_calling_its_operation_outside_handle(): void
    {
        $this->assertReportedInCopyOf(ApproveVendor::class, [
            '    public function handle(Actor $actor, int $vendorId): Vendor' => "    public function approveQuietly(Vendor \$vendor): void\n    {\n        \$this->lifecycle->approve(\$vendor); // reported\n    }\n\n    public function handle(Actor \$actor, int \$vendorId): Vendor",
        ], 'App\Modules\Vendors\Application\ApproveVendor', 'App\Modules\Vendors\Domain\VendorLifecycle', 'approve');
    }

    /** The message for the operation named on a fixture line. */
    private static function message(string $line): string
    {
        foreach ([
            '->handle(' => [RegisterVendor::class, 'App\Modules\Vendors\Application\CreateVendor', 'handle'],
            "'handle'" => [RegisterVendor::class, 'App\Modules\Vendors\Application\CreateVendor', 'handle'],
            'approve' => [ApproveVendor::class, 'App\Modules\Vendors\Domain\VendorLifecycle', 'approve'],
            'suspend' => [SuspendVendor::class, 'App\Modules\Vendors\Domain\VendorLifecycle', 'suspend'],
            'reactivate' => [ReactivateVendor::class, 'App\Modules\Vendors\Domain\VendorLifecycle', 'reactivate'],
        ] as $needle => [$caller, $target, $method]) {
            if (str_contains($line, $needle)) {
                return self::format($caller, $target, $method);
            }
        }

        self::fail("No operation on the line: {$line}");
    }

    private static function format(string $caller, string $target, string $method): string
    {
        return "Only {$caller}::handle() may call {$target}::{$method}(): the operation is audited only there (dependency-rules.md L9).";
    }

    /**
     * Analyse a temporary copy of $class's file with $replacements applied
     * (each found once): exactly its `// reported` line is reported.
     *
     * @param  class-string  $class
     * @param  array<string, string>  $replacements
     */
    private function assertReportedInCopyOf(string $class, array $replacements, string $caller, string $target, string $method): void
    {
        $source = file_get_contents((new \ReflectionClass($class))->getFileName() ?: '') ?: '';

        foreach ($replacements as $search => $replace) {
            self::assertSame(1, substr_count($source, $search), "{$class} contains \"{$search}\" once.");
            $source = str_replace($search, $replace, $source);
        }

        $lines = [];

        foreach (explode("\n", $source) as $index => $line) {
            if (str_contains($line, '// reported')) {
                $lines[] = [self::format($caller, $target, $method), $index + 1];
            }
        }

        self::assertCount(1, $lines, 'The copy marks one reported line.');

        $copy = sys_get_temp_dir().'/nexo-l9-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($copy, $source);

        try {
            $this->analyse([$copy], $lines);
        } finally {
            unlink($copy);
        }
    }
}
