<?php

declare(strict_types=1);

use Tests\Support\Redis\UsersAcl;
use Tests\Support\Redis\ValkeyPolicy;

/*
 * ADR-0022 §8: the live-policy check that CI runs after every test stage
 * (tests/Redis/bin/verify-valkey-policy.php). The positive cases use the
 * shape Valkey 8.1.11 prints for the rendered ACL; each negative case is one
 * kind of drift the check must catch.
 */

function liveAcl(?string $test = null, ?string $default = null, ?string $admin = null, string $extra = ''): string
{
    $commands = array_keys(UsersAcl::inventory());
    sort($commands);

    $test ??= 'user nexo_test on #'.UsersAcl::PASSWORD_SHA256.' ~nexo_testing:* resetchannels &nexo_testing:* -@all '
        .implode(' ', array_map(static fn (string $c): string => '+'.$c, $commands));
    $default ??= 'user default off resetchannels -@all';
    $admin ??= 'user nexo_ci_admin on #'.str_repeat('a', 64).' ~* resetchannels -@all '
        .implode(' ', array_map(static fn (string $c): string => '+'.$c, ValkeyPolicy::ADMIN_COMMANDS));

    return implode("\n", array_filter([$default, $admin, $test, $extra]))."\n";
}

/**
 * @param  array<string, array{0: int, 1?: int}>  $overrides  Command => [calls, rejected].
 */
function liveStats(array $overrides = []): string
{
    $lines = ['# Commandstats'];
    $stats = array_fill_keys([...array_keys(UsersAcl::inventory()), ...ValkeyPolicy::ADMIN_COMMANDS, 'auth'], [5, 0]);
    $stats['flushdb'] = [0, 12];

    foreach (array_merge($stats, $overrides) as $command => $counts) {
        $lines[] = sprintf('cmdstat_%s:calls=%d,usec=10,usec_per_call=2.00,rejected_calls=%d,failed_calls=0', $command, $counts[0], $counts[1] ?? 0);
    }

    return implode("\r\n", $lines)."\r\n";
}

it('accepts the live ACL as Valkey prints the rendered policy', function (): void {
    expect(ValkeyPolicy::aclViolations(liveAcl(), UsersAcl::inventory()))->toBe([]);
});

it('detects ACL drift', function (Closure $acl, string $violation): void {
    expect(implode("\n", ValkeyPolicy::aclViolations($acl(), UsersAcl::inventory())))->toContain($violation);
})->with([
    'an extra command' => [fn (): string => str_replace('-@all +acl|whoami', '-@all +flushdb +acl|whoami', liveAcl()), 'extra [+flushdb]'],
    'a category' => [fn (): string => str_replace('-@all +acl|whoami', '-@all +@scripting +acl|whoami', liveAcl()), 'extra [+@scripting]'],
    'a missing command' => [fn (): string => str_replace(' +zrem ', ' ', liveAcl()), 'missing [+zrem]'],
    'all keys' => [fn (): string => str_replace(' ~nexo_testing:* ', ' ~nexo_testing:* ~* ', liveAcl()), 'key patterns are'],
    'a read-only key pattern' => [fn (): string => str_replace(' ~nexo_testing:* ', ' ~nexo_testing:* %R~nexo_other:* ', liveAcl()), 'key patterns are'],
    'all channels' => [fn (): string => str_replace('&nexo_testing:*', '&*', liveAcl()), 'channel patterns are'],
    'a selector' => [fn (): string => str_replace(' -@all +acl|whoami', ' (~* +@all) -@all +acl|whoami', liveAcl()), 'selectors'],
    'another password for nexo_test' => [fn (): string => str_replace(' ~nexo_testing:* ', ' #'.str_repeat('b', 64).' ~nexo_testing:* ', liveAcl()), 'exactly the rendered password'],
    'nopass on nexo_test' => [fn (): string => str_replace('user nexo_test on ', 'user nexo_test on nopass ', liveAcl()), 'nopass'],
    'nexo_test switched off' => [fn (): string => str_replace('user nexo_test on ', 'user nexo_test off ', liveAcl()), 'must be on'],
    'the default user switched on' => [fn (): string => liveAcl(default: 'user default on nopass ~* &* +@all'), 'ACL user [default] must be off'],
    'a password on the default user' => [fn (): string => liveAcl(default: 'user default off #'.str_repeat('c', 64).' resetchannels -@all'), 'must have no password'],
    'a plaintext admin password' => [fn (): string => str_replace('#'.str_repeat('a', 64), '>secret', liveAcl()), 'exactly one hashed password'],
    'an extra admin command' => [fn (): string => str_replace('+set', '+set +flushall', liveAcl()), 'ACL user [nexo_ci_admin] command rules differ'],
    'an unexpected user' => [fn (): string => liveAcl(extra: 'user intruder on nopass ~* +@all'), 'unexpected user [intruder]'],
    'a missing test user' => [fn (): string => liveAcl(test: 'user other off -@all'), 'ACL has no nexo_test user'],
]);

it('accepts command statistics in which only refusals touched prohibited commands', function (): void {
    expect(ValkeyPolicy::commandStatsViolations(liveStats(), UsersAcl::inventory()))->toBe([]);
});

it('detects an executed prohibited command, an unlisted command and an unused entry', function (array $overrides, string $violation): void {
    expect(implode("\n", ValkeyPolicy::commandStatsViolations(liveStats($overrides), UsersAcl::inventory())))->toContain($violation);
})->with([
    'FLUSHDB executed' => [['flushdb' => [1, 12]], 'the prohibited command [flushdb] executed 1 time(s)'],
    'EXEC executed' => [['exec' => [1]], 'the prohibited command [exec] executed'],
    'COPY executed' => [['copy' => [2]], 'the prohibited command [copy] executed'],
    'a CONFIG subcommand executed' => [['config|get' => [1]], 'the prohibited command [config|get] executed'],
    'SELECT executed' => [['select' => [1]], 'the prohibited command [select] executed'],
    'a command outside the inventory' => [['hgetall' => [3]], '[hgetall] executed 3 time(s) but is not in the command inventory'],
    'EVALSHA executed' => [['evalsha' => [1]], '[evalsha] executed 1 time(s) but is not in the command inventory'],
    'an inventory entry never used' => [['zremrangebyrank' => [0]], 'the inventory entry [zremrangebyrank] never executed'],
]);

it('refuses to pass on empty statistics', function (): void {
    expect(ValkeyPolicy::commandStatsViolations('', UsersAcl::inventory()))->toBe(['INFO commandstats reported no commands; nothing was checked']);
});

it('keeps the admin rights in start.sh equal to the policy', function (): void {
    $start = (string) file_get_contents(dirname(__DIR__, 2).'/.github/valkey/start.sh');

    expect(preg_match("/user nexo_ci_admin on #%s resetkeys ~\\* resetchannels -@all ([^\\\\']+)\\\\n'/", $start, $match))->toBe(1);

    $granted = array_map(static fn (string $rule): string => ltrim($rule, '+'), explode(' ', trim($match[1])));

    expect($granted)->toBe(ValkeyPolicy::ADMIN_COMMANDS);
});
