<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LawCase;
use App\Services\InvoiceProgressService;
use App\Support\FeePhaseCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceProgressService $invoiceProgressService
    )
    {
    }

    public function index()
    {
        $query = Invoice::query()->latest();

        if (request()->filled('case_id')) {
            $query->where('case_id', request()->integer('case_id'));
        }

        if (request()->filled('invoice_number')) {
            $query->where('invoice_number', request()->string('invoice_number'));
        }

        if (request()->filled('invoice_id')) {
            $query->where('id', request()->integer('invoice_id'));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'case_type_fee_json' => 'sometimes|nullable|array',
            'case_type_fee_json.initial' => 'sometimes|array|max:5',
            'case_type_fee_json.first' => 'sometimes|array|max:5',
            'case_type_fee_json.second' => 'sometimes|array|max:5',
            'case_type_fee_json.third' => 'sometimes|array|max:5',
            'case_type_fee_json.final' => 'sometimes|array|max:5',
        ]);

        $payload = $request->all();

        if (array_key_exists('case_type_fee_json', $validated)) {
            $payload['case_type_fee_json'] = FeePhaseCalculator::normalizePhaseFees($validated['case_type_fee_json']);

            if (
                (!isset($payload['expected_amount']) || (float) $payload['expected_amount'] <= 0)
                && !empty($payload['payment_stage'])
            ) {
                $payload['expected_amount'] = FeePhaseCalculator::computeExpectedForStage(
                    $payload['case_type_fee_json'],
                    (string) $payload['payment_stage']
                );
            }
        }

        try {
            $invoice = Invoice::create($payload);
        } catch (\RuntimeException $e) {
            Log::warning('Invoice creation failed due to invoice number generation exhaustion', [
                'case_id' => $request->input('case_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to generate a unique invoice number right now. Please try again.',
            ], 409);
        }

        $caseProgress = null;

        $case = LawCase::find((int) $invoice->case_id);
        if ($case) {
            $caseProgress = $this->invoiceProgressService->syncCaseProgress($case);
        }

        return response()->json([
            'invoice' => $invoice,
            'case_progress' => $caseProgress,
        ], 201);
    }

    public function show($id)
    {
        return Invoice::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'invoice_number' => 'sometimes|nullable|string|max:255',
            'payment_stage' => 'sometimes|required|in:initial,first,second,third,final',
            'issue_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|nullable|date',
            'expected_amount' => 'sometimes|nullable|numeric|min:0',
            'paid_amount' => 'sometimes|nullable|numeric|min:0',
            'tax' => 'sometimes|nullable|numeric|min:0',
            'discount' => 'sometimes|nullable|numeric|min:0',
            'balance' => 'sometimes|nullable|numeric',
            'total_amount' => 'sometimes|nullable|numeric',
            'client_name' => 'sometimes|nullable|string|max:255',
            'case_title' => 'sometimes|nullable|string|max:255',
            'blob_path' => 'sometimes|nullable|string',
            'case_type_fee_json' => 'sometimes|nullable|array',
            'case_type_fee_json.initial' => 'sometimes|array|max:5',
            'case_type_fee_json.first' => 'sometimes|array|max:5',
            'case_type_fee_json.second' => 'sometimes|array|max:5',
            'case_type_fee_json.third' => 'sometimes|array|max:5',
            'case_type_fee_json.final' => 'sometimes|array|max:5',
        ]);

        $payload = $validated;

        if (array_key_exists('case_type_fee_json', $validated)) {
            $payload['case_type_fee_json'] = FeePhaseCalculator::normalizePhaseFees($validated['case_type_fee_json']);

            $stage = (string) ($payload['payment_stage'] ?? $invoice->payment_stage ?? '');
            if (
                $stage !== ''
                && (!isset($payload['expected_amount']) || (float) $payload['expected_amount'] <= 0)
            ) {
                $payload['expected_amount'] = FeePhaseCalculator::computeExpectedForStage(
                    $payload['case_type_fee_json'],
                    $stage
                );
            }
        }

        $expectedAmount = (float) ($payload['expected_amount'] ?? $invoice->expected_amount ?? 0);
        $paidAmount = (float) ($payload['paid_amount'] ?? $invoice->paid_amount ?? 0);
        $taxPercent = (float) ($payload['tax'] ?? $invoice->tax ?? 0);
        $discountPercent = (float) ($payload['discount'] ?? $invoice->discount ?? 0);

        if (!array_key_exists('balance', $payload) || $payload['balance'] === null) {
            $payload['balance'] = round(max($expectedAmount - $paidAmount, 0), 2);
        }

        if (!array_key_exists('total_amount', $payload) || $payload['total_amount'] === null) {
            $payload['total_amount'] = round(
                max($paidAmount + (($paidAmount * $taxPercent) / 100) - (($paidAmount * $discountPercent) / 100), 0),
                2
            );
        }

        $invoice->update($payload);

        $caseProgress = null;
        $case = LawCase::find((int) ($invoice->case_id ?? 0));
        if ($case) {
            $caseProgress = $this->invoiceProgressService->syncCaseProgress($case);
        }

        return response()->json([
            'invoice' => $invoice->fresh(),
            'case_progress' => $caseProgress,
        ]);
    }

    public function destroy($id)
    {
        Invoice::destroy($id);
        return response()->json(['message' => 'Deleted']);
    }

    // Generate invoice number: A + 2 alnum + case_id + 6 alnum
    public function generateInvoiceNumber(Request $request)
    {
        try {
            $request->validate([
                'case_id' => 'required',
            ]);

            $caseId = (int) $request->case_id;
            $invoiceNumber = Invoice::generateInvoiceNumber($caseId, null, null);

            return response()->json([
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('generateInvoiceNumber exhausted retries', [
                'case_id' => $request->input('case_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to generate a unique invoice number right now. Please try again.',
            ], 409);
        } catch (\Throwable $e) {
            Log::error('generateInvoiceNumber error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to generate invoice number',
            ], 500);
        }
    }
}