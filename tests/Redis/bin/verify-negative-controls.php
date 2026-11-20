<?php

declare(strict_types=1);

/*
| ADR-0022 §7: prove from the server that the negative-control children sent
| no command.
|
| Usage: php tests/Redis/bin/verify-negative-controls.php <before-file> <after-file>
|
| The files are INFO commandstats taken as nexo_ci_admin immediately before
| and after tests/Redis/bin/run-negative-controls.php, with nothing else
| running. Exit 0 when the only changes are the admin's own snapshot and one
| failed AUTH per wrong-password child, 1 otherwise.
*/

use Tests\Support\Redis\NegativeControls;
use Tests\Support\Redis\ValkeyPolicy;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[, $beforeFile, $afterFile] = array_pad(array_map('strval', (array) ($_SERVER['argv'] ?? [])), 3, '');

$before = is_file($beforeFile) ? (string) file_get_contents($beforeFile) : '';
$after = is_file($afterFile) ? (string) file_get_contents($afterFile) : '';

$violations = ValkeyPolicy::negativeControlViolations($before, $after, NegativeControls::authAttempts());

if ($violations !== []) {
    fwrite(STDERR, "NEGATIVE-CONTROLS: the unsafe children reached the server.\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, 'NEGATIVE-CONTROLS '.$violation."\n");
    }

    exit(1);
}

fwrite(STDOUT, sprintf(
    "The %d negative-control children sent no command; the server saw only %d refused AUTH.\n",
    count(NegativeControls::CASES),
    NegativeControls::authAttempts(),
));
