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
 * after a drop the next command would run for real, outside any transaction.
 *
 * This runs against a disposable Valkey whose default user may do anything
 * (.github/valkey/start-disposable.sh): the misconfigured server R6 exists to
 * catch. Its connection is dropped from the server side with CLIENT KILL, a
 * real disconnect seen by the real phpredis client. The shared test Valkey is
 * never interrupted.
 *
 * No destructive command is sent here. The commands that would run after a
 * drop are SETs of a key this test owns, so whether they ran is visible and
 * harmless. Every case first proves the target is the server this run
 * started: the per-run marker, its own port, and nothing but the marker in
 * its keyspace. It refuses otherwise.
 */

const PROOF_KEY = 'nexo_disposable:reconnect-proof';

function disposablePort(): int
{
    $port = getenv('NEXO_TEST_DISPOSABLE_REDIS_PORT');
    $marker = getenv('NEXO_TEST_DISPOSABLE_REDIS_MARKER');

    if (! is_string($port) || preg_match('/^[1-9][0-9]{1,4}$/', $port) !== 1 || ! is_string($marker) || $marker === '') {
        if (ContinuousIntegration::detected()) {
            Assert::fail('ADR-0022 requires the reconnect test in CI, but NEXO_TEST_DISPOSABLE_REDIS_PORT or _MARKER is not set.');
        }

        Assert::markTestSkipped('No disposable Valkey (NEXO_TEST_DISPOSABLE_REDIS_PORT, _MARKER); see .github/valkey/start-disposable.sh.');
    }

    return (int) $port;
}

/**
 * A client on the disposable server, after proving it is the one this run
 * started. Only reads are sent until the proof holds.
 */
function disposableClient(): PhpRedis
{
    $port = disposablePort();

    if ($port === (int) config('database.redis.default.port')) {
        Assert::fail('The disposable Valkey port is the test Valkey port; refusing to drop connections there.');
    }

    $client = new PhpRedis;
    $client->connect('127.0.0.1', $port, 2.0);

    $keyspace = array_values(array_filter(
        explode("\n", str_replace("\r", '', (string) $client->rawCommand('INFO', 'keyspace'))),
        static fn (string $line): bool => str_starts_with($line, 'db'),
    ));

    $isOurs = $client->rawCommand('GET', 'nexo_disposable:marker') === getenv('NEXO_TEST_DISPOSABLE_REDIS_MARKER')
        && count($keyspace) === 1
        && preg_match('/^db0:keys=(1|2),/', $keyspace[0]) === 1;

    if (! $isOurs) {
        $client->close();
        Assert::fail('127.0.0.1:'.$port.' is not the disposable Valkey this run started (marker, or keyspace '
            .json_encode($keyspace).'); refusing.');
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

function clientWithRetries(int $retries): PhpRedis
{
    $client = new PhpRedis;
    $client->connect('127.0.0.1', disposablePort(), 2.0);
    $client->setOption(PhpRedis::OPT_MAX_RETRIES, $retries);

    return $client;
}

/** The destructive commands that must never execute on the disposable server. */
const NEVER_EXECUTED = ['flushall', 'flushdb', 'swapdb', 'keys', 'config|get', 'config|set', 'shutdown', 'exec'];

beforeEach(function (): void {
    $this->admin = disposableClient();
    $this->token = bin2hex(random_bytes(8));
    // Only this test's own keys; a failed earlier run may have left them.
    $this->admin->rawCommand('UNLINK', PROOF_KEY, PROOF_KEY.':second');
    $this->admin->rawCommand('CONFIG', 'RESETSTAT');
});

afterEach(function (): void {
    if (isset($this->admin)) {
        $this->admin->rawCommand('UNLINK', PROOF_KEY, PROOF_KEY.':second');
        $this->admin->close();
    }
});

it('control: with reconnect on, a SET sent after a dropped MULTI runs outside the transaction', function (): void {
    // The defect, shown with a harmless command: had the transaction held,
    // the SET would only have been queued and lost with the connection.
    $client = clientWithRetries(3);

    expect((new DroppedAfterMulti($client, $this->admin))->rawCommand('MULTI'))->toBeTrue()
        ->and($client->rawCommand('SET', PROOF_KEY, $this->token))->toBeTrue();

    expect($this->admin->rawCommand('GET', PROOF_KEY))->toBe($this->token)
        ->and(disposableCalls($this->admin)['set'] ?? 0)->toBe(1)
        ->and(array_intersect_key(disposableCalls($this->admin), array_flip(NEVER_EXECUTED)))->toBe([]);

    $client->close();
});

it('control: with reconnect off, the same SET fails and does not run', function (): void {
    $client = clientWithRetries(0);

    expect((new DroppedAfterMulti($client, $this->admin))->rawCommand('MULTI'))->toBeTrue()
        ->and(fn () => $client->rawCommand('SET', PROOF_KEY, $this->token))->toThrow(RedisException::class, 'Connection lost');

    expect($this->admin->rawCommand('GET', PROOF_KEY))->toBeFalse()
        ->and(disposableCalls($this->admin)['set'] ?? 0)->toBe(0);

    $client->close();
});

it('never runs a probed command when its connection drops after MULTI', function (): void {
    // As Laravel configures it; the probe must switch reconnect off itself.
    $client = clientWithRetries(3);

    $violations = NonExecutingProbe::violationsOn(
        new DroppedAfterMulti($client, $this->admin),
        [['SET', PROOF_KEY, $this->token], ['SET', PROOF_KEY.':second', $this->token]],
    );
    $calls = disposableCalls($this->admin);

    expect($violations)->toBe(['SET '.PROOF_KEY.' '.$this->token.' was not refused by the ACL (Connection lost); the probe stopped and closed the connection'])
        // Nothing probed ran: the SET did not reconnect, and nothing followed it.
        ->and($this->admin->rawCommand('GET', PROOF_KEY))->toBeFalse()
        ->and($this->admin->rawCommand('GET', PROOF_KEY.':second'))->toBeFalse()
        ->and($calls['set'] ?? 0)->toBe(0)
        ->and($calls['discard'] ?? 0)->toBe(0)
        ->and(array_intersect_key($calls, array_flip(NEVER_EXECUTED)))->toBe([])
        // Failed closed: reconnect off, client closed, never handed back.
        ->and($client->getOption(PhpRedis::OPT_MAX_RETRIES))->toBe(0)
        ->and($client->isConnected())->toBeFalse();
});

it('positive control: with no drop, the probe queues and discards its commands without running them', function (): void {
    $client = clientWithRetries(3);

    $violations = NonExecutingProbe::violationsOn($client, [['SET', PROOF_KEY, $this->token]]);
    $calls = disposableCalls($this->admin);

    expect($violations)->toBe(['SET '.PROOF_KEY.' '.$this->token.' is permitted (queued, then discarded unexecuted; reply true)'])
        ->and($this->admin->rawCommand('GET', PROOF_KEY))->toBeFalse()
        ->and($calls['multi'] ?? 0)->toBe(1)
        ->and($calls['discard'] ?? 0)->toBe(1)
        ->and($calls['set'] ?? 0)->toBe(0)
        ->and(array_intersect_key($calls, array_flip(NEVER_EXECUTED)))->toBe([])
        ->and($client->isConnected())->toBeFalse();
});
