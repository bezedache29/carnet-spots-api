<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

describe('register', function () {
    it('creates a user, hashes the password, and returns a token on valid data', function () {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.name', 'Christophe Salou')
            ->assertJsonPath('user.email', 'christophe@example.com');

        expect($response->json('token'))->toBeString()->not->toBeEmpty();

        $this->assertDatabaseHas('users', [
            'email' => 'christophe@example.com',
            'name' => 'Christophe Salou',
        ]);

        $user = User::where('email', 'christophe@example.com')->first();
        expect($user->password)
            ->not->toBe('correct-password')
            ->and(Hash::check('correct-password', $user->password))->toBeTrue();
    });

    it('never exposes the password in the register response', function () {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertJsonMissingPath('user.password');
    });

    it('returns 422 when the email is already used', function () {
        $existing = User::factory()->create();

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Someone Else',
            'email' => $existing->email,
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('returns 422 when the password confirmation does not match', function () {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'different-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    });

    it('returns 422 when the password is shorter than the 12-character minimum', function () {
        // 6 characters: fails the `min:12` check before the Password rule ever
        // reaches ->uncompromised(), so this never triggers the HaveIBeenPwned
        // network call.
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'short1',
            'password_confirmation' => 'short1',
            'device_name' => 'iphone-16',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    });

    it('returns 422 when the email exceeds 255 characters', function () {
        $email = str_repeat('a', 300).'@example.com';

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => $email,
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('returns 422 when required fields are missing', function () {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'device_name']);
    });

    it('issues a token that works immediately on a protected route', function () {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $token = $response->json('token');

        $userResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/user');

        $userResponse->assertOk()
            ->assertJsonPath('data.email', 'christophe@example.com');
    });

    it('returns 429 after exceeding the register rate limit, counting successful registrations too', function () {
        $invalidPayload = [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'short1',
            'password_confirmation' => 'short1',
            'device_name' => 'iphone-16',
        ];

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/register', $invalidPayload)->assertUnprocessable();
        }

        // The 5th request is a *successful* registration: it must still count
        // toward the throttle, otherwise an attacker could bypass the limiter
        // by mixing in valid requests.
        $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/register', $invalidPayload);

        $response->assertStatus(429);
    });
});

describe('login', function () {
    it('returns a token and the user on valid credentials', function () {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);

        expect($response->json('token'))->toBeString()->not->toBeEmpty();
    });

    it('never exposes the password in the login response', function () {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertJsonMissingPath('user.password');
    });

    it('returns 422 and does not issue a token on wrong password', function () {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 422 without revealing account existence on unknown email', function () {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'unknown@example.com',
            'password' => 'whatever-password',
            'device_name' => 'iphone-16',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns the same error message for an unknown email as for a wrong password', function () {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $wrongPasswordResponse = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'iphone-16',
        ]);

        $unknownEmailResponse = $this->postJson('/api/v1/login', [
            'email' => 'unknown@example.com',
            'password' => 'whatever-password',
            'device_name' => 'iphone-16',
        ]);

        expect($wrongPasswordResponse->json('errors.email'))
            ->toBe($unknownEmailResponse->json('errors.email'));
    });

    it('returns 422 when required fields are missing', function () {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    });

    it('returns 429 after exceeding the login rate limit for the same email', function () {
        // The `login` limiter is keyed on `email|ip`, so the test must reuse
        // the same email on every attempt to actually exercise that key.
        $payload = [
            'email' => 'unknown@example.com',
            'password' => 'whatever-password',
            'device_name' => 'iphone-16',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', $payload)->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/login', $payload);

        $response->assertStatus(429);
    });

    it('revokes the previous token for the same device_name on repeated login', function () {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $credentials = [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'iphone-16',
        ];

        $firstToken = $this->postJson('/api/v1/login', $credentials)->json('token');
        $secondToken = $this->postJson('/api/v1/login', $credentials)->json('token');

        expect($secondToken)->not->toBe($firstToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // See the note on the logout tests below: the auth guard caches the
        // resolved user for the lifetime of the container, so it must be
        // forgotten between requests using different tokens within one test.
        $this->app->forgetInstance('auth');
        $this->withHeader('Authorization', "Bearer {$firstToken}")
            ->getJson('/api/v1/user')
            ->assertUnauthorized();

        $this->app->forgetInstance('auth');
        $this->withHeader('Authorization', "Bearer {$secondToken}")
            ->getJson('/api/v1/user')
            ->assertOk();
    });

    it('keeps tokens from other device_names intact on login (multi-device)', function () {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $phoneToken = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'iphone-16',
        ])->json('token');

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'macbook-pro',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->app->forgetInstance('auth');
        $this->withHeader('Authorization', "Bearer {$phoneToken}")
            ->getJson('/api/v1/user')
            ->assertOk();
    });
});

describe('authenticated access', function () {
    it('allows a valid token to access a protected route', function () {
        $user = User::factory()->create();
        $token = $user->createToken('iphone-16')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/user');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    });

    it('returns 401 on a protected route without a token', function () {
        $response = $this->getJson('/api/v1/user');

        $response->assertUnauthorized();
    });
});

describe('logout', function () {
    it('revokes the current token and returns 204', function () {
        $user = User::factory()->create();
        $token = $user->createToken('iphone-16')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout');

        $response->assertNoContent();
        expect(PersonalAccessToken::findToken($token))->toBeNull();
    });

    it('makes the revoked token unusable on a subsequent protected request', function () {
        $user = User::factory()->create();
        $token = $user->createToken('iphone-16')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/logout');

        // Laravel's RequestGuard (used by Sanctum) caches the resolved user on the
        // AuthManager singleton for the lifetime of the container. In production a
        // fresh container is booted per request, but a single test method reuses
        // the same container across calls, so we must force re-resolution here.
        $this->app->forgetInstance('auth');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/user');

        $response->assertUnauthorized();
    });

    it('only revokes the current token, leaving other devices logged in', function () {
        $user = User::factory()->create();
        $phoneToken = $user->createToken('iphone-16')->plainTextToken;
        $laptopToken = $user->createToken('macbook-pro')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$phoneToken}")
            ->postJson('/api/v1/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app->forgetInstance('auth');

        $this->withHeader('Authorization', "Bearer {$laptopToken}")
            ->getJson('/api/v1/user')
            ->assertOk();
    });
});
