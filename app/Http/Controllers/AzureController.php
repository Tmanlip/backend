<?php 
namespace App\Http\Controllers; 

use Illuminate\Http\Request; 
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use MicrosoftAzure\Storage\Blob\BlobRestProxy; 
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions; 
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions; 
use App\Models\Metadata; 
use App\Models\FileMetadata;
use App\Models\Invoice;
use App\Models\LawCase;
use App\Services\InvoiceProgressService;
use Illuminate\Http\JsonResponse;

class AzureController extends Controller { 
    private $client; 
    private $container; 
    private InvoiceProgressService $invoiceProgressService;

    private function envString(string $key, string $default = ''): string
    {
        $raw = trim((string) env($key, $default));

        return trim($raw, "\"'");
    }

    public function __construct() { 
        $connectionString = $this->envString('AZURE_STORAGE_CONNECTION_STRING', '');
        if (!empty($connectionString)) {
            $this->client = BlobRestProxy::createBlobService($connectionString);
        } else {
            $this->client = BlobRestProxy::createBlobService(sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
                $this->envString('AZURE_STORAGE_NAME', ''),
                $this->envString('AZURE_STORAGE_KEY', '')
            ));
        }
        
        $this->container = $this->envString('AZURE_STORAGE_CONTAINER', ''); 
        $this->invoiceProgressService = app(InvoiceProgressService::class);
    } 

    private function inferInvoiceStage(string $fileName): ?string
    {
        foreach (['initial', 'first', 'second', 'third', 'final'] as $stage) {
            if (preg_match('/^' . preg_quote($stage, '/') . '[-_]/i', $fileName) === 1) {
                return $stage;
            }
        }

        return null;
    }

    private function resolveActor(Request $request): array
    {
        $authUser = $request->user();

        return [
            'role' => strtolower((string) ($authUser?->role ?? $request->header('X-User-Role', ''))),
            'firmID' => (string) ($authUser?->firmID ?? $request->header('X-User-FirmID', '')),
        ];
    }

    private function extractCaseIdFromPath(string $path): ?int
    {
        if (preg_match('#cases/(\d+)(?:/|$)#', $path, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function denyIfArchived(Request $request, ?int $caseId): ?JsonResponse
    {
        if (!$caseId) {
            return null;
        }

        $case = LawCase::find($caseId);
        if (!$case) {
            return response()->json(['error' => 'Case not found'], 404);
        }

        $isArchived = strtolower((string) $case->status) === 'archived';

        if ($isArchived) {
            return response()->json([
                'error' => 'This case is archived. Upload and delete actions are blocked.'
            ], 403);
        }

        return null;
    }

    private function deletePathMetadata(string $path): int
    {
        return FileMetadata::where('blob_path', $path)->delete();
    }

    private function syncInvoiceDeletion(string $path, ?int $caseId): ?float
    {
        $deletedInvoices = Invoice::where('blob_path', $path)->delete();

        if ($deletedInvoices <= 0 || !$caseId) {
            return null;
        }

        $lawCase = LawCase::find($caseId);
        if (!$lawCase) {
            return null;
        }

        return $this->invoiceProgressService->syncCaseProgress($lawCase);
    }

    private function listCacheKey(string $folder): string
    {
        return 'azure:list:' . md5($folder);
    }

    private function invalidateListCacheByPath(string $path): void
    {
        $folder = trim((string) Str::beforeLast($path, '/'));
        if ($folder !== '') {
            Cache::forget($this->listCacheKey($folder . '/'));
        }
    }
    
    /* |-------------------------------------------------------------------------- | 1️⃣ Upload File Into Folder |-------------------------------------------------------------------------- */ 
    public function upload(Request $request) { 
        $actorRole = strtolower((string) ($request->user()?->role ?? $request->header('X-User-Role', '')));
        if ($actorRole === 'junioradmin') {
            return response()->json(['error' => 'Junior admin cannot upload documents'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:10240',
            'folder' => 'required|string',
            'invoice_stage' => 'nullable|in:initial,first,second,third,final',
            'expected_amount' => 'nullable|numeric|min:0.01',
            'paid_amount' => 'nullable|numeric|min:0',
        ]);
        $file = $request->file('file'); 
        $folderPath = rtrim($request->folder, '/') . '/'; 

        $requestCaseId = $request->input('caseId');
        $caseId = is_numeric($requestCaseId) ? (int) $requestCaseId : $this->extractCaseIdFromPath($folderPath);
        if ($denied = $this->denyIfArchived($request, $caseId)) {
            return $denied;
        }

        $isInvoiceFolder = preg_match('#/invoices/$#i', $folderPath) === 1;
        if ($isInvoiceFolder && $caseId) {
            $invoiceStage = $request->input('invoice_stage') ?: $this->inferInvoiceStage($file->getClientOriginalName());
            if (!$invoiceStage) {
                return response()->json([
                    'error' => 'invoice_stage is required for invoice uploads'
                ], 422);
            }

            $expectedAmountInput = $request->input('expected_amount');
            if ($expectedAmountInput === null || $expectedAmountInput === '') {
                return response()->json([
                    'error' => 'expected_amount is required for invoice uploads'
                ], 422);
            }
        }

        $blobName = $folderPath . $file->getClientOriginalName(); 
        $content = fopen($file->getPathname(), 'r'); 
        $options = new CreateBlockBlobOptions(); 
        $options->setContentType($file->getMimeType()); 
        $this->client->createBlockBlob($this->container, $blobName, $content, $options); 

        $updatedProgress = null;
        if ($isInvoiceFolder && $caseId) {
            $invoiceStage = $request->input('invoice_stage') ?: $this->inferInvoiceStage($file->getClientOriginalName());

            if (!$invoiceStage) {
                return response()->json([
                    'error' => 'invoice_stage is required for invoice uploads'
                ], 422);
            }

            $expectedAmountInput = $request->input('expected_amount');
            if ($expectedAmountInput === null || $expectedAmountInput === '') {
                return response()->json([
                    'error' => 'expected_amount is required for invoice uploads'
                ], 422);
            }

            $expectedAmount = (float) $expectedAmountInput;
            $paidAmount = (float) $request->input('paid_amount', 0);

            FileMetadata::create([
                'type' => 'invoice_payment',
                'case_id' => (int) $caseId,
                'category' => 'invoices',
                'uploader_user_id' => (int) ($request->user()?->id ?? 0),
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'blob_path' => $blobName,
                'content_hash_sha256' => hash_file('sha256', $file->getPathname()),
                'status' => 'active',
                'invoice_stage' => $invoiceStage,
                'expected_amount' => $expectedAmount,
                'paid_amount' => $paidAmount,
            ]);

            $lawCase = LawCase::find((int) $caseId);
            if ($lawCase) {
                $updatedProgress = $this->invoiceProgressService->syncCaseProgress($lawCase);
            }
        }

        Cache::forget($this->listCacheKey($folderPath));
        return response()->json([
            'message' => 'File uploaded successfully',
            'path' => $blobName,
            'case_progress' => $updatedProgress,
        ]); 
    } 
    
    /* |-------------------------------------------------------------------------- | 2️⃣ Read File By Full Path |-------------------------------------------------------------------------- */ 
    public function read($path) { 
        try { 
            $blob = $this->client->getBlob($this->container, $path); 
            $content = stream_get_contents($blob->getContentStream()); 
            return response($content, 200) ->header('Content-Type', $blob->getProperties()->getContentType()) ->header('Content-Disposition', 'inline; filename="' . basename($path) . '"'); 
        } catch (\Exception $e) { 
            return response()->json([ 'error' => 'File not found: ' . $e->getMessage() ], 404); 
        } 
    } 
    /* |-------------------------------------------------------------------------- | 3️⃣ Delete File By Full Path |-------------------------------------------------------------------------- */ 
    public function delete(Request $request, $path) { 
        $caseId = $this->extractCaseIdFromPath((string) $path);
        if ($denied = $this->denyIfArchived($request, $caseId)) {
            return $denied;
        }

        try { 
            $this->client->deleteBlob($this->container, $path);
            $this->invalidateListCacheByPath((string) $path);

            try {
                $deletedMetadata = $this->deletePathMetadata((string) $path);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'File deleted from storage, but failed to delete MongoDB path metadata.',
                    'path' => (string) $path,
                ], 500);
            }

            $updatedProgress = null;
            if (preg_match('#/invoices/#i', (string) $path) === 1) {
                $updatedProgress = $this->syncInvoiceDeletion((string) $path, $caseId);
            }

            return response()->json([
                'message' => 'File deleted successfully',
                'path' => (string) $path,
                'metadata_deleted_count' => $deletedMetadata,
                'case_progress' => $updatedProgress,
            ]);
        } catch (\Exception $e) { 
            return response()->json([ 'error' => 'Delete failed: ' . $e->getMessage() ], 404); 
        } 
    } 

    // DELETE /api/files?path=...
    public function deleteByQuery(Request $request)
    {
        $path = trim((string) $request->query('path', ''));

        if ($path === '') {
            return response()->json(['error' => 'Path is required'], 400);
        }

        // Safety: delete only one concrete blob, never a folder-like path.
        if (str_ends_with($path, '/')) {
            return response()->json([
                'error' => 'Folder paths are not allowed. Provide a full file path.'
            ], 422);
        }

        $fileName = basename($path);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return response()->json([
                'error' => 'Invalid file path. Provide a full file path.'
            ], 422);
        }

        $caseId = $this->extractCaseIdFromPath($path);
        if ($denied = $this->denyIfArchived($request, $caseId)) {
            return $denied;
        }

        try {
            $this->client->deleteBlob($this->container, $path);
            $this->invalidateListCacheByPath($path);

            try {
                $deletedMetadata = $this->deletePathMetadata($path);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'File deleted from storage, but failed to delete MongoDB path metadata.',
                    'path' => $path,
                ], 500);
            }

            $updatedProgress = null;
            if (preg_match('#/invoices/#i', $path) === 1) {
                $updatedProgress = $this->syncInvoiceDeletion($path, $caseId);
            }

            return response()->json([
                'message' => 'File deleted successfully',
                'path' => $path,
                'metadata_deleted_count' => $deletedMetadata,
                'case_progress' => $updatedProgress,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Delete failed: ' . $e->getMessage()], 404);
        }
    }
    /* |-------------------------------------------------------------------------- | 4️⃣ List Files By Folder |-------------------------------------------------------------------------- */ 
    public function list(Request $request) { 
        try { 
            $folder = $request->query('folder'); 
            $actor = $this->resolveActor($request);
            
            // Allow admins to list all files without a folder parameter
            if (!$folder && !in_array(strtolower((string) $actor['role']), ['admin', 'adminstaff'], true)) {
                return response()->json([ 'error' => 'Folder is required' ], 400); 
            }

            $listPrefix = $folder ?? 'cases/';

            $files = Cache::remember($this->listCacheKey((string) $listPrefix), 15, function () use ($listPrefix, $folder) {
                $options = new ListBlobsOptions(); 
                $options->setPrefix($listPrefix); 
                $blobs = $this->client->listBlobs($this->container, $options); 

                $files = [];
                foreach ($blobs->getBlobs() as $blob) { 
                    $name = $blob->getName();
                    $files[] = str_replace((string) $listPrefix, '', $name); 
                }

                return array_values(array_filter($files));
            });
            
            return response()->json([ 'folder' => $folder ?? 'all', 'files' => $files ]); 
        } catch (\Exception $e) { 
            $folder = (string) $request->query('folder', '');
            $message = $e->getMessage();

            // Dev fallback: if Azure auth is failing, still expose the seed placeholder
            // so folder tabs can render instead of being blocked by infrastructure issues.
            if (
                !empty($folder) &&
                (Str::contains($message, 'AuthorizationFailure') || Str::contains($message, 'not authorized')) &&
                preg_match('#^cases/\d+/(documents|reports|invoices)/$#', $folder)
            ) {
                return response()->json([
                    'folder' => $folder,
                    'files' => ['placeholder.txt'],
                    'warning' => 'Azure authorization failed; showing fallback placeholder only.'
                ]);
            }

            return response()->json([ 'error' => $e->getMessage() ], 500); 
        } 
    }
    
    /*
    |--------------------------------------------------------------------------
    | 5️⃣ Create Blob From String (For Seeder / Folder Creation)
    |--------------------------------------------------------------------------
    */
    public function createBlobFromString($blobName, $content, $contentType = 'text/plain')
    {
        $options = new CreateBlockBlobOptions();
        $options->setContentType($contentType);

        $this->client->createBlockBlob(
            $this->container,
            $blobName,
            $content,
            $options
        );

        return true;
    }

}