<?php

namespace App\Models;

use App\Services\CaseNumberGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LawCase extends Model
{
    use HasFactory;

    protected $table = 'law_cases';
    protected $primaryKey = 'caseId';

    protected $fillable = [
        'caseNumber',
        'title',
        'caseType',
        'description',
        'case_type_fee_json',
        'status',
        'progress',
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
        'total_balance',
        'lawyerID',
        'clientID',
        'lawyerFirmID',
        'clientFirmID',
        'oppositionLawyerName',
        'oppositionLawyerFirmID',
    ];

    protected $casts = [
        'case_type_fee_json' => 'array',
        'progress' => 'float',
        'expected_initial_payment' => 'float',
        'expected_first_payment' => 'float',
        'expected_second_payment' => 'float',
        'expected_third_payment' => 'float',
        'expected_final_payment' => 'float',
        'balance_initial_payment' => 'float',
        'balance_first_payment' => 'float',
        'balance_second_payment' => 'float',
        'balance_third_payment' => 'float',
        'balance_final_payment' => 'float',
        'total_balance' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (LawCase $case): void {
            if (!$case->caseNumber) {
                $case->caseNumber = CaseNumberGenerator::generate();
            }

            if (!isset($case->balance_initial_payment)) {
                $case->balance_initial_payment = (float) ($case->expected_initial_payment ?? 0);
            }
            if (!isset($case->balance_first_payment)) {
                $case->balance_first_payment = (float) ($case->expected_first_payment ?? 0);
            }
            if (!isset($case->balance_second_payment)) {
                $case->balance_second_payment = (float) ($case->expected_second_payment ?? 0);
            }
            if (!isset($case->balance_third_payment)) {
                $case->balance_third_payment = (float) ($case->expected_third_payment ?? 0);
            }
            if (!isset($case->balance_final_payment)) {
                $case->balance_final_payment = (float) ($case->expected_final_payment ?? 0);
            }

            if (!isset($case->total_balance)) {
                $case->total_balance =
                    (float) ($case->balance_initial_payment ?? 0)
                    + (float) ($case->balance_first_payment ?? 0)
                    + (float) ($case->balance_second_payment ?? 0)
                    + (float) ($case->balance_third_payment ?? 0)
                    + (float) ($case->balance_final_payment ?? 0);
            }
        });

        static::updating(function (LawCase $case): void {
            if ($case->isDirty('caseNumber')) {
                throw new \Exception('caseNumber cannot be modified.');
            }
        });
    }

    public function lawyer()
    {
        return $this->belongsTo(User::class, 'lawyerID', 'id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'clientID', 'id');
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class, 'case_id', 'caseId');
    }
}
