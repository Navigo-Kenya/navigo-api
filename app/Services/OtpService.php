<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Str;

class OtpService
{
    public function __construct(private SmsService $sms) {}

    /**
     * Generate a 6-digit OTP for the given phone + type, send it via SMS,
     * and return the plain code (for testing convenience).
     */
    public function generate(string $phone, string $type = 'phone_verification'): string
    {
        // Rate limit: allow at most 1 active (unused, unexpired) code per phone+type per minute
        $recent = OtpCode::where('phone', $phone)
            ->where('type', $type)
            ->whereNull('used_at')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recent) {
            // Return the existing code instead of creating a duplicate
            $existing = OtpCode::where('phone', $phone)
                ->where('type', $type)
                ->whereNull('used_at')
                ->latest()
                ->first();

            if ($existing) {
                $this->sms->send($phone, $this->buildMessage($existing->code, $type));
                return $existing->code;
            }
        }

        // Invalidate all previous unused codes for this phone+type
        OtpCode::where('phone', $phone)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone'      => $phone,
            'code'       => $code,
            'type'       => $type,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->sms->send($phone, $this->buildMessage($code, $type));

        return $code;
    }

    /**
     * Verify an OTP code. Returns true on success and marks the code used.
     */
    public function verify(string $phone, string $code, string $type = 'phone_verification'): bool
    {
        $otp = OtpCode::valid()
            ->where('phone', $phone)
            ->where('type', $type)
            ->latest()
            ->first();

        if (!$otp) {
            return false;
        }

        if (!hash_equals($otp->code, $code)) {
            $otp->incrementAttempts();
            return false;
        }

        $otp->markUsed();
        return true;
    }

    private function buildMessage(string $code, string $type): string
    {
        return match ($type) {
            'password_reset'      => "Your Navigo password reset code is: {$code}. Valid for 10 minutes.",
            default               => "Your Navigo verification code is: {$code}. Valid for 10 minutes.",
        };
    }
}
