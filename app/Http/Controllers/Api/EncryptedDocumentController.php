<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileMetadata;
use App\Models\Invoice;
use App\Models\LawCase;
use App\Models\User;
use App\Services\CaseNotificationService;
use App\Services\AzureStorage;
use App\Services\DocumentEnvelopeCryptoService;
use App\Services\DocumentGeneratorService;
use App\Services\InvoiceProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class EncryptedDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentEnvelopeCryptoService $crypto,
        private readonly InvoiceProgressService $invoiceProgressService,
        private readonly CaseNotificationService $caseNotificationService,
        private readonly DocumentGeneratorService $documentGeneratorService
    )
    {
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:51200',
            'case_id' => 'required|integer',
            'category' => 'nullable|in:documents,reports,invoices',
            'recipient_user_ids' => 'nullable|array',
            'recipient_user_ids.*' => 'integer',
            'invoice_stage' => 'nullable|in:initial,first,second,third,final',
            'payment_stage' => 'nullable|string',
            'type_of_work' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:255',
            'issue_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'client_name' => 'nullable|string|max:255',
            'case_title' => 'nullable|string|max:255',
            'expected_amount' => 'nullable|numeric',
            'paid_amount' => 'nullable|numeric',
            'balance' => 'nullable|numeric',
            'tax' => 'nullable|numeric',
            'discount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'clientID' => 'nullable|string|max:100',
            'lawyerID' => 'nullable|string|max:100',
            'blob_path' => 'nullable|string|max:1000',
        ]);

        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (strtolower((string) $actor->role) === 'junioradmin') {
            return response()->json(['message' => 'Junior admin cannot upload documents'], 403);
        }

        $case = LawCase::find($validated['case_id']);
        if (!$case) {
            return response()->json(['message' => 'Case not found'], 404);
        }

        $category = (string) ($validated['category'] ?? 'documents');
        $isInvoiceCategory = $category === 'invoices';

        if (!$this->canManageCase($actor->id, $case)) {
            return response()->json(['message' => 'Forbidden for this case'], 403);
        }

        if ($isInvoiceCategory && !in_array(strtolower((string) $actor->role), ['admin', 'adminstaff'], true)) {
            return response()->json(['message' => 'Only admins can upload invoices'], 403);
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

        $contentHash = hash('sha256', $plainContent);
        if ($this->hasDocumentConflict(
            (int) $case->caseId,
            $category,
            (string) $uploadedFile->getClientOriginalName(),
            $contentHash
        )) {
            return response()->json([
                'message' => 'A similar document already exists and is awaiting/has passed review.',
            ], 409);
        }

        $workflowDecision = $this->resolveUploadWorkflowDecision(
            $actor,
            $case,
            $category,
            (string) $uploadedFile->getClientOriginalName(),
            (string) ($uploadedFile->getMimeType() ?? ''),
            (int) $uploadedFile->getSize()
        );
        $documentStatus = $workflowDecision['requires_approval'] ? 'pending_approval' : 'active';

        $dek = $this->crypto->generateDek();
        $encrypted = $this->crypto->encrypt($plainContent, $dek);
        $invoiceStage = null;
        $expectedAmount = null;
        $paidAmount = null;

        if ($isInvoiceCategory) {
            $invoiceStage = (string) ($validated['invoice_stage'] ?? '');
            if ($invoiceStage === '') {
                return response()->json(['message' => 'invoice_stage is required for invoice uploads'], 422);
            }
            $expectedAmount = $this->resolveExpectedAmountForStage($case, $invoiceStage);
            $paidAmount = (float) ($validated['paid_amount'] ?? 0);
        }

        $fileUuid = Str::uuid()->toString();

        if ($documentStatus === 'pending_approval') {
            // Client-uploaded pending documents are stored locally (not in Azure)
            // until a Lawyer or Admin approves them.
            $localRelPath = 'pending-documents/' . $fileUuid . '.enc';
            \Illuminate\Support\Facades\Storage::disk('local')->put($localRelPath, $encrypted['cipherText']);
            $blobPath = 'pending://' . $localRelPath;
        } else {
            $blobPath = sprintf(
                'cases/%d/%s/encrypted/%s.enc',
                $case->caseId,
                $category,
                $fileUuid
            );
            AzureStorage::put($blobPath, $encrypted['cipherText']);
        }

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
            'content_hash_sha256' => $contentHash,
            'cipher' => $encrypted['cipher'],
            'nonce' => $encrypted['nonce'],
            'tag' => $encrypted['tag'],
            'server_encrypted_dek' => Crypt::encryptString(base64_encode($dek)),
            'dek_version' => 1,
            'status' => $documentStatus,
            'recipients' => $recipientEntries,
            'invoice_stage' => $invoiceStage,
            'type_of_work' => (string) ($validated['type_of_work'] ?? ''),
            'expected_amount' => $expectedAmount,
            'paid_amount' => $paidAmount,
        ]);

        $documentId = (string) $document->getKey();

        $caseProgress = null;
        $createdInvoiceError = null;
        $caseFinancials = null;

        // If this is an invoice upload, create an Invoice record so it can be queried/updated later.
        if ($isInvoiceCategory) {
            try {
                $invoiceData = [
                    'lawyerID' => (int) ($validated['lawyerID'] ?? $case->lawyerID ?? 0) ?: null,
                    'clientID' => (int) ($validated['clientID'] ?? $case->clientID ?? 0) ?: null,
                    'case_id' => $case->caseId,
                    'invoice_number' => (string) ($validated['invoice_number'] ?? ''),
                    'payment_stage' => (string) ($validated['payment_stage'] ?? $invoiceStage ?? ''),
                    'expected_amount' => $expectedAmount,
                    'paid_amount' => $paidAmount,
                    'balance' => array_key_exists('balance', $validated) ? (float) $validated['balance'] : max($expectedAmount - $paidAmount, 0),
                    'tax' => array_key_exists('tax', $validated) ? (float) $validated['tax'] : 0,
                    'discount' => array_key_exists('discount', $validated) ? (float) $validated['discount'] : 0,
                    'total_amount' => array_key_exists('total_amount', $validated) ? (float) $validated['total_amount'] : null,
                    'issue_date' => (string) ($validated['issue_date'] ?? now()->toDateString()),
                    'due_date' => $validated['due_date'] ?? null,
                    'blob_path' => (string) ($validated['blob_path'] ?? $blobPath),
                    'client_name' => (string) ($validated['client_name'] ?? ($case->clientName ?? optional($case->client)->name ?? '')),
                    'case_title' => (string) ($validated['case_title'] ?? ($case->title ?? '')),
                ];

                $invoice = Invoice::create($invoiceData);
            } catch (\RuntimeException $e) {
                $createdInvoiceError = 'Unable to generate a unique invoice number right now. Please try again.';

                logger()->warning('Invoice creation skipped due to invoice number generation exhaustion', [
                    'error' => $e->getMessage(),
                    'case_id' => $case->caseId ?? null,
                    'uploader' => $actor->id ?? null,
                ]);
            } catch (\Throwable $e) {
                $createdInvoiceError = 'Invoice record could not be created for this upload.';

                logger()->error('Failed to create Invoice record for uploaded invoice document', [
                    'error' => $e->getMessage(),
                    'case_id' => $case->caseId ?? null,
                    'uploader' => $actor->id ?? null,
                ]);
            }

            // Recompute case progress/balance after inserting invoice
            $caseProgress = $this->invoiceProgressService->syncCaseProgress($case);
            $case->refresh();

            $expectedPaymentPhases = [
                'initial' => (float) ($case->expected_initial_payment ?? 0),
                'first' => (float) ($case->expected_first_payment ?? 0),
                'second' => (float) ($case->expected_second_payment ?? 0),
                'third' => (float) ($case->expected_third_payment ?? 0),
                'final' => (float) ($case->expected_final_payment ?? 0),
            ];

            $invoicePaymentPhases = $this->invoiceProgressService->getStageSummaries((int) $case->caseId);
            foreach ($expectedPaymentPhases as $stage => $expectedDefault) {
                if (!isset($invoicePaymentPhases[$stage])) {
                    $invoicePaymentPhases[$stage] = [
                        'expected' => (float) $expectedDefault,
                        'paid' => 0.0,
                        'balance' => (float) $expectedDefault,
                    ];
                    continue;
                }

                if ((float) ($invoicePaymentPhases[$stage]['expected'] ?? 0) <= 0 && (float) $expectedDefault > 0) {
                    $paid = (float) ($invoicePaymentPhases[$stage]['paid'] ?? 0);
                    $invoicePaymentPhases[$stage]['expected'] = (float) $expectedDefault;
                    $invoicePaymentPhases[$stage]['balance'] = round(max((float) $expectedDefault - $paid, 0.0), 2);
                }
            }

            $caseFinancials = [
                'expected_payment_phases' => $expectedPaymentPhases,
                'balance_payment_phases' => [
                    'initial' => (float) ($case->balance_initial_payment ?? 0),
                    'first' => (float) ($case->balance_first_payment ?? 0),
                    'second' => (float) ($case->balance_second_payment ?? 0),
                    'third' => (float) ($case->balance_third_payment ?? 0),
                    'final' => (float) ($case->balance_final_payment ?? 0),
                ],
                'total_balance' => (float) ($case->total_balance ?? 0),
                'invoice_payment_phases' => $invoicePaymentPhases,
            ];
        }

        $this->caseNotificationService->notifyCaseUpdate(
            $case,
            $actor,
            $documentStatus === 'active' ? 'Document Uploaded' : 'Document Pending Approval',
            $documentStatus === 'active'
                ? sprintf('%s was uploaded to the %s section.', (string) $uploadedFile->getClientOriginalName(), $category)
                : sprintf('%s was uploaded to the %s section and is waiting for approval.', (string) $uploadedFile->getClientOriginalName(), $category)
        );

        return response()->json([
            'message' => $documentStatus === 'active'
                ? 'Encrypted document uploaded successfully'
                : 'Document uploaded and marked as pending approval',
            'document_id' => $documentId,
            'category' => $category,
            'storage_path' => $blobPath,
            'recipient_count' => count($recipients),
            'case_progress' => $caseProgress,
            'document_status' => $documentStatus,
            'approval_required' => (bool) $workflowDecision['requires_approval'],
            'approval_reasons' => $workflowDecision['reasons'],
            'created_invoice' => isset($invoice) ? $invoice : null,
            'created_invoice_error' => $createdInvoiceError,
            'case_financials' => $caseFinancials,
        ], 201);
    }

    public function review(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ]);

        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $role = strtolower((string) ($actor->role ?? ''));
        if (!in_array($role, ['admin', 'adminstaff', 'lawyer'], true)) {
            return response()->json(['message' => 'Only admins/lawyers can review pending documents'], 403);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $case = LawCase::find((int) $document->case_id);
        if (!$case || !$this->canManageCase((int) $actor->id, $case, (int) $document->uploader_user_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (strtolower((string) ($document->status ?? '')) !== 'pending_approval') {
            return response()->json(['message' => 'Document is not pending approval'], 422);
        }

        $isApprove = (string) $validated['action'] === 'approve';
        $currentBlobPath = (string) ($document->blob_path ?? '');
        $isPendingLocal = str_starts_with($currentBlobPath, 'pending://');

        if ($isApprove) {
            if ($isPendingLocal) {
                // Move from local temp storage to Azure and encrypt-at-rest in Azure.
                $localRelPath = substr($currentBlobPath, strlen('pending://'));
                $cipherText = \Illuminate\Support\Facades\Storage::disk('local')->get($localRelPath);

                if ($cipherText === null) {
                    return response()->json([
                        'message' => 'Pending document data not found. The client must re-upload the document.',
                    ], 404);
                }

                $azureBlobPath = sprintf(
                    'cases/%d/%s/encrypted/%s.enc',
                    (int) $document->case_id,
                    (string) $document->category,
                    Str::uuid()->toString()
                );

                try {
                    AzureStorage::put($azureBlobPath, $cipherText);
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' => 'Unable to upload approved document to storage. Please try again.',
                    ], 500);
                }

                // Delete the local temp file after successful Azure upload.
                \Illuminate\Support\Facades\Storage::disk('local')->delete($localRelPath);

                $document->blob_path = $azureBlobPath;
            }

            $document->status = 'active';
            $document->reviewed_by_user_id = (int) $actor->id;
            $document->reviewed_at = now();
            $document->review_note = (string) ($validated['note'] ?? '');
            $document->save();

            $this->caseNotificationService->notifyDocumentUpload(
                $case,
                $actor,
                (string) $document->file_name,
                (string) $document->category
            );

            return response()->json([
                'message' => 'Document approved and stored securely.',
                'document_id' => (string) $document->getKey(),
                'document_status' => (string) $document->status,
            ]);
        }

        // Reject: remove from local temp storage or Azure.
        try {
            if ($isPendingLocal) {
                $localRelPath = substr($currentBlobPath, strlen('pending://'));
                \Illuminate\Support\Facades\Storage::disk('local')->delete($localRelPath);
            } else {
                AzureStorage::delete($currentBlobPath);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to delete rejected document from storage. Document was not removed.',
            ], 500);
        }

        $documentId = (string) $document->getKey();
        $document->delete();

        return response()->json([
            'message' => 'Document rejected and removed successfully',
            'document_id' => $documentId,
            'document_status' => 'rejected',
            'removed' => true,
        ]);
    }

    public function getPayload(Request $request, string $documentId): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        if (!$this->canAccessDocumentStatus($actor, $document)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Check if user can access this document (recipient or admin)
        if (!$this->canAccessDocument($actor, $document)) {
            return response()->json(['message' => 'No access to this document'], 403);
        }

        // For admins, decrypt server-side and return plaintext
        if (in_array(strtolower((string) $actor->role), ['admin', 'adminstaff'], true)) {
            return $this->getPayloadForAdmin($document);
        }

        $blobPath = (string) $document->blob_path;

        if (str_starts_with($blobPath, 'pending://')) {
            return response()->json(['message' => 'Document is pending approval and cannot be accessed yet'], 403);
        }

        $recipientKey = $this->findActiveRecipient($document, (int) $actor->id);

        $cipherContent = AzureStorage::get($blobPath);
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

    // GET /api/encrypted-documents/{documentId}/invoice
    public function invoiceForDocument(Request $request, string $documentId): JsonResponse
    {
        $document = FileMetadata::find($documentId);
        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        $pdfPath = (string) ($document->blob_path ?? '');
        $invoice = Invoice::where('blob_path', $pdfPath)
            ->orderByDesc('id')
            ->first();

        // Backward-compatible fallback for older uploads where blob path was not persisted consistently.
        if (!$invoice) {
            $caseId = (int) ($document->case_id ?? 0);
            $stage = strtolower(trim((string) ($document->invoice_stage ?? '')));

            $fallbackQuery = Invoice::query()
                ->where('case_id', $caseId)
                ->orderByDesc('id');

            if (in_array($stage, ['initial', 'first', 'second', 'third', 'final'], true)) {
                $fallbackQuery->where('payment_stage', $stage);
            }

            $invoice = $fallbackQuery->first();
        }

        // Final fallback: lazily create invoice row from document metadata so Update flow can proceed.
        if (!$invoice) {
            $case = LawCase::with('client')->find((int) ($document->case_id ?? 0));

            if ($case) {
                $stage = strtolower(trim((string) ($document->invoice_stage ?? 'initial')));
                if (!in_array($stage, ['initial', 'first', 'second', 'third', 'final'], true)) {
                    $stage = 'initial';
                }

                $expectedAmount = $document->expected_amount;
                if ($expectedAmount === null) {
                    $expectedAmount = $this->resolveExpectedAmountForStage($case, $stage);
                }

                try {
                    $invoice = Invoice::create([
                        'lawyerID' => (int) ($case->lawyerID ?? 0) ?: null,
                        'clientID' => (int) ($case->clientID ?? 0) ?: null,
                        'case_id' => (int) $case->caseId,
                        'payment_stage' => $stage,
                        'expected_amount' => (float) ($expectedAmount ?? 0),
                        'paid_amount' => (float) ($document->paid_amount ?? 0),
                        'issue_date' => now()->toDateString(),
                        'blob_path' => (string) ($document->blob_path ?? ''),
                        'client_name' => (string) ($case->clientName ?? optional($case->client)->name ?? ''),
                        'case_title' => (string) ($case->title ?? ''),
                    ]);

                    logger()->info('Created fallback invoice from document metadata', [
                        'document_id' => $documentId,
                        'invoice_id' => $invoice->id ?? null,
                        'case_id' => $case->caseId ?? null,
                    ]);
                } catch (\Throwable $e) {
                    logger()->warning('Fallback invoice creation failed for document', [
                        'document_id' => $documentId,
                        'case_id' => $case->caseId ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $case = LawCase::find((int) ($document->case_id ?? 0));
        $caseFinancials = $case ? [
            'expected_payment_phases' => [
                'initial' => (float) ($case->expected_initial_payment ?? 0),
                'first' => (float) ($case->expected_first_payment ?? 0),
                'second' => (float) ($case->expected_second_payment ?? 0),
                'third' => (float) ($case->expected_third_payment ?? 0),
                'final' => (float) ($case->expected_final_payment ?? 0),
            ],
            'balance_payment_phases' => [
                'initial' => (float) ($case->balance_initial_payment ?? 0),
                'first' => (float) ($case->balance_first_payment ?? 0),
                'second' => (float) ($case->balance_second_payment ?? 0),
                'third' => (float) ($case->balance_third_payment ?? 0),
                'final' => (float) ($case->balance_final_payment ?? 0),
            ],
            'total_balance' => (float) ($case->total_balance ?? 0),
        ] : null;

        return response()->json([
            'invoice' => $invoice,
            'type_of_work' => (string) ($document->type_of_work ?? ''),
            'case_financials' => $caseFinancials,
        ], 200);
    }

    // PUT /api/encrypted-documents/{documentId}/invoice
    public function updateInvoiceForDocument(Request $request, string $documentId): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!in_array(strtolower((string) ($actor->role ?? '')), ['admin', 'adminstaff'], true)) {
            return response()->json(['message' => 'Only admins can update invoice payment details'], 403);
        }

        $validated = $request->validate([
            'paid_amount' => 'required|numeric|min:0',
            'new_document_id' => 'nullable|string',
        ]);

        $document = FileMetadata::find($documentId);
        if (!$document || (string) ($document->type ?? '') !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        if (strtolower((string) ($document->category ?? '')) !== 'invoices') {
            return response()->json(['message' => 'Only invoice documents can be updated with this endpoint'], 422);
        }

        $case = LawCase::find((int) ($document->case_id ?? 0));
        if (!$case) {
            return response()->json(['message' => 'Case not found for this document'], 404);
        }

        if (!$this->canManageCase((int) $actor->id, $case)) {
            return response()->json(['message' => 'Forbidden for this case'], 403);
        }

        $pdfPath = (string) ($document->blob_path ?? '');
        $invoice = Invoice::where('blob_path', $pdfPath)
            ->orderByDesc('id')
            ->first();

        if (!$invoice) {
            $stage = strtolower(trim((string) ($document->invoice_stage ?? '')));
            $fallbackQuery = Invoice::query()
                ->where('case_id', (int) ($document->case_id ?? 0))
                ->orderByDesc('id');

            if (in_array($stage, ['initial', 'first', 'second', 'third', 'final'], true)) {
                $fallbackQuery->where('payment_stage', $stage);
            }

            $invoice = $fallbackQuery->first();
        }

        if (!$invoice) {
            return response()->json(['message' => 'Invoice record not found for this document'], 404);
        }

        $oldBlobPath = (string) ($document->blob_path ?? '');
        $oldBlobCipherContent = null;
        if ($oldBlobPath !== '') {
            try {
                $oldBlobCipherContent = AzureStorage::get($oldBlobPath);
            } catch (\Throwable $e) {
                $oldBlobCipherContent = null;
            }
        }

        $oldDocumentAttributes = $document->getAttributes();
        $newPaidAmount = (float) $validated['paid_amount'];
        $oldPaidAmount = (float) ($invoice->paid_amount ?? 0);
        $expectedAmount = (float) ($invoice->expected_amount ?? 0);
        $taxRate = (float) ($invoice->tax ?? 0);
        $discountRate = (float) ($invoice->discount ?? 0);

        $calculatedBalance = round(max($expectedAmount - $newPaidAmount, 0.0), 2);
        $calculatedTotalAmount = round(max(
            $newPaidAmount + (($newPaidAmount * $taxRate) / 100) - (($newPaidAmount * $discountRate) / 100),
            0.0
        ), 2);

        $stage = strtolower(trim((string) ($invoice->payment_stage ?? 'initial')));
        if (!in_array($stage, ['initial', 'first', 'second', 'third', 'final'], true)) {
            $stage = 'initial';
        }

        $stageSummaries = $this->invoiceProgressService->getStageSummaries((int) ($case->caseId ?? 0));
        $stageExpectedAmount = (float) ($stageSummaries[$stage]['expected'] ?? $expectedAmount);
        $stagePaidAmount = (float) ($stageSummaries[$stage]['paid'] ?? 0);
        $adjustedStagePaidAmount = max($stagePaidAmount - $oldPaidAmount + $newPaidAmount, 0.0);
        $calculatedPhaseBalance = round(max($stageExpectedAmount - $adjustedStagePaidAmount, 0.0), 2);

        $documentTypeOfWork = trim((string) ($document->type_of_work ?? ''));
        $expectedTypeOfWorkAmount = $this->resolveExpectedAmountForTypeOfWork($case, $stage, $documentTypeOfWork);
        $calculatedTypeOfWorkBalance = $calculatedBalance;
        if ($expectedTypeOfWorkAmount !== null && $expectedTypeOfWorkAmount > 0) {
            $typePaidAmount = $this->resolvePaidAmountForTypeOfWork(
                $case,
                $stage,
                $documentTypeOfWork,
                (string) $documentId
            );
            $adjustedTypePaidAmount = max($typePaidAmount + $newPaidAmount, 0.0);
            $calculatedTypeOfWorkBalance = round(max($expectedTypeOfWorkAmount - $adjustedTypePaidAmount, 0.0), 2);
        }

        // Fast path: if the frontend already uploaded the new document, skip PDF generation.
        $newDocumentId = trim((string) ($validated['new_document_id'] ?? ''));
        if ($newDocumentId !== '') {
            $newDocument = FileMetadata::find($newDocumentId);
            if (!$newDocument) {
                return response()->json(['message' => 'New document not found'], 404);
            }

            $newBlobPath = (string) ($newDocument->blob_path ?? '');

            DB::beginTransaction();
            try {
                // Remove any duplicate Invoice record created by the upload endpoint for this new blob
                if ($newBlobPath !== '') {
                    Invoice::where('blob_path', $newBlobPath)
                        ->where('id', '!=', (int) $invoice->id)
                        ->delete();
                }

                $invoice->paid_amount = $newPaidAmount;
                $invoice->balance = $calculatedBalance;
                $invoice->total_amount = $calculatedTotalAmount;
                if ($newBlobPath !== '') {
                    $invoice->blob_path = $newBlobPath;
                }
                $invoice->save();

                $progress = $this->invoiceProgressService->syncCaseProgress($case);

                if ($oldBlobPath !== '' && $oldBlobPath !== $newBlobPath) {
                    try {
                        AzureStorage::delete($oldBlobPath);
                    } catch (\Throwable $deleteErr) {
                        logger()->warning('Could not delete old invoice blob during fast-path update', [
                            'blob_path' => $oldBlobPath,
                            'error' => $deleteErr->getMessage(),
                        ]);
                    }
                }

                $document->delete();

                DB::commit();

                $case->refresh();
                $invoice->refresh();

                $caseFinancials = [
                    'expected_payment_phases' => [
                        'initial' => (float) ($case->expected_initial_payment ?? 0),
                        'first' => (float) ($case->expected_first_payment ?? 0),
                        'second' => (float) ($case->expected_second_payment ?? 0),
                        'third' => (float) ($case->expected_third_payment ?? 0),
                        'final' => (float) ($case->expected_final_payment ?? 0),
                    ],
                    'balance_payment_phases' => [
                        'initial' => (float) ($case->balance_initial_payment ?? 0),
                        'first' => (float) ($case->balance_first_payment ?? 0),
                        'second' => (float) ($case->balance_second_payment ?? 0),
                        'third' => (float) ($case->balance_third_payment ?? 0),
                        'final' => (float) ($case->balance_final_payment ?? 0),
                    ],
                    'total_balance' => (float) ($case->total_balance ?? 0),
                ];

                return response()->json([
                    'message' => 'Invoice updated successfully. New invoice document linked.',
                    'invoice' => $invoice,
                    'type_of_work' => (string) ($newDocument->type_of_work ?? $document->type_of_work ?? ''),
                    'case_financials' => $caseFinancials,
                    'case_progress' => (float) $progress,
                    'updated_document' => [
                        'old_document_id' => $documentId,
                        'new_document_id' => $newDocumentId,
                        'new_file_name' => (string) ($newDocument->file_name ?? ''),
                        'new_blob_path' => $newBlobPath,
                    ],
                ], 200);
            } catch (\Throwable $e) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Failed to complete invoice update.',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        // Generate a replacement invoice document content using latest invoice values.
        $invoicePayload = [
            'invoice_id' => (int) ($invoice->id ?? 0),
            'invoice_number' => (string) ($invoice->invoice_number ?? ''),
            'lawyerID' => (int) ($invoice->lawyerID ?? $case->lawyerID ?? 0),
            'case_id' => (int) ($invoice->case_id ?? $case->caseId ?? 0),
            'clientID' => (int) ($invoice->clientID ?? $case->clientID ?? 0),
            'payment_stage' => (string) ($invoice->payment_stage ?? 'initial'),
            'type_of_work' => (string) ($document->type_of_work ?? $invoice->type_of_work ?? ''),
            'issue_date' => (string) ($invoice->issue_date ?? now()->toDateString()),
            'due_date' => $invoice->due_date,
            'expected_amount' => (float) ($invoice->expected_amount ?? 0),
            'paid_amount' => $newPaidAmount,
            'tax' => (float) ($invoice->tax ?? 0),
            'discount' => (float) ($invoice->discount ?? 0),
            'balance' => $calculatedTypeOfWorkBalance,
            'phase_balance' => $calculatedPhaseBalance,
            'total_amount' => $calculatedTotalAmount,
            'client_name' => (string) ($invoice->client_name ?? $case->clientName ?? optional($case->client)->name ?? ''),
            'case_title' => (string) ($invoice->case_title ?? $case->title ?? ''),
        ];

        $newBlobPath = null;
        $newDocument = null;
        $newBlobUploaded = false;
        $oldBlobDeleted = false;
        $oldDocumentDeleted = false;

        try {
            $generatedFile = $this->documentGeneratorService->generateInvoicePdf($invoicePayload);
            $plainInvoiceContent = (string) ($generatedFile['buffer'] ?? '');
            if ($plainInvoiceContent === '') {
                throw new \RuntimeException('Generated invoice document is empty.');
            }

            $dek = $this->crypto->generateDek();
            $encrypted = $this->crypto->encrypt($plainInvoiceContent, $dek);
            $newBlobPath = sprintf(
                'cases/%d/invoices/encrypted/%s.enc',
                (int) $case->caseId,
                Str::uuid()->toString()
            );

            AzureStorage::put($newBlobPath, $encrypted['cipherText']);
            $newBlobUploaded = true;

            $requestedRecipientIds = collect($document->recipients ?? [])
                ->filter(fn ($entry) => (bool) ($entry['is_active'] ?? false) === true)
                ->pluck('recipient_user_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();

            $recipients = $this->resolveRecipients($requestedRecipientIds, $case, (int) $actor->id);
            if (count($recipients) === 0) {
                throw new \RuntimeException('No valid recipients found for regenerated invoice document.');
            }

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

            $baseFileName = pathinfo((string) ($document->file_name ?: ($invoice->invoice_number ?? 'invoice')), PATHINFO_FILENAME);
            $newFileName = $baseFileName . '-updated-' . now()->format('YmdHis') . '.pdf';

            $newDocument = FileMetadata::create([
                'type' => 'encrypted_document',
                'case_id' => (int) $case->caseId,
                'category' => 'invoices',
                'uploader_user_id' => (int) $actor->id,
                'blob_path' => $newBlobPath,
                'file_name' => $newFileName,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($plainInvoiceContent),
                'content_hash_sha256' => hash('sha256', $plainInvoiceContent),
                'cipher' => $encrypted['cipher'],
                'nonce' => $encrypted['nonce'],
                'tag' => $encrypted['tag'],
                'server_encrypted_dek' => Crypt::encryptString(base64_encode($dek)),
                'dek_version' => 1,
                'status' => 'active',
                'recipients' => $recipientEntries,
                'invoice_stage' => (string) ($invoice->payment_stage ?? ''),
                'type_of_work' => (string) ($document->type_of_work ?? ''),
                'expected_amount' => (float) ($invoice->expected_amount ?? 0),
                'paid_amount' => $newPaidAmount,
            ]);

            DB::beginTransaction();

            $invoice->paid_amount = $newPaidAmount;
            $invoice->balance = $calculatedBalance;
            $invoice->total_amount = $calculatedTotalAmount;
            $invoice->blob_path = (string) $newBlobPath;
            $invoice->save();

            $progress = $this->invoiceProgressService->syncCaseProgress($case);

            if ($oldBlobPath !== '' && $oldBlobPath !== $newBlobPath) {
                AzureStorage::delete($oldBlobPath);
                $oldBlobDeleted = true;
            }

            $document->delete();
            $oldDocumentDeleted = true;

            DB::commit();

            $case->refresh();
            $invoice->refresh();

            $caseFinancials = [
                'expected_payment_phases' => [
                    'initial' => (float) ($case->expected_initial_payment ?? 0),
                    'first' => (float) ($case->expected_first_payment ?? 0),
                    'second' => (float) ($case->expected_second_payment ?? 0),
                    'third' => (float) ($case->expected_third_payment ?? 0),
                    'final' => (float) ($case->expected_final_payment ?? 0),
                ],
                'balance_payment_phases' => [
                    'initial' => (float) ($case->balance_initial_payment ?? 0),
                    'first' => (float) ($case->balance_first_payment ?? 0),
                    'second' => (float) ($case->balance_second_payment ?? 0),
                    'third' => (float) ($case->balance_third_payment ?? 0),
                    'final' => (float) ($case->balance_final_payment ?? 0),
                ],
                'total_balance' => (float) ($case->total_balance ?? 0),
            ];

            return response()->json([
                'message' => 'Invoice updated, old storage/metadata replaced, and new invoice generated successfully',
                'invoice' => $invoice,
                'type_of_work' => (string) ($newDocument->type_of_work ?? $document->type_of_work ?? ''),
                'case_financials' => $caseFinancials,
                'case_progress' => (float) $progress,
                'updated_document' => [
                    'old_document_id' => $documentId,
                    'new_document_id' => (string) $newDocument->getKey(),
                    'new_file_name' => (string) $newDocument->file_name,
                    'new_blob_path' => (string) $newBlobPath,
                ],
            ], 200);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($newDocument) {
                try {
                    $newDocument->delete();
                } catch (\Throwable $cleanupError) {
                    logger()->warning('Failed to cleanup regenerated invoice metadata after save failure', [
                        'new_document_id' => (string) $newDocument->getKey(),
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            if ($newBlobUploaded && $newBlobPath) {
                try {
                    AzureStorage::delete((string) $newBlobPath);
                } catch (\Throwable $cleanupError) {
                    logger()->warning('Failed to cleanup regenerated invoice blob after save failure', [
                        'new_blob_path' => (string) $newBlobPath,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            if ($oldBlobDeleted && $oldBlobPath !== '' && $oldBlobCipherContent !== null) {
                try {
                    AzureStorage::put($oldBlobPath, $oldBlobCipherContent);
                } catch (\Throwable $restoreError) {
                    logger()->error('Failed to restore old invoice blob after rollback', [
                        'old_blob_path' => $oldBlobPath,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            if ($oldDocumentDeleted) {
                try {
                    $restoredDocument = new FileMetadata();
                    foreach ($oldDocumentAttributes as $attributeKey => $attributeValue) {
                        $restoredDocument->setAttribute($attributeKey, $attributeValue);
                    }
                    $restoredDocument->save();
                } catch (\Throwable $restoreError) {
                    logger()->error('Failed to restore old invoice metadata after rollback', [
                        'old_document_id' => $documentId,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Failed to complete invoice replacement transaction. Changes were rolled back where possible.',
                'error' => $e->getMessage(),
            ], 500);
        }
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

        $this->caseNotificationService->notifyCaseUpdate(
            $case,
            $actor,
            'Document Shared',
            sprintf(
                '%s was shared with %d recipient(s).',
                (string) $document->file_name,
                $added
            )
        );

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

        if ((string) ($document->category ?? '') === 'invoices' && !in_array(strtolower((string) $actor->role), ['admin', 'adminstaff'], true)) {
            return response()->json(['message' => 'Only admins can delete invoices'], 403);
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

        $this->caseNotificationService->notifyCaseUpdate(
            $case,
            $actor,
            'Document Access Revoked',
            sprintf(
                'Access to %s was revoked for recipient #%d.',
                (string) $document->file_name,
                $recipientId
            )
        );

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

        $blobPath = (string) ($document->blob_path ?? '');
        $isInvoiceDocument = strtolower((string) ($document->category ?? '')) === 'invoices';

        $blobDeleted = true;
        try {
            AzureStorage::delete($blobPath);
        } catch (\Throwable $e) {
            $blobDeleted = false;
        }

        $document->delete();

        if ($isInvoiceDocument && $blobPath !== '') {
            // Keep invoice table consistent with deleted invoice documents.
            Invoice::where('blob_path', $blobPath)->delete();
            $this->invoiceProgressService->syncCaseProgress($case);
        }

        $this->caseNotificationService->notifyDocumentDeleted(
            $case,
            $actor,
            (string) $document->file_name,
            (string) ($document->category ?? 'documents')
        );

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
            ->header('Content-Disposition', 'inline; filename="' . (string) $document->file_name . '"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
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
            ->header('Content-Disposition', 'attachment; filename="' . (string) $document->file_name . '"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
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
        $actor = User::find($actorId);
        if ($actor && in_array(strtolower((string) $actor->role), ['admin', 'adminstaff'], true)) {
            return true;
        }

        if ($uploaderUserId !== null && $actorId === $uploaderUserId) {
            return true;
        }

        return $actorId === (int) $case->lawyerID || $actorId === (int) $case->clientID;
    }

    private function resolveExpectedAmountForStage(LawCase $case, string $stage): float
    {
        return match ($stage) {
            'initial' => (float) ($case->balance_initial_payment ?? $case->expected_initial_payment ?? 0),
            'first' => (float) ($case->balance_first_payment ?? $case->expected_first_payment ?? 0),
            'second' => (float) ($case->balance_second_payment ?? $case->expected_second_payment ?? 0),
            'third' => (float) ($case->balance_third_payment ?? $case->expected_third_payment ?? 0),
            'final' => (float) ($case->balance_final_payment ?? $case->expected_final_payment ?? 0),
            default => 0.0,
        };
    }

    private function resolveExpectedAmountForTypeOfWork(LawCase $case, string $stage, string $typeOfWork): ?float
    {
        $normalizedType = strtolower(trim($typeOfWork));
        if ($normalizedType === '') {
            return null;
        }

        $rawCaseTypeFeeJson = $case->case_type_fee_json;
        if (is_string($rawCaseTypeFeeJson)) {
            $decoded = json_decode($rawCaseTypeFeeJson, true);
            $rawCaseTypeFeeJson = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($rawCaseTypeFeeJson)) {
            return null;
        }

        $stageItems = $rawCaseTypeFeeJson[$stage] ?? null;
        if (!is_array($stageItems)) {
            return null;
        }

        $sum = 0.0;
        foreach ($stageItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemType = strtolower(trim((string) ($item['typeOfWork'] ?? $item['type_of_work'] ?? '')));
            if ($itemType !== $normalizedType) {
                continue;
            }

            $sum += (float) ($item['selectedFee'] ?? $item['selected_fee'] ?? 0);
        }

        return $sum > 0 ? round($sum, 2) : null;
    }

    private function resolvePaidAmountForTypeOfWork(
        LawCase $case,
        string $stage,
        string $typeOfWork,
        ?string $excludeDocumentId = null
    ): float {
        $normalizedType = strtolower(trim($typeOfWork));
        if ($normalizedType === '') {
            return 0.0;
        }

        $invoiceDocuments = FileMetadata::query()
            ->where('case_id', (int) ($case->caseId ?? 0))
            ->where('category', 'invoices')
            ->where('status', '!=', 'deleted')
            ->get(['_id', 'invoice_stage', 'type_of_work', 'paid_amount']);

        $paid = 0.0;
        foreach ($invoiceDocuments as $invoiceDocument) {
            $documentId = (string) $invoiceDocument->getKey();
            if ($excludeDocumentId !== null && $excludeDocumentId !== '' && $documentId === $excludeDocumentId) {
                continue;
            }

            $documentStage = strtolower(trim((string) ($invoiceDocument->invoice_stage ?? '')));
            $documentType = strtolower(trim((string) ($invoiceDocument->type_of_work ?? '')));

            if ($documentStage !== $stage || $documentType !== $normalizedType) {
                continue;
            }

            $paid += max((float) ($invoiceDocument->paid_amount ?? 0), 0.0);
        }

        return round(max($paid, 0.0), 2);
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

    private function canAccessDocument(User $actor, FileMetadata $document): bool
    {
        // Admins can access all documents
        if (in_array(strtolower((string) $actor->role), ['admin', 'adminstaff'], true)) {
            return true;
        }

        // Check if user is an active recipient
        return $this->findActiveRecipient($document, (int) $actor->id) !== null;
    }

    private function canAccessDocumentStatus(User $actor, FileMetadata $document): bool
    {
        $status = strtolower((string) ($document->status ?? 'active'));

        if ($status === 'active') {
            return true;
        }

        if ($status === 'pending_approval') {
            $role = strtolower((string) ($actor->role ?? ''));
            return in_array($role, ['admin', 'adminstaff', 'lawyer'], true);
        }

        return false;
    }

    private function hasDocumentConflict(int $caseId, string $category, string $fileName, string $contentHash): bool
    {
        return FileMetadata::query()
            ->where('type', 'encrypted_document')
            ->where('case_id', $caseId)
            ->where('category', $category)
            ->where('file_name', $fileName)
            ->where('content_hash_sha256', $contentHash)
            ->whereIn('status', ['active', 'pending_approval'])
            ->exists();
    }

    private function resolveUploadWorkflowDecision(
        User $actor,
        LawCase $case,
        string $category,
        string $fileName,
        string $mimeType,
        int $sizeBytes
    ): array {
        $role = strtolower((string) ($actor->role ?? ''));
        if ($role !== 'client') {
            return [
                'requires_approval' => false,
                'reasons' => [],
            ];
        }

        // All client-uploaded documents require Lawyer/Admin approval before
        // being stored in the Azure storage account.
        return [
            'requires_approval' => true,
            'reasons' => ['client_upload_requires_approval'],
        ];
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

    private function getPayloadForAdmin(FileMetadata $document): JsonResponse
    {
        try {
            $blobPath = (string) $document->blob_path;
            if (str_starts_with($blobPath, 'pending://')) {
                return response()->json(['message' => 'Document is pending approval and cannot be accessed yet'], 403);
            }

            $cipherContent = AzureStorage::get($blobPath);
            if ($cipherContent === null) {
                return response()->json(['message' => 'Encrypted file not found in storage'], 404);
            }

            $dek = base64_decode(Crypt::decryptString((string) $document->server_encrypted_dek), true);
            if ($dek === false || strlen($dek) !== 32) {
                return response()->json(['message' => 'Recovered DEK is invalid'], 500);
            }

            $plainContent = $this->crypto->decrypt(
                $cipherContent,
                $dek,
                (string) $document->nonce,
                (string) $document->tag
            );

            return response()->json([
                'document_id' => (string) $document->getKey(),
                'file_name' => $document->file_name,
                'mime_type' => $document->mime_type,
                'plaintext' => base64_encode($plainContent),
                'note' => 'Admin-decrypted plaintext. Downloaded server-side.',
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Unable to decrypt document content'], 500);
        }
    }

    private function decryptDocumentServerSide(FileMetadata $document): JsonResponse|array
    {
        try {
            $cipherContent = $this->resolveCipherContentByBlobPath((string) $document->blob_path);
            if ($cipherContent === null) {
                return response()->json(['message' => 'Encrypted file not found in storage'], 404);
            }

            $dek = base64_decode(Crypt::decryptString((string) $document->server_encrypted_dek), true);
            if ($dek === false || strlen($dek) !== 32) {
                return response()->json(['message' => 'Recovered DEK is invalid'], 500);
            }

            $plainContent = $this->crypto->decrypt(
                $cipherContent,
                $dek,
                (string) $document->nonce,
                (string) $document->tag
            );

            return [
                'document' => $document,
                'plaintext' => $plainContent,
            ];
        } catch (Throwable $e) {
            return response()->json(['message' => 'Unable to decrypt document content'], 500);
        }
    }

    private function resolveDocumentAndActorAccess(Request $request, string $documentId): JsonResponse|array
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $document = FileMetadata::find($documentId);
        if (!$document || $document->type !== 'encrypted_document') {
            return response()->json(['message' => 'Document not found'], 404);
        }

        if (!$this->canAccessDocumentStatus($actor, $document)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Check if user can access this document (recipient or admin)
        if (!$this->canAccessDocument($actor, $document)) {
            return response()->json(['message' => 'No access to this document'], 403);
        }

        // For admins, decrypt server-side
        if (in_array(strtolower((string) $actor->role), ['admin', 'adminstaff'], true)) {
            return $this->decryptDocumentServerSide($document);
        }

        $cipherContent = $this->resolveCipherContentByBlobPath((string) $document->blob_path);
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

    private function resolveCipherContentByBlobPath(string $blobPath): ?string
    {
        if (str_starts_with($blobPath, 'pending://')) {
            $localRelPath = substr($blobPath, strlen('pending://'));
            if ($localRelPath === '') {
                return null;
            }

            if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($localRelPath)) {
                return null;
            }

            return \Illuminate\Support\Facades\Storage::disk('local')->get($localRelPath);
        }

        return AzureStorage::get($blobPath);
    }
}
