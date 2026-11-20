<?php

declare(strict_types=1);

/*
 * ADR-0022 §8: every command the Redis test identity may run, and why.
 *
 * This is the single source for the nexo_test ACL. .github/valkey/users.acl
 * is rendered from it (Tests\Support\Redis\UsersAcl), and
 * tests/Unit/UsersAclTest.php fails when the two disagree. Lower case;
 * `command|subcommand` allows one subcommand only.
 *
 * Nothing in ADR-0022 §6 (prohibited commands) may ever be listed here,
 * except ACL WHOAMI, which the live guard (R6) needs.
 */

return [
    'acl|whoami' => 'R6: every connection confirms its authenticated identity when resolved',
    'discard' => 'R6: the non-executing probe always discards its transaction',
    'get' => 'namespace isolation: positive control and outside-key probe',
    'multi' => 'R6: the probe queues prohibited commands inside MULTI, so the ACL answers without running them (EXEC is never allowed)',
    'set' => 'namespace isolation: positive control and outside-key probe',
    'unlink' => 'clean-up deletes only the keys a test recorded (ADR-0022 §6)',
];
