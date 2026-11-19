<?php

declare(strict_types=1);

use Tests\Support\Redis\UsersAcl;

/*
 * ADR-0022 §8: .github/valkey/users.acl is rendered from the command
 * inventory, and the inventory never allows a prohibited command (§6).
 */

it('keeps users.acl in step with the command inventory', function (): void {
    $expected = UsersAcl::render();

    expect(file_get_contents(UsersAcl::path()))->toBe(
        $expected,
        "Re-render .github/valkey/users.acl from tests/Redis/command-inventory.php:\n".$expected,
    );
});

it('gives every inventory command a reason', function (): void {
    foreach (UsersAcl::inventory() as $command => $reason) {
        expect($command)->toMatch('/^[a-z]+(\|[a-z]+)?$/')
            ->and(trim($reason))->not->toBe('');
    }
});

it('denies the default user everything', function (): void {
    expect(UsersAcl::render())->toContain("user default off resetpass resetkeys resetchannels -@all\n");
});

it('limits nexo_test to its namespace and to the inventory', function (): void {
    $line = explode("\n", UsersAcl::render())[1];
    $rules = explode(' ', $line);

    $allowed = array_values(array_filter($rules, static fn (string $rule): bool => str_starts_with($rule, '+')));
    $inventory = array_keys(UsersAcl::inventory());
    sort($inventory);

    expect(array_slice($rules, 0, 3))->toBe(['user', 'nexo_test', 'on'])
        ->and($line)->toContain(' resetkeys ~nexo_testing:* resetchannels &nexo_testing:* -@all ')
        ->and($allowed)->toBe(array_map(static fn (string $command): string => '+'.$command, $inventory));
});

it('never allows a prohibited command except ACL WHOAMI', function (): void {
    foreach (array_keys(UsersAcl::inventory()) as $command) {
        $base = explode('|', $command)[0];

        expect($command === 'acl|whoami' || ! in_array($base, UsersAcl::PROHIBITED, true))->toBeTrue($command)
            ->and(in_array($command, UsersAcl::PROHIBITED, true))->toBeFalse($command);
    }
});
