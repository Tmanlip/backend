<?php

namespace App\Services;

class UserKeyService
{
    /**
     * Generate a secure random 256-bit encryption key.
     *
     * @return string
     */
    public static function generateKey(): string
    {
        // 32 bytes = 256 bits (AES-256)
        return bin2hex(random_bytes(32));
    }
}