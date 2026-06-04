<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'password'     => ['required', 'confirmed', Password::min(8)],
            'phone_number' => 'required|string|unique:users,phone_number',
        ]);

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'password'     => $data['password'],
            'phone_number' => $data['phone_number'],
        ]);

        $this->otp->generate($user->phone_number, 'phone_verification');

        return response()->json([
            'needs_phone_verification' => true,
            'phone'                    => $user->phone_number,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (!$user->isPhoneVerified()) {
            if (!$user->phone_number) {
                return response()->json([
                    'message'          => 'No phone number on this account. Please sign in with Google or Apple.',
                    'needs_phone_setup' => true,
                ], 403);
            }

            $this->otp->generate($user->phone_number, 'phone_verification');

            return response()->json([
                'needs_phone_verification' => true,
                'phone'                    => $user->phone_number,
            ], 403);
        }

        $token = $user->createToken('mobile', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->withConsoleFields($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->withConsoleFields($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'avatar' => 'sometimes|string|url|max:2048',
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json($user->fresh());
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();

        // Delete previous custom avatar from local storage
        if ($user->avatar && str_contains($user->avatar, '/storage/avatars/')) {
            $path = ltrim(parse_url($user->avatar, PHP_URL_PATH), '/');
            Storage::disk('public')->delete(str_replace('storage/', '', $path));
        }

        $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');
        $url  = url('/storage/' . $path);

        $user->update(['avatar' => $url]);

        return response()->json($user->fresh());
    }

    private function withConsoleFields(User $user): array
    {
        $data                  = $user->toArray();
        $data['permissions']   = $user->getEffectivePermissions();
        $data['agency_scopes'] = $user->agencyScopes()->pluck('agency_id')->toArray();
        $data['console_role']  = $user->roles->first()?->name ?? $user->role;
        return $data;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();

        // Always return 200 to avoid user enumeration
        if ($user && $user->phone_number) {
            $this->otp->generate($user->phone_number, 'password_reset');
        }

        return response()->json(['message' => 'If your account exists, a reset code was sent to your phone.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => 'required|string',
            'code'     => 'required|string|size:6',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (!$this->otp->verify($data['phone'], $data['code'], 'password_reset')) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::where('phone_number', $data['phone'])->firstOrFail();
        $user->update(['password' => $data['password']]);

        // Revoke all existing tokens for security
        $user->tokens()->delete();

        $token = $user->createToken('mobile', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }
}
