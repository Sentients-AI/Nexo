<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    ->in('Platform');
