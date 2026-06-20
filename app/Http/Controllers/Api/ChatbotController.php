<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'language' => ['nullable', 'string', 'in:english,malay,bm,auto'],
            'sessionId' => ['nullable', 'string', 'max:100'],
            'persist' => ['nullable', 'boolean'],
        ]);

        $question = trim((string) $validated['question']);
        $categoryHint = $validated['category']
            ?? $validated['selectedCategory']
            ?? $validated['practiceArea']
            ?? null;
        $languageHint = $validated['language'] ?? null;

        try {
            $result = $this->chatbotService->ask(
                $question,
                is_string($categoryHint) ? $categoryHint : null,
                is_string($languageHint) ? $languageHint : null
            );

            $sessionId = trim((string) ($validated['sessionId'] ?? ''));
            if ($sessionId === '') {
                $sessionId = (string) Str::uuid();
            }

            if ((bool) ($validated['persist'] ?? true)) {
                try {
                    $this->persistChat([
                        'sessionId' => $sessionId,
                        'question' => $question,
                        'answer' => (string) $result['answer'],
                        'category' => (string) $result['category'],
                        'model' => (string) $result['model'],
                    ]);
                } catch (\Throwable $persistError) {
                    Log::warning('Chatbot response generated but chat persistence failed.', [
                        'session_id' => $sessionId,
                        'category' => (string) ($result['category'] ?? ''),
                        'model' => (string) ($result['model'] ?? ''),
                        'error' => $persistError->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'sessionId' => $sessionId,
                'question' => $question,
                'answer' => $result['answer'],
                'category' => $result['category'],
                'model' => $result['model'],
                'domainMismatch' => (bool) ($result['domain_mismatch'] ?? false),
                'currentCategory' => $result['current_category'] ?? null,
                'suggestedCategory' => $result['suggested_category'] ?? null,
            ]);
        } catch (\Throwable $error) {
            Log::error('Chatbot ask failed.', [
                'error' => $error->getMessage(),
                'exception' => $error::class,
                'question_length' => mb_strlen($question),
                'category_hint' => is_string($categoryHint) ? $categoryHint : null,
                'language_hint' => is_string($languageHint) ? $languageHint : null,
                'persist' => (bool) ($validated['persist'] ?? true),
                'session_id' => (string) ($validated['sessionId'] ?? ''),
            ]);

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

    public function health(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'writeProbe' => ['nullable', 'boolean'],
        ]);

        $shouldWriteProbe = (bool) ($validated['writeProbe'] ?? false);
        $ollamaConfigured = trim((string) config('ai.ollama_base_url', 'http://127.0.0.1:11434'));
        $ollamaResolved = $this->resolveOllamaBaseUrl();
        $ollamaNormalized = $ollamaConfigured !== $ollamaResolved;

        $checks = [
            'ollama' => [
                'status' => 'error',
                'configured' => $ollamaConfigured,
                'resolved' => $ollamaResolved,
                'was_normalized' => $ollamaNormalized,
            ],
            'mongo_read' => [
                'status' => 'error',
            ],
            'mongo_write' => [
                'status' => $shouldWriteProbe ? 'error' : 'skipped',
            ],
        ];

        $statusCode = Response::HTTP_OK;

        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->get($ollamaResolved . '/api/tags');

            if ($response->successful()) {
                $checks['ollama']['status'] = 'ok';
                $checks['ollama']['http_status'] = $response->status();
            } else {
                $checks['ollama']['status'] = 'error';
                $checks['ollama']['http_status'] = $response->status();
                $checks['ollama']['error'] = 'Ollama returned non-success status.';
                $statusCode = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        } catch (\Throwable $error) {
            $checks['ollama']['status'] = 'error';
            $checks['ollama']['error'] = $error->getMessage();
            $statusCode = Response::HTTP_SERVICE_UNAVAILABLE;
        }

        try {
            $this->chatCollection()->countDocuments([]);
            $checks['mongo_read']['status'] = 'ok';
        } catch (\Throwable $error) {
            $checks['mongo_read']['status'] = 'error';
            $checks['mongo_read']['error'] = $error->getMessage();
            $statusCode = Response::HTTP_SERVICE_UNAVAILABLE;
        }

        if ($shouldWriteProbe) {
            try {
                $collection = $this->chatCollection();
                $probeId = (string) Str::uuid();
                $insert = $collection->insertOne([
                    'probe' => true,
                    'probeId' => $probeId,
                    'createdAt' => now()->toDateTimeImmutable(),
                    'createdAtIso' => now()->toIso8601String(),
                ]);

                $collection->deleteOne(['_id' => $insert->getInsertedId()]);

                $checks['mongo_write']['status'] = 'ok';
            } catch (\Throwable $error) {
                $checks['mongo_write']['status'] = 'error';
                $checks['mongo_write']['error'] = $error->getMessage();
                $statusCode = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        }

        $overall = $statusCode === Response::HTTP_OK ? 'ok' : 'degraded';

        return response()->json([
            'status' => $overall,
            'checks' => $checks,
        ], $statusCode);
    }

    private function resolveOllamaBaseUrl(): string
    {
        $configured = trim((string) config('ai.ollama_base_url', 'http://127.0.0.1:11434'));
        $normalized = preg_replace('/\s+/', '', $configured) ?? '';

        if ($normalized === '') {
            $normalized = 'http://127.0.0.1:11434';
        }

        if (! preg_match('/^https?:\/\//i', $normalized)) {
            $normalized = 'http://' . $normalized;
        }

        return rtrim($normalized, '/');
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
