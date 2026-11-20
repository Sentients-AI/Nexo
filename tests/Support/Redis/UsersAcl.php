<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use InvalidArgumentException;

/**
 * Renders .github/valkey/users.acl from the command inventory
 * (ADR-0022 §4, §8). Valkey ACL files take no comments, so the rendered
 * file has no "generated" header; never edit it by hand.
 *
 * The CI-step-only admin user is not part of it:
 * .github/valkey/start.sh appends that user at run time, with a password
 * the PHP tests never receive.
 */
final class UsersAcl
{
    public const string USERNAME = 'nexo_test';

    public const string KEY_PATTERN = 'nexo_testing:*';

    /** sha256 of the CI-only password `nexo_test` (ci.yml REDIS_PASSWORD). */
    public const string PASSWORD_SHA256 = '698c20b9581b06deaaba55b1f6e9bcfe4a9b2abd798bcc70678d298e03dc4d13';

    /**
     * ADR-0022 §6, plus `copy`: its DB option writes into another database,
     * which the ACL's key patterns do not restrict (database switching stays
     * impossible).
     *
     * EXEC is not here: Laravel's RedisStore::putMany() needs MULTI/EXEC, and
     * denying EXEC made it report success while storing nothing. It is safe
     * to allow because the ACL checks every command when it is queued, so a
     * transaction holding a prohibited command aborts as a whole
     * (tests/Redis/TransactionContractTest.php), and the R6 probe never sends
     * EXEC (tests/Unit/NonExecutingProbeTest.php).
     *
     * ACL WHOAMI is the one allowed exception to `acl`.
     *
     * @var list<string>
     */
    public const array PROHIBITED = [
        'flushall', 'flushdb', 'swapdb', 'select', 'move', 'migrate', 'rename', 'renamenx', 'copy',
        'keys', 'scan', 'randomkey',
        'config', 'debug', 'shutdown', 'save', 'bgsave', 'bgrewriteaof', 'replicaof', 'slaveof',
        'failover', 'cluster', 'module', 'acl', 'monitor', 'client|kill', 'client|pause',
        'script|flush', 'function|flush', 'function|delete', 'function|load',
    ];

    public static function path(): string
    {
        return dirname(__DIR__, 3).'/.github/valkey/users.acl';
    }

    /**
     * @return array<string, string> Command => reason.
     */
    public static function inventory(): array
    {
        /** @var array<string, string> $inventory */
        $inventory = require dirname(__DIR__, 2).'/Redis/command-inventory.php';

        foreach (array_keys($inventory) as $command) {
            if ($command !== 'acl|whoami' && self::prohibits($command)) {
                throw new InvalidArgumentException("The command inventory lists [{$command}], which ADR-0022 §6 prohibits.");
            }
        }

        return $inventory;
    }

    public static function render(): string
    {
        $commands = array_keys(self::inventory());
        sort($commands);

        return implode("\n", [
            'user default off resetpass resetkeys resetchannels -@all',
            'user '.self::USERNAME.' on #'.self::PASSWORD_SHA256
                .' resetkeys ~'.self::KEY_PATTERN.' resetchannels &'.self::KEY_PATTERN
                .' -@all '.implode(' ', array_map(static fn (string $c): string => '+'.$c, $commands)),
        ])."\n";
    }

    private static function prohibits(string $command): bool
    {
        $base = explode('|', $command)[0];

        return in_array($command, self::PROHIBITED, true) || in_array($base, self::PROHIBITED, true);
    }
}
