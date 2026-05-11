<?php

namespace App\Services;

use App\Models\FileMetadata;
use App\Models\Invoice;
use App\Models\LawCase;

class InvoiceProgressService
{
    private const STAGES = ['initial', 'first', 'second', 'third', 'final'];

    public function getStageSummaries(int $caseId): array
    {
        $stageTotals = $this->collectStageTotals($caseId);
        $summaries = [];

        foreach (self::STAGES as $stage) {
            $expected = $stageTotals[$stage]['expected'];
            $paid = $stageTotals[$stage]['paid'];

            $summaries[$stage] = [
                'expected' => round($expected, 2),
                'paid' => round($paid, 2),
                'balance' => round(max($expected - $paid, 0.0), 2),
            ];
        }

        return $summaries;
    }

    public function recalculateForCase(int $caseId): float
    {
        $stageTotals = $this->collectStageTotals($caseId);
        $case = LawCase::find($caseId);
        $expectedDefaults = $case ? $this->getExpectedByStageFromCase($case) : [];

        $totalExpected = 0.0;
        $totalPaid = 0.0;
        foreach (self::STAGES as $stage) {
            $expected = (float) ($stageTotals[$stage]['expected'] ?? 0);
            if ($expected <= 0 && isset($expectedDefaults[$stage])) {
                $expected = max(0.0, (float) $expectedDefaults[$stage]);
            }

            $totalExpected += $expected;
            $totalPaid += (float) ($stageTotals[$stage]['paid'] ?? 0);
        }

        if ($totalExpected <= 0) {
            return 0.0;
        }

        return round(min(max(($totalPaid / $totalExpected) * 100.0, 0.0), 100.0), 2);
    }

    private function collectStageTotals(int $caseId): array
    {
        $stageTotals = [];
        foreach (self::STAGES as $stage) {
            $stageTotals[$stage] = [
                'expected' => 0.0,
                'paid' => 0.0,
            ];
        }

        $activeInvoiceBlobPaths = FileMetadata::where('case_id', $caseId)
            ->where('status', '!=', 'deleted')
            ->where(function ($query) {
                $query->where('category', 'invoices')
                    ->orWhere('type', 'invoice_payment');
            })
            ->pluck('blob_path')
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->map(fn ($path) => trim((string) $path))
            ->values();

        $invoiceRows = Invoice::where('case_id', $caseId)
            ->get(['payment_stage', 'expected_amount', 'paid_amount', 'blob_path']);

        $hasInvoiceRows = false;
        foreach ($invoiceRows as $row) {
            $stage = $this->normalizeStage((string) ($row->payment_stage ?? ''));
            if ($stage === null) {
                continue;
            }

            $invoiceBlobPath = trim((string) ($row->blob_path ?? ''));
            if ($invoiceBlobPath !== '' && !$this->isInvoiceBlobPathActive($invoiceBlobPath, $activeInvoiceBlobPaths->all())) {
                // External deletion removed source metadata/file, so treat this invoice row as orphaned.
                continue;
            }

            $hasInvoiceRows = true;
            $expected = (float) ($row->expected_amount ?? 0);
            $paid = (float) ($row->paid_amount ?? 0);

            if ($expected > 0) {
                $stageTotals[$stage]['expected'] = max($stageTotals[$stage]['expected'], $expected);
            }

            $stageTotals[$stage]['paid'] += max(0.0, $paid);
        }

        if ($hasInvoiceRows) {
            return $stageTotals;
        }

        $invoiceRows = FileMetadata::where('case_id', $caseId)
            ->where('status', '!=', 'deleted')
            ->where(function ($query) {
                $query->where('category', 'invoices')
                    ->orWhere('type', 'invoice_payment');
            })
            ->get();

        foreach ($invoiceRows as $row) {
            $stage = $this->normalizeStage((string) ($row->invoice_stage ?? ''));
            if ($stage === null) {
                $stage = $this->inferStageFromFileName((string) ($row->file_name ?? ''));
            }

            if ($stage === null) {
                continue;
            }

            $expected = (float) ($row->expected_amount ?? 0);
            $paid = (float) ($row->paid_amount ?? 0);

            // Include invoices even with expected=0, since paid might be set separately
            // Only skip if both are zero
            if ($expected <= 0 && $paid <= 0) {
                continue;
            }

            if ($expected > 0) {
                // Expected amount is the stage target, not cumulative per invoice file.
                // Keep the largest expected amount seen for the stage.
                $stageTotals[$stage]['expected'] = max($stageTotals[$stage]['expected'], $expected);
            }
            $stageTotals[$stage]['paid'] += max(0.0, $paid);
        }

        return $stageTotals;
    }

    public function syncCaseProgress(LawCase $case): float
    {
        $progress = $this->recalculateForCase((int) $case->caseId);
        $summaries = $this->getStageSummaries((int) $case->caseId);
        $expectedDefaults = $this->getExpectedByStageFromCase($case);

        foreach (self::STAGES as $stage) {
            $summaryExpected = (float) ($summaries[$stage]['expected'] ?? 0);
            $summaryPaid = (float) ($summaries[$stage]['paid'] ?? 0);
            $fallbackExpected = (float) ($expectedDefaults[$stage] ?? 0);

            if ($summaryExpected <= 0 && $fallbackExpected > 0) {
                $summaries[$stage]['expected'] = $fallbackExpected;
                $summaries[$stage]['balance'] = round(max($fallbackExpected - $summaryPaid, 0.0), 2);
            }
        }

        // Update balance fields based on invoice summaries
        $case->balance_initial_payment = (float) ($summaries['initial']['balance'] ?? 0);
        $case->balance_first_payment = (float) ($summaries['first']['balance'] ?? 0);
        $case->balance_second_payment = (float) ($summaries['second']['balance'] ?? 0);
        $case->balance_third_payment = (float) ($summaries['third']['balance'] ?? 0);
        $case->balance_final_payment = (float) ($summaries['final']['balance'] ?? 0);

        // Recalculate total balance
        $case->total_balance = $case->balance_initial_payment +
                               $case->balance_first_payment +
                               $case->balance_second_payment +
                               $case->balance_third_payment +
                               $case->balance_final_payment;

        $case->progress = $progress;
        $case->save();

        return $progress;
    }

    private function normalizeStage(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, self::STAGES, true) ? $normalized : null;
    }

    private function inferStageFromFileName(string $fileName): ?string
    {
        if ($fileName === '') {
            return null;
        }

        foreach (self::STAGES as $stage) {
            if (preg_match('/^' . preg_quote($stage, '/') . '[-_]/i', $fileName) === 1) {
                return $stage;
            }
        }

        return null;
    }

    private function isInvoiceBlobPathActive(string $invoiceBlobPath, array $activePaths): bool
    {
        $normalizedInvoicePath = trim($invoiceBlobPath);
        if ($normalizedInvoicePath === '') {
            return true;
        }

        if (in_array($normalizedInvoicePath, $activePaths, true)) {
            return true;
        }

        foreach ($activePaths as $activePath) {
            $normalizedActivePath = trim((string) $activePath);
            if ($normalizedActivePath === '') {
                continue;
            }

            // Support folder-level blob paths (e.g. cases/2/invoices/) and exact-file paths.
            if (str_starts_with($normalizedActivePath, $normalizedInvoicePath) || str_starts_with($normalizedInvoicePath, $normalizedActivePath)) {
                return true;
            }
        }

        return false;
    }

    private function getExpectedByStageFromCase(LawCase $case): array
    {
        return [
            'initial' => (float) ($case->expected_initial_payment ?? 0),
            'first' => (float) ($case->expected_first_payment ?? 0),
            'second' => (float) ($case->expected_second_payment ?? 0),
            'third' => (float) ($case->expected_third_payment ?? 0),
            'final' => (float) ($case->expected_final_payment ?? 0),
        ];
    }
}
