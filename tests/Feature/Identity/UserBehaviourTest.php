<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * #33: the User relocation (ADR-0010 §2b) keeps the model's behaviour. Every
 * test here reaches the class through the authentication provider's
 * configured model, never by name, so the same tests describe the model
 * before and after it moves.
 */

/** @return class-string<Authenticatable> */
function configuredUserModel(): string
{
    $model = config('auth.providers.users.model');

    expect($model)->toBeString();

    /** @var class-string<Authenticatable> $model */
    return $model;
}

it('is an Eloquent authenticatable with notifications and a factory, on the users table', function (): void {
    $model = configuredUserModel();
    $user = new $model;

    expect($user)->toBeInstanceOf(Authenticatable::class)
        ->and(class_uses_recursive($model))->toContain(HasFactory::class, Notifiable::class)
        ->and($user->getTable())->toBe('users')
        ->and($user->getConnectionName())->toBeNull();
});

it('keeps its mass-assignable, hidden and cast attributes', function (): void {
    $model = configuredUserModel();
    $user = new $model;

    expect($user->getFillable())->toBe(['name', 'email', 'password'])
        ->and($user->getHidden())->toBe(['password', 'remember_token'])
        ->and($user->getCasts())->toMatchArray([
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ]);
});

it('ignores attributes outside its fillable list', function (): void {
    $model = configuredUserModel();
    $user = new $model(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password', 'remember_token' => 'x']);

    expect($user->getAttributes())->not->toHaveKey('remember_token');
});

it('builds and stores users of its own class through its factory, with a hashed password', function (): void {
    $model = configuredUserModel();
    $user = $model::factory()->create(['email' => 'ada@example.com']);
    $stored = DB::table('users')->where('email', 'ada@example.com')->first();

    expect($user)->toBeInstanceOf($model)
        ->and($model::factory()->modelName())->toBe($model)
        ->and($stored)->not->toBeNull()
        ->and(Hash::check('password', (string) $stored?->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('keeps the factory unverified state', function (): void {
    $model = configuredUserModel();

    expect($model::factory()->unverified()->create()->email_verified_at)->toBeNull();
});

it('hashes a password set on the model', function (): void {
    $model = configuredUserModel();
    $user = $model::factory()->create(['password' => 'correct-horse-battery']);

    expect($user->getAttributes()['password'])->not->toBe('correct-horse-battery')
        ->and(Hash::check('correct-horse-battery', $user->getAttributes()['password']))->toBeTrue();
});

it('hides the password and remember token when serialised', function (): void {
    $model = configuredUserModel();
    $user = $model::factory()->create();

    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token'])
        ->and($user->toArray())->toHaveKeys(['id', 'name', 'email']);
});

it('is the model the users authentication provider returns', function (): void {
    $model = configuredUserModel();
    $user = $model::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse-battery']);
    $provider = Auth::createUserProvider('users');

    $byId = $provider?->retrieveById($user->getKey());
    $byCredentials = $provider?->retrieveByCredentials(['email' => 'ada@example.com']);

    expect($byId)->toBeInstanceOf($model)
        ->and($byId?->getAuthIdentifier())->toBe($user->getKey())
        ->and($byCredentials)->toBeInstanceOf($model)
        ->and($byCredentials instanceof Model && $provider->validateCredentials($byCredentials, ['password' => 'correct-horse-battery']))->toBeTrue()
        ->and($byCredentials instanceof Model && $provider->validateCredentials($byCredentials, ['password' => 'wrong']))->toBeFalse();
});

it('lives in Identity\Domain and resolves its factory explicitly (ADR-0010 §2b, #33)', function (): void {
    expect(configuredUserModel())->toBe(User::class)
        ->and(User::factory())->toBeInstanceOf(UserFactory::class);
});
