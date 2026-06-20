<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

class EvaluateAslawModels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:evaluate-aslaw
                            {--source= : Source MongoDB collection containing evaluation questions}
                            {--file= : Plain text file path (one question per line) as source}
                            {--target=scope : Target MongoDB collection for evaluation results}
                            {--source-db= : Source MongoDB database (defaults to MONGODB_DATABASE)}
                            {--target-db=rag__usage : Target MongoDB database for evaluation results}
                            {--question-field=question : Field name that stores the question text}
                            {--expected-field=category : Field name that stores expected category}
                            {--model-field=model : Field name that stores explicit target model}
                            {--scope-model=qwen2.5:7b : Base model used to classify question scope}
                            {--infer-category=1 : Infer category from question when expected category is missing}
                            {--default-model=aslaw-general : Fallback model when mapping fails}
                            {--limit=0 : Max number of source records to evaluate (0 = all)}
                            {--scope-timeout=20 : Timeout in seconds for scope classification request}
                            {--answer-timeout=180 : Timeout in seconds for final answer generation request}
                            {--timeout=180 : Ollama request timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run ASLAW model evaluation from MongoDB questions and write results to rag__usage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = trim((string) $this->option('source'));
        $filePath = trim((string) $this->option('file'));
        $target = trim((string) $this->option('target'));
        $sourceDb = trim((string) $this->option('source-db'));
        $targetDb = trim((string) $this->option('target-db'));
        $questionField = trim((string) $this->option('question-field'));
        $expectedField = trim((string) $this->option('expected-field'));
        $modelField = trim((string) $this->option('model-field'));
        $scopeModel = trim((string) $this->option('scope-model'));
        $inferCategory = (bool) ((int) $this->option('infer-category'));
        $defaultModel = trim((string) $this->option('default-model'));
        $limit = (int) $this->option('limit');
        $scopeTimeout = (int) $this->option('scope-timeout');
        $answerTimeout = (int) $this->option('answer-timeout');
        $timeout = (int) $this->option('timeout');

        // Backward compatibility for existing usage that only sets --timeout.
        $hasExplicitScopeTimeout = $this->input->hasParameterOption('--scope-timeout');
        $hasExplicitAnswerTimeout = $this->input->hasParameterOption('--answer-timeout');
        if (! $hasExplicitScopeTimeout) {
            $scopeTimeout = max(10, min($timeout, 45));
        }
        if (! $hasExplicitAnswerTimeout) {
            $answerTimeout = max($timeout, 5);
        }

        if ($source === '' && $filePath === '') {
            $this->error('Provide either --source=collection_name or --file=path_to_questions.txt');
            return self::FAILURE;
        }

        $sourceDb = $sourceDb !== '' ? $sourceDb : (string) env('MONGODB_DATABASE', 'aslaw');
        $targetDb = $targetDb !== '' ? $targetDb : 'rag__usage';

        $allowedModels = [
            'aslaw-civil',
            'aslaw-corporate',
            'aslaw-criminal',
            'aslaw-general',
        ];

        if (! in_array($defaultModel, $allowedModels, true)) {
            $this->error('Invalid --default-model. Allowed: ' . implode(', ', $allowedModels));
            return self::FAILURE;
        }

        $baseUrl = $this->resolveOllamaBaseUrl();
        $runId = (string) Str::uuid();

        $client = DB::connection('mongodb')->getClient();
        $targetCollection = $client->selectCollection($targetDb, $target);

        $rows = [];
        $sourceLabel = '';

        if ($filePath !== '') {
            if (! is_file($filePath)) {
                $this->error("Question file not found: {$filePath}");
                return self::FAILURE;
            }

            $sourceLabel = 'file:' . $filePath;
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $index => $line) {
                $question = trim((string) $line);
                if ($question === '') {
                    continue;
                }

                $rows[] = [
                    '_id' => 'line-' . ($index + 1),
                    $questionField => $question,
                ];
            }

            if ($limit > 0) {
                $rows = array_slice($rows, 0, $limit);
            }
        } else {
            $sourceCollection = $client->selectCollection($sourceDb, $source);
            $findOptions = [];

            if ($limit > 0) {
                $findOptions['limit'] = $limit;
            }

            $rows = iterator_to_array($sourceCollection->find([], $findOptions), false);
            $sourceLabel = $sourceDb . '.' . $source;
        }

        if (count($rows) === 0) {
            $this->warn('No questions found in source input.');
            return self::SUCCESS;
        }

        $this->info("Starting ASLAW evaluation run: {$runId}");
        $this->info("Source: {$sourceLabel} | Target: {$targetDb}.{$target} | Total: " . count($rows));

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $doc = (array) $row;

            $question = trim((string) data_get($doc, $questionField, ''));
            if ($question === '') {
                $skipped++;
                $bar->advance();
                continue;
            }

            $expectedCategory = strtolower(trim((string) data_get($doc, $expectedField, '')));
            $explicitModel = strtolower(trim((string) data_get($doc, $modelField, '')));

            $scopeCategory = null;
            $scopeRaw = null;
            $scopeError = null;
            try {
                [$scopeCategory, $scopeRaw] = $this->classifyScopeCategory($baseUrl, $scopeModel, $question, max(5, $scopeTimeout));
            } catch (\Throwable $e) {
                $scopeError = $e->getMessage();
            }

            $routedCategory = $scopeCategory;
            if ($routedCategory === null || $routedCategory === '') {
                $routedCategory = $expectedCategory;
            }
            if (($routedCategory === null || $routedCategory === '') && $inferCategory) {
                $routedCategory = $this->inferCategoryFromQuestion($question);
            }

            // Routing is driven by scope classification first, then fallbacks.
            $selectedModel = $this->resolveModel('', (string) $routedCategory, $defaultModel, $allowedModels);

            $startedAtIso = now()->toIso8601String();
            $started = microtime(true);

            $status = 'ok';
            $answer = '';
            $errorMessage = null;
            $doneReason = null;

            try {
                $response = Http::connectTimeout(5)
                    ->timeout(max($answerTimeout, 5))
                    ->post($baseUrl . '/api/generate', [
                        'model' => $selectedModel,
                        'prompt' => $question,
                        'stream' => false,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('Ollama request failed with status ' . $response->status());
                }

                $json = $response->json();
                $answer = (string) data_get($json, 'response', '');
                $doneReason = data_get($json, 'done_reason');
            } catch (\Throwable $e) {
                $status = 'error';
                $errorMessage = $e->getMessage();
                $errors++;
            }

            $latencyMs = (int) round((microtime(true) - $started) * 1000);
            $finishedAtIso = now()->toIso8601String();
            $mongoNow = now()->toDateTimeImmutable();

            $resultDoc = [
                'runId' => $runId,
                'sourceCollection' => $sourceLabel,
                'sourceId' => isset($doc['_id']) ? (string) $doc['_id'] : null,
                'question' => $question,
                'expectedCategory' => $expectedCategory !== '' ? $expectedCategory : null,
                'scopeModel' => $scopeModel,
                'scopeTimeoutSec' => max(5, $scopeTimeout),
                'answerTimeoutSec' => max(5, $answerTimeout),
                'scopeCategory' => $scopeCategory,
                'scopeRaw' => $scopeRaw,
                'scopeError' => $scopeError,
                'explicitModel' => $explicitModel !== '' ? $explicitModel : null,
                'selectedModel' => $selectedModel,
                'status' => $status,
                'answer' => $answer,
                'error' => $errorMessage,
                'doneReason' => $doneReason,
                'checks' => [
                    'hasScopeSection' => $this->containsAny($answer, ['scope check', 'scope and safety check']),
                    'hasDisclaimer' => $this->containsAny($answer, ['not legal advice', 'general information']),
                ],
                'latencyMs' => $latencyMs,
                'startedAt' => $startedAtIso,
                'finishedAt' => $finishedAtIso,
                'createdAt' => $mongoNow,
                'updatedAt' => $mongoNow,
            ];

            $targetCollection->insertOne($resultDoc);
            $inserted++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Evaluation run completed: {$runId}");
        $this->line("Inserted: {$inserted}");
        $this->line("Skipped (empty question): {$skipped}");
        $this->line("Errors: {$errors}");
        $this->line("Results saved to MongoDB collection: {$targetDb}.{$target}");

        return self::SUCCESS;
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

    /**
     * Lightweight keyword-based category inference for uncategorized question lists.
     */
    private function inferCategoryFromQuestion(string $question): string
    {
        $q = Str::lower($question);

        $criminalKeywords = [
            'police', 'arrest', 'assault', 'threat', 'threatened', 'violence', 'stole', 'stolen', 'forged',
            'forgery', 'scam', 'fraud', 'hacked', 'blackmail', 'bribe', 'laundering', 'illegal activities',
            'fake cheques', 'identity', 'search my house', 'seize', 'money laundering',
        ];

        $corporateKeywords = [
            'director', 'shareholder', 'board', 'shares', 'company', 'annual documents', 'company accounts',
            'minority shareholder', 'majority shareholders', 'board resolutions', 'company sale', 'resigns',
            'remove a director', 'company decisions', 'management',
        ];

        $civilKeywords = [
            'contract', 'agreement', 'invoice', 'tenant', 'landlord', 'deposit', 'supplier', 'property',
            'neighbour', 'damaged goods', 'payment', 'refund', 'breached', 'verbal agreement', 'whatsapp',
            'renovation', 'project', 'shipment', 'consultant', 'travel package', 'claim compensation',
        ];

        if ($this->containsAny($q, $criminalKeywords)) {
            return 'criminal';
        }

        if ($this->containsAny($q, $corporateKeywords)) {
            return 'corporate';
        }

        if ($this->containsAny($q, $civilKeywords)) {
            return 'civil';
        }

        return 'general';
    }

    /**
     * Classify legal scope using base model and return [category, rawResponse].
     */
    private function classifyScopeCategory(string $baseUrl, string $scopeModel, string $question, int $timeoutSeconds): array
    {
        $prompt = "Classify this Malaysian legal question into exactly one category: civil, corporate, criminal, or general. "
            . "Return only one lowercase word from this set: civil|corporate|criminal|general. "
            . "Question: " . $question;

        $response = Http::connectTimeout(5)
            ->timeout($timeoutSeconds)
            ->post($baseUrl . '/api/generate', [
                'model' => $scopeModel,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0,
                    'num_predict' => 8,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Scope classification failed with status ' . $response->status());
        }

        $raw = trim((string) data_get($response->json(), 'response', ''));
        $normalized = Str::lower($raw);

        if (preg_match('/\b(civil|corporate|criminal|general)\b/', $normalized, $matches)) {
            return [$matches[1], $raw];
        }

        return [null, $raw];
    }

    /**
     * Map category/model fields to supported ASLAW model IDs.
     */
    private function resolveModel(string $explicitModel, string $expectedCategory, string $defaultModel, array $allowedModels): string
    {
        if ($explicitModel !== '') {
            if (in_array($explicitModel, $allowedModels, true)) {
                return $explicitModel;
            }

            if (str_starts_with($explicitModel, 'aslaw-')) {
                return $defaultModel;
            }
        }

        $categoryMap = [
            'civil' => 'aslaw-civil',
            'corporate' => 'aslaw-corporate',
            'criminal' => 'aslaw-criminal',
            'general' => 'aslaw-general',
        ];

        return $categoryMap[$expectedCategory] ?? $defaultModel;
    }

    /**
     * Case-insensitive contains helper for simple output checks.
     */
    private function containsAny(string $text, array $needles): bool
    {
        if ($text === '') {
            return false;
        }

        return Str::contains(Str::lower($text), array_map(static fn (string $s) => Str::lower($s), $needles));
    }
}
