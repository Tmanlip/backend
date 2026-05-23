<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AzureWebPubSubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    public function negotiate(Request $request, AzureWebPubSubService $webPubSub): JsonResponse
    {
        if (!$webPubSub->isEnabled()) {
            return response()->json([
                'message' => 'Real-time service is not enabled.',
            ], 503);
        }

        $userId = (string) $request->user()->id;
        $result = $webPubSub->negotiate($userId);

        return response()->json($result);
    }
}
