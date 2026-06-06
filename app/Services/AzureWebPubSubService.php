<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AzureWebPubSubService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.webpubsub.enabled', false)
            && filled(config('services.webpubsub.connection_string'))
            && filled(config('services.webpubsub.hub'));
    }

    public function negotiate(string|int $userId, array $roles = []): array
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('Azure Web PubSub is not configured.');
        }

        $parts = $this->connectionParts();
        $hub = $this->hub();
        $ttlSeconds = $this->tokenTtlSeconds();

        // Use manual client token generation with client audience.
        // This avoids dependency on :generateToken API auth mode differences.
        $clientAudience = $parts['endpoint'] . 'client/hubs/' . rawurlencode($hub);
        $claims = [
            'sub' => (string) $userId,
        ];

        if (!empty($roles)) {
            $claims['role'] = array_values($roles);
        }

        // Try signing with the raw access key first (most Azure Web PubSub environments).
        // If the key is base64-encoded bytes (some provisioning paths), we fall back to
        // the decoded form — consistent with the server-side publishToUser fallback.
        $token = $this->createJwtToken(
            audience: $clientAudience,
            accessKey: $parts['accessKey'],
            claims: $claims,
            ttlSeconds: $ttlSeconds,
            decodeAccessKey: false,
        );

        // Validate the token can be used: attempt a lightweight REST probe so we detect
        // auth failures before handing the URL to the client. On 401 regenerate with
        // base64-decoded key (mirrors the publishToUser fallback pattern).
        try {
            $probeUrl = sprintf(
                '%sapi/hubs/%s?api-version=%s',
                $parts['endpoint'],
                rawurlencode($hub),
                rawurlencode((string) config('services.webpubsub.api_version', '2024-01-01'))
            );
            $probeAudience = sprintf('%sapi/hubs/%s', rtrim($parts['endpoint'], '/') . '/', rawurlencode($hub));
            $serverToken = $this->createJwtToken(
                audience: $probeAudience,
                accessKey: $parts['accessKey'],
                claims: ['role' => ['webpubsub.sendToUser']],
                ttlSeconds: 120,
                decodeAccessKey: false,
            );
            $probe = \Illuminate\Support\Facades\Http::withToken($serverToken)->get($probeUrl);
            if ($probe->status() === 401) {
                // Raw key rejected — regenerate client token with decoded key.
                $token = $this->createJwtToken(
                    audience: $clientAudience,
                    accessKey: $parts['accessKey'],
                    claims: $claims,
                    ttlSeconds: $ttlSeconds,
                    decodeAccessKey: true,
                );
            }
        } catch (\Throwable) {
            // Probe failed for a non-auth reason (network, etc.) — proceed with original token.
        }

        $host = parse_url($parts['endpoint'], PHP_URL_HOST);
        $scheme = str_starts_with($parts['endpoint'], 'https://') ? 'wss' : 'ws';

        if (!$host) {
            throw new RuntimeException('Unable to resolve Azure Web PubSub host from endpoint.');
        }

        return [
            'url' => sprintf(
                '%s://%s/client/hubs/%s?access_token=%s',
                $scheme,
                $host,
                rawurlencode($hub),
                urlencode($token)
            ),
            'expires_in' => $ttlSeconds,
        ];
    }

    public function publishToUser(string|int $userId, array $payload): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $parts = $this->connectionParts();
        $hub = $this->hub();
        $apiVersion = (string) config('services.webpubsub.api_version', '2024-01-01');
        $serverAudience = sprintf(
            '%sapi/hubs/%s/users/%s/:send',
            rtrim($parts['endpoint'], '/') . '/',
            rawurlencode($hub),
            rawurlencode((string) $userId)
        );

        $url = sprintf(
            '%sapi/hubs/%s/users/%s/:send?api-version=%s',
            $parts['endpoint'],
            rawurlencode($hub),
            rawurlencode((string) $userId),
            rawurlencode($apiVersion)
        );

        $token = $this->createJwtToken(
            audience: $serverAudience,
            accessKey: $parts['accessKey'],
            claims: [
                'role' => ['webpubsub.sendToUser'],
            ],
            ttlSeconds: 3600,
            decodeAccessKey: false,
        );

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($url, $payload);

        if ($response->status() === 401) {
            $fallbackToken = $this->createJwtToken(
                audience: $serverAudience,
                accessKey: $parts['accessKey'],
                claims: [
                    'role' => ['webpubsub.sendToUser'],
                ],
                ttlSeconds: 3600,
                decodeAccessKey: true,
            );

            $response = Http::withToken($fallbackToken)
                ->acceptJson()
                ->post($url, $payload);
        }

        if ($response->successful()) {
            return true;
        }

        Log::warning('Azure Web PubSub publish failed', [
            'user_id' => (string) $userId,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return false;
    }

    private function hub(): string
    {
        $configured = trim((string) config('services.webpubsub.hub', 'aslawnotifications'));

        if ($configured === '') {
            return 'aslawnotifications';
        }

        // Hyphens are valid in Azure Web PubSub hub names when connecting via the client
        // WebSocket endpoint with manually generated tokens (this service does not use the
        // REST :generateToken endpoint that enforced a stricter pattern).
        $normalized = preg_replace('/[^A-Za-z0-9\-_`,.\[\]]/', '_', $configured) ?? 'aslawnotifications';

        if ($normalized === '') {
            return 'aslawnotifications';
        }

        if (!preg_match('/^[A-Za-z]/', $normalized)) {
            $normalized = 'h' . $normalized;
        }

        return substr($normalized, 0, 128);
    }

    private function tokenTtlSeconds(): int
    {
        $ttl = (int) config('services.webpubsub.token_ttl_seconds', 3600);

        return max(60, $ttl);
    }

    /**
     * @return array{endpoint: string, accessKey: string}
     */
    private function connectionParts(): array
    {
        $connectionString = trim((string) config('services.webpubsub.connection_string', ''));
        $segments = array_filter(array_map('trim', explode(';', $connectionString)));

        $parts = [];
        foreach ($segments as $segment) {
            [$key, $value] = array_pad(explode('=', $segment, 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[strtolower($key)] = $value;
            }
        }

        $endpoint = isset($parts['endpoint']) ? rtrim((string) $parts['endpoint'], '/') . '/' : null;
        $accessKey = $parts['accesskey'] ?? null;

        if (!filled($endpoint) || !filled($accessKey)) {
            throw new RuntimeException('Invalid AZURE_WEBPUBSUB_CONNECTION_STRING.');
        }

        return [
            'endpoint' => $endpoint,
            'accessKey' => (string) $accessKey,
        ];
    }

    private function createJwtToken(
        string $audience,
        string $accessKey,
        array $claims,
        int $ttlSeconds,
        bool $decodeAccessKey = true
    ): string
    {
        $now = time();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'aud' => $audience,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + max(60, $ttlSeconds),
        ]);

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $jwtSigningKey = $decodeAccessKey
            ? base64_decode($accessKey, true)
            : $accessKey;

        if ($jwtSigningKey === false || $jwtSigningKey === '') {
            throw new RuntimeException('Invalid AccessKey in AZURE_WEBPUBSUB_CONNECTION_STRING.');
        }

        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $jwtSigningKey, true);

        return $encodedHeader . '.' . $encodedPayload . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
