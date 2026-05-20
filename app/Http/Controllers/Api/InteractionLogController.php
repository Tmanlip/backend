<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AslawLog;
use App\Support\LogClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionLogController extends Controller
{
    private const SERVICE_NAME = 'aslaw-backend';

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (!$actor || strtolower((string) $actor->role) !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min($limit, 500));

        $logs = AslawLog::query()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get([
                'user_id',
                'firm_id',
                'email',
                'method',
                'path',
                'status_code',
                'ip',
                'created_at',
            ])
            ->map(function (AslawLog $log): array {
                $statusCode = (int) ($log->status_code ?? 0);
                $method = (string) ($log->method ?? '');
                $path = (string) ($log->path ?? '');

                return [
                    '_id' => $log->_id,
                    'user_id' => $log->user_id,
                    'firm_id' => $log->firm_id,
                    'email' => $log->email,
                    'method' => $method,
                    'path' => $path,
                    'status_code' => $statusCode,
                    'ip' => $log->ip,
                    'created_at' => $log->created_at,
                    'service' => self::SERVICE_NAME,
                    'module' => LogClassification::deriveModule($path),
                    'severity' => LogClassification::deriveSeverity($statusCode, $method, $path),
                ];
            })
            ->values();

        return response()->json([
            'data' => $logs,
            'count' => $logs->count(),
        ]);
    }
}
