<?php

namespace App\Http\Middleware;

use App\Models\AslawLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserInteraction
{
    /**
     * Mask sensitive fields before persisting request payload to logs.
     */
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

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);

        try {
            $user = $request->user();
            $endpoint = (string) (optional($request->route())->uri() ?: $request->path());
            AslawLog::ensureImportantIndex();

            $criteria = [
                'method' => $request->method(),
                'path' => $endpoint,
            ];

            if ($user?->id) {
                $criteria['user_id'] = (int) $user->id;
            } else {
                // For unauthenticated routes, dedupe by caller IP.
                $criteria['user_id'] = null;
                $criteria['ip'] = $request->ip();
            }

            AslawLog::firstOrCreate($criteria, [
                'user_id' => $user?->id,
                'firm_id' => $user?->firmID,
                'email' => $user?->email,
                'method' => $request->method(),
                'path' => $endpoint,
                'route_name' => optional($request->route())->getName(),
                'status_code' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'query' => $request->query(),
                'payload' => $this->sanitizePayload($request->except(['file'])),
                'response_time_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Intentionally swallow log write failures so user requests are not affected.
        }

        return $response;
    }
}