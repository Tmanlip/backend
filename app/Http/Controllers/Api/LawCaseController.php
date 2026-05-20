<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LawCase;
use App\Models\User;
use App\Models\Metadata;
use App\Services\CaseNotificationService;
use App\Services\CaseInvoiceFinancialSyncService;
use App\Services\InvoiceProgressService;
use App\Support\FeePhaseCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\FileMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class LawCaseController extends Controller
{
    private const CASE_TYPE_TO_PRACTICE_AREA = [
        'Litigation' => 'Civil',
        'Corporate' => 'Corporate',
        'Criminal' => 'Criminal',
    ];

    public function __construct(
        private readonly CaseNotificationService $caseNotificationService,
        private readonly InvoiceProgressService $invoiceProgressService,
        private readonly CaseInvoiceFinancialSyncService $caseInvoiceFinancialSyncService
    ) {
    }

    private function buildDummyExpectedPayments(string $caseType): array
    {
        return match ($caseType) {
            'Criminal' => [
                'expected_initial_payment' => 1200,
                'expected_first_payment' => 1800,
                'expected_second_payment' => 2000,
                'expected_third_payment' => 2200,
                'expected_final_payment' => 2800,
            ],
            'Corporate' => [
                'expected_initial_payment' => 3000,
                'expected_first_payment' => 4500,
                'expected_second_payment' => 5000,
                'expected_third_payment' => 5500,
                'expected_final_payment' => 7000,
            ],
            default => [
                'expected_initial_payment' => 1500,
                'expected_first_payment' => 2500,
                'expected_second_payment' => 3000,
                'expected_third_payment' => 3000,
                'expected_final_payment' => 4000,
            ],
        };
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

    private function mapExpectedByStageToCaseColumns(array $expectedByStage): array
    {
        return [
            'expected_initial_payment' => (float) ($expectedByStage['initial'] ?? 0),
            'expected_first_payment' => (float) ($expectedByStage['first'] ?? 0),
            'expected_second_payment' => (float) ($expectedByStage['second'] ?? 0),
            'expected_third_payment' => (float) ($expectedByStage['third'] ?? 0),
            'expected_final_payment' => (float) ($expectedByStage['final'] ?? 0),
        ];
    }

    private function buildBalancePaymentPhases(LawCase $case): array
    {
        return [
            'initial' => (float) ($case->balance_initial_payment ?? 0),
            'first' => (float) ($case->balance_first_payment ?? 0),
            'second' => (float) ($case->balance_second_payment ?? 0),
            'third' => (float) ($case->balance_third_payment ?? 0),
            'final' => (float) ($case->balance_final_payment ?? 0),
        ];
    }


    private function parseFeeRangeAmount(string $estimationFees): ?array
    {
        if (!preg_match_all('/\d+(?:[\.,]\d+)?/', $estimationFees, $matches)) {
            return null;
        }

        $numbers = $matches[0] ?? [];
        if (count($numbers) < 1) {
            return null;
        }

        $min = (float) str_replace(',', '', $numbers[0]);
        $max = count($numbers) > 1
            ? (float) str_replace(',', '', $numbers[1])
            : $min;

        if ($min < 0 || $max < 0 || $min > $max) {
            return null;
        }

        return [
            'rangeMin' => round($min, 2),
            'rangeMax' => round($max, 2),
            'estimatedAmount' => round(($min + $max) / 2, 2),
        ];
    }

    private function stripUtf8Bom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    private function normalizeFeeSourceItem(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            $normalizedKey = strtolower(str_replace([' ', '_', '-'], '', (string) $key));
            $normalized[$normalizedKey] = is_string($value) ? $this->stripUtf8Bom(trim($value)) : $value;
        }

        return [
            'practiceArea' => (string) ($normalized['practicearea'] ?? ''),
            'typeOfWork' => (string) ($normalized['typeofwork'] ?? ''),
            'estimationFees' => (string) ($normalized['estimationfees'] ?? ''),
        ];
    }

    public function typeOfWorkOptions(Request $request): JsonResponse
    {
        try {
            $caseType = (string) $request->query('caseType', 'Litigation');
            $practiceArea = self::CASE_TYPE_TO_PRACTICE_AREA[$caseType] ?? 'Civil';
            $jsonPath = storage_path('app/chatbot/operations-playbook-excel/TypeOfWork_EstimationFees.json');
            $csvPath = storage_path('app/chatbot/operations-playbook-excel/TypeOfWork_EstimationFees.csv');

            $sourceItems = [];

            if (file_exists($jsonPath)) {
                $raw = @file_get_contents($jsonPath);
                $decoded = json_decode($this->stripUtf8Bom((string) $raw), true);
                if (is_array($decoded)) {
                    $sourceItems = $decoded;
                }
            }

            if (empty($sourceItems) && file_exists($csvPath)) {
                $rows = @file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($rows) && count($rows) > 1) {
                    $headers = str_getcsv($this->stripUtf8Bom((string) $rows[0]));

                    for ($i = 1; $i < count($rows); $i++) {
                        $values = str_getcsv((string) $rows[$i]);
                        if (count($values) !== count($headers)) {
                            continue;
                        }

                        $item = [];
                        foreach ($headers as $index => $header) {
                            $item[trim((string) $header)] = $values[$index] ?? null;
                        }

                        $sourceItems[] = $item;
                    }
                }
            }

            $result = [];
            $seen = [];

            foreach ($sourceItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalizedItem = $this->normalizeFeeSourceItem($item);
                $itemPracticeArea = trim((string) ($normalizedItem['practiceArea'] ?? ''));
                if ($itemPracticeArea !== $practiceArea) {
                    continue;
                }

                $typeOfWork = trim((string) ($normalizedItem['typeOfWork'] ?? ''));
                $estimationFees = trim((string) ($normalizedItem['estimationFees'] ?? ''));

                if ($typeOfWork === '' || $estimationFees === '') {
                    continue;
                }

                $range = $this->parseFeeRangeAmount($estimationFees);
                if ($range === null) {
                    continue;
                }

                $dedupeKey = strtolower($typeOfWork . '|' . $estimationFees);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $result[] = [
                    'practiceArea' => $itemPracticeArea,
                    'typeOfWork' => $typeOfWork,
                    'estimationFeesRange' => $estimationFees,
                    'estimatedAmount' => $range['estimatedAmount'],
                    'rangeMin' => $range['rangeMin'],
                    'rangeMax' => $range['rangeMax'],
                ];
            }

            return response()->json([
                'caseType' => $caseType,
                'practiceArea' => $practiceArea,
                'items' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to load type of work options', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Unable to load type of work options.',
                'items' => [],
            ], 200);
        }
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

    private function resolveActor(Request $request): array
    {
        $authUser = $request->user();

        return [
            'role' => strtolower((string) ($authUser?->role ?? $request->header('X-User-Role', ''))),
            'firmID' => (string) ($authUser?->firmID ?? $request->header('X-User-FirmID', '')),
            'userId' => $authUser?->id ? (int) $authUser->id : null,
        ];
    }

    private function denyIfArchivedAndNotAdmin(Request $request, LawCase $case): ?JsonResponse
    {
        $actor = $this->resolveActor($request);
        $isAdmin = in_array($actor['role'], ['admin', 'adminstaff'], true);
        $isArchived = strtolower((string) $case->status) === 'archived';

        if ($isArchived && !$isAdmin) {
            return response()->json([
                'message' => 'This case is archived. Only admin can make changes.'
            ], 403);
        }

        return null;
    }

    // GET /api/cases
    public function index(Request $request)
    {
        $actor = $this->resolveActor($request);

        $cases = LawCase::with(['lawyer:id,name', 'client:id,name'])->get();
        $caseIds = $cases->pluck('caseId')->map(fn ($id) => (int) $id)->all();
        $encryptedByCaseId = $this->getEncryptedDocumentsByCaseIds(
            $caseIds,
            $actor['userId'],
            $actor['role']
        );

        $cases = $cases->map(function ($case) use ($encryptedByCaseId) {
                $caseId = (int) $case->caseId;
                $blobFolderPath = "cases/{$caseId}/";
                $encryptedDocuments = $encryptedByCaseId[$caseId] ?? [];
            $freshProgress = $this->invoiceProgressService->recalculateForCase($caseId);

                return [
                    'id'               => $case->caseId,
                    'caseNumber'       => $case->caseNumber,
                    'caseName'         => $case->title,
                    'caseType'         => (string) ($case->caseType ?? 'Litigation'),
                    'case_type_fee_json' => $case->case_type_fee_json ?? [
                        'initial' => [],
                        'first' => [],
                        'second' => [],
                        'third' => [],
                        'final' => [],
                    ],
                    'lawyerID'         => $case->lawyerID,
                    'clientID'         => $case->clientID,
                    'clientName'       => $case->client?->name,
                    'lawyerName'       => $case->lawyer?->name,
                    'lawyerFirmID'     => $case->lawyerFirmID,
                    'clientFirmID'     => $case->clientFirmID,
                    'oppositionLawyerName' => $case->oppositionLawyerName,
                    'oppositionLawyerFirmID' => $case->oppositionLawyerFirmID,
                    'status'           => $case->status,
                    'progress'         => $freshProgress,
                    'expected_payment_phases' => $this->buildExpectedPaymentPhases($case),
                    'invoice_payment_phases' => $this->buildInvoicePaymentPhases($case),
                    'blob_folder_path' => $blobFolderPath,
                    'created_at'       => $case->created_at,
                    'updated_at'       => $case->updated_at,
                    'encrypted_documents' => $encryptedDocuments,
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
                'caseType'    => 'nullable|in:Litigation,Criminal,Corporate',
                'description' => 'required|string',
                'lawyerID'    => 'required|string|exists:users,firmID',
                'clientID'    => 'required|string|exists:users,firmID',
                'case_type_fee_json' => 'nullable|array',
                'case_type_fee_json.initial' => 'sometimes|array|max:5',
                'case_type_fee_json.first' => 'sometimes|array|max:5',
                'case_type_fee_json.second' => 'sometimes|array|max:5',
                'case_type_fee_json.third' => 'sometimes|array|max:5',
                'case_type_fee_json.final' => 'sometimes|array|max:5',
                'case_type_fee_json.initial.*.typeOfWork' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.first.*.typeOfWork' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.second.*.typeOfWork' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.third.*.typeOfWork' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.final.*.typeOfWork' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.initial.*.type_of_work' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.first.*.type_of_work' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.second.*.type_of_work' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.third.*.type_of_work' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.final.*.type_of_work' => 'nullable|string|min:3|max:100',
                'case_type_fee_json.initial.*.selectedFee' => 'nullable|numeric|min:0',
                'case_type_fee_json.first.*.selectedFee' => 'nullable|numeric|min:0',
                'case_type_fee_json.second.*.selectedFee' => 'nullable|numeric|min:0',
                'case_type_fee_json.third.*.selectedFee' => 'nullable|numeric|min:0',
                'case_type_fee_json.final.*.selectedFee' => 'nullable|numeric|min:0',
                'case_type_fee_json.initial.*.selected_fee' => 'nullable|numeric|min:0',
                'case_type_fee_json.first.*.selected_fee' => 'nullable|numeric|min:0',
                'case_type_fee_json.second.*.selected_fee' => 'nullable|numeric|min:0',
                'case_type_fee_json.third.*.selected_fee' => 'nullable|numeric|min:0',
                'case_type_fee_json.final.*.selected_fee' => 'nullable|numeric|min:0',
                'case_type_fee_json.initial.*.rangeMin' => 'nullable|numeric|min:0',
                'case_type_fee_json.first.*.rangeMin' => 'nullable|numeric|min:0',
                'case_type_fee_json.second.*.rangeMin' => 'nullable|numeric|min:0',
                'case_type_fee_json.third.*.rangeMin' => 'nullable|numeric|min:0',
                'case_type_fee_json.final.*.rangeMin' => 'nullable|numeric|min:0',
                'case_type_fee_json.initial.*.rangeMax' => 'nullable|numeric|min:0',
                'case_type_fee_json.first.*.rangeMax' => 'nullable|numeric|min:0',
                'case_type_fee_json.second.*.rangeMax' => 'nullable|numeric|min:0',
                'case_type_fee_json.third.*.rangeMax' => 'nullable|numeric|min:0',
                'case_type_fee_json.final.*.rangeMax' => 'nullable|numeric|min:0',
                'case_type_fee_json.initial.*.range_min' => 'nullable|numeric|min:0',
                'case_type_fee_json.first.*.range_min' => 'nullable|numeric|min:0',
                'case_type_fee_json.second.*.range_min' => 'nullable|numeric|min:0',
                'case_type_fee_json.third.*.range_min' => 'nullable|numeric|min:0',
                'case_type_fee_json.final.*.range_min' => 'nullable|numeric|min:0',
                'case_type_fee_json.initial.*.range_max' => 'nullable|numeric|min:0',
                'case_type_fee_json.first.*.range_max' => 'nullable|numeric|min:0',
                'case_type_fee_json.second.*.range_max' => 'nullable|numeric|min:0',
                'case_type_fee_json.third.*.range_max' => 'nullable|numeric|min:0',
                'case_type_fee_json.final.*.range_max' => 'nullable|numeric|min:0',
                'expected_initial_payment' => 'nullable|numeric|min:0',
                'expected_first_payment' => 'nullable|numeric|min:0',
                'expected_second_payment' => 'nullable|numeric|min:0',
                'expected_third_payment' => 'nullable|numeric|min:0',
                'expected_final_payment' => 'nullable|numeric|min:0',
                'oppositionLawyerName' => 'nullable|string|max:255',
                'oppositionLawyerFirmID' => 'nullable|string|max:255',
            ]);

            if (!empty($validated['case_type_fee_json']) && is_array($validated['case_type_fee_json'])) {
                foreach (FeePhaseCalculator::STAGES as $stage) {
                    $items = $validated['case_type_fee_json'][$stage] ?? [];
                    if (!is_array($items)) {
                        continue;
                    }

                    foreach ($items as $index => $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $typeOfWork = trim((string) ($item['typeOfWork'] ?? $item['type_of_work'] ?? ''));
                        $selectedFee = $item['selectedFee'] ?? $item['selected_fee'] ?? null;
                        $rangeMin = $item['rangeMin'] ?? $item['range_min'] ?? null;
                        $rangeMax = $item['rangeMax'] ?? $item['range_max'] ?? null;

                        if ($typeOfWork === '') {
                            throw ValidationException::withMessages([
                                "case_type_fee_json.$stage.$index.typeOfWork" => 'Type of Work is required.',
                            ]);
                        }

                        if ($rangeMin === null || $rangeMax === null) {
                            throw ValidationException::withMessages([
                                "case_type_fee_json.$stage.$index.rangeMin" => 'Range minimum and maximum are required.',
                            ]);
                        }

                        $min = (float) $rangeMin;
                        $max = (float) $rangeMax;
                        $fee = (float) ($selectedFee ?? 0);

                        if ($min > $max) {
                            throw ValidationException::withMessages([
                                "case_type_fee_json.$stage.$index.rangeMax" => 'Range maximum must be greater than or equal to range minimum.',
                            ]);
                        }

                        if ($fee < $min || $fee > $max) {
                            throw ValidationException::withMessages([
                                "case_type_fee_json.$stage.$index.selectedFee" => 'Selected fee must be within the configured range.',
                            ]);
                        }
                    }
                }
            }

            // Find lawyer and client
            $lawyer = User::where('firmID', $validated['lawyerID'])
                        ->where('role', 'lawyer')
                        ->firstOrFail();

            $client = User::where('firmID', $validated['clientID'])
                        ->where('role', 'client')
                        ->firstOrFail();

            $caseType = (string) ($validated['caseType'] ?? 'Litigation');
            $dummyExpectedPayments = $this->buildDummyExpectedPayments($caseType);
            $inputExpectedPayments = array_filter([
                'expected_initial_payment' => $validated['expected_initial_payment'] ?? null,
                'expected_first_payment' => $validated['expected_first_payment'] ?? null,
                'expected_second_payment' => $validated['expected_second_payment'] ?? null,
                'expected_third_payment' => $validated['expected_third_payment'] ?? null,
                'expected_final_payment' => $validated['expected_final_payment'] ?? null,
            ], static fn ($value) => $value !== null);
            $expectedPayments = array_merge($dummyExpectedPayments, $inputExpectedPayments);

            if (array_key_exists('case_type_fee_json', $validated)) {
                $phaseFees = FeePhaseCalculator::normalizePhaseFees($validated['case_type_fee_json']);
                $expectedPayments = $this->mapExpectedByStageToCaseColumns(
                    FeePhaseCalculator::computeExpectedByStage($phaseFees)
                );
                $validated['case_type_fee_json'] = $phaseFees;
            }

            $initialBalances = [
                'balance_initial_payment' => (float) ($expectedPayments['expected_initial_payment'] ?? 0),
                'balance_first_payment' => (float) ($expectedPayments['expected_first_payment'] ?? 0),
                'balance_second_payment' => (float) ($expectedPayments['expected_second_payment'] ?? 0),
                'balance_third_payment' => (float) ($expectedPayments['expected_third_payment'] ?? 0),
                'balance_final_payment' => (float) ($expectedPayments['expected_final_payment'] ?? 0),
            ];
            $initialBalances['total_balance'] =
                (float) $initialBalances['balance_initial_payment'] +
                (float) $initialBalances['balance_first_payment'] +
                (float) $initialBalances['balance_second_payment'] +
                (float) $initialBalances['balance_third_payment'] +
                (float) $initialBalances['balance_final_payment'];

            // Record case creation in Malaysia time (MYT)
            $malaysiaNow = Carbon::now('Asia/Kuala_Lumpur');

            // Create case in PostgreSQL
            $case = LawCase::create([
                'title'         => $validated['title'],
                'caseType'      => $caseType,
                'description'   => $validated['description'],
                'case_type_fee_json' => $validated['case_type_fee_json'] ?? null,
                'lawyerID'      => $lawyer->id,
                'clientID'      => $client->id,
                'lawyerFirmID'  => $lawyer->firmID,
                'clientFirmID'  => $client->firmID,
                'oppositionLawyerName' => $validated['oppositionLawyerName'] ?? null,
                'oppositionLawyerFirmID' => $validated['oppositionLawyerFirmID'] ?? null,
                'created_at'    => $malaysiaNow,
                'updated_at'    => $malaysiaNow,
                ...$expectedPayments,
                ...$initialBalances,
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
            $subFolders = ['documents', 'reports', 'invoices'];

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

            $case->load(['lawyer:id,name,email,role', 'client:id,name,email,role']);
            $actor = $this->resolveActor($request);
            $this->caseNotificationService->notifyCaseUpdate(
                $case,
                $request->user(),
                'Case Created',
                sprintf('A new case has been created with the title "%s".', (string) $case->title)
            );

            return response()->json([
                'message' => 'Case created successfully with Azure folders',
                'caseId'  => $case->caseId,
                'azureFolder' => $caseFolder,
                'case' => $this->formatCasePayload($case, $actor)
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

        if ($denied = $this->denyIfArchivedAndNotAdmin($request, $case)) {
            return $denied;
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

        // Normalize only fields that are actually present in the request.
        $normalized = [];

        if ($request->exists('title')) {
            $normalized['title'] = $normalizeText($request->input('title'));
        }

        if ($request->exists('description')) {
            $normalized['description'] = $normalizeText($request->input('description'));
        }

        if ($request->exists('caseType')) {
            $normalized['caseType'] = $normalizeText($request->input('caseType'));
        }

        if ($request->exists('status')) {
            $rawStatus = strtolower(trim((string) $request->input('status')));
            $normalized['status'] = $rawStatus === '' ? '' : ucfirst($rawStatus);
        }

        if ($request->exists('lawyerID')) {
            $normalized['lawyerID'] = $normalizeText($request->input('lawyerID'));
        }

        if ($request->exists('clientID')) {
            $normalized['clientID'] = $normalizeText($request->input('clientID'));
        }

        if (!empty($normalized)) {
            $request->merge($normalized);
        }

        // If description is empty, ignore it instead of validating/updating it.
        if ($request->exists('description') && trim((string) $request->input('description', '')) === '') {
            $request->request->remove('description');
        }

        // If status is empty, ignore it as well.
        if ($request->exists('status') && trim((string) $request->input('status', '')) === '') {
            $request->request->remove('status');
        }

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'caseType'    => 'sometimes|in:Litigation,Criminal,Corporate',
            'status'      => 'sometimes|in:Active,Archived',
            'case_type_fee_json' => 'sometimes|nullable|array',
            'case_type_fee_json.initial' => 'sometimes|array|max:5',
            'case_type_fee_json.first' => 'sometimes|array|max:5',
            'case_type_fee_json.second' => 'sometimes|array|max:5',
            'case_type_fee_json.third' => 'sometimes|array|max:5',
            'case_type_fee_json.final' => 'sometimes|array|max:5',
            'expected_initial_payment' => 'sometimes|numeric|min:0',
            'expected_first_payment' => 'sometimes|numeric|min:0',
            'expected_second_payment' => 'sometimes|numeric|min:0',
            'expected_third_payment' => 'sometimes|numeric|min:0',
            'expected_final_payment' => 'sometimes|numeric|min:0',
            'balance_initial_payment' => 'sometimes|numeric|min:0',
            'balance_first_payment' => 'sometimes|numeric|min:0',
            'balance_second_payment' => 'sometimes|numeric|min:0',
            'balance_third_payment' => 'sometimes|numeric|min:0',
            'balance_final_payment' => 'sometimes|numeric|min:0',
            'oppositionLawyerName' => 'sometimes|nullable|string|max:255',
            'oppositionLawyerFirmID' => 'sometimes|nullable|string|max:255',

            // firmID-based reassignment (optional)
            'lawyerID'    => 'sometimes|string|exists:users,firmID',
            'clientID'    => 'sometimes|string|exists:users,firmID',
        ]);

        if (array_key_exists('case_type_fee_json', $validated)) {
            $phaseFees = FeePhaseCalculator::normalizePhaseFees($validated['case_type_fee_json']);
            $validated['case_type_fee_json'] = $phaseFees;

            $validated = array_merge(
                $validated,
                $this->mapExpectedByStageToCaseColumns(FeePhaseCalculator::computeExpectedByStage($phaseFees))
            );
        }

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

        $autoExpectedSeeded = false;
        if (
            isset($validated['caseType'])
            && !isset($validated['expected_initial_payment'])
            && !isset($validated['expected_first_payment'])
            && !isset($validated['expected_second_payment'])
            && !isset($validated['expected_third_payment'])
            && !isset($validated['expected_final_payment'])
        ) {
            $dummyExpectedPayments = $this->buildDummyExpectedPayments((string) $validated['caseType']);
            $case->fill($dummyExpectedPayments);
            $autoExpectedSeeded = true;
        }

        $case->save();

        $dirtyFinancialFields = array_keys(array_intersect_key($validated, array_flip([
            'expected_initial_payment',
            'expected_first_payment',
            'expected_second_payment',
            'expected_third_payment',
            'expected_final_payment',
            'balance_initial_payment',
            'balance_first_payment',
            'balance_second_payment',
            'balance_third_payment',
            'balance_final_payment',
        ])));

        $targetStages = $this->caseInvoiceFinancialSyncService->stageKeysForCaseChanges($dirtyFinancialFields);
        if ($autoExpectedSeeded && empty($targetStages)) {
            $targetStages = ['initial', 'first', 'second', 'third', 'final'];
        }
        if (!empty($targetStages)) {
            $this->caseInvoiceFinancialSyncService->syncInvoicesFromCase($case, $targetStages);
        }

        // Refresh derived total balance after case/invoice synchronization.
        $this->caseInvoiceFinancialSyncService->syncCaseFromInvoices((int) $case->caseId);
        $case->refresh();

        $case->load(['lawyer:id,name,email,role', 'client:id,name,email,role']);
        $actor = $this->resolveActor($request);
        $this->caseNotificationService->notifyCaseUpdate(
            $case,
            $request->user(),
            'Case Updated',
            'Updated fields: ' . implode(', ', array_keys($validated))
        );

        return response()->json([
            'message' => 'Case updated successfully',
            'case' => $this->formatCasePayload($case, $actor)
        ]);
    }

    // GET /api/cases/{caseId}
    public function show(Request $request, int $caseId)
    {
        $case = LawCase::with(['lawyer:id,name,firmID', 'client:id,name,firmID'])->find($caseId);

        if (!$case) {
            return response()->json(['message' => 'Case not found'], 404);
        }

        $actor = $this->resolveActor($request);

        return response()->json([
            'case' => $this->formatCasePayload($case, $actor)
        ]);
    }

    private function formatCasePayload(LawCase $case, array $actor): array
    {
        $blobFolderPath = "cases/{$case->caseId}/";
        $freshProgress = $this->invoiceProgressService->recalculateForCase((int) $case->caseId);

        return [
            'id' => $case->caseId,
            'caseId' => $case->caseId,
            'caseNumber' => $case->caseNumber,
            'caseName' => $case->title,
            'title' => $case->title,
            'caseType' => (string) ($case->caseType ?? 'Litigation'),
            'description' => $case->description,
            'case_type_fee_json' => $case->case_type_fee_json ?? [
                'initial' => [],
                'first' => [],
                'second' => [],
                'third' => [],
                'final' => [],
            ],
            'clientName' => $case->client?->name,
            'lawyerName' => $case->lawyer?->name,
            'lawyerFirmID' => $case->lawyerFirmID,
            'clientFirmID' => $case->clientFirmID,
            'oppositionLawyerName' => $case->oppositionLawyerName,
            'oppositionLawyerFirmID' => $case->oppositionLawyerFirmID,
            'status' => $case->status,
            'progress' => $freshProgress,
            'expected_payment_phases' => $this->buildExpectedPaymentPhases($case),
            'balance_payment_phases' => $this->buildBalancePaymentPhases($case),
            'total_balance' => (float) ($case->total_balance ?? 0),
            'invoice_payment_phases' => $this->buildInvoicePaymentPhases($case),
            'blob_folder_path' => $blobFolderPath,
            'encrypted_documents' => $this->getCaseEncryptedDocuments(
                (int) $case->caseId,
                $actor['userId'],
                $actor['role']
            ),
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
        ];
    }

    private function getEncryptedDocumentsByCaseIds(array $caseIds, ?int $actorUserId, string $actorRole): array
    {
        if (empty($caseIds)) {
            return [];
        }

        $documents = FileMetadata::where('type', 'encrypted_document')
            ->whereIn('case_id', array_values(array_unique(array_map('intval', $caseIds))))
            ->where('status', '!=', 'deleted')
            ->get();

        if (in_array($actorRole, ['admin', 'adminstaff'], true)) {
            $visible = $documents;
        } elseif ($actorUserId !== null) {
            $visible = $documents->filter(function ($document) use ($actorUserId) {
                $recipients = $document->recipients ?? [];

                foreach ($recipients as $recipient) {
                    if ((int) ($recipient['recipient_user_id'] ?? 0) === $actorUserId && (bool) ($recipient['is_active'] ?? false) === true) {
                        return true;
                    }
                }

                return false;
            })->values();
        } else {
            $visible = collect();
        }

        return $visible
            ->groupBy(function ($document) {
                return (int) $document->case_id;
            })
            ->map(function ($docs) {
                return $docs->map(function ($document) {
                    $documentId = (string) $document->getKey();

                    return [
                        'document_id' => $documentId,
                        'file_name' => $document->file_name,
                        'mime_type' => $document->mime_type,
                        'size_bytes' => (int) ($document->size_bytes ?? 0),
                        'category' => (string) ($document->category ?? 'documents'),
                        'is_encrypted' => $this->isDocumentStoredEncrypted($document),
                        'status' => (string) ($document->status ?? 'active'),
                        'created_at' => $document->created_at,
                        'invoice_stage' => (string) ($document->invoice_stage ?? ''),
                        'type_of_work' => (string) ($document->type_of_work ?? ''),
                        'paid_amount' => (float) ($document->paid_amount ?? 0),
                        'preview_url' => "/api/encrypted-documents/{$documentId}/preview",
                        'download_url' => "/api/encrypted-documents/{$documentId}/download",
                        'delete_url' => "/api/encrypted-documents/{$documentId}",
                    ];
                })->values()->all();
            })
            ->toArray();
    }

    private function isDocumentStoredEncrypted(FileMetadata $document): bool
    {
        $blobPath = strtolower((string) ($document->blob_path ?? ''));
        $hasEncryptedDirectoryMarker = str_contains($blobPath, '/encrypted/');
        $hasCipherMetadata = !empty($document->cipher);
        $hasAeadMetadata = !empty($document->nonce) && !empty($document->tag);
        $hasWrappedDek = !empty($document->server_encrypted_dek);
        $hasLegacyEncryptionMetadata = !empty($document->encrypted_key) && !empty($document->iv);

        return $hasEncryptedDirectoryMarker
            || $hasCipherMetadata
            || $hasAeadMetadata
            || $hasWrappedDek
            || $hasLegacyEncryptionMetadata;
    }

    private function getCaseEncryptedDocuments(int $caseId, ?int $actorUserId, string $actorRole)
    {
        $mapped = $this->getEncryptedDocumentsByCaseIds([$caseId], $actorUserId, $actorRole);

        return $mapped[$caseId] ?? [];
    }

}