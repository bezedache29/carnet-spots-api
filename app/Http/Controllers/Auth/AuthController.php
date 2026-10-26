<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Fixed-cost dummy hash so login takes the same time whether the email
     * exists or not (Hash::check on a null password would short-circuit).
     */
    private const DUMMY_HASH = '$2y$12$kfX42TTrA/QvRsb6ZUmh/uV4bFqYyDnrIqQWSkl96pDNX5gl.WEcm';

    #[Endpoint(title: 'Register a user', description: 'Creates an account, sends the email verification link, and returns an API token plus the created user.')]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        $user->sendEmailVerificationNotification();

        $token = $user->createToken($request->validated('device_name'));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    #[Endpoint(title: 'Log in', description: 'Authenticates by email/password and returns a new API token, revoking any previous token issued for the same device name.')]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! Hash::check($request->validated('password'), $user->password ?? self::DUMMY_HASH)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $request->validated('device_name');

        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    #[Endpoint(title: 'Log out', description: 'Revokes the API token used to authenticate the current request.')]
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
