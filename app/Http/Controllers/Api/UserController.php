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
use Illuminate\Support\Facades\Mail;
use App\Models\FileMetadata;
use App\Models\UserPicture;

class UserController extends Controller
{
    // GET /api/users
    public function index(){
        $users = User::select('id', 'name', 'email', 'role', 'status', 'firmID', 'key')
            ->get()
            ->map(function ($user) {
                // Only for clients, check if they have at least one case
                $caseId = null;
                if ($user->role === 'client') {
                    $case = LawCase::where('clientID', $user->id)->first();
                    if ($case) {
                        $caseId = $case->caseId; // assign caseId if exists
                    }
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
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|unique:users,email',
            'username'       => 'required|string|max:50|unique:users,username',
            'password'       => 'required|min:8',
            'role'           => 'required|in:admin,client,lawyer',
            'age'            => 'required|integer|min:1',
            'ICNumber'       => 'required|string',
            'phoneNumber'    => 'required|string',
            'HomeAddress'    => 'required|string',
            'gender'         => 'required|in:Male,Female',
            'maritalStatus'  => 'required|in:Single,Married,Divorce',
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

        // ✅ Hash the password
        $validated['password'] = bcrypt($validated['password']);
        
        // ✅ Create the user
        $user = User::create($validated);

        // Upload user picture to Azure Storage and save path in MongoDB collection `user_picture`.
        $picture = $request->file('picture');
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

        // ✅ Send email to the user (with original password)
        Mail::to($user->email)->send(new UserRegisteredMail($user, $request->password));

        return response()->json([
            'message' => 'User created successfully',
            'user' => [
                ...$user->toArray(),
                'photo' => $photoUrl,
            ],
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

                return [
                    'id'          => $case->caseId,
                    'caseId'      => $case->caseId,
                    'caseName'    => $case->title,
                    'title'       => $case->title,
                    'description' => $case->description,
                    'status'      => $case->status,
                    'clientName' => $case->client?->name,
                    'lawyerName'  => $case->lawyer?->name,
                    'lawyerFirmID'=> $case->lawyerFirmID,
                    'clientFirmID'=> $case->clientFirmID,
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
                'photo'         => $this->resolveUserPhotoUrl((int) $client->id, (string) $client->firmID),
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

                return [
                    'id'         => $case->caseId,
                    'caseId'     => $case->caseId,
                    'caseName'   => $case->title,
                    'title'      => $case->title,
                    'description'=> $case->description,
                    'status'     => $case->status,
                    'clientName' => $case->client?->name,
                    'clientId' => $case->client?->id,
                    'lawyerId' => $case->lawyer?->id,
                    'lawyerName'  => $case->lawyer?->name,
                    'lawyerFirmID'=> $case->lawyerFirmID,
                    'clientFirmID'=> $case->clientFirmID,
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
                'photo'         => $this->resolveUserPhotoUrl((int) $lawyer->id, (string) $lawyer->firmID),
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
                'photo'         => $this->resolveUserPhotoUrl((int) $admin->id, (string) $admin->firmID),
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
        }

        if (!empty($normalized)) {
            $request->merge($normalized);
        }

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
        ]);

        if (array_key_exists('password', $validated)) {
            $validated['password'] = bcrypt($validated['password']);
        }

        // Update allowed fields only
        $user->update($validated);

        // New: return full updated user
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user  // full object with all fields
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
                    'preview_url' => "/api/encrypted-documents/{$documentId}/preview",
                    'download_url' => "/api/encrypted-documents/{$documentId}/download",
                    'delete_url' => "/api/encrypted-documents/{$documentId}",
                ];
            })
            ->all();
    }

    private function resolveUserPhotoUrl(int $userId, string $firmId): ?string
    {
        $picture = UserPicture::where('firm_id', $firmId)
            ->orWhere('user_id', $userId)
            ->latest('updated_at')
            ->first();

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