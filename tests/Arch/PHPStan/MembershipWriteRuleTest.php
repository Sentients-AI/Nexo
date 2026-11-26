<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<MembershipWriteRule> */
final class MembershipWriteRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new MembershipWriteRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::getContainer();
    }

    public function test_it_detects_membership_writes_outside_the_audited_operations(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/L11/MemberWrites.php';
        $expected = [];
        foreach (file($fixture) ?: [] as $index => $line) {
            if (str_contains($line, '// violation')) {
                $expected[] = [MembershipWriteRule::MESSAGE, $index + 1];
            }
        }

        self::assertCount(8, $expected);
        $this->analyse([$fixture], $expected);
    }

    public function test_it_allows_the_audited_operations(): void
    {
        $directory = dirname(__DIR__, 3).'/app/Modules/Vendors/Application/';
        $this->analyse([
            $directory.'AssignFirstOwner.php',
            $directory.'ChangeOwnership.php',
            $directory.'DeactivateMember.php',
        ], []);
    }
}
