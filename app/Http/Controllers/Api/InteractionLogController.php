<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AslawLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionLogController extends Controller
{
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
            ]);

        return response()->json([
            'data' => $logs,
            'count' => $logs->count(),
        ]);
    }
}
