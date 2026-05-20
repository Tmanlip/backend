<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\HttpFoundation\Response;

class ChatbotController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbotService)
    {
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:8000'],
            'category' => ['nullable', 'string', 'in:civil,corporate,criminal,general'],
            'selectedCategory' => ['nullable', 'string', 'in:civil,corporate,criminal,general'],
            'practiceArea' => ['nullable', 'string', 'in:civil,corporate,criminal,general'],
            'sessionId' => ['nullable', 'string', 'max:100'],
            'persist' => ['nullable', 'boolean'],
        ]);

        $question = trim((string) $validated['question']);
        $categoryHint = $validated['category']
            ?? $validated['selectedCategory']
            ?? $validated['practiceArea']
            ?? null;

        try {
            $result = $this->chatbotService->ask($question, is_string($categoryHint) ? $categoryHint : null);

            $sessionId = trim((string) ($validated['sessionId'] ?? ''));
            if ($sessionId === '') {
                $sessionId = (string) Str::uuid();
            }

            if ((bool) ($validated['persist'] ?? true)) {
                $this->persistChat([
                    'sessionId' => $sessionId,
                    'question' => $question,
                    'answer' => (string) $result['answer'],
                    'category' => (string) $result['category'],
                    'model' => (string) $result['model'],
                ]);
            }

            return response()->json([
                'sessionId' => $sessionId,
                'question' => $question,
                'answer' => $result['answer'],
                'category' => $result['category'],
                'model' => $result['model'],
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'error' => $error->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function chats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sessionId' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $col = $this->chatCollection();
            $limit = (int) ($validated['limit'] ?? 50);
            $rows = [];
            foreach ($col->find([
                'sessionId' => (string) $validated['sessionId'],
            ], [
                'sort' => ['createdAt' => 1],
                'limit' => $limit,
                'projection' => [
                    '_id' => 0,
                    'sessionId' => 1,
                    'question' => 1,
                    'answer' => 1,
                    'category' => 1,
                    'model' => 1,
                    'createdAtIso' => 1,
                ],
            ]) as $doc) {
                $rows[] = $doc;
            }

            return response()->json([
                'sessionId' => (string) $validated['sessionId'],
                'count' => count($rows),
                'chats' => array_values(array_map(static fn ($doc) => (array) $doc, $rows)),
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'error' => $error->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dbHealth(): JsonResponse
    {
        try {
            $this->chatCollection()->countDocuments([]);

            return response()->json([
                'status' => 'ok',
                'database' => 'mongodb',
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'status' => 'error',
                'database' => 'mongodb',
                'error' => $error->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function persistChat(array $payload): void
    {
        $now = now()->toDateTimeImmutable();

        $this->chatCollection()->insertOne([
            'sessionId' => (string) $payload['sessionId'],
            'question' => (string) $payload['question'],
            'answer' => (string) $payload['answer'],
            'category' => (string) $payload['category'],
            'model' => (string) $payload['model'],
            'createdAt' => $now,
            'createdAtIso' => now()->toIso8601String(),
        ]);
    }

    private function chatCollection(): \MongoDB\Collection
    {
        $db = (string) config('ai.chatbot_db', 'rag__usage');
        $collection = (string) config('ai.chatbot_collection', 'chat_history');

        return DB::connection('mongodb')->getClient()->selectCollection($db, $collection);
    }
}
