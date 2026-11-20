<?php

declare(strict_types=1);

/*
 * ADR-0022 §8: every command the Redis test identity may run, and why.
 *
 * This is the single source for the nexo_test ACL. .github/valkey/users.acl
 * is rendered from it (Tests\Support\Redis\UsersAcl), and
 * tests/Unit/UsersAclTest.php fails when the two disagree. CI also compares
 * the live ACL with it and fails on any command executed outside it or any
 * entry never executed (tests/Redis/bin/verify-valkey-policy.php). Lower
 * case; `command|subcommand` allows one subcommand only.
 *
 * Commands run inside Lua are checked by the ACL one by one, with their real
 * key arguments, so they are listed too. Nothing in ADR-0022 §6 (prohibited
 * commands) may ever be listed here, except ACL WHOAMI, which R6 needs.
 *
 * Deliberately absent: EXEC (nothing tested needs a transaction, and the R6
 * probe relies on EXEC being impossible), EVALSHA and SCRIPT (Laravel sends
 * EVAL), and every category such as @scripting.
 */

return [
    'acl|whoami' => 'R6: every connection confirms its authenticated identity when resolved',
    'decrby' => 'cache: decrement',
    'del' => 'cache: forget; locks: the release script',
    'discard' => 'R6: the non-executing probe always discards its transaction',
    'eval' => 'Laravel\'s Lua scripts: cache add, lock release, queue push/pop/migrate/release/size, Redis::throttle',
    'exists' => 'cache add script; Redis::throttle script; cache contract assertions',
    'expire' => 'Redis::throttle (DurationLimiter) script: the window TTL',
    'get' => 'cache and lock reads; lock release script; namespace isolation probes',
    'hget' => 'Redis::throttle (DurationLimiter) script',
    'hincrby' => 'Redis::throttle (DurationLimiter) script',
    'hmset' => 'Redis::throttle (DurationLimiter) script',
    'incrby' => 'cache: increment; rate limiter hits',
    'llen' => 'queue: size script; queue contract assertions',
    'lpop' => 'queue: pop script (job and notify lists)',
    'mget' => 'queue worker: pause and restart signals in the cache',
    'multi' => 'R6: the probe queues prohibited commands inside MULTI, so the ACL answers without running them (EXEC is never allowed)',
    'pttl' => 'contract assertions on server-side TTLs',
    'rpush' => 'queue: push and migrate scripts',
    'set' => 'cache put; lock acquire (SET NX EX); namespace isolation probes',
    'setex' => 'cache put with a TTL; cache add script',
    'unlink' => 'clean-up deletes only the keys a test recorded (ADR-0022 §6)',
    'zadd' => 'queue: delayed and reserved sets (later, pop, release)',
    'zcard' => 'queue: size script; queue contract assertions',
    'zrangebyscore' => 'queue: migrate script for due delayed and expired reserved jobs',
    'zrem' => 'queue: deleting a reserved job; release script',
    'zremrangebyrank' => 'queue: migrate script',
];
