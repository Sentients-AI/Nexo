<?php

declare(strict_types=1);

use App\Modules\Vendors\Infrastructure\Console\CreateVendorCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

/*
 * #29, D6: vendors:create is the operator's console entry to CreateVendor. It
 * reports the use case's rules and refusals with distinct exit codes, says
 * plainly that it is neither authenticated nor audited, and asks before
 * creating in production.
 */

it('creates a pending vendor and says it cannot operate yet', function (): void {
    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies'])
        ->expectsOutputToContain('as pending. It cannot operate until it is approved.')
        ->assertExitCode(Command::SUCCESS);

    $row = DB::table('vendors')->first();

    expect(DB::table('vendors')->count())->toBe(1)
        ->and($row?->slug)->toBe('acme-supplies')
        ->and($row?->name)->toBe('Acme Supplies')
        ->and($row?->status)->toBe('pending');
});

it('refuses an invalid slug or name with the rule, and creates nothing', function (string $slug, string $name, string $rule): void {
    $this->artisan('vendors:create', ['slug' => $slug, 'name' => $name])
        ->expectsOutputToContain($rule)
        ->assertExitCode(Command::INVALID);

    expect(DB::table('vendors')->count())->toBe(0);
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

    expect(DB::table('vendors')->pluck('name')->all())->toBe(['Acme Supplies']);
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

    $exitCode = Artisan::call('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', '--no-interaction' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        // The production alert prints its warning in capitals.
        ->and(Artisan::output())->toContain('THIS CREATES A VENDOR WITHOUT AUTHENTICATION OR AN AUDIT RECORD.')
        ->toContain('Command cancelled.')
        ->and(DB::table('vendors')->count())->toBe(0);
});

it('creates in production with --force, without asking', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', '--force' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('Command cancelled.')
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table('vendors')->count())->toBe(1);
});

it('does not present itself as an authenticated or audited interface', function (): void {
    $command = Artisan::all()['vendors:create'] ?? null;

    expect($command)->toBeInstanceOf(CreateVendorCommand::class)
        ->and($command?->getDescription())->toContain('not authenticated or audited');
});
