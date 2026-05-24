<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string']);

        $this->otp->generate($data['phone'], 'phone_verification');

        return response()->json(['message' => 'OTP sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'       => 'required|string',
            'code'        => 'required|string|size:6',
            'setup_token' => 'nullable|string',
        ]);

        if (!$this->otp->verify($data['phone'], $data['code'], 'phone_verification')) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::where('phone_number', $data['phone'])->firstOrFail();
        $user->update(['phone_verified_at' => now()]);

        // Social login path: exchange short-lived setup token for a full 30-day token
        if (!empty($data['setup_token'])) {
            // Revoke the setup token and issue a full one
            $user->tokens()->where('name', 'setup')->delete();
            $token = $user->createToken('mobile', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json(['token' => $token, 'user' => $user]);
        }

        return response()->json(['verified' => true]);
    }
}
