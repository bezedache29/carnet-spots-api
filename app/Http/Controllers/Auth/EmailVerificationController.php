<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Handle the signed verification link sent by email.
     *
     * Unauthenticated by design: the link is opened from an email client,
     * not from within an already-authenticated web/mobile session. The
     * `signed` middleware guarantees the link wasn't tampered with or
     * expired; the hash check below ties it to this specific user/email.
     */
    #[Endpoint(title: 'Verify an email address', description: "Confirms a user's email via the signed link sent at registration. Unauthenticated by design — the request is validated by the link's signature and hash, not a session.")]
    public function verify(Request $request): JsonResponse
    {
        $user = User::findOrFail($request->route('id'));

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash'))) {
            abort(403, 'Invalid verification link.');
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->markEmailAsVerified();

        return response()->json(['message' => 'Email verified successfully.']);
    }

    #[Endpoint(title: 'Resend the verification email', description: 'Sends a new email verification link to the authenticated user, if not already verified.')]
    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}
