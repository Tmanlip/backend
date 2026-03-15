<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

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

    /**
     * Generate an RSA key pair and return encrypted private key + public key.
     *
     * @return array{encryptedPrivateKey: string, publicKey: string}
     */
    public static function generateRsaKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('Unable to generate RSA key pair.');
        }

        $privateKey = '';
        if (!openssl_pkey_export($resource, $privateKey)) {
            throw new \RuntimeException('Unable to export RSA private key.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException('Unable to read RSA public key details.');
        }

        $privateKeyBody = self::extractPemBody($privateKey);
        $publicKeyBody = self::extractPemBody($details['key']);

        return [
            'encryptedPrivateKey' => Crypt::encryptString($privateKeyBody),
            'publicKey' => $publicKeyBody,
        ];
    }

    /**
     * Remove PEM header/footer and whitespace from key content.
     */
    public static function extractPemBody(string $pem): string
    {
        $normalized = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----/', '', $pem);
        if ($normalized === null) {
            throw new \RuntimeException('Unable to normalize PEM key content.');
        }

        $normalized = preg_replace('/\s+/', '', $normalized);
        if ($normalized === null || $normalized === '') {
            throw new \RuntimeException('PEM key content is empty after normalization.');
        }

        return $normalized;
    }
}
