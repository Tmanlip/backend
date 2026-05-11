<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LawCase;

class CaseInvoiceFinancialSyncService
{
    private const STAGE_FIELD_MAP = [
        'initial' => ['expected' => 'expected_initial_payment', 'balance' => 'balance_initial_payment'],
        'first' => ['expected' => 'expected_first_payment', 'balance' => 'balance_first_payment'],
        'second' => ['expected' => 'expected_second_payment', 'balance' => 'balance_second_payment'],
        'third' => ['expected' => 'expected_third_payment', 'balance' => 'balance_third_payment'],
        'final' => ['expected' => 'expected_final_payment', 'balance' => 'balance_final_payment'],
    ];

    public function __construct(private readonly InvoiceProgressService $invoiceProgressService)
    {
    }

    public function syncCaseFromInvoices(int $caseId): void
    {
        $case = LawCase::find($caseId);
        if (!$case) {
            return;
        }

        $summaries = $this->invoiceProgressService->getStageSummaries($caseId);
        $totalBalance = 0.0;

        foreach (self::STAGE_FIELD_MAP as $stage => $fields) {
            $summary = $summaries[$stage] ?? ['expected' => 0, 'paid' => 0, 'balance' => 0];

            $expected = (float) ($summary['expected'] ?? 0);
            $paid = (float) ($summary['paid'] ?? 0);
            $balance = (float) ($summary['balance'] ?? 0);
            $fallbackExpected = (float) ($case->{$fields['expected']} ?? 0);

            // Preserve existing expected target if invoices have not provided a stage value.
            if ($expected > 0) {
                $case->{$fields['expected']} = round($expected, 2);
            } else {
                $expected = max($fallbackExpected, 0.0);
            }

            // When no invoice rows exist for a stage, keep case balance aligned to expected-paid.
            if ($balance <= 0 && $expected > 0) {
                $balance = round(max($expected - max($paid, 0.0), 0.0), 2);
            }

            $case->{$fields['balance']} = round(max($balance, 0.0), 2);
            $totalBalance += (float) $case->{$fields['balance']};
        }

        $case->progress = $this->invoiceProgressService->recalculateForCase($caseId);
        $case->total_balance = round(max($totalBalance, 0.0), 2);
        $case->saveQuietly();
    }

    public function syncInvoicesFromCase(LawCase $case, ?array $stages = null): void
    {
        $targetStages = $stages ?: array_keys(self::STAGE_FIELD_MAP);

        foreach ($targetStages as $stage) {
            if (!isset(self::STAGE_FIELD_MAP[$stage])) {
                continue;
            }

            $fields = self::STAGE_FIELD_MAP[$stage];
            $expected = (float) ($case->{$fields['expected']} ?? 0);
            $balance = (float) ($case->{$fields['balance']} ?? 0);

            Invoice::query()
                ->where('case_id', (int) $case->caseId)
                ->where('payment_stage', $stage)
                ->update([
                    'expected_amount' => round(max($expected, 0.0), 2),
                    'balance' => round(max($balance, 0.0), 2),
                ]);
        }
    }

    public function stageKeysForCaseChanges(array $dirtyFields): array
    {
        $resolvedStages = [];

        foreach (self::STAGE_FIELD_MAP as $stage => $fields) {
            if (in_array($fields['expected'], $dirtyFields, true) || in_array($fields['balance'], $dirtyFields, true)) {
                $resolvedStages[] = $stage;
            }
        }

        return $resolvedStages;
    }
}
