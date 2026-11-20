<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Redis as PhpRedis;
use Tests\Support\ContinuousIntegration;
use Tests\Support\Redis\NonExecutingProbe;
use Tests\Support\Redis\ProbeClient;

/*
 * ADR-0022 §5, R6: the probe must not execute anything even if its connection
 * drops after MULTI. phpredis reconnects transparently (OPT_MAX_RETRIES,
 * default 10; Laravel configures 3) and does not know a raw MULTI is open, so
 * after a drop the next probed command would run for real.
 *
 * This runs against a disposable Valkey whose default user may do anything
 * (.github/valkey/start-disposable.sh): the misconfigured server R6 exists to
 * catch. Its connection is dropped from the server side with CLIENT KILL, a
 * real disconnect seen by the real phpredis client. The shared test Valkey is
 * never interrupted. Every case first proves the target is the disposable
 * server (its marker key, its own port, almost empty), and refuses otherwise.
 */

const DISPOSABLE_MARKER = 'nexo-disposable-valkey';

function disposablePort(): int
{
    $port = getenv('NEXO_TEST_DISPOSABLE_REDIS_PORT');

    if (! is_string($port) || preg_match('/^[1-9][0-9]{1,4}$/', $port) !== 1) {
        if (ContinuousIntegration::detected()) {
            Assert::fail('ADR-0022 requires the reconnect test in CI, but NEXO_TEST_DISPOSABLE_REDIS_PORT is not set.');
        }

        Assert::markTestSkipped('No disposable Valkey (NEXO_TEST_DISPOSABLE_REDIS_PORT); see .github/valkey/start-disposable.sh.');
    }

    return (int) $port;
}

/**
 * A client on the disposable server, after proving that is what it is.
 */
function disposableClient(): PhpRedis
{
    $port = disposablePort();

    if ($port === (int) config('database.redis.default.port')) {
        Assert::fail('The disposable Valkey port is the test Valkey port; refusing to drop connections there.');
    }

    $client = new PhpRedis;
    $client->connect('127.0.0.1', $port, 2.0);

    if ($client->rawCommand('GET', 'nexo_disposable:marker') !== DISPOSABLE_MARKER || (int) $client->rawCommand('DBSIZE') > 2) {
        Assert::fail('127.0.0.1:'.$port.' is not the disposable Valkey (marker missing or keys present); refusing.');
    }

    return $client;
}

/**
 * @return array<string, int> Executed calls per command since the last reset.
 */
function disposableCalls(PhpRedis $admin): array
{
    preg_match_all('/^cmdstat_([a-z|_-]+):calls=(\d+)/m', (string) $admin->rawCommand('INFO', 'commandstats'), $m, PREG_SET_ORDER);

    return array_column(array_map(static fn (array $row): array => [$row[1], (int) $row[2]], $m), 1, 0);
}

/**
 * Forwards to a real phpredis client, and kills that client's connection
 * from the server right after MULTI succeeds.
 */
final class DroppedAfterMulti implements ProbeClient
{
    public function __construct(private readonly PhpRedis $inner, private readonly PhpRedis $admin) {}

    public function setOption(int $option, mixed $value): bool
    {
        return $this->inner->setOption($option, $value);
    }

    public function getOption(int $option): mixed
    {
        return $this->inner->getOption($option);
    }

    public function rawCommand(string $command, mixed ...$arguments): mixed
    {
        $id = $command === 'MULTI' ? $this->inner->rawCommand('CLIENT', 'ID') : null;
        $reply = $this->inner->rawCommand($command, ...$arguments);

        if ($id !== null) {
            $this->admin->rawCommand('CLIENT', 'KILL', 'ID', (string) $id);
            usleep(50_000);
        }

        return $reply;
    }

    public function clearLastError(): bool
    {
        return $this->inner->clearLastError();
    }

    public function getLastError(): ?string
    {
        return $this->inner->getLastError();
    }

    public function close(): bool
    {
        return $this->inner->close();
    }
}

beforeEach(function (): void {
    $this->admin = disposableClient();
    $this->admin->rawCommand('SET', 'canary', 'alive');
    $this->admin->rawCommand('CONFIG', 'RESETSTAT');
});

afterEach(function (): void {
    if (isset($this->admin)) {
        $this->admin->rawCommand('SET', 'nexo_disposable:marker', DISPOSABLE_MARKER);
        $this->admin->rawCommand('UNLINK', 'canary');
    }
});

it('control: with reconnect left on, a dropped connection runs the next command outside the transaction', function (): void {
    // What the probe did before this fix, step by step: the test must be able
    // to see the failure it guards against.
    $client = new PhpRedis;
    $client->connect('127.0.0.1', disposablePort(), 2.0);
    $client->setOption(PhpRedis::OPT_MAX_RETRIES, 3);
    $dropping = new DroppedAfterMulti($client, $this->admin);

    expect($dropping->rawCommand('MULTI'))->toBeTrue()
        ->and($client->rawCommand('FLUSHALL'))->toBeTrue();

    expect(disposableCalls($this->admin)['flushall'] ?? 0)->toBe(1)
        ->and($this->admin->rawCommand('GET', 'canary'))->toBeFalse();

    $client->close();
});

it('never runs a probed command when its connection drops after MULTI', function (): void {
    $client = new PhpRedis;
    $client->connect('127.0.0.1', disposablePort(), 2.0);
    // As Laravel configures it; the probe must switch it off itself.
    $client->setOption(PhpRedis::OPT_MAX_RETRIES, 3);

    $violations = NonExecutingProbe::violationsOn(new DroppedAfterMulti($client, $this->admin));
    $calls = disposableCalls($this->admin);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('CONFIG GET maxmemory was not refused by the ACL (')
        ->and($violations[0])->toEndWith('the probe stopped and closed the connection')
        // Nothing probed ever executed, and nothing was sent after the drop.
        ->and(array_intersect_key($calls, array_flip(['flushall', 'flushdb', 'keys', 'config|get', 'exec', 'discard'])))->toBe([])
        ->and($this->admin->rawCommand('GET', 'canary'))->toBe('alive')
        // Failed closed: reconnect off, client closed, never handed back.
        ->and($client->getOption(PhpRedis::OPT_MAX_RETRIES))->toBe(0)
        ->and($client->isConnected())->toBeFalse();
});

it('positive control: on the same server, with no drop, the probe reports everything permitted and runs nothing', function (): void {
    $client = new PhpRedis;
    $client->connect('127.0.0.1', disposablePort(), 2.0);

    $violations = NonExecutingProbe::violationsOn($client);
    $calls = disposableCalls($this->admin);

    expect($violations)->toHaveCount(4)
        ->and($violations[3])->toStartWith('FLUSHALL is permitted (queued, then discarded unexecuted')
        ->and($calls['multi'] ?? 0)->toBe(1)
        ->and($calls['discard'] ?? 0)->toBe(1)
        ->and(array_intersect_key($calls, array_flip(['flushall', 'flushdb', 'keys', 'config|get', 'exec'])))->toBe([])
        ->and($this->admin->rawCommand('GET', 'canary'))->toBe('alive');
});
