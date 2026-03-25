<?php

namespace App\Services;

class DocumentEnvelopeCryptoService
{
    public function generateDek(): string
    {
        return random_bytes(32);
    }

    public function encrypt(string $plainContent, string $dek): array
    {
        $nonce = random_bytes(12);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainContent,
            'aes-256-gcm',
            $dek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($cipherText === false) {
            throw new \RuntimeException('Unable to encrypt document content.');
        }

        return [
            'cipher' => 'AES-256-GCM',
            'cipherText' => $cipherText,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
        ];
    }

    public function wrapDek(string $dek, string $publicKeyBody): array
    {
        $publicPem = $this->toPublicPem($publicKeyBody);

        $encryptedDek = '';
        $result = openssl_public_encrypt(
            $dek,
            $encryptedDek,
            $publicPem,
            OPENSSL_PKCS1_OAEP_PADDING
        );

        if ($result !== true) {
            throw new \RuntimeException('Unable to wrap DEK with recipient public key.');
        }

        return [
            'wrappedDek' => base64_encode($encryptedDek),
            'keyFingerprint' => hash('sha256', preg_replace('/\s+/', '', $publicKeyBody) ?? $publicKeyBody),
            'keyAlgorithm' => 'RSA-OAEP-256',
        ];
    }

    public function decrypt(string $cipherText, string $dek, string $nonceBase64, string $tagBase64): string
    {
        $nonce = base64_decode($nonceBase64, true);
        $tag = base64_decode($tagBase64, true);

        if ($nonce === false || $tag === false) {
            throw new \RuntimeException('Invalid nonce or tag encoding.');
        }

        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            $dek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plainText === false) {
            throw new \RuntimeException('Unable to decrypt document content.');
        }

        return $plainText;
    }

    private function toPublicPem(string $body): string
    {
        $normalized = preg_replace('/\s+/', '', $body);
        if ($normalized === null || $normalized === '') {
            throw new \InvalidArgumentException('Public key body is empty.');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($normalized, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
