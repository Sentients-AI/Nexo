<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Redis\RedisGate;
use Tests\Support\Redis\RedisKeys;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests exercise the application through its HTTP and console
| boundaries and run against a migrated, transaction-isolated database.
| Unit tests cover isolated logic and must not touch the database.
|
| See docs/engineering/testing.md for the full testing conventions.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Platform proofs manage their own, explicitly named probe databases and must
// not run inside a test transaction (CREATE DATABASE commits implicitly).
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        if (getenv('TEST_TOKEN') !== false || isset($_SERVER['TEST_TOKEN'])) {
            $this->markTestSkipped('Platform proofs share named probe databases; run them without --parallel.');
        }
    })
    ->in('Platform');

// Concurrency tests commit their fixtures, so worker processes can see them
// (ADR-0009 §3). DatabaseTruncation, not RefreshDatabase.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

// Redis contracts (ADR-0022): gated (CI-mandatory, skipped locally without
// NEXO_TEST_REDIS=1) and verified live (R6) before the first command. Each
// test deletes only the keys it recorded.
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        RedisGate::enter();
    })
    ->afterEach(function (): void {
        RedisKeys::cleanUp();
    })
    ->in('Redis');
