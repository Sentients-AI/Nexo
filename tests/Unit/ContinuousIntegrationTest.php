<?php

declare(strict_types=1);

use Tests\Support\ContinuousIntegration;

it('detects GitHub Actions and any other CI value', function (array $env): void {
    expect(ContinuousIntegration::detectedIn($env))->toBeTrue();
})->with([
    'GitHub Actions' => [['CI' => 'true', 'GITHUB_ACTIONS' => 'true']],
    'CI=true' => [['CI' => 'true']],
    'CI=1' => [['CI' => '1']],
    'GITHUB_ACTIONS alone' => [['GITHUB_ACTIONS' => 'true']],
    'GITHUB_ACTIONS despite CI=false' => [['CI' => 'false', 'GITHUB_ACTIONS' => 'true']],
    'unexpected case fails closed' => [['CI' => 'TRUE']],
    'unexpected value fails closed' => [['CI' => 'yes']],
]);

it('does not detect CI locally', function (array $env): void {
    expect(ContinuousIntegration::detectedIn($env))->toBeFalse();
})->with([
    'nothing set' => [[]],
    'unset' => [['CI' => null, 'GITHUB_ACTIONS' => null]],
    'empty' => [['CI' => '']],
    'blank' => [['CI' => ' ']],
    'CI=0' => [['CI' => '0']],
    'CI=false' => [['CI' => 'false']],
    'CI=FALSE' => [['CI' => 'FALSE']],
    'GITHUB_ACTIONS other than true' => [['GITHUB_ACTIONS' => 'false']],
]);
