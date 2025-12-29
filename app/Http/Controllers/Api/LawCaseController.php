<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LawCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LawCaseController extends Controller
{
    // GET /api/cases
    public function index()
    {
        $cases = LawCase::with(['lawyer:id,name', 'client:id,name'])
            ->get()
            ->map(function ($case) {
                return [
                    'id'         => $case->caseId,
                    'caseName'   => $case->title,
                    'clientName' => $case->client?->name,
                    'lawyerName' => $case->lawyer?->name,
                    'status'     => $case->status,
                ];
            });

        return response()->json($cases);
    }

    // POST /api/registercases
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            Log::info('Incoming case request', $request->all());

            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'description' => 'required|string',
                'lawyerID'    => 'required|string|exists:users,firmID',
                'clientID'    => 'required|string|exists:users,firmID',
            ]);

            $lawyer = User::where('firmID', $validated['lawyerID'])
                          ->where('role', 'lawyer')
                          ->firstOrFail();

            $client = User::where('firmID', $validated['clientID'])
                          ->where('role', 'client')
                          ->firstOrFail();

            $case = LawCase::create([
                'title'         => $validated['title'],
                'description'   => $validated['description'],
                'lawyerID'      => $lawyer->id,
                'clientID'      => $client->id,
                'lawyerFirmID'  => $lawyer->firmID,
                'clientFirmID'  => $client->firmID,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Case created',
                'caseId'  => $case->caseId,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Case creation failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}