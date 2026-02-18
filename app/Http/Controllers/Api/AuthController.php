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
use Carbon\Carbon;
use App\Mail\ResetPasswordMail;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'role'    => $user->role,
            'token'   => $token,
            'user'    => $user, // ✅ FULL USER OBJECT
        ]);
    }

    // Logout user
    public function logout(Request $request){
        // Revoke the current user's token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.'
        ]);
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
        $user->save();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function sendResetLink(Request $request){
        $request->validate([
            'email' => 'required|email',
        ]);

        // Check if user exists
        $user = DB::table('users')->where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'If the email exists, a reset link will be sent.'
            ], 200); // Don't reveal if email exists
        }

        // Generate token
        $token = Str::random(64);

        // Insert token into password_resets table
        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => $token,
                'created_at' => Carbon::now()
            ]
        );

        // Create a React frontend URL
        $resetUrl = "http://localhost:3000/reset_password?token={$token}&email={$request->email}";

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

        // 1️⃣ Check token exists
        $reset = DB::table('password_resets')
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
                'password' => Hash::make($request->password)
            ]);

        // 4️⃣ Delete used token
        DB::table('password_resets')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'Password reset successfully.'
        ], 200);
    }
}

