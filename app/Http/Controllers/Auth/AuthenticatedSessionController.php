<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Google_Client;
class AuthenticatedSessionController extends Controller
{
    /**
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();
        $user = $request->user();

        if (!$user->status) {
            return response()->json([
                "status" => "error",
                "message" => "Votre compte est désactivé !"
            ], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        $permissions = load_permissions($user);
        $roles = $user->roles()->pluck('name');
        $establishments = \App\Models\UserEstablishment::getByUser($user->id);

        $rememberMe = $request->boolean('remember_me');

        $accessTokenMinutes = 1440;

        $refreshTokenMinutes = $rememberMe
            ? (60 * 24 * 30)
            : config('sanctum.expiration', 43200);

        $accessToken = $user->createToken(
            name: config('app.name') . '_access',
            abilities: $permissions,
            expiresAt: now()->addMinutes($accessTokenMinutes)
        );

        $refreshToken = $user->createToken(
            name: config('app.name') . '_refresh',
            abilities: ['server:refresh'],
            expiresAt: now()->addMinutes($refreshTokenMinutes)
        );

        $user->increment('connexion_counter');

        return response()->json([
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken->plainTextToken,
            'expire_in' => $accessToken->accessToken->expires_at ? $accessToken->accessToken->expires_at->toIso8601String() : null,
            'new_user' => $user->default,
            'permissions' => $permissions,
            'user' => $user,
            'roles' => $roles,
            'establishments' => $establishments,
        ]);
    }

    public function googleLogin(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $client = new Google_Client(['client_id' => env('GOOGLE_CLIENT_ID')]);
        $payload = $client->verifyIdToken($request->id_token);

        if (!$payload) {
            return response()->json([
                "status" => "error",
                "message" => "Jeton Google invalide ou expiré !"
            ], Response::HTTP_UNAUTHORIZED);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'];
        $name = $payload['name'] ?? 'Utilisateur Google';

        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        $isNewUser = false;

        if (!$user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'password' => bcrypt(Str::random(16)),
                'status' => true,
            ]);
            $isNewUser = true;
        } elseif (!$user->google_id) {
            $user->update(['google_id' => $googleId]);
        }
        if (!$user->status) {
            return response()->json([
                "status" => "error",
                "message" => "Votre compte est désactivé !"
            ], Response::HTTP_UNAUTHORIZED);
        }
        $permissions = load_permissions($user);
        $roles = $user->roles()->pluck('name');

        $expirationMinutes = config('sanctum.expiration');

        $access = $user->createToken(
            name: config('app.name'),
            abilities: $permissions,
            expiresAt: $expirationMinutes ? now()->addMinutes($expirationMinutes) : null
        );
        $user->increment('connexion_counter');

        return response()->json([
            'access_token' => $access->plainTextToken,
            'expire_in' => $access->accessToken->expires_at ? $access->accessToken->expires_at->toIso8601String() : null,
            'new_user' => $isNewUser || $user->default,
            'permissions' => $permissions,
            'user' => $user,
            'roles' => $roles,
        ]);
    }


    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }


    public function refresh(Request $request)
    {
        $user = $request->user();

        if (!$user->status) {
            return response()->json([
                "status" => "error",
                "message" => "Votre compte est désactivé !"
            ], 401);
        }

        $permissions = load_permissions($user);

        $roles = $user->roles()->pluck('name');

        return response()->json([
            'status' => 'success',
            'permissions' => $permissions,
            'roles' => $roles,
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Non authentifié'
            ], 401);
        }

        if (!$user->status) {
            return response()->json([
                'status' => 'error',
                'message' => 'Votre compte est désactivé'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'user' => $user,
            'roles' => $user->roles()->pluck('name'),
            'permissions' => load_permissions($user),
        ]);
    }

}
