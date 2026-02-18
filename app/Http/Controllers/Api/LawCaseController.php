<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LawCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Metadata;

class LawCaseController extends Controller
{
    // GET /api/cases
    public function index()
    {
        $cases = LawCase::with(['lawyer:id,name', 'client:id,name'])
            ->get()
            ->map(function ($case) {

                $metadata = \App\Models\Metadata::cases()
                    ->where('case_id', (string) $case->caseId)
                    ->first();

                return [
                    'id'         => $case->caseId,
                    'caseName'   => $case->title,
                    'clientName' => $case->client?->name,
                    'lawyerName' => $case->lawyer?->name,
                    'lawyerFirmID' => $case->lawyerFirmID,
                    'clientFirmID' => $case->clientFirmID,
                    'status'     => $case->status,
                    'blob_folder_path' => $metadata?->blob_folder_path
                ];
            });

        return response()->json($cases);
    }

    // POST /api/registercases
    public function store(Request $request){
        DB::beginTransaction();

        try {
            Log::info('Incoming case request', $request->all());

            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'description' => 'required|string',
                'lawyerID'    => 'required|string|exists:users,firmID',
                'clientID'    => 'required|string|exists:users,firmID',
            ]);

            // Find lawyer and client
            $lawyer = User::where('firmID', $validated['lawyerID'])
                        ->where('role', 'lawyer')
                        ->firstOrFail();

            $client = User::where('firmID', $validated['clientID'])
                        ->where('role', 'client')
                        ->firstOrFail();

            // Create case in PostgreSQL
            $case = LawCase::create([
                'title'         => $validated['title'],
                'description'   => $validated['description'],
                'lawyerID'      => $lawyer->id,
                'clientID'      => $client->id,
                'lawyerFirmID'  => $lawyer->firmID,
                'clientFirmID'  => $client->firmID,
            ]);

            // Store metadata in MongoDB
            $metadata = Metadata::storeCase(
                (string) $case->caseId,
                $lawyer->firmID,
                $client->firmID
            );

            // Create Azure folders and metadata.txt
            $azureController = new \App\Http\Controllers\AzureController();

            $caseFolder = "cases/{$case->caseId}/";
            $subFolders = ['documents', 'reports', 'cheques'];

            // Create a small placeholder file for each subfolder
            foreach ($subFolders as $folder) {
                $blobName = $caseFolder . $folder . '/placeholder.txt';
                $content = "This folder: {$folder} for case {$case->caseId}";
                $azureController->createBlobFromString($blobName, $content);
            }

            // Create metadata.txt in case folder
            $metadataJson = json_encode($metadata->toArray(), JSON_PRETTY_PRINT);
            $azureController->createBlobFromString($caseFolder . 'metadata.txt', $metadataJson);

            DB::commit();

            return response()->json([
                'message' => 'Case created successfully with Azure folders',
                'caseId'  => $case->caseId,
                'azureFolder' => $caseFolder
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Case creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Case creation failed'
            ], 500);
        }
    }

    // PUT /api/cases/{caseId}
    public function update(Request $request, int $caseId){
        $case = LawCase::find($caseId);

        if (!$case) {
            return response()->json(['message' => 'Case not found'], 404);
        }

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'status'      => 'sometimes|in:Active,Archived',

            // firmID-based reassignment (optional)
            'lawyerID'    => 'sometimes|string|exists:users,firmID',
            'clientID'    => 'sometimes|string|exists:users,firmID',
        ]);

        // Handle lawyer reassignment
        if (isset($validated['lawyerID'])) {
            $lawyer = User::where('firmID', $validated['lawyerID'])
                ->where('role', 'lawyer')
                ->firstOrFail();

            $case->lawyerID = $lawyer->id;
            $case->lawyerFirmID = $lawyer->firmID;
        }

        // Handle client reassignment
        if (isset($validated['clientID'])) {
            $client = User::where('firmID', $validated['clientID'])
                ->where('role', 'client')
                ->firstOrFail();

            $case->clientID = $client->id;
            $case->clientFirmID = $client->firmID;
        }

        // Update simple fields
        $case->fill(collect($validated)->except(['lawyerID', 'clientID'])->toArray());
        $case->save();

        return response()->json([
            'message' => 'Case updated successfully',
            'case' => [
                'caseId'      => $case->caseId,
                'title'       => $case->title,
                'status'      => $case->status,
                'lawyerFirmID'=> $case->lawyerFirmID,
                'clientFirmID'=> $case->clientFirmID,
                'updated_at'  => $case->updated_at,
            ]
        ]);
    }

}