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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Mail\ResetPasswordMail;
use App\Models\UserPicture;
use App\Services\AzureStorage;
use App\Services\TotpService;
use Throwable;

class AuthController extends Controller
{
    private const MFA_CHALLENGE_CACHE_PREFIX = 'auth:mfa:challenge:';
    private const ENTRA_STATE_CACHE_PREFIX = 'auth:entra:state:';

    public function __construct(private readonly TotpService $totpService)
    {
    }

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

        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

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

        if ($this->requiresEntraSsoForRole($user)) {
            return response()->json([
                'message' => 'This role must sign in with Microsoft SSO.',
                'code' => 'SSO_REQUIRED',
                'role' => strtolower((string) $user->role),
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

        if ($this->isClientMfaEnabled($user)) {
            $challengeToken = Str::random(64);
            Cache::put(
                self::MFA_CHALLENGE_CACHE_PREFIX . $challengeToken,
                [
                    'user_id' => (int) $user->id,
                    'remember' => $remember,
                    'issued_at' => now()->toIso8601String(),
                ],
                now()->addMinutes(5)
            );

            return response()->json([
                'message' => 'MFA verification required.',
                'code' => 'MFA_REQUIRED',
                'mfa_required' => true,
                'mfa_challenge' => $challengeToken,
                'role' => $user->role,
                'must_change_password' => $mustChangePassword,
                'must_change_password_expires_at' => $mustChangePasswordExpiresAt?->toIso8601String(),
            ]);
        }

        return $this->issueLoginSuccessResponse($user, $remember, $mustChangePassword, $mustChangePasswordExpiresAt);
    }

    public function redirectToEntra(Request $request)
    {
        $validated = $request->validate([
            'role' => 'nullable|string|in:admin,lawyer,adminstaff,junioradmin',
        ]);

        if (!$this->isEntraEnabled()) {
            return response()->json([
                'message' => 'Microsoft SSO is not configured yet.',
                'code' => 'SSO_NOT_CONFIGURED',
            ], 503);
        }

        $requestedRole = strtolower((string) ($validated['role'] ?? ''));
        $stateToken = Str::random(64);

        Cache::put(
            self::ENTRA_STATE_CACHE_PREFIX . $stateToken,
            [
                'role' => $requestedRole,
                'issued_at' => now()->toIso8601String(),
            ],
            now()->addMinutes(10)
        );

        $tenant = (string) config('services.microsoft.tenant_id', 'common');
        $authorizeEndpoint = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', rawurlencode($tenant));

        $query = http_build_query([
            'client_id' => (string) config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => (string) config('services.microsoft.redirect_uri'),
            // Use form_post to avoid oversized callback query strings returning 400 at the edge.
            'response_mode' => 'form_post',
            'scope' => 'openid profile email User.Read',
            'state' => $stateToken,
            'prompt' => (string) config('services.microsoft.prompt', 'login'),
        ]);

        return redirect()->away($authorizeEndpoint . '?' . $query);
    }

    public function handleEntraCallback(Request $request)
    {
        Log::info('Entra callback received.', [
            'method' => $request->method(),
            'content_type' => (string) $request->header('content-type', ''),
            'has_state' => $request->filled('state') || $request->query->has('state'),
            'has_code' => $request->filled('code') || $request->query->has('code'),
            'code_length' => strlen((string) $request->input('code', (string) $request->query('code', ''))),
        ]);

        $stateToken = (string) $request->input('state', (string) $request->query('state', ''));
        $cacheKey = self::ENTRA_STATE_CACHE_PREFIX . $stateToken;
        $statePayload = Cache::get($cacheKey);

        if (!is_array($statePayload)) {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => 'Invalid or expired SSO state. Please try signing in again.',
            ]));
        }

        Cache::forget($cacheKey);

        if ($request->filled('error')) {
            $errorDescription = (string) $request->query('error_description', 'SSO sign-in was cancelled or denied.');
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => $errorDescription,
            ]));
        }

        $authorizationCode = (string) $request->input('code', (string) $request->query('code', ''));

        if ($authorizationCode === '') {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => 'SSO authorization code is missing.',
            ]));
        }

        $tokenResponse = $this->exchangeEntraCodeForToken($authorizationCode);

        if (!$tokenResponse['ok']) {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => $tokenResponse['message'],
            ]));
        }

        $graphResponse = $this->fetchEntraProfile((string) $tokenResponse['access_token']);

        if (!$graphResponse['ok']) {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => $graphResponse['message'],
            ]));
        }

        $entraEmail = strtolower(trim((string) ($graphResponse['email'] ?? '')));
        $requestedRole = strtolower((string) ($statePayload['role'] ?? ''));

        if ($entraEmail === '') {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => 'Unable to resolve email from Microsoft account.',
            ]));
        }

        $user = User::whereRaw('LOWER(email) = ?', [$entraEmail])->first();

        if (!$user) {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => 'No ASALAW account is linked to this Microsoft email.',
            ]));
        }

        if ($requestedRole !== '' && strtolower((string) $user->role) !== $requestedRole) {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => 'Selected SSO role does not match your ASALAW account role.',
            ]));
        }

        if (strtolower((string) $user->status) === 'archived') {
            return redirect()->away($this->buildFrontendSsoRedirectUrl([
                'error' => 'Your account has been archived and cannot be used.',
            ]));
        }

        if ((int) $user->failed_login_attempts > 0 || !is_null($user->account_locked_at)) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'account_locked_at' => null,
            ])->save();
        }

        $mustChangePassword = (bool) $user->must_change_password;
        $mustChangePasswordExpiresAt = null;

        if ($mustChangePassword) {
            $generatedAt = $user->temporary_password_generated_at
                ? Carbon::parse($user->temporary_password_generated_at)
                : null;

            if (!$generatedAt || $generatedAt->copy()->addMinutes(10)->isPast()) {
                return redirect()->away($this->buildFrontendSsoRedirectUrl([
                    'error' => 'Your temporary password has expired. Please contact the admin to issue a new password.',
                ]));
            }

            $mustChangePasswordExpiresAt = $generatedAt->copy()->addMinutes(10);
        }

        $remember = true;
        $response = $this->issueLoginSuccessResponse($user, $remember, $mustChangePassword, $mustChangePasswordExpiresAt);
        $responseData = $response->getData(true);

        $encodedUser = $this->base64UrlEncode(json_encode($responseData['user'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');

        return redirect()->away($this->buildFrontendSsoRedirectUrl([
            'token' => (string) ($responseData['token'] ?? ''),
            'role' => (string) ($responseData['role'] ?? ''),
            'message' => (string) ($responseData['message'] ?? 'Login successful'),
            'must_change_password' => !empty($responseData['must_change_password']) ? '1' : '0',
            'must_change_password_expires_at' => (string) ($responseData['must_change_password_expires_at'] ?? ''),
            'user' => $encodedUser,
        ]));
    }

    public function verifyMfaLogin(Request $request)
    {
        $validated = $request->validate([
            'challenge' => 'required|string|max:255',
            'code' => 'required|string|max:20',
        ]);

        $cacheKey = self::MFA_CHALLENGE_CACHE_PREFIX . $validated['challenge'];
        $challenge = Cache::get($cacheKey);

        if (!is_array($challenge) || !isset($challenge['user_id'])) {
            return response()->json([
                'message' => 'MFA challenge expired. Please login again.',
                'code' => 'MFA_CHALLENGE_EXPIRED',
            ], 422);
        }

        $user = User::find((int) $challenge['user_id']);

        if (!$user || !$this->isClientMfaEnabled($user)) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => 'MFA challenge is no longer valid. Please login again.',
                'code' => 'MFA_CHALLENGE_INVALID',
            ], 422);
        }

        $secret = $this->decryptMfaSecret($user);

        if (!$secret || !$this->totpService->verify($secret, (string) $validated['code'])) {
            return response()->json([
                'message' => 'Invalid or expired authenticator code.',
                'code' => 'MFA_INVALID_CODE',
            ], 422);
        }

        Cache::forget($cacheKey);

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

        $remember = (bool) ($challenge['remember'] ?? false);

        return $this->issueLoginSuccessResponse($user, $remember, $mustChangePassword, $mustChangePasswordExpiresAt);
    }

    public function mfaSetupStart(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$this->isClientRole($user)) {
            return response()->json([
                'message' => 'MFA setup for this role is not available here.',
            ], 403);
        }

        $secret = $this->totpService->generateSecret(32);
        $issuer = (string) config('app.name', 'ASALAW');
        $accountName = (string) $user->email;
        $otpauthUrl = $this->totpService->getOtpAuthUrl($issuer, $accountName, $secret);

        $user->forceFill([
            'mfa_secret_encrypted' => Crypt::encryptString($secret),
            'mfa_enabled' => false,
            'mfa_confirmed_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Scan the QR code using Microsoft Authenticator and verify with a code.',
            'mfa_enabled' => false,
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'qr_code_url' => 'https://quickchart.io/qr?size=280&text=' . rawurlencode($otpauthUrl),
        ]);
    }

    public function mfaSetupConfirm(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$this->isClientRole($user)) {
            return response()->json([
                'message' => 'MFA setup for this role is not available here.',
            ], 403);
        }

        $secret = $this->decryptMfaSecret($user);

        if (!$secret) {
            return response()->json([
                'message' => 'No pending MFA setup found. Start setup again.',
            ], 422);
        }

        if (!$this->totpService->verify($secret, (string) $validated['code'])) {
            return response()->json([
                'message' => 'Invalid or expired authenticator code.',
                'code' => 'MFA_INVALID_CODE',
            ], 422);
        }

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Client MFA enabled successfully.',
            'mfa_enabled' => true,
        ]);
    }

    public function mfaDisable(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string|max:255',
            'code' => 'required|string|max:20',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$this->isClientRole($user)) {
            return response()->json([
                'message' => 'MFA disable for this role is not available here.',
            ], 403);
        }

        if (!Hash::check((string) $validated['password'], (string) $user->password)) {
            return response()->json([
                'message' => 'Invalid password.',
            ], 422);
        }

        $secret = $this->decryptMfaSecret($user);

        if (!$secret || !$this->totpService->verify($secret, (string) $validated['code'])) {
            return response()->json([
                'message' => 'Invalid or expired authenticator code.',
                'code' => 'MFA_INVALID_CODE',
            ], 422);
        }

        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret_encrypted' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        return response()->json([
            'message' => 'Client MFA disabled successfully.',
            'mfa_enabled' => false,
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

    private function isClientRole(User $user): bool
    {
        return strtolower((string) $user->role) === 'client';
    }

    private function requiresEntraSsoForRole(User $user): bool
    {
        if (!$this->isEntraEnabled()) {
            return false;
        }

        return in_array(strtolower((string) $user->role), ['admin', 'lawyer', 'adminstaff', 'junioradmin'], true);
    }

    private function isClientMfaEnabled(User $user): bool
    {
        return $this->isClientRole($user)
            && (bool) $user->mfa_enabled
            && !empty($user->mfa_secret_encrypted);
    }

    private function decryptMfaSecret(User $user): ?string
    {
        if (empty($user->mfa_secret_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $user->mfa_secret_encrypted);
        } catch (Throwable $exception) {
            Log::warning('Failed to decrypt MFA secret for user.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function issueLoginSuccessResponse(
        User $user,
        bool $remember,
        bool $mustChangePassword,
        ?Carbon $mustChangePasswordExpiresAt
    ) {
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

    private function isEntraEnabled(): bool
    {
        return (bool) config('services.microsoft.enabled', false)
            && !empty(config('services.microsoft.client_id'))
            && !empty(config('services.microsoft.client_secret'))
            && !empty(config('services.microsoft.redirect_uri'));
    }

    private function exchangeEntraCodeForToken(string $authorizationCode): array
    {
        $tenant = (string) config('services.microsoft.tenant_id', 'common');
        $tokenEndpoint = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode($tenant));

        try {
            $response = Http::asForm()->timeout(20)->post($tokenEndpoint, [
                'client_id' => (string) config('services.microsoft.client_id'),
                'client_secret' => (string) config('services.microsoft.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
                'redirect_uri' => (string) config('services.microsoft.redirect_uri'),
                'scope' => 'openid profile email User.Read',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Entra token exchange request failed.', [
                'error' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Unable to reach Microsoft SSO service.'];
        }

        if (!$response->ok()) {
            Log::warning('Entra token exchange failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'message' => 'Microsoft SSO token exchange failed.'];
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';

        if ($accessToken === '') {
            return ['ok' => false, 'message' => 'Microsoft SSO access token is missing.'];
        }

        return ['ok' => true, 'access_token' => $accessToken];
    }

    private function fetchEntraProfile(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->get('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName');
        } catch (Throwable $exception) {
            Log::warning('Failed to fetch Microsoft Graph profile.', [
                'error' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Unable to fetch Microsoft profile.'];
        }

        if (!$response->ok()) {
            Log::warning('Microsoft Graph profile request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'message' => 'Microsoft profile fetch failed.'];
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Invalid Microsoft profile response.'];
        }

        $email = strtolower(trim((string) ($payload['mail'] ?? $payload['userPrincipalName'] ?? '')));

        return [
            'ok' => true,
            'email' => $email,
        ];
    }

    private function buildFrontendSsoRedirectUrl(array $params): string
    {
        $frontendBaseUrl = rtrim((string) env('APP_FRONTEND_URL', 'http://localhost:3000'), '/');
        $callbackPath = (string) config('services.microsoft.frontend_callback_path', '/sso/callback');

        return $frontendBaseUrl . $callbackPath . '?' . http_build_query($params);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

