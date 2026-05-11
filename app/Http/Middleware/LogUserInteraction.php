<?php

namespace App\Http\Middleware;

use App\Jobs\LogUserInteractionJob;
use App\Models\AslawLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class LogUserInteraction
{
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'original_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
            ];
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : get_class($value);
        }

        return $value;
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

            $payload = $request->except(array_keys($request->allFiles()));
            $payload = $this->normalizeValue($payload);

            LogUserInteractionJob::dispatch([
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
                'payload' => $payload,
                'response_time_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_at' => now(),
            ])->afterResponse();
        } catch (\Throwable $e) {
            // Intentionally swallow log write failures so user requests are not affected.
        }

        return $response;
    }
}