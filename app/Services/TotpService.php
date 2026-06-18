<?php

namespace App\Services;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $alphabetLength = strlen(self::BASE32_ALPHABET);
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    public function getOtpAuthUrl(string $issuer, string $accountName, string $secret): string
    {
        $issuerEncoded = rawurlencode($issuer);
        $accountEncoded = rawurlencode($accountName);

        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $issuerEncoded,
            $accountEncoded,
            $secret,
            $issuerEncoded
        );
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $normalizedCode = preg_replace('/\D+/', '', $code ?? '');

        if (!is_string($normalizedCode) || strlen($normalizedCode) !== 6) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $calculated = $this->calculateCode($secret, $timeSlice + $offset);
            if (hash_equals($calculated, $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret): string
    {
        return $this->calculateCode($secret, (int) floor(time() / 30));
    }

    private function calculateCode(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $timeBytes = pack('N*', 0, $timeSlice);

        $hmac = hash_hmac('sha1', $timeBytes, $secretKey, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;

        $binary = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        );

        $otp = $binary % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $length = strlen($normalized); $i < $length; $i++) {
            $character = $normalized[$i];
            $value = strpos(self::BASE32_ALPHABET, $character);

            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
