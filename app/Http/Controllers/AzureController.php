<?php 
namespace App\Http\Controllers; 

use Illuminate\Http\Request; 
use Illuminate\Support\Str;
use MicrosoftAzure\Storage\Blob\BlobRestProxy; 
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions; 
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions; 
use App\Models\Metadata; 
use App\Models\LawCase;
use Illuminate\Http\JsonResponse;

class AzureController extends Controller { 
    private $client; 
    private $container; 
    public function __construct() { 
        $connectionString = env('AZURE_STORAGE_CONNECTION_STRING');
        if (!empty($connectionString)) {
            $this->client = BlobRestProxy::createBlobService($connectionString);
        } else {
            $this->client = BlobRestProxy::createBlobService(sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
                env('AZURE_STORAGE_NAME'),
                env('AZURE_STORAGE_KEY')
            ));
        }
        
        $this->container = env('AZURE_STORAGE_CONTAINER'); 
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

    private function denyIfArchivedAndNotAdmin(Request $request, ?int $caseId): ?JsonResponse
    {
        if (!$caseId) {
            return null;
        }

        $case = LawCase::find($caseId);
        if (!$case) {
            return response()->json(['error' => 'Case not found'], 404);
        }

        $actor = $this->resolveActor($request);
        $isAdmin = $actor['role'] === 'admin';
        $isArchived = strtolower((string) $case->status) === 'archived';

        if ($isArchived && !$isAdmin) {
            return response()->json([
                'error' => 'This case is archived. Only admin can make changes.'
            ], 403);
        }

        return null;
    }
    
    /* |-------------------------------------------------------------------------- | 1️⃣ Upload File Into Folder |-------------------------------------------------------------------------- */ 
    public function upload(Request $request) { 
        $request->validate([ 'file' => 'required|file|max:10240', 'folder' => 'required|string' ]); 
        $file = $request->file('file'); 
        $folderPath = rtrim($request->folder, '/') . '/'; 

        $requestCaseId = $request->input('caseId');
        $caseId = is_numeric($requestCaseId) ? (int) $requestCaseId : $this->extractCaseIdFromPath($folderPath);
        if ($denied = $this->denyIfArchivedAndNotAdmin($request, $caseId)) {
            return $denied;
        }

        $blobName = $folderPath . $file->getClientOriginalName(); 
        $content = fopen($file->getPathname(), 'r'); 
        $options = new CreateBlockBlobOptions(); 
        $options->setContentType($file->getMimeType()); 
        $this->client->createBlockBlob($this->container, $blobName, $content, $options); 
        return response()->json([ 'message' => 'File uploaded successfully', 'path' => $blobName ]); 
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
        if ($denied = $this->denyIfArchivedAndNotAdmin($request, $caseId)) {
            return $denied;
        }

        try { 
            $this->client->deleteBlob($this->container, $path); 
            return response()->json([ 'message' => 'File deleted successfully' ]); 
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
        if ($denied = $this->denyIfArchivedAndNotAdmin($request, $caseId)) {
            return $denied;
        }

        try {
            $this->client->deleteBlob($this->container, $path);
            return response()->json(['message' => 'File deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Delete failed: ' . $e->getMessage()], 404);
        }
    }
    /* |-------------------------------------------------------------------------- | 4️⃣ List Files By Folder |-------------------------------------------------------------------------- */ 
    public function list(Request $request) { 
        try { 
            $folder = $request->query('folder'); 
            if (!$folder) { 
                return response()->json([ 'error' => 'Folder is required' ], 400); 
            } 
            
            $options = new ListBlobsOptions(); 
            $options->setPrefix($folder); 
            $blobs = $this->client->listBlobs($this->container, $options); 
            $files = []; foreach ($blobs->getBlobs() as $blob) { 
                $name = $blob->getName(); // Remove folder prefix 
                $files[] = str_replace($folder, '', $name); 
            } 
            
            return response()->json([ 'folder' => $folder, 'files' => array_values(array_filter($files)) ]); 
        } catch (\Exception $e) { 
            $folder = (string) $request->query('folder', '');
            $message = $e->getMessage();

            // Dev fallback: if Azure auth is failing, still expose the seed placeholder
            // so folder tabs can render instead of being blocked by infrastructure issues.
            if (
                !empty($folder) &&
                (Str::contains($message, 'AuthorizationFailure') || Str::contains($message, 'not authorized')) &&
                preg_match('#^cases/\d+/(documents|reports|cheques)/$#', $folder)
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