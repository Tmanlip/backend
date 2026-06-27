<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\LawCase;
use App\Services\CaseInvoiceFinancialSyncService;
use App\Support\FeePhaseCalculator;

class Invoice extends Model
{
    private const MAX_INVOICE_NUMBER_RETRIES = 10;

    protected $fillable = [
        'lawyerID',
        'case_id',
        'clientID',
        'invoice_number',
        'payment_stage',
        'case_type_fee_json',
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
        'type_of_work',
        'blob_path'
    ];

    protected $casts = [
        'case_type_fee_json' => 'array',
    ];

    public function case()
    {
        return $this->belongsTo(LawCase::class, 'case_id', 'caseId');
    }

    public static function generateInvoiceNumber($caseId, $clientId = null, $lawyerId = null): string
    {
        for ($attempt = 0; $attempt < self::MAX_INVOICE_NUMBER_RETRIES; $attempt++) {
            $candidate = self::generateNumberFromParts($caseId, $clientId, $lawyerId);
            $exists = self::query()->where('invoice_number', $candidate)->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique invoice number after multiple attempts.');
    }

    public static function generateNumberFromParts($caseId, $clientId, $lawyerId): string
    {
        $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomToken = static function (int $length) use ($pool): string {
            $token = '';
            $maxIndex = strlen($pool) - 1;
            for ($i = 0; $i < $length; $i++) {
                $token .= $pool[random_int(0, $maxIndex)];
            }

            return $token;
        };

        // Format: A + 2 alnum + case_id + 6 alnum
        return sprintf('A%s%s%s', $randomToken(2), (string) ($caseId ?? 0), $randomToken(6));
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            $case = LawCase::with('client')->find($invoice->case_id);

            if ($case) {
                if (empty($invoice->lawyerID)) {
                    $invoice->lawyerID = $case->lawyerID;
                }

                if (empty($invoice->clientID)) {
                    $invoice->clientID = $case->clientID;
                }
            }

            // Preserve caller-provided invoice numbers (e.g., generated document number).
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateInvoiceNumber(
                    $invoice->case_id,
                    $invoice->clientID,
                    $invoice->lawyerID
                );
            }

            if (empty($invoice->expected_amount) && !empty($invoice->case_type_fee_json) && !empty($invoice->payment_stage)) {
                $invoice->expected_amount = FeePhaseCalculator::computeExpectedForStage(
                    $invoice->case_type_fee_json,
                    (string) $invoice->payment_stage
                );
            }

            if ($case) {
                if (empty($invoice->expected_amount)) {
                    switch ($invoice->payment_stage) {
                        case 'initial':
                            $invoice->expected_amount = $case->expected_initial_payment;
                            break;
                        case 'first':
                            $invoice->expected_amount = $case->expected_first_payment;
                            break;
                        case 'second':
                            $invoice->expected_amount = $case->expected_second_payment;
                            break;
                        case 'third':
                            $invoice->expected_amount = $case->expected_third_payment;
                            break;
                        case 'final':
                            $invoice->expected_amount = $case->expected_final_payment;
                            break;
                    }
                }

                // Snapshot
                if (empty($invoice->case_title)) {
                    $invoice->case_title = $case->title;
                }

                if (empty($invoice->client_name)) {
                    $invoice->client_name = optional($case->client)->name;
                }
            }

            // Balance calculation
            if (empty($invoice->balance)) {
                $invoice->balance = $invoice->expected_amount - ($invoice->paid_amount ?? 0);
            }

            // Total calculation (for PDF)
            if (empty($invoice->total_amount)) {
                $invoice->total_amount =
                    $invoice->expected_amount +
                    ($invoice->tax ?? 0) -
                    ($invoice->discount ?? 0);
            }
        });

        static::updating(function ($invoice) {
            if (
                ($invoice->isDirty('case_type_fee_json') || $invoice->isDirty('payment_stage'))
                && (float) ($invoice->expected_amount ?? 0) <= 0
                && !empty($invoice->case_type_fee_json)
                && !empty($invoice->payment_stage)
            ) {
                $invoice->expected_amount = FeePhaseCalculator::computeExpectedForStage(
                    $invoice->case_type_fee_json,
                    (string) $invoice->payment_stage
                );
            }

            // Always keep balance updated
            $invoice->balance = $invoice->expected_amount - $invoice->paid_amount;
        });

        static::saved(function (Invoice $invoice) {
            app(CaseInvoiceFinancialSyncService::class)->syncCaseFromInvoices((int) $invoice->case_id);
        });

        static::deleted(function (Invoice $invoice) {
            app(CaseInvoiceFinancialSyncService::class)->syncCaseFromInvoices((int) $invoice->case_id);
        });
    }
}