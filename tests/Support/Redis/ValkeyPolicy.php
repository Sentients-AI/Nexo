<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

/**
 * Judges the live Valkey against the approved policy (ADR-0022 §8). Pure: it
 * reads the text of `ACL LIST` and `INFO commandstats`, which a CI step
 * collects as nexo_ci_admin (.github/valkey/admin.sh), so the admin
 * credential never reaches PHP.
 *
 * - ACL: exactly the users default, nexo_test and nexo_ci_admin; default
 *   off, with no password and no access; nexo_test and the admin exactly as
 *   rendered, with no extra key, channel, command rule or selector.
 * - Command statistics (server-wide, so they include the admin's own
 *   commands): no prohibited command ever executed, no command executed
 *   outside the inventory and the admin's set, and no inventory entry left
 *   unused. Rejected attempts are fine; they are refusals.
 *
 * Statistics show what ran, not on which keys. They do not prove namespace
 * isolation; the ACL key patterns and the isolation tests do.
 */
final class ValkeyPolicy
{
    /**
     * The admin's commands. .github/valkey/start.sh grants exactly these
     * (tests/Unit/ValkeyPolicyTest.php keeps the two in step).
     *
     * @var list<string>
     */
    public const array ADMIN_COMMANDS = ['acl|list', 'acl|whoami', 'config|resetstat', 'get', 'info', 'ping', 'set'];

    /** Always permitted to every client; never in an ACL. */
    private const array CONNECTION_COMMANDS = ['auth', 'hello'];

    /**
     * @param  array<string, string>  $inventory  Command => reason.
     * @return list<string>
     */
    public static function aclViolations(string $aclList, array $inventory): array
    {
        $users = self::parseAcl($aclList);
        $violations = [];

        $unexpected = array_diff(array_keys($users), ['default', UsersAcl::USERNAME, 'nexo_ci_admin']);

        foreach ($unexpected as $name) {
            $violations[] = "ACL has an unexpected user [{$name}]";
        }

        $default = $users['default'] ?? null;

        if ($default === null) {
            $violations[] = 'ACL has no default user';
        } else {
            array_push($violations, ...self::userViolations('default', $default, false, [], [], []));
        }

        $test = $users[UsersAcl::USERNAME] ?? null;

        if ($test === null) {
            $violations[] = 'ACL has no '.UsersAcl::USERNAME.' user';
        } else {
            array_push($violations, ...self::userViolations(
                UsersAcl::USERNAME,
                $test,
                true,
                ['~'.UsersAcl::KEY_PATTERN],
                ['&'.UsersAcl::KEY_PATTERN],
                array_keys($inventory),
            ));

            if ($test['passwords'] !== ['#'.UsersAcl::PASSWORD_SHA256]) {
                $violations[] = 'ACL user ['.UsersAcl::USERNAME.'] does not have exactly the rendered password';
            }
        }

        $admin = $users['nexo_ci_admin'] ?? null;

        if ($admin === null) {
            $violations[] = 'ACL has no nexo_ci_admin user';
        } else {
            array_push($violations, ...self::userViolations('nexo_ci_admin', $admin, true, ['~*'], [], self::ADMIN_COMMANDS));

            if (count($admin['passwords']) !== 1 || ! str_starts_with($admin['passwords'][0], '#')) {
                $violations[] = 'ACL user [nexo_ci_admin] must have exactly one hashed password';
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, string>  $inventory
     * @return list<string>
     */
    public static function commandStatsViolations(string $commandStats, array $inventory): array
    {
        $calls = self::parseCommandStats($commandStats);
        $allowed = array_merge(array_keys($inventory), self::ADMIN_COMMANDS, self::CONNECTION_COMMANDS);
        $violations = [];

        if ($calls === []) {
            return ['INFO commandstats reported no commands; nothing was checked'];
        }

        foreach ($calls as $command => $count) {
            if ($count === 0) {
                continue;
            }

            if (self::prohibited($command) && ! in_array($command, self::ADMIN_COMMANDS, true) && $command !== 'acl|whoami') {
                $violations[] = "the prohibited command [{$command}] executed {$count} time(s)";
            } elseif (! in_array($command, $allowed, true)) {
                $violations[] = "[{$command}] executed {$count} time(s) but is not in the command inventory";
            }
        }

        foreach (array_keys($inventory) as $command) {
            if (($calls[$command] ?? 0) === 0) {
                $violations[] = "the inventory entry [{$command}] never executed; remove it or the test that needs it is missing";
            }
        }

        return $violations;
    }

    /**
     * @return array<string, int> Command => executed calls (not rejected).
     */
    public static function parseCommandStats(string $commandStats): array
    {
        preg_match_all('/^cmdstat_([a-z0-9_|-]+):calls=(\d+),/m', $commandStats, $matches, PREG_SET_ORDER);

        $calls = [];

        foreach ($matches as [, $command, $count]) {
            $calls[$command] = (int) $count;
        }

        return $calls;
    }

    /**
     * @return array<string, array{flags: list<string>, passwords: list<string>, keys: list<string>, channels: list<string>, commands: list<string>, other: list<string>}>
     */
    public static function parseAcl(string $aclList): array
    {
        $users = [];

        foreach (preg_split('/\R/', trim($aclList)) ?: [] as $line) {
            $tokens = preg_split('/\s+/', trim($line)) ?: [];

            if (count($tokens) < 2 || $tokens[0] !== 'user') {
                continue;
            }

            $user = ['flags' => [], 'passwords' => [], 'keys' => [], 'channels' => [], 'commands' => [], 'other' => []];

            foreach (array_slice($tokens, 2) as $token) {
                $slot = match (true) {
                    in_array($token, ['on', 'off', 'nopass', 'sanitize-payload', 'skip-sanitize-payload', 'resetchannels', 'resetkeys'], true) => 'flags',
                    str_starts_with($token, '#') || str_starts_with($token, '>') => 'passwords',
                    str_starts_with($token, '~') || str_starts_with($token, '%') || $token === 'allkeys' => 'keys',
                    str_starts_with($token, '&') || $token === 'allchannels' => 'channels',
                    str_starts_with($token, '+') || str_starts_with($token, '-') => 'commands',
                    default => 'other',
                };
                $user[$slot][] = $token;
            }

            $users[$tokens[1]] = $user;
        }

        return $users;
    }

    /**
     * @param  array{flags: list<string>, passwords: list<string>, keys: list<string>, channels: list<string>, commands: list<string>, other: list<string>}  $user
     * @param  list<string>  $keys
     * @param  list<string>  $channels
     * @param  list<string>  $commands  Allowed commands, without the leading +.
     * @return list<string>
     */
    private static function userViolations(string $name, array $user, bool $on, array $keys, array $channels, array $commands): array
    {
        $violations = [];
        $label = "ACL user [{$name}]";

        if (in_array('on', $user['flags'], true) !== $on || in_array('off', $user['flags'], true) === $on) {
            $violations[] = $label.' must be '.($on ? 'on' : 'off');
        }

        if (in_array('nopass', $user['flags'], true)) {
            $violations[] = $label.' accepts any password (nopass)';
        }

        if (! $on && $user['passwords'] !== []) {
            $violations[] = $label.' must have no password';
        }

        if ($user['other'] !== []) {
            $violations[] = $label.' has rules this policy does not know (selectors?): '.implode(' ', $user['other']);
        }

        if (self::sorted($user['keys']) !== self::sorted($keys)) {
            $violations[] = $label.' key patterns are ['.implode(' ', $user['keys']).'], expected ['.implode(' ', $keys).']';
        }

        if (self::sorted($user['channels']) !== self::sorted($channels)) {
            $violations[] = $label.' channel patterns are ['.implode(' ', $user['channels']).'], expected ['.implode(' ', $channels).']';
        }

        $expected = array_merge(['-@all'], array_map(static fn (string $c): string => '+'.$c, $commands));

        if (self::sorted($user['commands']) !== self::sorted($expected)) {
            $extra = array_diff($user['commands'], $expected);
            $missing = array_diff($expected, $user['commands']);
            $violations[] = $label.' command rules differ from the policy: extra ['.implode(' ', $extra).'], missing ['.implode(' ', $missing).']';
        }

        return $violations;
    }

    private static function prohibited(string $command): bool
    {
        return in_array($command, UsersAcl::PROHIBITED, true)
            || in_array(explode('|', $command)[0], UsersAcl::PROHIBITED, true);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
