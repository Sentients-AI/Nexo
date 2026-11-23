<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Domain\VendorLifecycle;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * L4 for the Vendor: the rule reports every marked line of its violation
 * fixture, nothing in its allowed fixture (including the documented blind
 * spots), and nothing in VendorLifecycle itself.
 *
 * @extends RuleTestCase<VendorStatusWriteRule>
 */
final class VendorStatusWriteRuleTest extends RuleTestCase
{
    private const MESSAGE = 'Only App\Modules\Vendors\Domain\VendorLifecycle may write a Vendor\'s status (dependency-rules.md L4).';

    protected function getRule(): Rule
    {
        return new VendorStatusWriteRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        // Larastan types the Eloquent builders the rule inspects.
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Building the container boots Larastan's Laravel application, which
        // installs global error and exception handlers. Doing it here, outside
        // any test, keeps PHPUnit from reporting a test as risky for them.
        self::getContainer();
    }

    public function test_it_reports_every_status_write_outside_the_lifecycle(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/L4/StatusWrites.php';
        $expected = [];

        foreach (file($fixture) ?: [] as $index => $line) {
            if (str_contains($line, '// violation')) {
                $expected[] = [self::MESSAGE, $index + 1];
            }
        }

        self::assertCount(18, $expected, 'The fixture marks 18 violations.');

        $this->analyse([$fixture], $expected);
    }

    public function test_it_ignores_other_writes_and_its_documented_blind_spots(): void
    {
        $this->analyse([dirname(__DIR__).'/Fixtures/L4Allowed/OtherWrites.php'], []);
    }

    public function test_it_lets_the_lifecycle_write_the_status(): void
    {
        $this->analyse([(new \ReflectionClass(VendorLifecycle::class))->getFileName() ?: ''], []);
    }
}
