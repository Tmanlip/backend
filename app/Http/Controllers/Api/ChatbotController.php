<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ChatbotController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbotService)
    {
    }

    public function health(): JsonResponse
    {
        return response()->json($this->chatbotService->health());
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string|max:5000',
            'firmID' => 'nullable|string|max:255',
            'category' => 'nullable|string|in:civil,corporate,criminal,general',
        ]);

        $firmID = $validated['firmID'] ?? $request->header('X-User-FirmID');
        $category = $validated['category'] ?? null;

        try {
            return response()->json($this->chatbotService->ask($validated['question'], $firmID, $category));
        } catch (Throwable $error) {
            if (str_contains(strtolower($error->getMessage()), 'timed out')) {
                return response()->json([
                    'error' => 'Model request timed out. Please try a shorter question or try again in a moment.',
                ], 504);
            }

            return response()->json([
                'error' => 'Ollama connection failed',
            ], 500);
        }
    }

    public function saveChat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string|max:5000',
            'answers' => 'required|string',
            'model' => 'required|string|in:aslaw-civil,aslaw-corporate,aslaw-criminal,aslaw-general',
            'category' => 'nullable|string|in:civil,corporate,criminal,general',
            'firmID' => 'nullable|string|max:255',
        ]);

        $firmID = $validated['firmID'] ?? $request->header('X-User-FirmID');

        try {
            $chatId = $this->chatbotService->saveChat($validated, $firmID);

            return response()->json([
                'success' => true,
                'message' => 'Chat saved successfully',
                'chatId' => $chatId,
            ], 201);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Failed to save chat. Check schema validation.',
            ], 400);
        }
    }

    public function chats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => 'nullable|string|in:aslaw-civil,aslaw-corporate,aslaw-criminal,aslaw-general',
            'category' => 'nullable|string|in:civil,corporate,criminal,general',
            'limit' => 'nullable|integer|min:1|max:500',
            'firmID' => 'nullable|string|max:255',
        ]);

        $firmID = $validated['firmID'] ?? $request->header('X-User-FirmID');
        $limit = (int) ($validated['limit'] ?? 10);

        try {
            return response()->json(
                $this->chatbotService->listChats($validated, $firmID, $limit)
            );
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage(),
            ], 500);
        }
    }
}
