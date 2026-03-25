<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileMetadata;
use App\Models\LawCase;
use App\Models\User;
use App\Services\AzureStorage;
use App\Services\DocumentEnvelopeCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class EncryptedDocumentController extends Controller
{
    public function __construct(private readonly DocumentEnvelopeCryptoService $crypto)
    {
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:51200',
            'case_id' => 'required|integer',
            'category' => 'nullable|in:documents,reports,cheques',
            'recipient_user_ids' => 'nullable|array',
            'recipient_user_ids.*' => 'integer',
        ]);

        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $case = LawCase::find($validated['case_id']);
        if (!$case) {
            return response()->json(['message' => 'Case not found'], 404);
        }

        if (!$this->canManageCase($actor->id, $case)) {
            return response()->json(['message' => 'Forbidden for this case'], 403);
        }

        if ($denied = $this->denyIfArchived($case)) {
            return $denied;
        }

        $recipients = $this->resolveRecipients(
            $validated['recipient_user_ids'] ?? [],
            $case,
            (int) $actor->id
        );

        if (count($recipients) === 0) {
            return response()->json(['message' => 'No valid recipients found'], 422);
        }

        $uploadedFile = $request->file('file');
        $plainContent = file_get_contents($uploadedFile->getPathname());
        if ($plainContent === false) {
            return response()->json(['message' => 'Unable to read uploaded file'], 422);
        }

        $dek = $this->crypto->generateDek();
        $encrypted = $this->crypto->encrypt($plainContent, $dek);
        $category = (string) ($validated['category'] ?? 'documents');

        $blobPath = sprintf(
            'cases/%d/%s/encrypted/%s.enc',
            $case->caseId,
            $category,
            Str::uuid()->toString()
        );

        AzureStorage::put($blobPath, $encrypted['cipherText']);

        $recipientEntries = [];
        foreach ($recipients as $recipient) {
            $wrapped = $this->crypto->wrapDek($dek, (string) $recipient->rsa_public_key);

            $recipientEntries[] = [
                'recipient_user_id' => (int) $recipient->id,
                'recipient_role' => strtolower((string) $recipient->role),
                'key_algorithm' => $wrapped['keyAlgorithm'],
                'wrapped_dek' => $wrapped['wrappedDek'],
                'key_fingerprint' => $wrapped['keyFingerprint'],
                'is_active' => true,
            ];
        }

        $document = FileMetadata::create([
            'type' => 'encrypted_document',
            'case_id' => (int) $case->caseId,
            'category' => $category,
            'uploader_user_id' => (int) $actor->id,
            'blob_path' => $blobPath,
            'file_name' => $uploadedFile->getClientOriginalName(),
            'mime_type' => (string) $uploadedFile->getMimeType(),
            'size_bytes' => (int) $uploadedFile->getSize(),
            'content_hash_sha256' => hash('sha256', $plainContent),
            'cipher' => $encrypted['cipher'],
            'nonce' => $encrypted['nonce'],
            'tag' => $encrypted['tag'],
            'server_encrypted_dek' => Crypt::encryptString(base64_encode($dek)),
            'dek_version' => 1,
            'status' => 'active',
            'recipients' => $recipientEntries,
        ]);

        $documentId = (string) $document->getKey();

        return response()->json([
            'message' => 'Encrypted document uploaded successfully',
            'document_id' => $documentId,
            'category' => $category,
            'storage_path' => $blobPath,
            'recipient_count' => count($recipients),
        ], 201);
    }

    public function getPayload(Request $request, string $documentId): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document' || strtolower((string) $document->status) !== 'active') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $recipientKey = $this->findActiveRecipient($document, (int) $actor->id);

        if (!$recipientKey) {
            return response()->json(['message' => 'No access to this document'], 403);
        }

        $cipherContent = AzureStorage::get((string) $document->blob_path);
        if ($cipherContent === null) {
            return response()->json(['message' => 'Encrypted file not found in storage'], 404);
        }

        return response()->json([
            'document_id' => (string) $document->getKey(),
            'file_name' => $document->file_name,
            'mime_type' => $document->mime_type,
            'cipher' => $document->cipher,
            'nonce' => $document->nonce,
            'tag' => $document->tag,
            'key_algorithm' => $recipientKey['key_algorithm'],
            'wrapped_dek' => $recipientKey['wrapped_dek'],
            'ciphertext' => base64_encode($cipherContent),
            'note' => 'Decrypt client-side using your private key and AES-256-GCM metadata.',
        ]);
    }

    public function share(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'recipient_user_ids' => 'required|array|min:1',
            'recipient_user_ids.*' => 'integer',
        ]);

        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $case = LawCase::find((int) $document->case_id);
        if (!$case || !$this->canManageCase((int) $actor->id, $case, (int) $document->uploader_user_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($denied = $this->denyIfArchived($case)) {
            return $denied;
        }

        $newRecipients = User::whereIn('id', $validated['recipient_user_ids'])
            ->whereNotNull('rsa_public_key')
            ->get();

        if ($newRecipients->isEmpty()) {
            return response()->json(['message' => 'No valid recipients with public keys'], 422);
        }

        try {
            $dek = base64_decode(Crypt::decryptString((string) $document->server_encrypted_dek), true);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to recover document key for re-share'], 500);
        }

        if ($dek === false || strlen($dek) !== 32) {
            return response()->json(['message' => 'Recovered DEK is invalid'], 500);
        }

        $recipientMap = [];
        foreach (($document->recipients ?? []) as $entry) {
            if (isset($entry['recipient_user_id'])) {
                $recipientMap[(int) $entry['recipient_user_id']] = $entry;
            }
        }

        $added = 0;
        foreach ($newRecipients as $recipient) {
            $wrapped = $this->crypto->wrapDek($dek, (string) $recipient->rsa_public_key);
            $recipientMap[(int) $recipient->id] = [
                'recipient_user_id' => (int) $recipient->id,
                'recipient_role' => strtolower((string) $recipient->role),
                'key_algorithm' => $wrapped['keyAlgorithm'],
                'wrapped_dek' => $wrapped['wrappedDek'],
                'key_fingerprint' => $wrapped['keyFingerprint'],
                'is_active' => true,
            ];

            $added++;
        }

        $document->recipients = array_values($recipientMap);
        $document->save();

        return response()->json([
            'message' => 'Document shared successfully',
            'document_id' => (string) $document->getKey(),
            'updated_recipient_count' => $added,
        ]);
    }

    public function revoke(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'recipient_user_id' => 'required|integer',
        ]);

        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $case = LawCase::find((int) $document->case_id);
        if (!$case || !$this->canManageCase((int) $actor->id, $case, (int) $document->uploader_user_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($denied = $this->denyIfArchived($case)) {
            return $denied;
        }

        $recipientId = (int) $validated['recipient_user_id'];
        $recipients = $document->recipients ?? [];
        $found = false;

        foreach ($recipients as &$entry) {
            if ((int) ($entry['recipient_user_id'] ?? 0) === $recipientId && (bool) ($entry['is_active'] ?? false) === true) {
                $entry['is_active'] = false;
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            return response()->json(['message' => 'Recipient access key not found or already revoked'], 404);
        }

        $document->recipients = $recipients;
        $document->save();

        return response()->json([
            'message' => 'Recipient access revoked successfully',
            'document_id' => (string) $document->getKey(),
            'recipient_user_id' => (int) $validated['recipient_user_id'],
        ]);
    }

    public function destroy(Request $request, string $documentId): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $case = LawCase::find((int) $document->case_id);
        if (!$case || !$this->canManageCase((int) $actor->id, $case, (int) $document->uploader_user_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($denied = $this->denyIfArchived($case)) {
            return $denied;
        }

        $blobDeleted = true;
        try {
            AzureStorage::delete((string) $document->blob_path);
        } catch (\Throwable $e) {
            $blobDeleted = false;
        }

        $document->delete();

        return response()->json([
            'message' => 'Encrypted document deleted successfully',
            'document_id' => $documentId,
            'blob_deleted' => $blobDeleted,
        ]);
    }

    public function preview(Request $request, string $documentId)
    {
        $resolved = $this->resolveDocumentAndActorAccess($request, $documentId);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        ['document' => $document, 'plaintext' => $plainContent] = $resolved;

        return response($plainContent, 200)
            ->header('Content-Type', (string) ($document->mime_type ?: 'application/octet-stream'))
            ->header('Content-Disposition', 'inline; filename="' . (string) $document->file_name . '"');
    }

    public function download(Request $request, string $documentId)
    {
        $resolved = $this->resolveDocumentAndActorAccess($request, $documentId);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        ['document' => $document, 'plaintext' => $plainContent] = $resolved;

        return response($plainContent, 200)
            ->header('Content-Type', (string) ($document->mime_type ?: 'application/octet-stream'))
            ->header('Content-Disposition', 'attachment; filename="' . (string) $document->file_name . '"');
    }

    private function resolveRecipients(array $requestedRecipientIds, LawCase $case, int $actorId)
    {
        $ids = $requestedRecipientIds;

        if (count($ids) === 0) {
            $ids = [
                (int) $case->lawyerID,
                (int) $case->clientID,
                $actorId,
            ];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        return User::whereIn('id', $ids)
            ->whereNotNull('rsa_public_key')
            ->get();
    }

    private function canManageCase(int $actorId, LawCase $case, ?int $uploaderUserId = null): bool
    {
        if ($uploaderUserId !== null && $actorId === $uploaderUserId) {
            return true;
        }

        return $actorId === (int) $case->lawyerID || $actorId === (int) $case->clientID;
    }

    private function denyIfArchived(LawCase $case): ?JsonResponse
    {
        if (strtolower((string) $case->status) === 'archived') {
            return response()->json([
                'message' => 'This case is archived. Upload, delete, and sharing actions are blocked.'
            ], 403);
        }

        return null;
    }

    private function findActiveRecipient(FileMetadata $document, int $recipientUserId): ?array
    {
        foreach (($document->recipients ?? []) as $entry) {
            if ((int) ($entry['recipient_user_id'] ?? 0) !== $recipientUserId) {
                continue;
            }

            if ((bool) ($entry['is_active'] ?? false) !== true) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    private function resolveDocumentAndActorAccess(Request $request, string $documentId): JsonResponse|array
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document' || strtolower((string) $document->status) !== 'active') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $recipientKey = $this->findActiveRecipient($document, (int) $actor->id);
        if (!$recipientKey) {
            return response()->json(['message' => 'No access to this document'], 403);
        }

        $cipherContent = AzureStorage::get((string) $document->blob_path);
        if ($cipherContent === null) {
            return response()->json(['message' => 'Encrypted file not found in storage'], 404);
        }

        try {
            $dek = base64_decode(Crypt::decryptString((string) $document->server_encrypted_dek), true);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Unable to recover document key'], 500);
        }

        if ($dek === false || strlen($dek) !== 32) {
            return response()->json(['message' => 'Recovered DEK is invalid'], 500);
        }

        try {
            $plainContent = $this->crypto->decrypt(
                $cipherContent,
                $dek,
                (string) $document->nonce,
                (string) $document->tag
            );
        } catch (Throwable $e) {
            return response()->json(['message' => 'Unable to decrypt document content'], 500);
        }

        return [
            'document' => $document,
            'plaintext' => $plainContent,
        ];
    }
}
