<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LawCase;
use App\Models\Invoice;
use App\Services\InvoiceProgressService;
use App\Services\DocumentGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentGeneratorController extends Controller
{
    public function __construct(
        private readonly DocumentGeneratorService $service,
        private readonly InvoiceProgressService $invoiceProgressService
    )
    {
    }

    public function health(): JsonResponse
    {
        $payload = $this->service->health();
        $status = ($payload['status'] ?? 'ok') === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return response()->json($payload, $status);
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string'],
            'language' => ['nullable', 'string'],
        ]);

        try {
            $output = $this->service->generateAiText(
                (string) $validated['prompt'],
                (string) ($validated['language'] ?? 'english')
            );

            return response()->json(['output' => $output]);
        } catch (\Throwable $error) {
            return response()->json([
                'error' => $error->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateLodDocx(Request $request)
    {
        $formData = (array) $request->input('formData', []);

        try {
            $file = $this->service->generateLodDocx($formData);

            return response($file['buffer'], Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            ]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Failed to generate LOD DOCX'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateLodPdf(Request $request)
    {
        $formData = (array) $request->input('formData', []);

        try {
            $file = $this->service->generateLodPdf($formData);

            return response($file['buffer'], Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            ]);
        } catch (\Throwable $error) {
            logger()->error('Document generator PDF generation failed.', [
                'message' => $error->getMessage(),
                'exception' => $error::class,
            ]);

            return response()->json(['error' => 'Failed to generate LOD PDF'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateWritDocx(Request $request)
    {
        $formData = (array) $request->input('formData', []);

        try {
            $file = $this->service->generateWritDocx($formData);

            return response($file['buffer'], Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            ]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Failed to generate Writ DOCX'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateInvoiceDocx(Request $request)
    {
        $validated = $request->validate([
            'language' => ['nullable', 'string', 'in:english,bi,malay'],
            'formData' => ['required', 'array'],
            'formData.invoice_id' => ['nullable', 'integer'],
            'formData.invoice_number' => ['nullable', 'string'],
            'formData.lawyerID' => ['nullable', 'integer'],
            'formData.case_id' => ['required', 'integer'],
            'formData.clientID' => ['nullable', 'integer'],
            'formData.payment_stage' => ['required', 'in:initial,first,second,third,final'],
            'formData.issue_date' => ['required', 'date'],
            'formData.due_date' => ['nullable', 'date'],
            'formData.blob_path' => ['nullable', 'string'],
            'formData.pdf_path' => ['nullable', 'string'],
            'formData.expected_amount' => ['nullable', 'numeric', 'min:0'],
            'formData.paid_amount' => ['nullable', 'numeric', 'min:0'],
            'formData.tax' => ['nullable', 'numeric', 'min:0'],
            'formData.discount' => ['nullable', 'numeric', 'min:0'],
            'formData.balance' => ['nullable', 'numeric'],
            'formData.type_of_work' => ['nullable', 'string'],
            'formData.phase_balance' => ['nullable', 'numeric'],
        ]);

        $formData = (array) ($validated['formData'] ?? []);
        $invoicePersistenceData = $formData;
        unset($invoicePersistenceData['type_of_work'], $invoicePersistenceData['phase_balance'], $invoicePersistenceData['phase_balance_base']);
        $case = LawCase::with('client')->findOrFail((int) $formData['case_id']);

        $stage = (string) ($formData['payment_stage'] ?? 'initial');
        $stageExpectedAmount = match ($stage) {
            'initial' => (float) ($case->expected_initial_payment ?? 0),
            'first' => (float) ($case->expected_first_payment ?? 0),
            'second' => (float) ($case->expected_second_payment ?? 0),
            'third' => (float) ($case->expected_third_payment ?? 0),
            'final' => (float) ($case->expected_final_payment ?? 0),
            default => 0.0,
        };

        $paidAmount = (float) ($formData['paid_amount'] ?? 0);
        $balance = array_key_exists('balance', $formData) && $formData['balance'] !== null && $formData['balance'] !== ''
            ? (float) $formData['balance']
            : round(max($stageExpectedAmount - $paidAmount, 0), 2);

        $formData['lawyerID'] = (int) $case->lawyerID;
        $formData['clientID'] = (int) $case->clientID;
        $formData['client_name'] = ($formData['client_name'] ?? null) ?: ($case->client?->name ?? null);
        $formData['case_title'] = ($formData['case_title'] ?? null) ?: $case->title;

        if (empty($formData['expected_amount'])) {
            $formData['expected_amount'] = $stageExpectedAmount;
        }

        if (empty($formData['balance'])) {
            $formData['balance'] = $balance;
        }

        if (empty($formData['total_amount'])) {
            $paidAmountForTotal = (float) ($formData['paid_amount'] ?? 0);
            $taxPercentForTotal = (float) ($formData['tax'] ?? 0);
            $discountPercentForTotal = (float) ($formData['discount'] ?? 0);
            $formData['total_amount'] = $paidAmountForTotal
                + (($paidAmountForTotal * $taxPercentForTotal) / 100)
                + (($paidAmountForTotal * $discountPercentForTotal) / 100);
        }

        // Backward compatibility: accept legacy pdf_path input and map to blob_path.
        if (empty($formData['blob_path']) && !empty($formData['pdf_path'])) {
            $formData['blob_path'] = $formData['pdf_path'];
        }

        $invoice = null;
        $caseProgress = null;
        $createdInvoice = false;

        try {
            $existingInvoiceId = isset($formData['invoice_id']) ? (int) $formData['invoice_id'] : 0;
            $shouldPersistInvoice = $existingInvoiceId > 0;

            if ($existingInvoiceId > 0) {
                $existingInvoice = Invoice::find($existingInvoiceId);
                if ($existingInvoice) {
                    $updateFields = array_filter([
                        'paid_amount' => $formData['paid_amount'] ?? null,
                        'balance' => $formData['balance'] ?? null,
                        'total_amount' => $formData['total_amount'] ?? null,
                        'tax' => $formData['tax'] ?? null,
                        'discount' => $formData['discount'] ?? null,
                        'expected_amount' => $formData['expected_amount'] ?? null,
                        'issue_date' => $formData['issue_date'] ?? null,
                        'due_date' => $formData['due_date'] ?? null,
                        'client_name' => $formData['client_name'] ?? null,
                        'case_title' => $formData['case_title'] ?? null,
                    ], fn ($v) => $v !== null);
                    $existingInvoice->update($updateFields);
                    $invoice = $existingInvoice->fresh();
                }
            }
            if (!$invoice) {
                $invoice = Invoice::create($invoicePersistenceData);
            }
            $caseProgress = $this->invoiceProgressService->syncCaseProgress($case);

            $invoiceData = $invoice->fresh()->only([
                'id',
                'lawyerID',
                'case_id',
                'clientID',
                'invoice_number',
                'payment_stage',
                'issue_date',
                'due_date',
                'expected_amount',
                'paid_amount',
                'balance',
                'tax',
                'discount',
                'total_amount',
                'client_name',
                'case_title',
                'blob_path',
                'created_at',
                'updated_at',
            ]);

            $invoiceData['invoice_id'] = $invoiceData['id'] ?? null;

            $payload = array_merge($formData, $invoiceData);
            $payload['language'] = (string) ($validated['language'] ?? 'english');
            $file = $this->service->generateInvoiceDocx($payload);

            $headerKeys = [
                'X-Invoice-Id',
                'X-Invoice-Number',
                'X-Invoice-Expected-Amount',
                'X-Invoice-Paid-Amount',
                'X-Invoice-Balance',
                'X-Case-Progress',
            ];

            return response($file['buffer'], Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
                'X-Invoice-Id' => (string) $invoice->id,
                'X-Invoice-Number' => (string) $invoice->invoice_number,
                'X-Invoice-Expected-Amount' => (string) $invoice->expected_amount,
                'X-Invoice-Paid-Amount' => (string) $invoice->paid_amount,
                'X-Invoice-Balance' => (string) $invoice->balance,
                'X-Case-Progress' => (string) ($caseProgress ?? ''),
                'Access-Control-Expose-Headers' => implode(', ', $headerKeys),
            ]);
        } catch (\RuntimeException $error) {
            return response()->json([
                'error' => 'Unable to generate a unique invoice number right now. Please try again.',
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $error) {
            if ($invoice) {
                try {
                    $invoice->delete();
                } catch (\Throwable $deleteError) {
                    logger()->warning('Failed to rollback invoice creation after document generation error.', [
                        'invoice_id' => $invoice->id ?? null,
                        'error' => $deleteError->getMessage(),
                    ]);
                }
            }

            return response()->json(['error' => 'Failed to generate Invoice DOCX'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateInvoicePdf(Request $request)
    {
        $validated = $request->validate([
            'language' => ['nullable', 'string', 'in:english,bi,malay'],
            'formData' => ['required', 'array'],
            'formData.invoice_id' => ['nullable', 'integer'],
            'formData.invoice_number' => ['nullable', 'string'],
            'formData.lawyerID' => ['nullable', 'integer'],
            'formData.case_id' => ['required', 'integer'],
            'formData.clientID' => ['nullable', 'integer'],
            'formData.payment_stage' => ['required', 'in:initial,first,second,third,final'],
            'formData.issue_date' => ['required', 'date'],
            'formData.due_date' => ['nullable', 'date'],
            'formData.blob_path' => ['nullable', 'string'],
            'formData.pdf_path' => ['nullable', 'string'],
            'formData.expected_amount' => ['nullable', 'numeric', 'min:0'],
            'formData.paid_amount' => ['nullable', 'numeric', 'min:0'],
            'formData.tax' => ['nullable', 'numeric', 'min:0'],
            'formData.discount' => ['nullable', 'numeric', 'min:0'],
            'formData.balance' => ['nullable', 'numeric'],
            'formData.type_of_work' => ['nullable', 'string'],
            'formData.phase_balance' => ['nullable', 'numeric'],
        ]);

        $formData = (array) ($validated['formData'] ?? []);
        $invoicePersistenceData = $formData;
        unset($invoicePersistenceData['type_of_work'], $invoicePersistenceData['phase_balance'], $invoicePersistenceData['phase_balance_base']);
        $case = LawCase::with('client')->findOrFail((int) $formData['case_id']);

        $stage = (string) ($formData['payment_stage'] ?? 'initial');
        $stageExpectedAmount = match ($stage) {
            'initial' => (float) ($case->expected_initial_payment ?? 0),
            'first' => (float) ($case->expected_first_payment ?? 0),
            'second' => (float) ($case->expected_second_payment ?? 0),
            'third' => (float) ($case->expected_third_payment ?? 0),
            'final' => (float) ($case->expected_final_payment ?? 0),
            default => 0.0,
        };

        $paidAmount = (float) ($formData['paid_amount'] ?? 0);
        $balance = array_key_exists('balance', $formData) && $formData['balance'] !== null && $formData['balance'] !== ''
            ? (float) $formData['balance']
            : round(max($stageExpectedAmount - $paidAmount, 0), 2);

        $formData['lawyerID'] = (int) $case->lawyerID;
        $formData['clientID'] = (int) $case->clientID;
        $formData['client_name'] = ($formData['client_name'] ?? null) ?: ($case->client?->name ?? null);
        $formData['case_title'] = ($formData['case_title'] ?? null) ?: $case->title;

        if (empty($formData['expected_amount'])) {
            $formData['expected_amount'] = $stageExpectedAmount;
        }

        if (empty($formData['balance'])) {
            $formData['balance'] = $balance;
        }

        if (empty($formData['total_amount'])) {
            $paidAmountForTotal = (float) ($formData['paid_amount'] ?? 0);
            $taxPercentForTotal = (float) ($formData['tax'] ?? 0);
            $discountPercentForTotal = (float) ($formData['discount'] ?? 0);
            $formData['total_amount'] = $paidAmountForTotal
                + (($paidAmountForTotal * $taxPercentForTotal) / 100)
                + (($paidAmountForTotal * $discountPercentForTotal) / 100);
        }

        if (empty($formData['blob_path']) && !empty($formData['pdf_path'])) {
            $formData['blob_path'] = $formData['pdf_path'];
        }

        $invoice = null;
        $caseProgress = null;
        $createdInvoice = false;

        try {
            $existingInvoiceId = isset($formData['invoice_id']) ? (int) $formData['invoice_id'] : 0;
            $shouldPersistInvoice = $existingInvoiceId > 0;
            if ($existingInvoiceId > 0) {
                $existingInvoice = Invoice::find($existingInvoiceId);
                if ($existingInvoice) {
                    $updateFields = array_filter([
                        'paid_amount' => $formData['paid_amount'] ?? null,
                        'balance' => $formData['balance'] ?? null,
                        'total_amount' => $formData['total_amount'] ?? null,
                        'tax' => $formData['tax'] ?? null,
                        'discount' => $formData['discount'] ?? null,
                        'expected_amount' => $formData['expected_amount'] ?? null,
                        'issue_date' => $formData['issue_date'] ?? null,
                        'due_date' => $formData['due_date'] ?? null,
                        'client_name' => $formData['client_name'] ?? null,
                        'case_title' => $formData['case_title'] ?? null,
                    ], fn ($v) => $v !== null);
                    $existingInvoice->update($updateFields);
                    $invoice = $existingInvoice->fresh();
                }
            }

            if (!$invoice && $shouldPersistInvoice) {
                $invoice = Invoice::create($invoicePersistenceData);
                $createdInvoice = true;
            }

            if ($invoice) {
                $caseProgress = $this->invoiceProgressService->syncCaseProgress($case);
            }

            if ($invoice) {
                $invoiceData = $invoice->fresh()->only([
                    'id', 'lawyerID', 'case_id', 'clientID', 'invoice_number',
                    'payment_stage', 'issue_date', 'due_date', 'expected_amount',
                    'paid_amount', 'balance', 'tax', 'discount', 'total_amount',
                    'client_name', 'case_title', 'blob_path', 'created_at', 'updated_at',
                ]);
            } else {
                $invoiceData = [
                    'id' => null,
                    'lawyerID' => $formData['lawyerID'] ?? null,
                    'case_id' => $formData['case_id'] ?? null,
                    'clientID' => $formData['clientID'] ?? null,
                    'invoice_number' => (string) ($formData['invoice_number'] ?? Invoice::generateInvoiceNumber((int) ($formData['case_id'] ?? 0))),
                    'payment_stage' => $formData['payment_stage'] ?? null,
                    'issue_date' => $formData['issue_date'] ?? null,
                    'due_date' => $formData['due_date'] ?? null,
                    'expected_amount' => $formData['expected_amount'] ?? null,
                    'paid_amount' => $formData['paid_amount'] ?? null,
                    'balance' => $formData['balance'] ?? null,
                    'tax' => $formData['tax'] ?? null,
                    'discount' => $formData['discount'] ?? null,
                    'total_amount' => $formData['total_amount'] ?? null,
                    'client_name' => $formData['client_name'] ?? null,
                    'case_title' => $formData['case_title'] ?? null,
                    'blob_path' => $formData['blob_path'] ?? null,
                    'created_at' => null,
                    'updated_at' => null,
                ];
            }

            $invoiceData['invoice_id'] = $invoiceData['id'] ?? null;
            $payload = array_merge($formData, $invoiceData);
            $payload['language'] = (string) ($validated['language'] ?? 'english');
            
            // Generate PDF using HTML template with Dompdf (active approach - NOT DOCX)
            $file = $this->service->generateInvoicePdf($payload);

            $headerKeys = [
                'X-Invoice-Id', 'X-Invoice-Number', 'X-Invoice-Expected-Amount',
                'X-Invoice-Paid-Amount', 'X-Invoice-Balance', 'X-Case-Progress',
            ];

            // Ensure response is pure PDF (no DOCX)
            return response($file['buffer'], Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Invoice-Id' => (string) ($invoice->id ?? ''),
                'X-Invoice-Number' => (string) ($invoiceData['invoice_number'] ?? ''),
                'X-Invoice-Expected-Amount' => (string) ($invoiceData['expected_amount'] ?? ''),
                'X-Invoice-Paid-Amount' => (string) ($invoiceData['paid_amount'] ?? ''),
                'X-Invoice-Balance' => (string) ($invoiceData['balance'] ?? ''),
                'X-Case-Progress' => (string) ($caseProgress ?? ''),
                'Access-Control-Expose-Headers' => implode(', ', $headerKeys),
            ]);
        } catch (\RuntimeException $error) {
            // Only return 409 for the specific "unique invoice number" conflict
            if (str_contains($error->getMessage(), 'unique invoice number')) {
                return response()->json([
                    'error' => 'Unable to generate a unique invoice number right now. Please try again.',
                ], Response::HTTP_CONFLICT);
            }
            return response()->json(['error' => 'Failed to generate Invoice PDF: ' . $error->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $error) {
            if ($invoice && $createdInvoice) {
                try {
                    $invoice->delete();
                } catch (\Throwable $deleteError) {
                    logger()->warning('Failed to rollback invoice creation after PDF generation error.', [
                        'invoice_id' => $invoice->id ?? null,
                        'error' => $deleteError->getMessage(),
                    ]);
                }
            }

            return response()->json(['error' => 'Failed to generate Invoice PDF: ' . $error->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateLodDataXlsx(Request $request): JsonResponse
    {
        $formData = (array) $request->input('formData', []);

        try {
            $path = $this->service->syncLodWorkbook($formData);

            return response()->json([
                'ok' => true,
                'file' => basename($path),
                'path' => $path,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Failed to generate LOD workbook'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function generateWritDataXlsx(Request $request): JsonResponse
    {
        $formData = (array) $request->input('formData', []);

        try {
            $path = $this->service->syncWritWorkbook($formData);

            return response()->json([
                'success' => true,
                'message' => 'Writ data workbook updated',
                'path' => $path,
            ]);
        } catch (\Throwable $error) {
            return response()->json(['error' => 'Failed to generate Writ XLSX'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
