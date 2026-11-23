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

it('asks before creating in production, and creates nothing when refused', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies'])
        // The production alert prints its warning in capitals.
        ->expectsOutputToContain('THIS CREATES A VENDOR WITHOUT AUTHENTICATION OR AN AUDIT RECORD.')
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(Command::FAILURE);

    expect(DB::table('vendors')->count())->toBe(0);
});

it('creates in production when confirmed, or with --force', function (array $options, bool $asks): void {
    app()->detectEnvironment(fn (): string => 'production');

    $command = $this->artisan('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', ...$options]);

    if ($asks) {
        $command->expectsConfirmation('Are you sure you want to run this command?', 'yes');
    }

    // Run it now: a pending command otherwise runs only when it is destroyed.
    $command->assertExitCode(Command::SUCCESS)->run();

    expect(DB::table('vendors')->count())->toBe(1);
})->with([
    'confirmed' => [[], true],
    '--force' => [['--force' => true], false],
]);

it('refuses in production without --force when it cannot ask', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $exitCode = Artisan::call('vendors:create', ['slug' => 'acme-supplies', 'name' => 'Acme Supplies', '--no-interaction' => true]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and(DB::table('vendors')->count())->toBe(0);
});

it('does not present itself as an authenticated or audited interface', function (): void {
    $command = Artisan::all()['vendors:create'] ?? null;

    expect($command)->toBeInstanceOf(CreateVendorCommand::class)
        ->and($command?->getDescription())->toContain('not authenticated or audited');
});
