<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Shared\Audit\AuditRecorder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * L10: the rule reports every marked line of its violation fixture, nothing
 * in its allowed fixture (including the documented blind spots), nothing in
 * App\Shared\Audit as it is (the recorder's insert is the negative control),
 * and a mutation added to AuditRecorder itself: App\Shared\Audit has no
 * exemption.
 *
 * @extends RuleTestCase<AuditLogMutationRule>
 */
final class AuditLogMutationRuleTest extends RuleTestCase
{
    private const MESSAGE = 'audit_log is append-only: only App\Shared\Audit\AuditRecorder inserts into it, and nothing updates, deletes, truncates, upserts or replaces its rows (dependency-rules.md L10).';

    protected function getRule(): Rule
    {
        return new AuditLogMutationRule;
    }

    public static function getAdditionalConfigFiles(): array
    {
        // Larastan types the Eloquent builders and relations the rule inspects.
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // As in VendorStatusWriteRuleTest: boot Larastan's application outside
        // any test, so its global handlers do not make a test risky.
        self::getContainer();
    }

    public function test_it_reports_every_audit_log_mutation(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/L10/Mutations.php';
        $expected = [];

        foreach (file($fixture) ?: [] as $index => $line) {
            if (str_contains($line, '// violation')) {
                $expected[] = [self::MESSAGE, $index + 1];
            }
        }

        self::assertCount(43, $expected, 'The fixture marks 43 violations.');

        $this->analyse([$fixture], $expected);
    }

    public function test_it_ignores_inserts_reads_other_tables_and_its_documented_blind_spots(): void
    {
        $this->analyse([dirname(__DIR__).'/Fixtures/L10Allowed/OtherOperations.php'], []);
    }

    public function test_it_lets_the_recorder_insert(): void
    {
        $files = glob(dirname(__DIR__, 3).'/app/Shared/Audit/*.php') ?: [];

        self::assertContains((new \ReflectionClass(AuditRecorder::class))->getFileName(), array_map('realpath', $files));

        $this->analyse($files, []);
    }

    public function test_it_reports_a_mutation_inside_the_recorder_itself(): void
    {
        $source = file_get_contents((new \ReflectionClass(AuditRecorder::class))->getFileName() ?: '') ?: '';
        $insert = "        \$connection->table('audit_log')->insert([";

        self::assertSame(1, substr_count($source, $insert), 'The recorder inserts once, where the mutation is added.');

        $mutated = str_replace($insert, "        \$connection->table('audit_log')->where('subject_id', \$subjectId)->delete();\n".$insert, $source);
        $line = substr_count(strstr($mutated, '->delete();', true) ?: '', "\n") + 1;
        $copy = sys_get_temp_dir().'/nexo-l10-'.bin2hex(random_bytes(6)).'-AuditRecorder.php';

        file_put_contents($copy, $mutated);

        try {
            $this->analyse([$copy], [[self::MESSAGE, $line]]);
        } finally {
            unlink($copy);
        }
    }
}
