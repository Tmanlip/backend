<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\LawCase;
use App\Mail\UserRegisteredMail;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Services\UserKeyService;
use App\Services\AzureStorage;
use App\Services\InvoiceProgressService;
use Illuminate\Support\Facades\Mail;
use App\Models\FileMetadata;
use App\Models\UserPicture;
use App\Mail\AccountDeactivatedMail;

class UserController extends Controller
{
    public function __construct(private readonly InvoiceProgressService $invoiceProgressService)
    {
    }

    private function buildExpectedPaymentPhases(LawCase $case): array
    {
        return [
            'initial' => (float) ($case->expected_initial_payment ?? 0),
            'first' => (float) ($case->expected_first_payment ?? 0),
            'second' => (float) ($case->expected_second_payment ?? 0),
            'third' => (float) ($case->expected_third_payment ?? 0),
            'final' => (float) ($case->expected_final_payment ?? 0),
        ];
    }

    private function buildInvoicePaymentPhases(LawCase $case): array
    {
        $summaries = $this->invoiceProgressService->getStageSummaries((int) $case->caseId);
        $expectedByStage = $this->buildExpectedPaymentPhases($case);

        foreach ($expectedByStage as $stage => $expectedDefault) {
            if (!isset($summaries[$stage])) {
                $summaries[$stage] = [
                    'expected' => (float) $expectedDefault,
                    'paid' => 0.0,
                    'balance' => (float) $expectedDefault,
                ];
                continue;
            }

            if ((float) ($summaries[$stage]['expected'] ?? 0) <= 0 && (float) $expectedDefault > 0) {
                $paid = (float) ($summaries[$stage]['paid'] ?? 0);
                $summaries[$stage]['expected'] = (float) $expectedDefault;
                $summaries[$stage]['balance'] = round(max((float) $expectedDefault - $paid, 0.0), 2);
            }
        }

        return $summaries;
    }

    // GET /api/users
    public function index(){
        $users = User::select('id', 'name', 'email', 'role', 'status', 'firmID', 'key')->get();

        $clientIds = $users
            ->where('role', 'client')
            ->pluck('id')
            ->values()
            ->all();

        $caseIdByClientId = [];
        if (!empty($clientIds)) {
            $caseIdByClientId = LawCase::whereIn('clientID', $clientIds)
                ->orderBy('caseId')
                ->get(['clientID', 'caseId'])
                ->groupBy('clientID')
                ->map(function ($rows) {
                    return (int) $rows->first()->caseId;
                })
                ->toArray();
        }

        $users = $users->map(function ($user) use ($caseIdByClientId) {
            $caseId = null;
            if ($user->role === 'client') {
                $caseId = $caseIdByClientId[(int) $user->id] ?? null;
            }

            return [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'role'     => $user->role,
                'status'   => $user->status,
                'firmID'   => $user->firmID,
                'caseId'   => $caseId,
                'key'      => $user->key
            ];
        });

        return response()->json($users);
    }

    // POST /api/registerusers
    public function store(Request $request){
        // Backward compatibility: accept legacy frontend value and normalize.
        if ($request->input('maritalStatus') === 'Divorce') {
            $request->merge(['maritalStatus' => 'Divorced']);
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|unique:users,email',
            'username'       => 'required|string|max:50|unique:users,username',
            'role'           => 'required|in:admin,adminstaff,junioradmin,client,lawyer',
            'age'            => 'required|integer|min:1',
            'ICNumber'       => 'required|string',
            'phoneNumber'    => 'required|string',
            'HomeAddress'    => 'required|string',
            'gender'         => 'required|in:Male,Female',
            'maritalStatus'  => 'required|in:Single,Married,Divorced',
            'picture'        => [
                'required',
                'file',
                'image',
                'mimes:jpeg,jpg,png',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (!$value || !method_exists($value, 'getRealPath')) {
                        return;
                    }

                    $imageSize = @getimagesize($value->getRealPath());
                    if (!$imageSize) {
                        $fail('The picture must be a valid image file.');
                        return;
                    }

                    [$width, $height] = $imageSize;
                    if ($width < 350 || $height < 450) {
                        $fail('The picture must be at least 350x450 pixels (passport size).');
                        return;
                    }

                    $passportRatio = 35 / 45;
                    $actualRatio = $width / $height;
                    if (abs($actualRatio - $passportRatio) > 0.08) {
                        $fail('The picture must follow passport ratio (35:45).');
                    }
                },
            ],
        ]);

        // ✅ Generate secure random AES key for document encryption
        $validated['key'] = UserKeyService::generateKey();

        // ✅ Generate RSA key pair
        $rsaKeys = UserKeyService::generateRsaKeyPair();
        $validated['rsa_private_key'] = $rsaKeys['encryptedPrivateKey'];
        $validated['rsa_public_key'] = $rsaKeys['publicKey'];

        // ✅ Auto-generate initial password and store only the hash.
        $generatedPassword = Str::password(12, true, true, false, false);
        $validated['password'] = bcrypt($generatedPassword);
        $validated['must_change_password'] = true;
        $validated['temporary_password_generated_at'] = now();
        
        $user = null;
        $picture = $request->file('picture');

        try {
            // ✅ Create the user
            $user = User::create($validated);

            // Upload user picture to Azure Storage and save path in MongoDB collection `user_picture`.
            $extension = strtolower($picture->getClientOriginalExtension() ?: 'jpg');
            $blobPath = "aslaw-picture/{$user->firmID}.{$extension}";
            AzureStorage::put($blobPath, file_get_contents($picture->getRealPath()));

            $photoUrl = AzureStorage::url($blobPath);
            UserPicture::updateOrCreate(
                ['firm_id' => $user->firmID],
                [
                    'user_id' => $user->id,
                    'firm_id' => $user->firmID,
                    'blob_path' => $blobPath,
                    'photo_url' => $photoUrl,
                    'mime_type' => $picture->getClientMimeType(),
                    'size_bytes' => $picture->getSize(),
                ]
            );
        } catch (\Throwable $e) {
            if ($user) {
                try {
                    $user->delete();
                } catch (\Throwable $cleanupException) {
                    logger()->warning('Failed to rollback partially created user after storage error.', [
                        'user_id' => $user->id,
                        'message' => $cleanupException->getMessage(),
                    ]);
                }
            }

            logger()->error('User registration failed while uploading passport picture.', [
                'email' => $request->input('email'),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to reach image storage service right now. Please try again shortly.',
            ], 503);
        }

        // ✅ Send email to the user (with generated password)
        $emailSent = true;
        $emailWarning = null;
        $emailErrorCode = null;
        $fallbackPassword = null;

        try {
            Mail::to($user->email)->send(new UserRegisteredMail($user, $generatedPassword));
        } catch (\Throwable $e) {
            $emailSent = false;
            $emailErrorCode = $this->classifyMailFailure($e);
            $emailWarning = $this->buildMailFailureMessage($e, $emailErrorCode);

            if (config('app.debug')) {
                // Local/dev fallback to avoid locking out newly created users when SMTP is unavailable.
                $fallbackPassword = $generatedPassword;
            }

            logger()->warning('User registration email could not be sent.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $emailSent
                ? 'User created successfully'
                : 'User created, but email delivery failed',
            'email_sent' => $emailSent,
            'email_warning' => $emailWarning,
            'email_error_code' => $emailErrorCode,
            'user' => [
                ...$user->toArray(),
                'photo' => 'data:' . ($picture->getClientMimeType() ?: 'image/jpeg') . ';base64,' . base64_encode(file_get_contents($picture->getRealPath())),
                'photo_url' => $photoUrl,
                'photo_blob_path' => $blobPath,
            ],
            'generated_password' => $fallbackPassword,
        ], 201);
    }

    // ============================
    // CLIENT FULL DATA
    // GET /api/clients/{firmID}
    // ============================
    public function getClientFullData(string $firmID)
    {
        $client = User::where('firmID', $firmID)
            ->where('role', 'client')
            ->first();

        if (!$client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        $cases = LawCase::where('clientID', $client->id)
            ->with('lawyer:id,name')
            ->get()
            ->map(function ($case) use ($client) {
                $encryptedDocuments = $this->getCaseEncryptedDocuments((int) $case->caseId, (int) $client->id);
                $freshProgress = $this->invoiceProgressService->recalculateForCase((int) $case->caseId);

                return [
                    'id'          => $case->caseId,
                    'caseId'      => $case->caseId,
                    'caseNumber'  => $case->caseNumber,
                    'caseName'    => $case->title,
                    'title'       => $case->title,
                    'caseType'    => (string) ($case->caseType ?? 'Litigation'),
                    'description' => $case->description,
                    'status'      => $case->status,
                    'progress'    => $freshProgress,
                    'case_type_fee_json' => $case->case_type_fee_json ?? [
                        'initial' => [],
                        'first' => [],
                        'second' => [],
                        'third' => [],
                        'final' => [],
                    ],
                    'expected_payment_phases' => $this->buildExpectedPaymentPhases($case),
                    'invoice_payment_phases' => $this->buildInvoicePaymentPhases($case),
                    'clientName' => $case->client?->name,
                    'lawyerName'  => $case->lawyer?->name,
                    'lawyerFirmID'=> $case->lawyerFirmID,
                    'clientFirmID'=> $case->clientFirmID,
                    'oppositionLawyerName' => $case->oppositionLawyerName,
                    'oppositionLawyerFirmID' => $case->oppositionLawyerFirmID,
                    'created_at'  => $case->created_at,
                    'updated_at'  => $case->updated_at,
                    'blob_folder_path' => "cases/{$case->caseId}/",
                    'encrypted_documents' => $encryptedDocuments,
                ];
            });

        return response()->json([
            'client' => [
                'id'            => $client->id,
                'firmID'        => $client->firmID,
                'name'          => $client->name,
                'email'         => $client->email,
                'username'      => $client->username,
                'age'           => $client->age,
                'ICNumber'      => $client->ICNumber,
                'phoneNumber'   => $client->phoneNumber,
                'HomeAddress'   => $client->HomeAddress,
                'gender'        => $client->gender,
                'maritalStatus' => $client->maritalStatus,
                'status'        => $client->status,
                'created_at'    => $client->created_at,
                'photo'         => null,
                'photo_url'     => $this->resolveUserPhotoUrl((int) $client->id, (string) $client->firmID),
                'photo_blob_path' => $this->resolveUserPhotoBlobPath((int) $client->id, (string) $client->firmID),
            ],
            'cases' => $cases
        ]);
    }

    // ============================
    // LAWYER FULL DATA (NEW)
    // GET /api/lawyers/{firmID}
    // ============================
    public function getLawyerFullData(string $firmID)
    {
        $lawyer = User::where('firmID', $firmID)
            ->where('role', 'lawyer')
            ->first();

        if (!$lawyer) {
            return response()->json(['message' => 'Lawyer not found'], 404);
        }

        $cases = LawCase::where('lawyerID', $lawyer->id)
            ->with('client:id,name')
            ->get()
            ->map(function ($case) use ($lawyer) {

                $metadata = \App\Models\Metadata::cases()
                    ->where('case_id', (string) $case->caseId)
                    ->first();

                $encryptedDocuments = $this->getCaseEncryptedDocuments((int) $case->caseId, (int) $lawyer->id);
                $freshProgress = $this->invoiceProgressService->recalculateForCase((int) $case->caseId);

                return [
                    'id'         => $case->caseId,
                    'caseId'     => $case->caseId,
                    'caseNumber' => $case->caseNumber,
                    'caseName'   => $case->title,
                    'title'      => $case->title,
                    'caseType'   => (string) ($case->caseType ?? 'Litigation'),
                    'description'=> $case->description,
                    'status'     => $case->status,
                    'progress'   => $freshProgress,
                    'case_type_fee_json' => $case->case_type_fee_json ?? [
                        'initial' => [],
                        'first' => [],
                        'second' => [],
                        'third' => [],
                        'final' => [],
                    ],
                    'expected_payment_phases' => $this->buildExpectedPaymentPhases($case),
                    'invoice_payment_phases' => $this->buildInvoicePaymentPhases($case),
                    'clientName' => $case->client?->name,
                    'clientId' => $case->client?->id,
                    'lawyerId' => $case->lawyer?->id,
                    'lawyerName'  => $case->lawyer?->name,
                    'lawyerFirmID'=> $case->lawyerFirmID,
                    'clientFirmID'=> $case->clientFirmID,
                    'oppositionLawyerName' => $case->oppositionLawyerName,
                    'oppositionLawyerFirmID' => $case->oppositionLawyerFirmID,
                    'created_at' => $case->created_at,
                    'updated_at' => $case->updated_at,
                    'blob_folder_path'=> $metadata?->blob_folder_path ?? null,
                    'encrypted_documents' => $encryptedDocuments,
                ];
            });

        return response()->json([
            'lawyer' => [
                'id'            => $lawyer->id,
                'firmID'        => $lawyer->firmID,
                'name'          => $lawyer->name,
                'email'         => $lawyer->email,
                'username'      => $lawyer->username,
                'age'           => $lawyer->age,
                'ICNumber'      => $lawyer->ICNumber,
                'phoneNumber'   => $lawyer->phoneNumber,
                'HomeAddress'   => $lawyer->HomeAddress,
                'gender'        => $lawyer->gender,
                'maritalStatus' => $lawyer->maritalStatus,
                'status'        => $lawyer->status,
                'created_at'    => $lawyer->created_at,
                'photo'         => null,
                'photo_url'     => $this->resolveUserPhotoUrl((int) $lawyer->id, (string) $lawyer->firmID),
                'photo_blob_path' => $this->resolveUserPhotoBlobPath((int) $lawyer->id, (string) $lawyer->firmID),
            ],
            'cases' => $cases
        ]);
    }

    // ============================
    // ADMIN FULL DATA (NEW)
    // GET /api/admins/{firmID}
    // ============================
    public function getAdminFullData(string $firmID)
    {
        $admin = User::where('firmID', $firmID)
            ->where('role', 'admin')
            ->first();

        if (!$admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }

        // admins may not have cases, but we keep the structure consistent
        return response()->json([
            'admin' => [
                'id'            => $admin->id,
                'firmID'        => $admin->firmID,
                'name'          => $admin->name,
                'email'         => $admin->email,
                'username'      => $admin->username,
                'age'           => $admin->age,
                'ICNumber'      => $admin->ICNumber,
                'phoneNumber'   => $admin->phoneNumber,
                'HomeAddress'   => $admin->HomeAddress,
                'gender'        => $admin->gender,
                'maritalStatus' => $admin->maritalStatus,
                'status'        => $admin->status,
                'created_at'    => $admin->created_at,
                'photo'         => null,
                'photo_url'     => $this->resolveUserPhotoUrl((int) $admin->id, (string) $admin->firmID),
                'photo_blob_path' => $this->resolveUserPhotoBlobPath((int) $admin->id, (string) $admin->firmID),
            ],
            'cases' => [], // empty array
        ]);
    }

    // PUT /api/users/{firmID}
    public function update(Request $request, string $firmID){
        $user = User::where('firmID', $firmID)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $normalizeText = static function ($value): ?string {
            if ($value === null) {
                return null;
            }

            if (is_string($value)) {
                return $value;
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value);
        };

        // Accept both camelCase and snake_case payloads from different frontend screens,
        // but only merge fields that are explicitly provided in the request.
        $normalized = [];

        if ($request->exists('phoneNumber') || $request->exists('phone_number')) {
            $normalized['phoneNumber'] = $normalizeText($request->input('phoneNumber', $request->input('phone_number')));
        }

        if ($request->exists('HomeAddress') || $request->exists('home_address')) {
            $normalized['HomeAddress'] = $normalizeText($request->input('HomeAddress', $request->input('home_address')));
        }

        if ($request->exists('ICNumber') || $request->exists('ic_number')) {
            $normalized['ICNumber'] = $normalizeText($request->input('ICNumber', $request->input('ic_number')));
        }

        if ($request->exists('maritalStatus') || $request->exists('marital_status')) {
            $normalized['maritalStatus'] = $normalizeText($request->input('maritalStatus', $request->input('marital_status')));

            if ($normalized['maritalStatus'] === 'Divorce') {
                $normalized['maritalStatus'] = 'Divorced';
            }
        }

        if (!empty($normalized)) {
            $request->merge($normalized);
        }

        $shouldResetPassword = filter_var(
            $request->input('reset_password', $request->input('resetPassword', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'email'          => 'sometimes|email|unique:users,email,' . $user->id,
            'username'       => 'sometimes|string|max:50|unique:users,username,' . $user->id,
            'age'            => 'sometimes|nullable|integer|min:1',
            'ICNumber'       => 'sometimes|nullable|string',
            'phoneNumber'    => 'sometimes|nullable|string',
            'HomeAddress'    => 'sometimes|nullable|string',
            'gender'         => 'sometimes|in:Male,Female',
            'maritalStatus'  => 'sometimes|nullable|in:Single,Married,Divorced',
            'status'         => 'sometimes|in:Active,Inactive,Archived',
            'password'       => 'sometimes|string|min:8',
            'resetPassword'  => 'sometimes|boolean',
            'reset_password' => 'sometimes|boolean',
        ]);

        $generatedPassword = null;
        if ($shouldResetPassword) {
            // Keep reset generation behavior aligned with user registration.
            $generatedPassword = Str::password(12, true, true, false, false);
            $validated['password'] = $generatedPassword;
            $validated['must_change_password'] = true;
            $validated['temporary_password_generated_at'] = now();
            $validated['failed_login_attempts'] = 0;
            $validated['account_locked_at'] = null;
        }

        unset($validated['resetPassword'], $validated['reset_password']);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = bcrypt($validated['password']);

            if (!$shouldResetPassword) {
                // Password changed by the user from profile reset flow.
                $validated['must_change_password'] = false;
                $validated['temporary_password_generated_at'] = null;
                $validated['failed_login_attempts'] = 0;
                $validated['account_locked_at'] = null;
            }
        }

        $attemptedEmailChange = array_key_exists('email', $validated)
            && strtolower(trim((string) $validated['email'])) !== strtolower(trim((string) $user->email));
        $attemptedUsernameChange = array_key_exists('username', $validated)
            && trim((string) $validated['username']) !== trim((string) $user->username);

        if ($attemptedEmailChange || $attemptedUsernameChange) {
            return response()->json([
                'message' => 'Email and username cannot be changed from manage profile.',
            ], 422);
        }

        unset($validated['email'], $validated['username']);

        // Check if status is being changed to archived
        $wasArchivedJustNow = isset($validated['status']) && strtolower((string) $validated['status']) === 'archived' && strtolower((string) $user->status) !== 'archived';

        // Update allowed fields only
        $user->update($validated);

        // Send deactivation email if account was just deactivated
        if ($wasArchivedJustNow) {
            try {
                Mail::to($user->email)->send(new AccountDeactivatedMail($user->name));
            } catch (\Throwable $e) {
                // Log the error but don't fail the request
                \Illuminate\Support\Facades\Log::warning('Account deactivation email failed', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($generatedPassword !== null) {
            try {
                Mail::to($user->email)->send(new UserRegisteredMail($user, $generatedPassword));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Reset password email failed', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // New: return full updated user
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,  // full object with all fields
            'password_reset' => $generatedPassword !== null,
            'password_emailed' => $generatedPassword !== null,
        ]);
    }

    // GET /api/lawyers
    public function getAllLawyers(){
        $lawyers = User::select('id', 'name', 'email', 'firmID', 'status')
            ->where('role', 'lawyer')
            ->get();

        return response()->json($lawyers);
    }

    // GET /api/clients
    public function getAllClients(){
        $clients = User::select('id', 'name', 'email', 'firmID', 'status')
            ->where('role', 'client')
            ->get();

        return response()->json($clients);
    }

    public function getPublicKey(string $firmID){
        $user = User::where('firmID', $firmID)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Return the stored public key
        return response()->json([
            'publicKey' => UserKeyService::extractPemBody($user->rsa_public_key)
        ]);
    }

    private function getCaseEncryptedDocuments(int $caseId, int $actorUserId)
    {
        return FileMetadata::where('type', 'encrypted_document')
            ->where('case_id', $caseId)
            ->where('status', '!=', 'deleted')
            ->get()
            ->filter(function ($document) use ($actorUserId) {
                $recipients = $document->recipients ?? [];

                foreach ($recipients as $recipient) {
                    if ((int) ($recipient['recipient_user_id'] ?? 0) === $actorUserId && (bool) ($recipient['is_active'] ?? false) === true) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->map(function ($document) {
                $documentId = (string) $document->getKey();

                return [
                    'document_id' => $documentId,
                    'file_name' => $document->file_name,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => (int) ($document->size_bytes ?? 0),
                    'category' => (string) ($document->category ?? 'documents'),
                    'status' => (string) ($document->status ?? 'active'),
                    'created_at' => $document->created_at,
                    'invoice_stage' => (string) ($document->invoice_stage ?? ''),
                    'type_of_work' => (string) ($document->type_of_work ?? ''),
                    'paid_amount' => (float) ($document->paid_amount ?? 0),
                    'preview_url' => "/api/encrypted-documents/{$documentId}/preview",
                    'download_url' => "/api/encrypted-documents/{$documentId}/download",
                    'delete_url' => "/api/encrypted-documents/{$documentId}",
                ];
            })
            ->all();
    }

    private function classifyMailFailure(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'failed to authenticate on smtp server') || str_contains($message, 'code "535"')) {
            return 'smtp_auth_failed';
        }

        if (str_contains($message, 'connection could not be established') || str_contains($message, 'stream_socket_client')) {
            return 'smtp_connection_failed';
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'maximum execution time')) {
            return 'smtp_timeout';
        }

        return 'mail_send_failed';
    }

    private function buildMailFailureMessage(\Throwable $e, string $errorCode): string
    {
        $base = match ($errorCode) {
            'smtp_auth_failed' => 'User was created, but email was not sent because SMTP login was rejected. Check MAIL_USERNAME and MAIL_PASSWORD.',
            'smtp_connection_failed' => 'User was created, but email was not sent because the SMTP server could not be reached. Check MAIL_HOST, MAIL_PORT, and network/firewall rules.',
            'smtp_timeout' => 'User was created, but email sending timed out. Check SMTP service responsiveness and timeout settings.',
            default => 'User was created, but email delivery failed due to a mail transport error.',
        };

        if (config('app.debug')) {
            return $base.' Details: '.$e->getMessage();
        }

        return $base;
    }

    private function findUserPicture(int $userId, string $firmId): ?UserPicture
    {
        return UserPicture::where('firm_id', $firmId)
            ->orWhere('user_id', $userId)
            ->latest('updated_at')
            ->first();
    }

    private function resolveUserPhotoBlobPath(int $userId, string $firmId): ?string
    {
        $picture = $this->findUserPicture($userId, $firmId);

        if (!$picture || empty($picture->blob_path)) {
            return null;
        }

        return (string) $picture->blob_path;
    }

    private function resolveUserPhotoUrl(int $userId, string $firmId): ?string
    {
        $picture = $this->findUserPicture($userId, $firmId);

        if (!$picture) {
            return null;
        }

        if (!empty($picture->photo_url)) {
            return (string) $picture->photo_url;
        }

        if (!empty($picture->blob_path)) {
            return AzureStorage::url((string) $picture->blob_path);
        }

        return null;
    }
}