<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Closure;
use RedisException;
use Throwable;

/**
 * Asks the server whether commands are permitted without ever running them
 * (ADR-0022 §5, R6).
 *
 * The commands are queued inside MULTI and the transaction is always
 * discarded: a queued command runs only on EXEC, and EXEC is never sent. The
 * ACL is checked when a command is queued, so a refused command answers
 * NOPERM at once, and a permitted one answers QUEUED and is thrown away.
 *
 * Fail-closed rules:
 * - Nothing is queued unless MULTI itself succeeded. If MULTI were refused,
 *   a later command would run outside any transaction.
 * - Any reply other than NOPERM counts as permitted.
 * - If DISCARD cannot be confirmed, the connection is abandoned (closed).
 *   The server drops an unfinished transaction when its client disconnects.
 */
final class NonExecutingProbe
{
    /**
     * @var list<list<string>> ADR-0022 §6 commands that R6 asks about.
     */
    public const array COMMANDS = [
        ['CONFIG', 'GET', 'maxmemory'],
        ['KEYS', 'nexo_testing:r6-probe'],
        ['FLUSHDB'],
        ['FLUSHALL'],
    ];

    /**
     * @param  Closure(list<string>): mixed  $send  Sends one command and
     *                                              returns its reply; throws, with the server's message, for an error reply.
     * @param  Closure(): void  $abandon  Closes the connection.
     * @param  list<list<string>>  $commands
     * @return list<string> Why the server cannot be trusted; empty if every
     *                      command was refused.
     */
    public static function violations(Closure $send, Closure $abandon, array $commands = self::COMMANDS): array
    {
        try {
            $started = $send(['MULTI']);
        } catch (Throwable $e) {
            $abandon();

            return ['MULTI was refused, so nothing could be checked without running it: '.$e->getMessage()];
        }

        if ($started !== true && $started !== 'OK') {
            $abandon();

            return ['MULTI replied '.var_export($started, true).' instead of OK; nothing else was sent'];
        }

        $violations = [];

        try {
            foreach ($commands as $command) {
                try {
                    $reply = $send($command);
                } catch (Throwable $e) {
                    if (str_starts_with($e->getMessage(), 'NOPERM')) {
                        continue;
                    }

                    $violations[] = implode(' ', $command).' was not refused by the ACL: '.$e->getMessage();

                    continue;
                }

                $violations[] = implode(' ', $command).' is permitted (queued, then discarded unexecuted; reply '
                    .var_export($reply, true).')';
            }
        } finally {
            try {
                $discarded = $send(['DISCARD']);
            } catch (Throwable) {
                $discarded = false;
            }

            if ($discarded !== true && $discarded !== 'OK') {
                $abandon();
                $violations[] = 'DISCARD was not confirmed; the connection was closed';
            }
        }

        return $violations;
    }

    /**
     * A sender for a phpredis client. phpredis throws for NOPERM but returns
     * false for some other error replies; those are rethrown, so no error
     * can look like success.
     *
     * @return Closure(list<string>): mixed
     */
    public static function sender(\Redis $client): Closure
    {
        return static function (array $command) use ($client): mixed {
            $client->clearLastError();
            $reply = $client->rawCommand(...$command);

            if ($reply === false && is_string($error = $client->getLastError())) {
                throw new RedisException($error);
            }

            return $reply;
        };
    }
}
