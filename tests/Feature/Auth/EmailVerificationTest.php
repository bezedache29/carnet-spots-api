<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function verificationUrlFor(User $user, ?DateTimeInterface $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        $expiresAt ?? now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
    );
}

describe('register sends the verification notification', function () {
    it('sends a VerifyEmail notification to the new user on registration', function () {
        Notification::fake();

        $this->postJson('/api/v1/register', [
            'name' => 'Christophe Salou',
            'email' => 'christophe@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'device_name' => 'iphone-16',
        ])->assertCreated();

        $user = User::where('email', 'christophe@example.com')->first();

        Notification::assertSentTo($user, VerifyEmail::class);
    });
});

describe('verify', function () {
    it('marks the email as verified on a valid signed link', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->getJson(verificationUrlFor($user));

        $response->assertOk()
            ->assertJson(['message' => 'Email verified successfully.']);

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('returns already-verified on a replayed link without altering email_verified_at', function () {
        $user = User::factory()->unverified()->create();
        $url = verificationUrlFor($user);

        $this->getJson($url)->assertOk();
        $verifiedAt = $user->fresh()->email_verified_at;

        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJson(['message' => 'Email already verified.']);

        expect($user->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
    });

    it('returns 403 and leaves the email unverified when the hash does not match', function () {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong@example.com')]
        );

        $response = $this->getJson($url);

        $response->assertStatus(403);
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('returns 403 when the signature is invalid because the id was tampered with', function () {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->unverified()->create();

        $url = verificationUrlFor($user);
        $tamperedUrl = str_replace("/{$user->id}/", "/{$otherUser->id}/", $url);

        $response = $this->getJson($tamperedUrl);

        $response->assertStatus(403);
        expect($otherUser->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('returns 403 when the signed link has expired', function () {
        $user = User::factory()->unverified()->create();

        $url = verificationUrlFor($user, now()->subMinute());

        $response = $this->getJson($url);

        $response->assertStatus(403);
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });
});

describe('resend', function () {
    it('returns 401 when not authenticated', function () {
        $response = $this->postJson('/api/v1/email/verification-notification');

        $response->assertUnauthorized();
    });

    it('returns already-verified and sends no notification when already verified', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = $user->createToken('iphone-16')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/email/verification-notification');

        $response->assertOk()
            ->assertJson(['message' => 'Email already verified.']);

        Notification::assertNotSentTo($user, VerifyEmail::class);
    });

    it('sends a new verification notification when unverified', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $token = $user->createToken('iphone-16')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/email/verification-notification');

        $response->assertOk()
            ->assertJson(['message' => 'Verification link sent.']);

        Notification::assertSentTo($user, VerifyEmail::class);
    });
});
