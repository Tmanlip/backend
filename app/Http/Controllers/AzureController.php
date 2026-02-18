<?php 
namespace App\Http\Controllers; 

use Illuminate\Http\Request; 
use MicrosoftAzure\Storage\Blob\BlobRestProxy; 
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions; 
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions; 
use App\Models\Metadata; 

class AzureController extends Controller { 
    private $client; 
    private $container; 
    public function __construct() { 
        $this->client = BlobRestProxy::createBlobService( sprintf( 'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net', 
        env('AZURE_STORAGE_NAME'), env('AZURE_STORAGE_KEY') ) ); 
        
        $this->container = env('AZURE_STORAGE_CONTAINER'); 
    } 
    
    /* |-------------------------------------------------------------------------- | 1️⃣ Upload File Into Folder |-------------------------------------------------------------------------- */ 
    public function upload(Request $request) { 
        $request->validate([ 'file' => 'required|file|max:10240', 'folder' => 'required|string' ]); 
        $file = $request->file('file'); 
        $folderPath = rtrim($request->folder, '/') . '/'; 
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
    public function delete($path) { 
        try { 
            $this->client->deleteBlob($this->container, $path); 
            return response()->json([ 'message' => 'File deleted successfully' ]); 
        } catch (\Exception $e) { 
            return response()->json([ 'error' => 'Delete failed: ' . $e->getMessage() ], 404); 
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
            return response()->json([ 'error' => $e->getMessage() ], 500); 
        } 
    } 
}