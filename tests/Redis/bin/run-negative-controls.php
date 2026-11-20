<?php

declare(strict_types=1);

/*
| ADR-0022 §7: run the unsafe-environment children on their own, so a CI step
| can compare INFO commandstats before and after them.
|
| Usage: php tests/Redis/bin/run-negative-controls.php
|
| Each child must fail with its guard rule. Then
| tests/Redis/bin/verify-negative-controls.php checks, from the two admin
| snapshots, that none of them sent the server a command (beyond the one
| failed AUTH a wrong password needs). Exit 0 when every child failed as
| expected, 1 otherwise.
*/

use Tests\Support\Redis\NegativeControls;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$token = bin2hex(random_bytes(8));
$failures = [];

foreach (NegativeControls::CASES as $name => [$rule]) {
    $result = NegativeControls::run($token, NegativeControls::CASES[$name][1]);

    if ($result['exit'] === 0 || ! str_contains($result['output'], 'TEST-DB-GUARD '.$rule)) {
        $failures[] = "{$name}: expected a failure naming {$rule}, got exit ".var_export($result['exit'], true);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "NEGATIVE-CONTROLS: an unsafe environment was not refused.\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, 'NEGATIVE-CONTROLS '.$failure."\n");
    }

    exit(1);
}

fwrite(STDOUT, sprintf("%d unsafe environments refused, each by its rule.\n", count(NegativeControls::CASES)));
