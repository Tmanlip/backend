<?php

namespace App\Services;

use App\Models\User;

class FirmIdGenerator
{
    public static function generate(string $role): string
    {
        $prefixMap = [
            'admin'       => 'D',
            'junioradmin' => 'J',
            'lawyer'      => 'Y',
            'client'      => 'E',
        ];

        $prefix = $prefixMap[$role] ?? 'E';

        $lastUser = User::where('role', $role)
            ->where('firmID', 'like', $prefix . '%')
            ->orderBy('firmID', 'desc')
            ->first();

        $nextNumber = $lastUser
            ? intval(substr($lastUser->firmID, 1)) + 1
            : 1;

        return $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
