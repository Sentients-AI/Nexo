<?php

declare(strict_types=1);

/*
| ADR-0022 §10: fail if composer.lock installs a prohibited package.
|
| Usage: php tests/bin/check-package-policy.php [path/to/composer.lock]
|
| Needs no vendor directory, so CI runs it before composer install. Prints
| only the violations, never lockfile contents. Exit 0 when the lockfile
| complies, 1 otherwise.
*/

use Tests\Support\Composer\PackagePolicy;

require dirname(__DIR__).'/Support/Composer/PackagePolicy.php';

$path = (string) ($_SERVER['argv'][1] ?? dirname(__DIR__, 2).'/composer.lock');
$violations = PackagePolicy::violationsInFile($path);

if ($violations !== []) {
    fwrite(STDERR, "PACKAGE-POLICY: composer.lock breaks the package policy (ADR-0022 §10).\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, 'PACKAGE-POLICY '.$violation."\n");
    }

    fwrite(STDERR, "Changing the prohibited list needs an owner decision; see docs/engineering/testing.md (package policy).\n");

    exit(1);
}

fwrite(STDOUT, 'composer.lock complies with the package policy: none of '.implode(', ', array_keys(PackagePolicy::PROHIBITED))." is installed.\n");
