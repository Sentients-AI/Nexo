<?php

declare(strict_types=1);

/*
| ADR-0022 §8: compare the live Valkey with the approved policy.
|
| Usage: php tests/Redis/bin/verify-valkey-policy.php <acl-list-file> <commandstats-file>
|
| The two files are the output of `ACL LIST` and `INFO commandstats`, taken
| by a CI step as nexo_ci_admin (.github/valkey/admin.sh) after every test
| stage. This script never connects to Valkey and never sees a credential.
| It prints only violations and counts, never the ACL lines (they contain
| password hashes). Exit 0 when the server matches the policy, 1 otherwise.
*/

use Tests\Support\Redis\UsersAcl;
use Tests\Support\Redis\ValkeyPolicy;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[, $aclFile, $statsFile] = array_pad(array_map('strval', (array) ($_SERVER['argv'] ?? [])), 3, '');

$acl = is_file($aclFile) ? (string) file_get_contents($aclFile) : '';
$stats = is_file($statsFile) ? (string) file_get_contents($statsFile) : '';
$inventory = UsersAcl::inventory();

$violations = [
    ...($acl === '' ? ['no ACL LIST output in '.var_export($aclFile, true)] : ValkeyPolicy::aclViolations($acl, $inventory)),
    ...($stats === '' ? ['no INFO commandstats output in '.var_export($statsFile, true)] : ValkeyPolicy::commandStatsViolations($stats, $inventory)),
];

if ($violations !== []) {
    fwrite(STDERR, "VALKEY-POLICY: the live server does not match ADR-0022's approved policy.\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, 'VALKEY-POLICY '.$violation."\n");
    }

    exit(1);
}

$calls = ValkeyPolicy::parseCommandStats($stats);

fwrite(STDOUT, sprintf(
    "Valkey matches the policy: %d users, %d inventory commands all executed, %d commands executed in total, 0 prohibited executions.\n",
    count(ValkeyPolicy::parseAcl($acl)),
    count($inventory),
    count(array_filter($calls)),
));
