<?php

declare(strict_types=1);

use Tests\Support\Redis\NonExecutingProbe;

/*
 * ADR-0022 §5, R6: the destructive-command probe never runs what it asks
 * about. These cases drive it with a scripted server, so every path,
 * including broken connections, is proven without Redis. tests/Redis/AclTest
 * runs it against the real Valkey, and the PR's local evidence ran it against
 * a server that permits everything.
 */

final class ScriptedRedis
{
    /** @var list<string> */
    public array $sent = [];

    public bool $abandoned = false;

    /**
     * @param  Closure(list<string>): mixed  $reply
     */
    public function __construct(private readonly Closure $reply) {}

    /**
     * @return list<string>
     */
    public function run(): array
    {
        return NonExecutingProbe::violations(
            function (array $command): mixed {
                $this->sent[] = implode(' ', $command);

                return ($this->reply)($command);
            },
            function (): void {
                $this->abandoned = true;
            },
        );
    }
}

function refusingEverything(array $command): mixed
{
    return $command === ['MULTI'] || $command === ['DISCARD']
        ? true
        : throw new RuntimeException("NOPERM User nexo_test has no permissions to run the '".strtolower($command[0])."' command");
}

it('reports nothing when the ACL refuses every probed command, and never sends EXEC', function (): void {
    $redis = new ScriptedRedis(refusingEverything(...));

    expect($redis->run())->toBe([])
        ->and($redis->sent)->toBe(['MULTI', 'CONFIG GET maxmemory', 'KEYS nexo_testing:r6-probe', 'FLUSHDB', 'FLUSHALL', 'DISCARD'])
        ->and($redis->sent)->not->toContain('EXEC')
        ->and($redis->abandoned)->toBeFalse();
});

it('asks about FLUSHDB and FLUSHALL', function (): void {
    expect(NonExecutingProbe::COMMANDS)->toContain(['FLUSHDB'], ['FLUSHALL']);
});

it('reports a permitted command as queued and discarded, never executed', function (): void {
    // A server that permits everything answers QUEUED (true in phpredis).
    $redis = new ScriptedRedis(static fn (array $command): bool => true);

    $violations = $redis->run();

    expect($violations)->toHaveCount(4)
        ->and($violations[2])->toStartWith('FLUSHDB is permitted (queued, then discarded unexecuted')
        ->and($redis->sent)->toBe(['MULTI', 'CONFIG GET maxmemory', 'KEYS nexo_testing:r6-probe', 'FLUSHDB', 'FLUSHALL', 'DISCARD'])
        ->and($redis->sent)->not->toContain('EXEC');
});

it('sends nothing else when MULTI is refused, because a command would then run for real', function (): void {
    $redis = new ScriptedRedis(static fn (array $command): never => throw new RuntimeException("NOPERM User nexo_test has no permissions to run the 'multi' command"));

    expect($redis->run())->toHaveCount(1)
        ->and($redis->sent)->toBe(['MULTI'])
        ->and($redis->abandoned)->toBeTrue();
});

it('sends nothing else when MULTI gives an unexpected reply', function (mixed $reply): void {
    $redis = new ScriptedRedis(static fn (array $command): mixed => $reply);

    expect($redis->run()[0])->toContain('MULTI replied')
        ->and($redis->sent)->toBe(['MULTI'])
        ->and($redis->abandoned)->toBeTrue();
})->with([
    'false' => false,
    'QUEUED' => 'QUEUED',
    'null' => null,
]);

it('counts an error other than NOPERM as not refused', function (): void {
    $redis = new ScriptedRedis(static function (array $command): mixed {
        if ($command === ['FLUSHDB']) {
            throw new RuntimeException('ERR unknown command');
        }

        return refusingEverything($command);
    });

    expect($redis->run())->toBe(['FLUSHDB was not refused by the ACL: ERR unknown command']);
});

it('counts a malformed reply to a queued command as permitted, never as refused', function (mixed $reply): void {
    $redis = new ScriptedRedis(static function (array $command) use ($reply): mixed {
        return $command === ['FLUSHDB'] ? $reply : refusingEverything($command);
    });

    expect($redis->run())->toHaveCount(1)
        ->and($redis->run()[0])->toStartWith('FLUSHDB is permitted')
        ->and($redis->sent)->not->toContain('EXEC');
})->with([
    'a garbage string' => 'garbage',
    'an array' => [['QUEUED', 'extra']],
    'null' => null,
    'an integer' => 0,
]);

it('closes the connection when the link breaks mid-probe', function (): void {
    $redis = new ScriptedRedis(static function (array $command): mixed {
        if ($command === ['MULTI']) {
            return true;
        }

        throw new RuntimeException('read error on connection');
    });

    $violations = $redis->run();

    expect($violations)->toContain('DISCARD was not confirmed; the connection was closed')
        ->and($redis->abandoned)->toBeTrue()
        ->and($redis->sent)->not->toContain('EXEC');
});

it('counts any unexpected failure as not refused, and still discards', function (): void {
    $redis = new ScriptedRedis(static function (array $command): mixed {
        if ($command === ['KEYS', 'nexo_testing:r6-probe']) {
            throw new LogicException('unexpected');
        }

        return refusingEverything($command);
    });

    expect($redis->run())->toBe(['KEYS nexo_testing:r6-probe was not refused by the ACL: unexpected'])
        ->and(end($redis->sent))->toBe('DISCARD')
        ->and($redis->abandoned)->toBeFalse();
});

it('closes the connection when DISCARD is not confirmed', function (): void {
    $redis = new ScriptedRedis(static function (array $command): mixed {
        return $command === ['DISCARD'] ? false : refusingEverything($command);
    });

    expect($redis->run())->toBe(['DISCARD was not confirmed; the connection was closed'])
        ->and($redis->abandoned)->toBeTrue();
});
