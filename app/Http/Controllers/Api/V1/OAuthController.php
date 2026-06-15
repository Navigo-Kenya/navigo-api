<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token'     => 'required|string',
            'phone_number' => 'nullable|string|max:20',
        ]);

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['id_token'],
        ]);

        if ($response->failed()) {
            return response()->json(['message' => 'Invalid Google token.'], 401);
        }

        $payload = $response->json();

        // Accept any of the app's configured client IDs (web, iOS, Android)
        $validAudiences = array_filter([
            config('services.google.client_id'),
            config('services.google.client_id_ios'),
            config('services.google.client_id_android'),
        ]);

        if (!empty($validAudiences) && !in_array($payload['aud'] ?? '', $validAudiences, true)) {
            return response()->json(['message' => 'Token audience mismatch.'], 401);
        }

        $googleId = $payload['sub'] ?? null;
        $email    = $payload['email'] ?? null;
        $name     = $payload['name'] ?? 'User';
        $avatar   = $payload['picture'] ?? null;
        $phone    = $data['phone_number'] ?? null;

        if (!$googleId) {
            return response()->json(['message' => 'Invalid Google token payload.'], 401);
        }

        $user = User::where('google_id', $googleId)->first()
            ?? ($email ? User::where('email', $email)->first() : null);

        if ($user) {
            $updateData = ['google_id' => $googleId, 'oauth_provider' => 'google'];
            if ($avatar && !$this->isCustomAvatar($user->avatar)) {
                $updateData['avatar'] = $avatar;
            }
            // Only store phone if the user doesn't already have one
            if ($phone && !$user->phone_number) {
                $updateData['phone_number'] = $phone;
            }
            $user->update($updateData);
        } else {
            $user = User::create([
                'name'           => $name,
                'email'          => $email,
                'password'       => Str::random(32),
                'google_id'      => $googleId,
                'oauth_provider' => 'google',
                'avatar'         => $avatar,
                'phone_number'   => $phone,
            ]);
        }

        return $this->respondWithTokenOrPhoneSetup($user);
    }

    public function apple(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity_token' => 'required|string',
            'user'           => 'nullable|array',
            'user.name'      => 'nullable|string',
            'user.email'     => 'nullable|email',
            'phone_number'   => 'nullable|string|max:20',
        ]);

        $payload = $this->verifyAppleToken($data['identity_token']);

        if (!$payload) {
            return response()->json(['message' => 'Invalid Apple token.'], 401);
        }

        $appleId = $payload->sub ?? null;
        $email   = $payload->email ?? ($data['user']['email'] ?? null);
        $name    = $data['user']['name'] ?? 'User';
        $phone   = $data['phone_number'] ?? null;

        if (!$appleId) {
            return response()->json(['message' => 'Invalid Apple token payload.'], 401);
        }

        $user = User::where('apple_id', $appleId)->first()
            ?? ($email ? User::where('email', $email)->first() : null);

        if ($user) {
            $updateData = array_filter([
                'apple_id'       => $appleId,
                'oauth_provider' => $user->oauth_provider ?? 'apple',
            ]);
            if ($phone && !$user->phone_number) {
                $updateData['phone_number'] = $phone;
            }
            $user->update($updateData);
        } else {
            $user = User::create([
                'name'           => $name,
                'email'          => $email,
                'password'       => Str::random(32),
                'apple_id'       => $appleId,
                'oauth_provider' => 'apple',
                'phone_number'   => $phone,
            ]);
        }

        return $this->respondWithTokenOrPhoneSetup($user);
    }

    private function respondWithTokenOrPhoneSetup(User $user): JsonResponse
    {
        if (!$user->isPhoneVerified()) {
            $setupToken = $user->createToken('setup', ['phone:verify'], now()->addMinutes(10))->plainTextToken;

            return response()->json([
                'needs_phone' => true,
                'setup_token' => $setupToken,
                // Echo back the stored phone so the client can pre-fill the OTP screen
                'phone'       => $user->phone_number,
            ], 200);
        }

        $token = $user->createToken('mobile', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }

    private function isCustomAvatar(?string $url): bool
    {
        return $url !== null && str_contains($url, '/storage/avatars/');
    }

    private function verifyAppleToken(string $identityToken): ?object
    {
        try {
            $client   = new Client();
            $response = $client->get('https://appleid.apple.com/auth/keys');
            $keys     = json_decode($response->getBody()->getContents(), true);

            $publicKeys = JWK::parseKeySet($keys);
            $decoded    = JWT::decode($identityToken, $publicKeys);

            $iss = $decoded->iss ?? '';
            if ($iss !== 'https://appleid.apple.com') {
                return null;
            }

            $aud      = $decoded->aud ?? '';
            $clientId = config('services.apple.client_id');
            if ($clientId && $aud !== $clientId) {
                return null;
            }

            return $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
