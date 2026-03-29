<?php

namespace App\Http\Middleware;

use App\Jobs\LogUserInteractionJob;
use App\Models\AslawLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserInteraction
{
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
                'payload' => $request->except(['file']),
                'response_time_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_at' => now(),
            ])->afterResponse();
        } catch (\Throwable $e) {
            // Intentionally swallow log write failures so user requests are not affected.
        }

        return $response;
    }
}