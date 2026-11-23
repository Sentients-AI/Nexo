<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Modules\Vendors\Application\CreateVendor;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * L8: the rule reports every marked line of its violation fixture, nothing
 * in its allowed fixture (including the documented blind spots), and nothing
 * in CreateVendor itself.
 *
 * @extends RuleTestCase<VendorCreationRule>
 */
final class VendorCreationRuleTest extends RuleTestCase
{
    private const MESSAGE = 'Only App\Modules\Vendors\Application\CreateVendor may create a Vendor (dependency-rules.md L8).';

    protected function getRule(): Rule
    {
        return new VendorCreationRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        // Larastan types the Eloquent builders the rule inspects.
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // As in VendorStatusWriteRuleTest: boot Larastan's application outside
        // any test, so its global handlers do not make a test risky.
        self::getContainer();
    }

    public function test_it_reports_every_vendor_creation_outside_create_vendor(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/L8/Creations.php';
        $expected = [];

        foreach (file($fixture) ?: [] as $index => $line) {
            if (str_contains($line, '// violation')) {
                $expected[] = [self::MESSAGE, $index + 1];
            }
        }

        self::assertCount(26, $expected, 'The fixture marks 26 violations.');

        $this->analyse([$fixture], $expected);
    }

    public function test_it_ignores_other_operations_and_its_documented_blind_spots(): void
    {
        $this->analyse([dirname(__DIR__).'/Fixtures/L8Allowed/OtherOperations.php'], []);
    }

    public function test_it_lets_create_vendor_create_a_vendor(): void
    {
        $this->analyse([(new \ReflectionClass(CreateVendor::class))->getFileName() ?: ''], []);
    }
}
