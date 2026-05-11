<?php

namespace App\Services;

use App\Models\LawCase;

class CaseNumberGenerator
{
    public static function generate(): string
    {
        $prefix = 'C';

        $lastCase = LawCase::where('caseNumber', 'like', $prefix . '%')
            ->orderBy('caseNumber', 'desc')
            ->first();

        $nextNumber = $lastCase
            ? intval(substr((string) $lastCase->caseNumber, 1)) + 1
            : 1;

        return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
