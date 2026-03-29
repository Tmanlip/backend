<?php

namespace App\Jobs;

use App\Models\AslawLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogUserInteractionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly array $payload)
    {
    }

    private function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'otp',
            'code',
            'rsa_private_key',
            'key',
        ];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }

    public function handle(): void
    {
        $data = $this->payload;

        $criteria = [
            'method' => (string) ($data['method'] ?? ''),
            'path' => (string) ($data['path'] ?? ''),
        ];

        if (!empty($data['user_id'])) {
            $criteria['user_id'] = (int) $data['user_id'];
        } else {
            $criteria['user_id'] = null;
            $criteria['ip'] = (string) ($data['ip'] ?? '');
        }

        if (isset($data['payload']) && is_array($data['payload'])) {
            $data['payload'] = $this->sanitizePayload($data['payload']);
        }

        AslawLog::firstOrCreate($criteria, $data);
    }
}
