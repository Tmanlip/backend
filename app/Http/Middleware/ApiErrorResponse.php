<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiErrorResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!($request->is('api/*') || $request->expectsJson())) {
            return $response;
        }

        if (!($response instanceof JsonResponse)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }

        $data = $response->getData(true);
        if (is_array($data) && array_key_exists('success', $data)) {
            return $response;
        }

        $message = 'Error';
        if (is_array($data)) {
            $message = $data['message'] ?? $data['error'] ?? $message;
        }

        $message = $message !== '' ? $message : (Response::$statusTexts[$status] ?? 'Error');

        $payload = [
            'success' => false,
            'message' => $message,
            'status' => $status,
        ];

        if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $payload['errors'] = $data['errors'];
        }

        return response()->json($payload, $status);
    }
}
