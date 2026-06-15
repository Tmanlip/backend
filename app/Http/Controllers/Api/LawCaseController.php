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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;

class LawCaseController extends Controller
{
    private const CASE_TYPE_TO_PRACTICE_AREA = [
        'Litigation' => 'Civil',
        'Corporate' => 'Corporate',
        'Criminal' => 'Criminal',
    ];

    private const STATIC_TYPE_OF_WORK_FALLBACK = [
        'Civil' => [
            ['typeOfWork' => 'Case Consultation', 'rangeMin' => 800, 'rangeMax' => 2000],
            ['typeOfWork' => 'Document Preparation', 'rangeMin' => 1200, 'rangeMax' => 3200],
            ['typeOfWork' => 'Court Representation', 'rangeMin' => 2500, 'rangeMax' => 7000],
        ],
        'Corporate' => [
            ['typeOfWork' => 'Corporate Advisory', 'rangeMin' => 1500, 'rangeMax' => 4500],
            ['typeOfWork' => 'Contract Drafting', 'rangeMin' => 1800, 'rangeMax' => 5000],
            ['typeOfWork' => 'Regulatory Compliance', 'rangeMin' => 2200, 'rangeMax' => 6000],
        ],
        'Criminal' => [
            ['typeOfWork' => 'Initial Legal Advice', 'rangeMin' => 1000, 'rangeMax' => 3000],
            ['typeOfWork' => 'Bail Application', 'rangeMin' => 1800, 'rangeMax' => 5000],
            ['typeOfWork' => 'Trial Representation', 'rangeMin' => 3000, 'rangeMax' => 9000],
        ],
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

    private function decodeTypeScriptFeeItems(string $tsContent): array
    {
        $content = $this->stripUtf8Bom($tsContent);
        $start = strpos($content, '[');
        $end = strrpos($content, ']');

        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $jsonChunk = trim(substr($content, $start, $end - $start + 1));
        if ($jsonChunk === '') {
            return [];
        }

        $decoded = json_decode($jsonChunk, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePracticeArea(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'civil', 'litigation' => 'Civil',
            'corporate' => 'Corporate',
            'criminal' => 'Criminal',
            default => trim($value),
        };
    }

    private function candidateTypeOfWorkSourcePaths(): array
    {
        $paths = [];

        $configuredBase = trim((string) env('CHATBOT_PLAYBOOK_PATH', ''));
        if ($configuredBase !== '') {
            $normalizedBase = rtrim(str_replace('\\', '/', $configuredBase), '/');
            $paths[] = [
                $normalizedBase . '/TypeOfWork_EstimationFees.json',
                $normalizedBase . '/TypeOfWork_EstimationFees.csv',
                $normalizedBase . '/TypeOfWork_EstimationFees.ts',
            ];
        }

        $storageBase = storage_path('app/chatbot/operations-playbook-excel');
        $resourcesBase = base_path('resources/chatbot/operations-playbook-excel');

        $paths[] = [
            $resourcesBase . '/TypeOfWork_EstimationFees.json',
            $resourcesBase . '/TypeOfWork_EstimationFees.csv',
            $resourcesBase . '/TypeOfWork_EstimationFees.ts',
        ];

        $paths[] = [
            $storageBase . '/TypeOfWork_EstimationFees.json',
            $storageBase . '/TypeOfWork_EstimationFees.csv',
            $storageBase . '/TypeOfWork_EstimationFees.ts',
        ];

        $unique = [];
        $result = [];
        foreach ($paths as $triple) {
            $key = strtolower(implode('|', $triple));
            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $result[] = $triple;
        }

        return $result;
    }

    private function formatCurrencyAmount(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function formatRangeLabel(float $rangeMin, float $rangeMax): string
    {
        return sprintf(
            'RM %s - RM %s',
            $this->formatCurrencyAmount($rangeMin),
            $this->formatCurrencyAmount($rangeMax)
        );
    }

    private function normalizeOptionRow(
        string $practiceArea,
        string $typeOfWork,
        ?string $estimationFeesRange,
        ?float $rangeMin,
        ?float $rangeMax,
        ?float $estimatedAmount = null
    ): ?array {
        $cleanPracticeArea = trim($practiceArea);
        $cleanTypeOfWork = trim($typeOfWork);

        if ($cleanPracticeArea === '' || $cleanTypeOfWork === '') {
            return null;
        }

        $min = is_numeric((string) $rangeMin) ? round((float) $rangeMin, 2) : null;
        $max = is_numeric((string) $rangeMax) ? round((float) $rangeMax, 2) : null;

        if ($min === null || $max === null || $min < 0 || $max < 0 || $min > $max) {
            return null;
        }

        $label = trim((string) ($estimationFeesRange ?? ''));
        if ($label === '') {
            $label = $this->formatRangeLabel($min, $max);
        }

        $amount = is_numeric((string) $estimatedAmount)
            ? round((float) $estimatedAmount, 2)
            : round(($min + $max) / 2, 2);

        return [
            'practiceArea' => $cleanPracticeArea,
            'typeOfWork' => $cleanTypeOfWork,
            'estimationFeesRange' => $label,
            'estimatedAmount' => $amount,
            'rangeMin' => $min,
            'rangeMax' => $max,
        ];
    }

    private function loadTypeOfWorkOptionsFromCases(string $practiceArea): array
    {
        $matchingCaseTypes = [];
        foreach (self::CASE_TYPE_TO_PRACTICE_AREA as $caseType => $mappedPracticeArea) {
            if ($mappedPracticeArea === $practiceArea) {
                $matchingCaseTypes[] = $caseType;
            }
        }

        if (empty($matchingCaseTypes)) {
            return [];
        }

        $cases = LawCase::query()
            ->whereIn('caseType', $matchingCaseTypes)
            ->whereNotNull('case_type_fee_json')
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get(['case_type_fee_json']);

        $result = [];
        $seen = [];
        $stages = ['initial', 'first', 'second', 'third', 'final'];

        foreach ($cases as $case) {
            $feeJson = $case->case_type_fee_json;
            if (is_string($feeJson)) {
                $decoded = json_decode($feeJson, true);
                $feeJson = is_array($decoded) ? $decoded : [];
            }

            if (!is_array($feeJson)) {
                continue;
            }

            foreach ($stages as $stage) {
                $items = $feeJson[$stage] ?? [];
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $typeOfWork = trim((string) ($item['typeOfWork'] ?? $item['type_of_work'] ?? ''));
                    $rangeMin = $item['rangeMin'] ?? $item['range_min'] ?? null;
                    $rangeMax = $item['rangeMax'] ?? $item['range_max'] ?? null;
                    $estimatedAmount = $item['selectedFee'] ?? $item['selected_fee'] ?? null;
                    $rangeLabel = trim((string) ($item['estimationFeesRange'] ?? $item['estimation_fees_range'] ?? ''));

                    $normalized = $this->normalizeOptionRow(
                        $practiceArea,
                        $typeOfWork,
                        $rangeLabel,
                        is_numeric((string) $rangeMin) ? (float) $rangeMin : null,
                        is_numeric((string) $rangeMax) ? (float) $rangeMax : null,
                        is_numeric((string) $estimatedAmount) ? (float) $estimatedAmount : null
                    );

                    if ($normalized === null) {
                        continue;
                    }

                    $dedupeKey = strtolower($normalized['typeOfWork'] . '|' . $normalized['estimationFeesRange']);
                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }

                    $seen[$dedupeKey] = true;
                    $result[] = $normalized;
                }
            }
        }

        return $result;
    }

    private function buildStaticTypeOfWorkFallback(string $practiceArea): array
    {
        $source = self::STATIC_TYPE_OF_WORK_FALLBACK[$practiceArea] ?? [];
        $result = [];

        foreach ($source as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized = $this->normalizeOptionRow(
                $practiceArea,
                (string) ($item['typeOfWork'] ?? ''),
                null,
                isset($item['rangeMin']) ? (float) $item['rangeMin'] : null,
                isset($item['rangeMax']) ? (float) $item['rangeMax'] : null,
                null
            );

            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    public function typeOfWorkOptions(Request $request): JsonResponse
    {
        try {
            $caseType = (string) $request->query('caseType', 'Litigation');
            $practiceArea = self::CASE_TYPE_TO_PRACTICE_AREA[$caseType] ?? 'Civil';
            $normalizedPracticeArea = $this->normalizePracticeArea($practiceArea);

            $sourceItems = [];
            $sourceMeta = [
                'kind' => 'none',
                'path' => null,
            ];

            foreach ($this->candidateTypeOfWorkSourcePaths() as [$jsonPath, $csvPath, $tsPath]) {
                if (empty($sourceItems) && file_exists($jsonPath)) {
                    $raw = @file_get_contents($jsonPath);
                    $decoded = json_decode($this->stripUtf8Bom((string) $raw), true);
                    if (is_array($decoded)) {
                        $sourceItems = $decoded;
                        $sourceMeta = [
                            'kind' => 'json',
                            'path' => $jsonPath,
                        ];
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

                        if (!empty($sourceItems)) {
                            $sourceMeta = [
                                'kind' => 'csv',
                                'path' => $csvPath,
                            ];
                        }
                    }
                }

                if (empty($sourceItems) && file_exists($tsPath)) {
                    $tsRaw = @file_get_contents($tsPath);
                    if (is_string($tsRaw) && trim($tsRaw) !== '') {
                        $sourceItems = $this->decodeTypeScriptFeeItems($tsRaw);
                        if (!empty($sourceItems)) {
                            $sourceMeta = [
                                'kind' => 'ts',
                                'path' => $tsPath,
                            ];
                        }
                    }
                }

                if (!empty($sourceItems)) {
                    break;
                }
            }

            $result = [];
            $seen = [];

            foreach ($sourceItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalizedItem = $this->normalizeFeeSourceItem($item);
                $itemPracticeArea = $this->normalizePracticeArea((string) ($normalizedItem['practiceArea'] ?? ''));
                if ($itemPracticeArea !== $normalizedPracticeArea) {
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

            if (empty($result)) {
                $result = $this->loadTypeOfWorkOptionsFromCases($normalizedPracticeArea);
                if (!empty($result)) {
                    $sourceMeta = [
                        'kind' => 'case_type_fee_json',
                        'path' => 'law_cases.case_type_fee_json',
                    ];
                }
            }

            if (empty($result)) {
                $result = $this->buildStaticTypeOfWorkFallback($normalizedPracticeArea);
                if (!empty($result)) {
                    $sourceMeta = [
                        'kind' => 'static_fallback',
                        'path' => 'LawCaseController::STATIC_TYPE_OF_WORK_FALLBACK',
                    ];
                }
            }

            Log::info('Type of work options resolved', [
                'caseType' => $caseType,
                'practiceArea' => $normalizedPracticeArea,
                'source' => $sourceMeta,
                'sourceItemsCount' => count($sourceItems),
                'resultCount' => count($result),
            ]);

            return response()->json([
                'caseType' => $caseType,
                'practiceArea' => $normalizedPracticeArea,
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

            $metadataWarning = null;
            try {
                Metadata::storeCase(
                    (string) $case->caseId,
                    $lawyer->firmID,
                    $client->firmID
                );
            } catch (\Throwable $metadataError) {
                $metadataWarning = 'Case created, but metadata sync is currently unavailable.';
                Log::warning('Case metadata sync failed during creation', [
                    'caseId' => $case->caseId,
                    'error' => $metadataError->getMessage(),
                ]);
            }

            DB::commit();

            $caseFolder = "cases/{$case->caseId}/";
            $azureSetupWarning = null;
            try {
                // Azure folder bootstrap is non-critical; do not fail case creation if storage is unavailable.
                $azureController = new \App\Http\Controllers\AzureController();
                $subFolders = ['documents', 'reports', 'invoices'];

                foreach ($subFolders as $folder) {
                    $blobName = $caseFolder . $folder . '/placeholder.txt';
                    $content = "This folder: {$folder} for case {$case->caseId}";
                    $azureController->createBlobFromString($blobName, $content);
                }

                $azureController->createBlobFromString(
                    $caseFolder . 'metadata.txt',
                    json_encode([
                        'case_id' => (string) $case->caseId,
                        'lawyer_firm_id' => (string) $lawyer->firmID,
                        'client_firm_id' => (string) $client->firmID,
                        'generated_at' => now()->toIso8601String(),
                    ], JSON_PRETTY_PRINT)
                );
            } catch (\Throwable $azureError) {
                $azureSetupWarning = 'Case created, but Azure folder setup is unavailable right now.';
                Log::warning('Azure bootstrap failed during case creation', [
                    'caseId' => $case->caseId,
                    'error' => $azureError->getMessage(),
                ]);
            }

            $case->load(['lawyer:id,name,email,role', 'client:id,name,email,role']);
            $actor = $this->resolveActor($request);
            $this->caseNotificationService->notifyCaseUpdate(
                $case,
                $request->user(),
                'Case Created',
                sprintf('A new case has been created with the title "%s".', (string) $case->title)
            );

            return response()->json([
                'message' => 'Case created successfully',
                'caseId'  => $case->caseId,
                'azureFolder' => $caseFolder,
                'warnings' => array_values(array_filter([
                    $metadataWarning,
                    $azureSetupWarning,
                ])),
                'case' => $this->formatCasePayload($case, $actor)
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Selected lawyer/client was not found with the required role.',
            ], 422);
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
                        'document_placeholder' => (string) ($document->document_placeholder ?? ''),
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