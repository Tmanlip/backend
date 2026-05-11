<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckArchivedUser
{
    /**
     * Routes that bypass the status check (allowed for archived/inactive users).
     */
    protected array $bypassRoutes = [
        '/logout',
        '/refresh',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $status = strtolower(trim((string) $user->status));
            $path = $request->getPathInfo();

            // Skip status check for bypass routes
            $isBypassRoute = collect($this->bypassRoutes)
                ->some(fn ($route) => str_ends_with($path, $route));

            if (!$isBypassRoute) {
                // Block archived and inactive users from most interactions
                if ($status === 'archived') {
                    return response()->json([
                        'message' => 'Your account has been archived and cannot access this resource.',
                        'status' => 'archived'
                    ], 403);
                }

                if ($status === 'inactive') {
                    return response()->json([
                        'message' => 'Your account is inactive. Please contact an administrator.',
                        'status' => 'inactive'
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
