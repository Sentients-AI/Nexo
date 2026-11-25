<?php

declare(strict_types=1);

namespace Tests\Arch\PHPStan;

use App\Shared\Audit\AuditRecorder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * L10: the rule reports every marked line of its violation fixture and
 * nothing in its allowed fixture (including the documented blind spots).
 * App\Shared\Audit as it is reports nothing: the recorder's insert in
 * record() is the negative control. Temporary copies prove the exemption's
 * edges: inside App\Shared\Audit, any other write is reported, whether in
 * record(), in another AuditRecorder method, in a closure, or in another
 * class.
 *
 * @extends RuleTestCase<AuditLogMutationRule>
 */
final class AuditLogMutationRuleTest extends RuleTestCase
{
    private const MESSAGE = 'audit_log is append-only: only App\Shared\Audit\AuditRecorder::record() inserts into it, and nothing updates, deletes, truncates, upserts or replaces its rows (dependency-rules.md L10).';

    /** The recorder's one insert, as written in record(). */
    private const RECORDER_INSERT = "        \$connection->table('audit_log')->insert([";

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

    public function test_it_reports_every_audit_log_write_outside_the_recorder(): void
    {
        $fixture = dirname(__DIR__).'/Fixtures/L10/Mutations.php';
        $expected = [];

        foreach (file($fixture) ?: [] as $index => $line) {
            if (str_contains($line, '// violation')) {
                $expected[] = [self::MESSAGE, $index + 1];
            }
        }

        self::assertCount(62, $expected, 'The fixture marks 62 violations.');

        $this->analyse([$fixture], $expected);
    }

    public function test_it_ignores_reads_other_tables_and_its_documented_blind_spots(): void
    {
        $this->analyse([dirname(__DIR__).'/Fixtures/L10Allowed/OtherOperations.php'], []);
    }

    public function test_it_lets_the_recorder_insert_in_record(): void
    {
        $files = glob(dirname(__DIR__, 3).'/app/Shared/Audit/*.php') ?: [];

        self::assertContains((new \ReflectionClass(AuditRecorder::class))->getFileName(), array_map('realpath', $files));
        self::assertSame(1, substr_count($this->recorderSource(), self::RECORDER_INSERT), 'record() inserts once.');

        $this->analyse($files, []);
    }

    public function test_it_reports_a_mutation_inside_record(): void
    {
        $this->assertReportedInRecorderCopy(
            self::RECORDER_INSERT,
            "        \$connection->table('audit_log')->where('subject_id', \$subjectId)->delete(); // reported\n".self::RECORDER_INSERT,
        );
    }

    public function test_it_reports_another_kind_of_insert_inside_record(): void
    {
        $this->assertReportedInRecorderCopy(
            self::RECORDER_INSERT,
            "        \$connection->table('audit_log')->insertGetId([ // reported",
        );
    }

    public function test_it_reports_an_insert_in_a_closure_inside_record(): void
    {
        $this->assertReportedInRecorderCopy(
            self::RECORDER_INSERT,
            "        (fn () => \$connection->table('audit_log')->insert(['action' => \$action]))(); // reported\n".self::RECORDER_INSERT,
        );
    }

    public function test_it_reports_an_insert_in_another_recorder_method(): void
    {
        $method = '    private static function assertName(';

        $this->assertReportedInRecorderCopy(
            $method,
            "    public function forge(string \$action): void\n    {\n"
            ."        \$this->connections->connection()->table('audit_log')->insert(['action' => \$action]); // reported\n"
            ."    }\n\n".$method,
        );
    }

    public function test_it_reports_an_insert_in_another_shared_audit_class(): void
    {
        $this->assertReportedIn(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Shared\Audit;

            use Illuminate\Support\Facades\DB;

            final class AuditBackfill
            {
                public function run(): void
                {
                    DB::table('audit_log')->insert(['action' => 'vendor.created']); // reported
                }
            }
            PHP);
    }

    private function recorderSource(): string
    {
        return file_get_contents((new \ReflectionClass(AuditRecorder::class))->getFileName() ?: '') ?: '';
    }

    /** Analyse a copy of AuditRecorder with $search replaced once. */
    private function assertReportedInRecorderCopy(string $search, string $replace): void
    {
        $source = $this->recorderSource();

        self::assertSame(1, substr_count($source, $search), "The recorder contains \"{$search}\" once.");

        $this->assertReportedIn(str_replace($search, $replace, $source));
    }

    /** Analyse $source from a temporary file: exactly its `// reported` line is reported. */
    private function assertReportedIn(string $source): void
    {
        $lines = [];

        foreach (explode("\n", $source) as $index => $line) {
            if (str_contains($line, '// reported')) {
                $lines[] = [self::MESSAGE, $index + 1];
            }
        }

        self::assertCount(1, $lines, 'The copy marks one reported line.');

        $copy = sys_get_temp_dir().'/nexo-l10-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($copy, $source);

        try {
            $this->analyse([$copy], $lines);
        } finally {
            unlink($copy);
        }
    }
}
