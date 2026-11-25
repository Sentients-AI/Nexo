<?php

declare(strict_types=1);

use App\Modules\Vendors\Infrastructure\Console\CreateVendorCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

/*
 * #29, D6; #35: vendors:create is the operator's console entry to
 * RegisterVendor. It reports the use case's rules and refusals with distinct
 * exit codes, records each creation with a console actor named after the
 * command (provenance, never a person), says plainly that it is not
 * authenticated, and asks before creating in production.
 */

/** @return list<array<string, mixed>> */
function commandAuditRows(): array
{
    return DB::table('audit_log')->get()->map(fn (object $row): array => (array) $row)->all();
}

it('creates a pending vendor and says it cannot operate yet', function (): void {
    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies'])
        ->expectsOutputToContain('as pending. It cannot operate until it is approved.')
        ->assertExitCode(Command::SUCCESS);

    $row = DB::table('vendors')->first();

    expect(DB::table('vendors')->count())->toBe(1)
        ->and($row?->slug)->toBe('acme-supplies')
        ->and($row?->name)->toBe('Acme Supplies')
        ->and($row?->status)->toBe('pending');

    $audit = commandAuditRows();

    expect($audit)->toHaveCount(1)
        ->and([$audit[0]['actor_type'], $audit[0]['actor_user_id'], $audit[0]['impersonator_user_id'], $audit[0]['actor_label'], $audit[0]['ip']])
        ->toBe(['console', null, null, 'vendors:create', null])
        ->and([$audit[0]['action'], $audit[0]['subject_type'], $audit[0]['subject_id']])->toBe(['vendor.created', 'vendor', $row?->id])
        ->and($audit[0]['before'])->toBeNull();
});

it('refuses an invalid slug or name with the rule, and creates nothing', function (string $slug, string $name, string $rule): void {
    $this->artisan('vendors:create', ['slug' => $slug, 'name' => $name])
        ->expectsOutputToContain($rule)
        ->assertExitCode(Command::INVALID);

    expect(DB::table('vendors')->count())->toBe(0)
        ->and(commandAuditRows())->toBe([]);
})->with([
    'uppercase slug' => ['Acme', 'Acme Supplies', 'The vendor slug must be 3 to 80 characters'],
    'consecutive hyphens' => ['acme--supplies', 'Acme Supplies', 'The vendor slug must be 3 to 80 characters'],
    'blank name' => ['acme-supplies', '   ', 'The vendor name must be valid UTF-8 text of 1 to 255 characters'],
]);

it('refuses a duplicate slug and leaves the existing vendor alone', function (): void {
    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies'])->assertExitCode(Command::SUCCESS);

    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Another Acme'])
        ->expectsOutputToContain('A vendor with this slug already exists.')
        ->assertExitCode(Command::FAILURE);

    expect(DB::table('vendors')->pluck('name')->all())->toBe(['Acme Supplies'])
        ->and(commandAuditRows())->toHaveCount(1);
});

/*
 * Production. Outside the `testing` environment the confirmation is real: on
 * Linux a Laravel prompt, which needs a TTY, and on Windows Symfony's question
 * helper. So these tests run without interaction, through Artisan::call and its
 * real output rather than a mocked one, and the confirmation takes its default,
 * no, on both. Answering it interactively is ConfirmableTrait's own behaviour.
 */

it('warns in production and creates nothing when it cannot ask', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // The alert box wraps its text to the terminal's width, which Symfony reads
    // from COLUMNS. Fix it wide enough for one line, then restore it exactly:
    // unset stays unset, and a set value comes back.
    $columns = getenv('COLUMNS');
    putenv('COLUMNS=200');

    try {
        $exitCode = Artisan::call('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', '--no-interaction' => true]);
    } finally {
        putenv($columns === false ? 'COLUMNS' : 'COLUMNS='.$columns);
    }

    expect($exitCode)->toBe(Command::FAILURE)
        // The production alert prints its warning in capitals.
        ->and(Artisan::output())->toContain('THIS CREATES A VENDOR WITHOUT AUTHENTICATION; THE AUDIT LOG RECORDS THE COMMAND, NOT WHO RAN IT.')
        ->toContain('Command cancelled.')
        ->and(DB::table('vendors')->count())->toBe(0)
        ->and(commandAuditRows())->toBe([])
        ->and(getenv('COLUMNS'))->toBe($columns);
});

it('creates in production with --force, without asking', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', '--force' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('Command cancelled.')
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table('vendors')->count())->toBe(1)
        ->and(commandAuditRows())->toHaveCount(1);
});

it('presents itself as audited console provenance, not an authenticated interface', function (): void {
    $command = Artisan::all()['vendors:create'] ?? null;

    expect($command)->toBeInstanceOf(CreateVendorCommand::class)
        ->and($command?->getDescription())->toContain('audited as console provenance, not authenticated');
});

it('keeps its arguments and offers no way to claim an identity', function (): void {
    $definition = (Artisan::all()['vendors:create'] ?? null)?->getDefinition();

    expect(array_keys($definition?->getArguments() ?? []))->toBe(['slug', 'name'])
        ->and(array_keys($definition?->getOptions() ?? []))->toBe(['force']);
});
