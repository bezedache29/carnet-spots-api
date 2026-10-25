<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

describe('login', function () {
    it('returns a token and the user for valid credentials', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson(route('v1.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'pixel-7',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password');

        expect($response->json('token'))->toBeString()->not->toBeEmpty();
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'pixel-7',
        ]);
    });

    it('returns a token that authenticates later requests', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $token = $this->postJson(route('v1.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'web',
        ])->json('token');

        $this->withToken($token)->getJson(route('v1.user'))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    });

    it('revokes the previous token of the same device when logging in again', function () {
        $user = User::factory()->create(['password' => 'secret-password']);
        $webToken = $user->createToken('web');
        $oldPhoneToken = $user->createToken('pixel-7');

        $newPhoneToken = $this->postJson(route('v1.login'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'pixel-7',
        ])->assertOk()->json('token');

        expect(PersonalAccessToken::find($oldPhoneToken->accessToken->id))->toBeNull()
            ->and(PersonalAccessToken::find($webToken->accessToken->id))->not->toBeNull()
            ->and(PersonalAccessToken::findToken($newPhoneToken))->not->toBeNull()
            ->and($user->tokens()->where('name', 'pixel-7')->count())->toBe(1);
    });

    it('returns 422 for a wrong password', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->postJson(route('v1.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'web',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 422 for an unknown email with the same message as a wrong password', function () {
        $this->postJson(route('v1.login'), [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
            'device_name' => 'web',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
    });

    it('returns 422 when required fields are missing', function () {
        $this->postJson(route('v1.login'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    });

    it('returns 429 after too many attempts', function () {
        $payload = ['email' => 'nobody@example.com', 'password' => 'whatever', 'device_name' => 'web'];

        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('v1.login'), $payload)->assertUnprocessable();
        }

        $this->postJson(route('v1.login'), $payload)->assertTooManyRequests();
    });
});

describe('logout', function () {
    it('revokes only the token used for the request', function () {
        $user = User::factory()->create();
        $phoneToken = $user->createToken('phone');
        $webToken = $user->createToken('web');

        $this->withToken($phoneToken->plainTextToken)
            ->postJson(route('v1.logout'))
            ->assertNoContent();

        expect(PersonalAccessToken::find($phoneToken->accessToken->id))->toBeNull()
            ->and(PersonalAccessToken::find($webToken->accessToken->id))->not->toBeNull();
    });

    it('makes the revoked token return 401 on the next request', function () {
        $token = User::factory()->create()->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson(route('v1.logout'))->assertNoContent();

        // The guard caches the authenticated user within a test; reset it so the token is checked again.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(route('v1.user'))->assertUnauthorized();
    });

    it('returns 401 without a token', function () {
        $this->postJson(route('v1.logout'))->assertUnauthorized();
    });
});

describe('user', function () {
    it('returns the authenticated user', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('v1.user'))
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]]);
    });

    it('returns 401 with an invalid token', function () {
        $this->withToken('not-a-real-token')
            ->getJson(route('v1.user'))
            ->assertUnauthorized();
    });
});
