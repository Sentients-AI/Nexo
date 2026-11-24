<?php

declare(strict_types=1);

use App\Shared\Audit\Actor;
use App\Shared\Audit\ActorType;

/*
 * #34, ADR-0023 Decisions 1 and 2: the audit actor. Each named constructor
 * builds only a shape audit_log accepts. The constraints themselves are
 * proven on MySQL in tests/Feature/Audit/AuditLogSchemaTest.php.
 */

it('has exactly the actor types audit_log accepts', function (): void {
    expect(array_map(fn (ActorType $type): string => $type->value, ActorType::cases()))
        ->toBe(['user', 'system', 'console']);
});

it('builds a user actor with only a user id', function (): void {
    $actor = Actor::user(7);

    expect($actor->type)->toBe(ActorType::User)
        ->and($actor->userId)->toBe(7)
        ->and($actor->impersonatorUserId)->toBeNull()
        ->and($actor->label)->toBeNull()
        ->and($actor->ip)->toBeNull();
});

it('builds a user actor with an impersonator and an IP', function (string $ip): void {
    $actor = Actor::user(7, impersonatorUserId: 3, ip: $ip);

    expect($actor->type)->toBe(ActorType::User)
        ->and($actor->userId)->toBe(7)
        ->and($actor->impersonatorUserId)->toBe(3)
        ->and($actor->label)->toBeNull()
        ->and($actor->ip)->toBe($ip);
})->with([
    'IPv4' => '203.0.113.9',
    'IPv6' => '2001:db8::1',
    'longest IPv6' => 'ffff:ffff:ffff:ffff:ffff:ffff:255.255.255.255',
]);

it('builds a console actor that names the command and nobody', function (): void {
    $actor = Actor::console('vendors:create');

    expect($actor->type)->toBe(ActorType::Console)
        ->and($actor->label)->toBe('vendors:create')
        ->and($actor->userId)->toBeNull()
        ->and($actor->impersonatorUserId)->toBeNull()
        ->and($actor->ip)->toBeNull();
});

it('builds a system actor that names the job', function (): void {
    $actor = Actor::system('App\Jobs\ExpireCarts');

    expect($actor->type)->toBe(ActorType::System)
        ->and($actor->label)->toBe('App\Jobs\ExpireCarts')
        ->and($actor->userId)->toBeNull()
        ->and($actor->impersonatorUserId)->toBeNull()
        ->and($actor->ip)->toBeNull();
});

it('accepts a label of exactly 191 characters', function (): void {
    expect(Actor::console(str_repeat('é', 191))->label)->toBe(str_repeat('é', 191));
});

it('rejects a user or impersonator id that is not positive', function (int $userId, ?int $impersonator, string $message): void {
    expect(fn () => Actor::user($userId, $impersonator))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero user id' => [0, null, "An actor's user id must be a positive id, got 0."],
    'negative user id' => [-1, null, "An actor's user id must be a positive id, got -1."],
    'zero impersonator' => [7, 0, "An actor's impersonator user id must be a positive id, got 0."],
    'negative impersonator' => [7, -4, "An actor's impersonator user id must be a positive id, got -4."],
]);

it('rejects an IP that is not an address', function (string $ip): void {
    expect(fn () => Actor::user(7, ip: $ip))->toThrow(InvalidArgumentException::class, "An actor's IP must be an IPv4 or IPv6 address");
})->with([
    'empty' => '',
    'host name' => 'localhost',
    'padded' => ' 203.0.113.9',
    'out of range' => '256.1.1.1',
]);

it('rejects a console or system label that is empty, padded or too long', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty console label' => [fn () => Actor::console(''), 'A console actor needs a label'],
    'blank console label' => [fn () => Actor::console('   '), 'A console actor needs a label'],
    'padded console label' => [fn () => Actor::console('vendors:create '), 'A console actor needs a label'],
    'empty system label' => [fn () => Actor::system(''), 'A system actor needs a label'],
    'padded system label' => [fn () => Actor::system(' App\Jobs\ExpireCarts'), 'A system actor needs a label'],
    'long console label' => [fn () => Actor::console(str_repeat('a', 192)), "A console actor's label is longer than 191 characters."],
    'long system label' => [fn () => Actor::system(str_repeat('a', 192)), "A system actor's label is longer than 191 characters."],
]);

it('can be built only through its named constructors', function (): void {
    $class = new ReflectionClass(Actor::class);

    expect($class->getConstructor()?->isPublic())->toBeFalse()
        ->and($class->isReadOnly())->toBeTrue()
        ->and($class->isFinal())->toBeTrue();
});

it('is immutable', function (): void {
    $actor = Actor::user(7);

    expect(fn () => $actor->userId = 8)->toThrow(Error::class, 'Cannot modify readonly property');
});
