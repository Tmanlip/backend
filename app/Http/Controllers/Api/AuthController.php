<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Mail\ResetPasswordMail;
use App\Models\UserPicture;
use App\Services\AzureStorage;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $emailInput = $request->input('email');
        $passwordInput = $request->input('password');

        $normalizedEmail = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';

        $request->merge([
            'email' => $normalizedEmail,
        ]);

        $request->validate([
            'email' => 'required|string|email:rfc|max:254',
            'password' => 'required|string|max:255',
            'remember' => 'nullable|boolean',
        ]);

        // ✅ Allow login for all active users; inactive/archived users are warned on frontend but can still obtain token

        if (preg_match('/[\x00-\x1F\x7F]/', $normalizedEmail . $password)) {
            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        $user = User::where('email', $normalizedEmail)->first();

        if ($user && !is_null($user->account_locked_at)) {
            return response()->json([
                'message' => 'Account is locked after 3 failed attempts. Please reset your password.',
                'code' => 'ACCOUNT_LOCKED',
                'reset_required' => true,
            ], 423);
        }

        if (!$user || !Hash::check($password, $user->password)) {
            if ($user) {
                $attempts = ((int) $user->failed_login_attempts) + 1;
                $isNowLocked = $attempts >= 3;

                $user->forceFill([
                    'failed_login_attempts' => $isNowLocked ? 3 : $attempts,
                    'account_locked_at' => $isNowLocked ? now() : null,
                ])->save();

                if ($isNowLocked) {
                    return response()->json([
                        'message' => 'Account is locked after 3 failed attempts. Please reset your password.',
                        'code' => 'ACCOUNT_LOCKED',
                        'reset_required' => true,
                    ], 423);
                }
            }

            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        if ((int) $user->failed_login_attempts > 0 || !is_null($user->account_locked_at)) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'account_locked_at' => null,
            ])->save();
        }

        // ❌ Block archived users from logging in
        if (strtolower((string) $user->status) === 'archived') {
            return response()->json([
                'message' => 'Your account has been archived and cannot be used. Please contact an administrator.'
            ], 403);
        }

        $mustChangePassword = (bool) $user->must_change_password;
        $mustChangePasswordExpiresAt = null;

        if ($mustChangePassword) {
            $generatedAt = $user->temporary_password_generated_at
                ? Carbon::parse($user->temporary_password_generated_at)
                : null;

            if (!$generatedAt || $generatedAt->copy()->addMinutes(10)->isPast()) {
                return response()->json([
                    'message' => 'Your temporary password has expired. Please contact the admin to issue a new password.',
                    'code' => 'TEMP_PASSWORD_EXPIRED',
                ], 403);
            }

            $mustChangePasswordExpiresAt = $generatedAt->copy()->addMinutes(10);
        }

        $remember = (bool) $request->boolean('remember');
        $expirationMinutes = $remember
            ? (int) config('sanctum.remember_me_expiration', 43200)
            : (int) config('sanctum.session_expiration', 1440);
        $expiresAt = now()->addMinutes($expirationMinutes);

        $token = $user->createToken('api-token', ['*'], $expiresAt)->plainTextToken;
        $userPayload = $user->toArray();
        $photoPayload = $this->resolveUserPhoto((int) $user->id, (string) $user->firmID);
        $userPayload['photo'] = $photoPayload['photo'];
        $userPayload['photo_blob_path'] = $photoPayload['photo_blob_path'];
        $userPayload['photo_url'] = $photoPayload['photo_url'];

        return response()->json([
            'message' => 'Login successful',
            'role'    => $user->role,
            'token'   => $token,
            'remember' => $remember,
            'expires_at' => $expiresAt->toIso8601String(),
            'must_change_password' => $mustChangePassword,
            'must_change_password_expires_at' => $mustChangePasswordExpiresAt?->toIso8601String(),
            'user'    => $userPayload,
        ]);
    }

    // Logout user
    public function logout(Request $request){
        try {
            // Revoke the current user's token
            $token = $request->user()->currentAccessToken();
            
            if ($token) {
                $token->delete();
            }

            return response()->json([
                'message' => 'Logged out successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
     // Step 1: Send OTP (optional if already sent)
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $otp = rand(100000, 999999);

        // Store OTP in database (or cache)
        $user = User::where('email', $request->email)->first();
        $user->otp = $otp;
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

        // Send OTP via email (Mailable)
        Mail::to($user->email)->send(new \App\Mail\OtpMail($otp));

        return response()->json(['message' => 'OTP sent successfully.']);
    }

    // Step 2: Verify OTP
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|digits:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->otp !== $request->code || $user->otp_expires_at < now()) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 422);
        }

        // Optional: mark OTP as verified
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();

        return response()->json(['message' => 'Verification successful.']);
    }

    // Step 3: Reset password
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();
        $user->password = bcrypt($request->password);
        $user->failed_login_attempts = 0;
        $user->account_locked_at = null;
        $user->must_change_password = false;
        $user->temporary_password_generated_at = null;
        $user->save();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function sendResetLink(Request $request){
        $request->validate([
            'email' => 'required|email',
        ]);

        $resetTable = config('auth.passwords.users.table', 'password_resets');

        // Check if user exists
        $user = DB::table('users')->where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'If the email exists, a reset link will be sent.'
            ], 200); // Don't reveal if email exists
        }

        // Generate token
        $token = Str::random(64);

        // Store the reset token in the configured password reset table.
        DB::table($resetTable)->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => $token,
                'created_at' => Carbon::now()
            ]
        );

        // Create a React frontend URL that matches the public reset page route.
        $frontendUrl = rtrim(env('APP_FRONTEND_URL', 'http://localhost:3000'), '/');
        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $request->email,
        ]);

        Mail::to($request->email)->send(new ResetPasswordMail($resetUrl));

        return response()->json([
            'message' => 'Password reset link has been sent.',
            'reset_url' => $resetUrl, // optional, for testing
        ], 200);
    }

    public function resetNewPassword(Request $request){
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $resetTable = config('auth.passwords.users.table', 'password_resets');

        // 1️⃣ Check token exists
        $reset = DB::table($resetTable)
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json([
                'message' => 'Invalid reset token.'
            ], 400);
        }

        // 2️⃣ Optional: expire after 60 minutes
        if (Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {
            return response()->json([
                'message' => 'Reset token expired.'
            ], 400);
        }

        // 3️⃣ Update password
        DB::table('users')
            ->where('email', $request->email)
            ->update([
                'password' => Hash::make($request->password),
                'failed_login_attempts' => 0,
                'account_locked_at' => null,
                'must_change_password' => false,
                'temporary_password_generated_at' => null,
            ]);

        // 4️⃣ Delete used token
        DB::table($resetTable)
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'Password reset successfully.'
        ], 200);
    }

    private function resolveUserPhoto(int $userId, string $firmId): array
    {
        try {
            $picture = UserPicture::where('firm_id', $firmId)
                ->orWhere('user_id', $userId)
                ->latest('updated_at')
                ->first();
        } catch (Throwable $exception) {
            Log::warning('User photo lookup skipped because MongoDB is unavailable during login.', [
                'user_id' => $userId,
                'firm_id' => $firmId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'photo' => null,
                'photo_blob_path' => null,
                'photo_url' => null,
            ];
        }

        if (!$picture) {
            return [
                'photo' => null,
                'photo_blob_path' => null,
                'photo_url' => null,
            ];
        }

        $blobPath = !empty($picture->blob_path) ? (string) $picture->blob_path : null;
        $photoUrl = !empty($picture->photo_url)
            ? (string) $picture->photo_url
            : ($blobPath ? AzureStorage::url($blobPath) : null);

        return [
            'photo' => null,
            'photo_blob_path' => $blobPath,
            'photo_url' => $photoUrl,
        ];
    }
}

