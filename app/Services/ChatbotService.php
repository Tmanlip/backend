<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use MongoDB\Client;
use MongoDB\Collection;
use RuntimeException;
use Throwable;

class ChatbotService
{
    private const DEFAULT_COLLECTION = 'chatbot-question-answer';
    private const CLASSIFIER_MODEL = 'phi3:mini';

    private const VALID_CATEGORIES = ['civil', 'corporate', 'criminal', 'general'];

    private const CRIMINAL_PATTERNS = [
        '/\bcriminal\b/i',
        '/\boffence\b/i',
        '/\boffense\b/i',
        '/\bpenalty\b/i',
        '/\bpunishment\b/i',
        '/\bcharge\b/i',
        '/\barrest\b/i',
        '/\bpolice\b/i',
        '/\bprosecution\b/i',
        '/\bcourt\b/i',
        '/\bbail\b/i',
        '/\bremand\b/i',
        '/\bconvict\w*\b/i',
        '/\bsentence\w*\b/i',
        '/\bmurder\b/i',
        '/\bhomicide\b/i',
        '/\bmanslaughter\b/i',
        '/\bkill\w*\b/i',
        '/\bslay\w*\b/i',
        '/\bstab\w*\b/i',
        '/\bshot\b/i',
        '/\bshoot\w*\b/i',
        '/\bself[-\s]?defen[cs]e\b/i',
        '/\bbreak\s+into\b/i',
        '/\bintrud\w*\b/i',
        '/\btrespass\w*\b/i',
        '/\bassault\b/i',
        '/\btheft\b/i',
        '/\bsteal\b/i',
        '/\brobbery\b/i',
        '/\bburglary\b/i',
        '/\bfraud\b/i',
        '/\bforgery\b/i',
        '/\bextortion\b/i',
        '/\bkidnap\b/i',
        '/\bbribe\b/i',
        '/\bcorruption\b/i',
        '/\bdrug\w*\b/i',
        '/\bnarcotic\w*\b/i',
        '/\btraffick\w*\b/i',
        '/\bfirearm\w*\b/i',
        '/\bweapon\w*\b/i',
        '/\bkanun\s+keseksaan\b/i',
        '/\bkanun\s+tatacara\s+jenayah\b/i',
        '/\bjenayah\b/i',
        '/\bpolis\b/i',
        '/\btangkapan\b/i',
        '/\bdadah\b/i',
        '/\brompak\b/i',
        '/\bcuri\b/i',
        '/\brasuah\b/i',
    ];

    private const CIVIL_PATTERNS = [
        '/\bcivil\b/i',
        '/\binjunction\b/i',
        '/\brestraining\s+order\b/i',
        '/\bformer\s+employee\b/i',
        '/\bemployment\b/i',
        '/\bcontract\b/i',
        '/\bbreach\s+of\s+contract\b/i',
        '/\bdamages\b/i',
        '/\bdebt\b/i',
        '/\bspecific\s+performance\b/i',
        '/\blawful\s+notice\b/i',
        '/\bunlawful\s+termination\b/i',
        '/\bwrongful\s+dismissal\b/i',
    ];

    private const CORPORATE_PATTERNS = [
        '/\bcorporate\b/i',
        '/\bcompany\b/i',
        '/\bboard\b/i',
        '/\bdirector\b/i',
        '/\bshareholder\b/i',
        '/\bshareholders\b/i',
        '/\bcorporation\b/i',
        '/\bbusiness\b/i',
        '/\bpartnership\b/i',
        '/\bmerger\b/i',
        '/\bacquisition\b/i',
        '/\bwinding\s+up\b/i',
        '/\binsolvency\b/i',
        '/\blocal\s+company\b/i',
    ];

    public function health(): array
    {
        return [
            'mongodb' => $this->checkMongoHealth(),
            'ollama' => $this->checkOllamaHealth(),
        ];
    }

    public function saveChat(array $chatData, ?string $firmID = null): string
    {
        if (empty($chatData['question']) || empty($chatData['answers']) || empty($chatData['model'])) {
            throw new RuntimeException('Chat must have question, answers, and model fields.');
        }

        $now = $this->now();
        $result = $this->collectionForFirm($firmID)->insertOne([
            'question' => (string) $chatData['question'],
            'answers' => (string) $chatData['answers'],
            'model' => (string) $chatData['model'],
            'category' => isset($chatData['category']) ? (string) $chatData['category'] : null,
            'firmID' => $firmID ? (string) $firmID : null,
            'createdAt' => $chatData['createdAt'] ?? $now,
            'updatedAt' => $chatData['updatedAt'] ?? $now,
        ]);

        return (string) $result->getInsertedId();
    }

    public function listChats(array $filters = [], ?string $firmID = null, int $limit = 10): array
    {
        $query = [];

        if (!empty($filters['model'])) {
            $query['model'] = (string) $filters['model'];
        }

        if (!empty($filters['category'])) {
            $query['category'] = (string) $filters['category'];
        }

        $cursor = $this->collectionForFirm($firmID)->find($query, [
            'limit' => max(1, $limit),
        ]);

        $chats = [];
        foreach ($cursor as $document) {
            $chats[] = $this->normalizeDocument($document);
        }

        return [
            'count' => count($chats),
            'chats' => $chats,
        ];
    }

    public function ask(string $question, ?string $firmID = null, ?string $categoryHint = null): array
    {
        // Suspend PHP's max_execution_time for the duration of this method.
        // Ollama LLM inference can legitimately take >60 s on CPU-only machines.
        // Guzzle's own per-request timeout (CHATBOT_GENERATION_TIMEOUT_MS) is the
        // real guard — without this, PHP kills the process before Guzzle can throw
        // a catchable exception and the fallback answer is never returned.
        set_time_limit(0);

        $question = trim($question);
        $validatedCategoryHint = is_string($categoryHint) && in_array($categoryHint, self::VALID_CATEGORIES, true)
            ? $categoryHint
            : null;

        if ($question === '') {
            throw new RuntimeException('No question provided.');
        }

        // Detect and correct typos
        $typoCorrections = $this->detectTypoCorrection($question);
        $correctedQuestion = $question;
        $typoNote = '';
        if ($typoCorrections !== []) {
            foreach ($typoCorrections as $typo => $correction) {
                $correctedQuestion = preg_replace('/\b' . preg_quote($typo) . '\b/i', $correction, $correctedQuestion);
            }
            $correctedWords = implode(', ', array_map(fn($t, $c) => "$t → $c", array_keys($typoCorrections), $typoCorrections));
            $typoNote = "\n[Detected typo(s): $correctedWords. Interpreting as: \"$correctedQuestion\"]";
        }

        // Use corrected question for processing
        $processingQuestion = $correctedQuestion !== '' ? $correctedQuestion : $question;

        if ($this->isGreetingOnly($processingQuestion)) {
            $responseData = [
                'answer' => 'Hello. I can help with Malaysian legal information. Please share your legal question and, if possible, include key facts such as location, dates, and documents.' . $typoNote,
                'category' => 'general',
                'model' => 'aslaw-general',
            ];

            try {
                $responseData['chatId'] = $this->saveChat([
                    'question' => $question,
                    'answers' => $responseData['answer'],
                    'model' => $responseData['model'],
                    'category' => $responseData['category'],
                    'createdAt' => $this->now(),
                    'updatedAt' => $this->now(),
                ], $firmID);
                $responseData['saved'] = true;
            } catch (Throwable $saveError) {
                $responseData['saved'] = false;
                $responseData['saveError'] = $saveError->getMessage();
            }

            return $responseData;
        }

        $quotationCategory = $this->resolveQuotationCategory($processingQuestion, $validatedCategoryHint);
        if ($quotationCategory !== null) {
            $category = $quotationCategory;
            $activeModel = $this->routeModel($category);
            $resolvedAnswer = $this->buildQuotationAnswer($category) . $typoNote;

            $responseData = [
                'answer' => $resolvedAnswer,
                'category' => $category,
                'model' => $activeModel,
                'degraded' => false,
            ];

            try {
                $responseData['chatId'] = $this->saveChat([
                    'question' => $question,
                    'answers' => $resolvedAnswer,
                    'model' => $activeModel,
                    'category' => $category,
                    'createdAt' => $this->now(),
                    'updatedAt' => $this->now(),
                ], $firmID);
                $responseData['saved'] = true;
            } catch (Throwable $saveError) {
                $responseData['saved'] = false;
                $responseData['saveError'] = $saveError->getMessage();
            }

            return $responseData;
        }

        $contactHandoffCategory = $this->resolveContactHandoffCategory($processingQuestion, $validatedCategoryHint);
        if ($contactHandoffCategory !== null) {
            $category = $contactHandoffCategory;
            $activeModel = $this->routeModel($category);
            $resolvedAnswer = $this->buildBasicAnswer($category) . $typoNote;

            $responseData = [
                'answer' => $resolvedAnswer,
                'category' => $category,
                'model' => $activeModel,
                'degraded' => false,
            ];

            try {
                $responseData['chatId'] = $this->saveChat([
                    'question' => $question,
                    'answers' => $resolvedAnswer,
                    'model' => $activeModel,
                    'category' => $category,
                    'createdAt' => $this->now(),
                    'updatedAt' => $this->now(),
                ], $firmID);
                $responseData['saved'] = true;
            } catch (Throwable $saveError) {
                $responseData['saved'] = false;
                $responseData['saveError'] = $saveError->getMessage();
            }

            return $responseData;
        }

        $category = $this->resolveCategory($processingQuestion);
        $model = $this->routeModel($category);
        $unlimitedPredictEnabled = filter_var(env('CHATBOT_UNLIMITED_NUM_PREDICT', true), FILTER_VALIDATE_BOOLEAN);
        $defaultNumPredict = $this->parseNumPredict(env('CHATBOT_DEFAULT_NUM_PREDICT', '-1'), -1);
        $civilNumPredict = $this->parseNumPredict(env('CHATBOT_CIVIL_NUM_PREDICT', (string) $defaultNumPredict), $defaultNumPredict);
        $corporateNumPredict = $this->parseNumPredict(env('CHATBOT_CORPORATE_NUM_PREDICT', (string) $defaultNumPredict), $defaultNumPredict);
        $generalNumPredict = $this->parseNumPredict(env('CHATBOT_GENERAL_NUM_PREDICT', (string) $defaultNumPredict), $defaultNumPredict);
        $criminalNumPredict = $this->parseNumPredict(env('CHATBOT_CRIMINAL_NUM_PREDICT', (string) $defaultNumPredict), $defaultNumPredict);
        $generationTimeoutMs = (int) env('CHATBOT_GENERATION_TIMEOUT_MS', 60000);

        $systemPrompt = $this->buildPrompt($category, $processingQuestion);
        $conversation = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $processingQuestion],
        ];

        $numPredictByCategory = [
            'civil' => $civilNumPredict,
            'corporate' => $corporateNumPredict,
            'criminal' => $criminalNumPredict,
            'general' => $generalNumPredict,
        ];
        $numPredict = $unlimitedPredictEnabled ? -1 : ($numPredictByCategory[$category] ?? $defaultNumPredict);
        $activeModel = $model;
        $finalAnswer = '';
        $usedGenerationTimeoutFallback = false;

        try {
            $aslawResponse = $this->requestOllamaChat([
                'model' => $activeModel,
                'messages' => $conversation,
                'options' => $this->buildGenerationOptions(0.1, $numPredict),
            ], $generationTimeoutMs, 'Final response generation');

            $finalAnswer = (string) data_get($aslawResponse, 'message.content', '');

            $finalAnswer = $finalAnswer . $typoNote;
            if ($this->looksTruncatedAnswer($finalAnswer, $aslawResponse)) {
                try {
                    $continuationResponse = $this->requestOllamaChat([
                        'model' => $activeModel,
                        'messages' => [
                            ...$conversation,
                            ['role' => 'assistant', 'content' => $finalAnswer],
                            [
                                'role' => 'user',
                                'content' => 'Continue from where you stopped. Do not repeat earlier points. Finish the answer in at most 2 concise bullets.',
                            ],
                        ],
                        'options' => $this->buildGenerationOptions(
                            0.1,
                            $numPredict === -1 ? -1 : max(80, (int) floor($numPredict * 0.75))
                        ),
                    ], $generationTimeoutMs, 'Continuation generation');

                    $continuationText = trim((string) data_get($continuationResponse, 'message.content', ''));
                    if ($continuationText !== '') {
                        $finalAnswer = rtrim(trim($finalAnswer)) . PHP_EOL . $continuationText;
                    }
                } catch (Throwable $continuationError) {
                    if (! $this->isTimeoutLikeError($continuationError)) {
                        throw $continuationError;
                    }
                }
            }
        } catch (Throwable $generationError) {
            if ($this->isTimeoutLikeError($generationError)) {
                $usedGenerationTimeoutFallback = true;
                $finalAnswer = $this->buildBasicAnswer($category) . $typoNote;
            } else {
                throw $generationError;
            }
        }

        $resolvedAnswer = $finalAnswer !== '' ? $finalAnswer : ($this->buildBasicAnswer($category) . $typoNote);

        $responseData = [
            'answer' => $resolvedAnswer,
            'category' => $category,
            'model' => $activeModel,
            'degraded' => $usedGenerationTimeoutFallback,
        ];

        try {
            $responseData['chatId'] = $this->saveChat([
                'question' => $question,
                'answers' => $resolvedAnswer,
                'model' => $activeModel,
                'category' => $category,
                'createdAt' => $this->now(),
                'updatedAt' => $this->now(),
            ], $firmID);
            $responseData['saved'] = true;
        } catch (Throwable $saveError) {
            $responseData['saved'] = false;
            $responseData['saveError'] = $saveError->getMessage();
        }

        return $responseData;
    }

    private function checkMongoHealth(): array
    {
        $uri = $this->mongoUri();

        if ($uri === null) {
            return [
                'configured' => false,
                'connected' => false,
                'error' => 'MongoDB URI is not configured.',
            ];
        }

        try {
            $client = new Client($uri);
            $client->selectDatabase($this->mongoDatabaseName())->command(['ping' => 1]);

            return [
                'configured' => true,
                'connected' => true,
                'error' => null,
            ];
        } catch (Throwable $error) {
            return [
                'configured' => true,
                'connected' => false,
                'error' => $error->getMessage(),
            ];
        }
    }

    private function checkOllamaHealth(): array
    {
        try {
            Http::timeout(5)->get($this->ollamaBaseUrl() . '/api/tags')->throw();

            return [
                'configured' => true,
                'connected' => true,
                'error' => null,
            ];
        } catch (Throwable $error) {
            return [
                'configured' => true,
                'connected' => false,
                'error' => $error->getMessage(),
            ];
        }
    }

    private function requestOllamaChat(array $payload, int $timeoutMs, string $label): array
    {
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
        $url = $this->ollamaBaseUrl() . '/api/chat';
        if (!array_key_exists('stream', $payload)) {
            $payload['stream'] = false;
        }

        try {
            $response = Http::timeout($timeoutSeconds)->post($url, $payload);
            $response->throw();
        } catch (ConnectionException $error) {
            if ($this->isTimeoutLikeError($error)) {
                throw new RuntimeException($label . ' timed out after ' . $timeoutMs . 'ms', previous: $error);
            }

            throw new RuntimeException('Ollama connection failed', previous: $error);
        } catch (Throwable $error) {
            throw new RuntimeException('Ollama connection failed', previous: $error);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function buildBasicAnswer(string $category): string
    {
        $bookingContact = $this->primaryBookingContact($category);
        $formattedContact = $bookingContact !== '' ? $this->formatBookingContact($bookingContact) : '';

        if ($category === 'civil') {
            $answer = [
                'Basic civil steps:',
                '1. Gather your contract, receipts, and messages.',
                '2. Send a short written demand first.',
                '3. Keep copies of all replies.',
                '4. Speak to a Malaysian civil lawyer if needed.',
            ];

            if ($formattedContact !== '') {
                $answer[] = '';
                $answer[] = 'Contact:';
                $answer[] = $formattedContact;
            }

            return implode(PHP_EOL, $answer);
        }

        if ($category === 'corporate') {
            $answer = [
                'Basic corporate steps:',
                '1. Gather company documents and emails.',
                '2. Check board resolutions and contracts.',
                '3. Write down the timeline of events.',
                '4. Get a Malaysian corporate lawyer to review it.',
            ];

            if ($formattedContact !== '') {
                $answer[] = '';
                $answer[] = 'Contact:';
                $answer[] = $formattedContact;
            }

            return implode(PHP_EOL, $answer);
        }

        if ($category === 'criminal') {
            $answer = [
                'Basic criminal steps:',
                '1. If anyone is in danger, call 999 or 112 now.',
                '2. Keep the scene and evidence safe.',
                '3. Make a police report as soon as possible.',
                '4. Contact a criminal lawyer quickly.',
            ];

            if ($formattedContact !== '') {
                $answer[] = '';
                $answer[] = 'Contact:';
                $answer[] = $formattedContact;
            }

            return implode(PHP_EOL, $answer);
        }

        $answer = [
            'Basic general steps:',
            '1. Share the key facts and documents.',
            '2. Explain the outcome you want.',
            '3. Ask again with a shorter question if needed.',
        ];

        if ($formattedContact !== '') {
            $answer[] = '';
            $answer[] = 'Contact:';
            $answer[] = $formattedContact;
        }

        return implode(PHP_EOL, $answer);
    }

    private function formatBookingContact(string $rawContact): string
    {
        $parts = array_map(static fn (string $part): string => trim($part), explode('|', $rawContact));

        $contactType = '';
        $name = '';
        $phone = '';
        $whatsapp = '';
        $email = '';

        foreach ($parts as $part) {
            if (str_contains($part, 'Booking Contact Name:')) {
                $name = trim(str_replace('Booking Contact Name:', '', str_replace(['[', ']'], '', $part)));
            } elseif (str_contains($part, 'Phone:')) {
                $phone = trim(str_replace('Phone:', '', str_replace(['[', ']'], '', $part)));
            } elseif (str_contains($part, 'WhatsApp:')) {
                $whatsapp = trim(str_replace('WhatsApp:', '', str_replace(['[', ']'], '', $part)));
            } elseif (str_contains($part, 'Email:')) {
                $email = trim(str_replace('Email:', '', str_replace(['[', ']'], '', $part)));
            } elseif (str_contains($part, 'Contact') && $contactType === '') {
                $contactType = $part;
            }
        }

        $lines = [];

        if ($name !== '') {
            $lines[] = $contactType !== '' ? "{$name} ({$contactType})" : $name;
        }

        if ($phone !== '' && $phone !== 'N/A') {
            $lines[] = "Phone: {$phone}";
        }

        if ($whatsapp !== '' && $whatsapp !== 'N/A') {
            $lines[] = "WhatsApp: {$whatsapp}";
        }

        if ($email !== '' && $email !== 'N/A') {
            $lines[] = "Email: {$email}";
        }

        return implode(PHP_EOL, $lines);
    }

    private function buildPrompt(string $category, string $userInput): string
    {
        $bookingContacts = $this->bookingContactsForCategory($category);

        return <<<PROMPT
You are ASLAW, a Malaysian {$category} law assistant.

Jurisdiction:
- Federal law of Malaysia
- Selangor
- Kuala Lumpur
- Putrajaya

Rules:
- Provide general legal information only
- Mention relevant Malaysian Acts where applicable
- Do NOT give legal advice
- Keep the answer short and practical
- Use at most 4 bullet points
- Only share the booking contact if the user explicitly asks to speak to, contact, or find a lawyer (e.g. "give me the contact", "I need a lawyer", "how do I reach you"). For general legal information questions, do NOT include the booking contact
- Do not invent, change, or omit the lawyer contact details
- If the user describes immediate danger, serious injury, or witnessing a violent crime:
    - Prioritize safety first
    - Tell them to call Malaysia emergency services at 999 (or 112 from mobile)
    - Encourage preserving evidence and prompt police reporting
    - Do not invent specific station names or uncertain phone numbers

Playbook booking contacts:
{$bookingContacts}

User question:
"{$userInput}"
PROMPT;
    }

    private function bookingContactsForCategory(string $category): string
    {
        $contacts = $this->loadBookingContacts($category);

        if ($contacts === []) {
            return '- No booking contact is configured in the playbook.';
        }

        return implode(PHP_EOL, array_map(
            static fn (string $contact): string => '- ' . $contact,
            $contacts
        ));
    }

    private function primaryBookingContact(string $category): string
    {
        $contacts = $this->loadBookingContacts($category);

        return $contacts[0] ?? '';
    }

    private function loadBookingContacts(string $category): array
    {
        $csvPath = $this->playbookCsvPath($category);

        if ($csvPath === null || ! is_file($csvPath) || ! is_readable($csvPath)) {
            return [];
        }

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            return [];
        }

        $contacts = [];

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return [];
            }

            while (($row = fgetcsv($handle)) !== false) {
                $practiceArea = trim((string) ($row[0] ?? ''));
                $section = strtolower(trim((string) ($row[1] ?? '')));

                if ($practiceArea !== ucfirst($category) || ! str_contains($section, 'booking contacts')) {
                    continue;
                }

                $parts = [];
                foreach ([3, 4, 5, 6] as $index) {
                    $value = trim((string) ($row[$index] ?? ''));

                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }

                if ($parts !== []) {
                    $contacts[] = implode(' | ', $parts);
                }
            }
        } finally {
            fclose($handle);
        }

        return $contacts;
    }

    private function playbookCsvPath(string $category): ?string
    {
        $basePath = $this->playbookBasePath();

        if ($basePath === null) {
            return null;
        }

        $fileName = match ($category) {
            'civil' => 'Civil_Fee_Structure.csv',
            'corporate' => 'Corporate_Fee_Structure.csv',
            'criminal' => 'Criminal_Fee_Structure.csv',
            default => null,
        };

        if ($fileName === null) {
            return null;
        }

        return $basePath . DIRECTORY_SEPARATOR . $fileName;
    }

    private function playbookBasePath(): ?string
    {
        $configuredPath = trim((string) env('CHATBOT_PLAYBOOK_PATH', 'storage/app/chatbot/operations-playbook-excel'));

        if ($configuredPath === '') {
            return null;
        }

        if (preg_match('/^(?:[A-Za-z]:\\\\|\\\\|\/)/', $configuredPath) === 1) {
            return rtrim($configuredPath, '\\/');
        }

        return rtrim(base_path($configuredPath), '\\/');
    }

    private function buildGenerationOptions(float $temperature, int $numPredict): array
    {
        $options = ['temperature' => $temperature];

        if ($numPredict > 0) {
            $options['num_predict'] = $numPredict;
        }

        return $options;
    }

    private function parseNumPredict(mixed $value, int $fallback = -1): int
    {
        $raw = strtolower(trim((string) $value));

        if ($raw === '') {
            return $fallback;
        }

        if ($raw === '-1' || $raw === 'unlimited' || $raw === 'infinite') {
            return -1;
        }

        $parsed = (int) $raw;

        return $parsed > 0 ? $parsed : $fallback;
    }

    private function resolveCategory(string $text): string
    {
        $category = 'general';
        $keywordSuggestsCriminal = $this->hasCriminalKeywords($text);

        if ($this->classifierBypassOnCriminalKeywords() && $keywordSuggestsCriminal) {
            $category = 'criminal';
        }

        if ($this->classifierEnabled() && !($this->classifierBypassOnCriminalKeywords() && $keywordSuggestsCriminal)) {
            try {
                $classifierResponse = $this->requestOllamaChat([
                    'model' => self::CLASSIFIER_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->classifierPrompt()],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'options' => [
                        'temperature' => 0,
                        'num_predict' => 50,
                    ],
                ], (int) env('CHATBOT_CLASSIFIER_TIMEOUT_MS', 12000), 'Classifier generation');

                $rawOutput = (string) data_get($classifierResponse, 'message.content', '');
                if (preg_match('/\{[\s\S]*?\}/', $rawOutput, $matches)) {
                    $parsed = json_decode($matches[0], true);
                    if (is_array($parsed) && in_array($parsed['category'] ?? null, self::VALID_CATEGORIES, true)) {
                        $category = $parsed['category'];
                    }
                }
            } catch (Throwable $error) {
                // Fall back to keyword and default routing.
            }
        }

        if ($this->civilKeywordOverrideEnabled() && $this->hasCivilKeywords($text) && $category !== 'civil') {
            $category = 'civil';
        }

        if ($this->corporateKeywordOverrideEnabled() && $this->hasCorporateKeywords($text) && $category !== 'corporate') {
            $category = 'corporate';
        }

        if ($this->criminalKeywordOverrideEnabled() && $keywordSuggestsCriminal && $category !== 'criminal') {
            $category = 'criminal';
        }

        return $category;
    }

    private function routeModel(string $category): string
    {
        return match ($category) {
            'civil' => 'aslaw-civil',
            'corporate' => 'aslaw-corporate',
            'criminal' => 'aslaw-criminal',
            default => 'aslaw-general',
        };
    }

    private function classifierPrompt(): string
    {
        return <<<'PROMPT'
You are a Malaysian legal domain classifier AI.

You MUST respond in valid JSON format ONLY.

Example:
{"category":"civil"}

Valid values:
- civil
- corporate
- criminal
- general

Rules:
- No explanation
- No markdown
- No additional text
- Only JSON
PROMPT;
    }

    private function isGreetingOnly(string $text): bool
    {
        $normalized = strtolower(trim($text));

        return (bool) preg_match('/^(hi|hello|hey|salam|hai|good morning|good afternoon|good evening)$/', $normalized);
    }

    private function resolveQuotationCategory(string $text, ?string $categoryHint = null): ?string
    {
        if (! $this->hasQuotationIntent($text)) {
            return null;
        }

        if ($this->hasCivilKeywords($text)) {
            return 'civil';
        }

        if ($this->hasCorporateKeywords($text)) {
            return 'corporate';
        }

        if ($this->hasCriminalKeywords($text)) {
            return 'criminal';
        }

        if ($categoryHint !== null && in_array($categoryHint, self::VALID_CATEGORIES, true)) {
            return $categoryHint;
        }

        return null;
    }

    private function hasQuotationIntent(string $text): bool
    {
        return (bool) preg_match(
            '/\b(price|pricing|cost|costs|fee|fees|charge|charges|rate|rates|quotation|quote|how\s+much|what\s+is\s+the\s+fee|what\s+does\s+it\s+cost|what\s+is\s+the\s+cost|what\s+is\s+the\s+price|how\s+much\s+does\s+it|estimate|estimation)\b/i',
            $text
        );
    }

    private function buildQuotationAnswer(string $category): string
    {
        $items = $this->loadFeeItems($category);
        $bookingContact = $this->primaryBookingContact($category);
        $formattedContact = $bookingContact !== '' ? $this->formatBookingContact($bookingContact) : '';
        $label = ucfirst($category);

        if ($items === []) {
            $lines = [
                "Here is a general overview of {$label} Law fees:",
                '',
                'Fees vary depending on the complexity of your matter.',
                'Please contact our lawyer for a detailed quotation.',
            ];

            if ($formattedContact !== '') {
                $lines[] = '';
                $lines[] = 'Contact:';
                $lines[] = $formattedContact;
            }

            return implode(PHP_EOL, $lines);
        }

        $lines = ["Here is an overview of {$label} Law estimated fees (RM):", ''];

        foreach ($items as $sectionLabel => $sectionItems) {
            $lines[] = $sectionLabel . ':';

            foreach ($sectionItems as $item) {
                $fee = $item['fee'];
                $fee = preg_replace('/^(\d)/', 'RM $1', $fee) ?? $fee;
                $lines[] = '  • ' . $item['type'] . ': ' . $fee;
            }

            $lines[] = '';
        }

        $lines[] = 'Fees vary based on complexity and scope. Contact us for a personalised quotation.';

        if ($formattedContact !== '') {
            $lines[] = '';
            $lines[] = 'Contact:';
            $lines[] = $formattedContact;
        }

        return implode(PHP_EOL, $lines);
    }

    private function loadFeeItems(string $category): array
    {
        $csvPath = $this->playbookCsvPath($category);

        if ($csvPath === null || ! is_file($csvPath) || ! is_readable($csvPath)) {
            return [];
        }

        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            return [];
        }

        $sections = [];
        $sectionCounters = [];
        $maxPerSection = 4;

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return [];
            }

            while (($row = fgetcsv($handle)) !== false) {
                $practiceArea = trim((string) ($row[0] ?? ''));
                $section = trim((string) ($row[1] ?? ''));

                if ($practiceArea !== ucfirst($category)) {
                    continue;
                }

                if (str_contains(strtolower($section), 'booking contacts')) {
                    continue;
                }

                $type = trim((string) ($row[3] ?? ''));
                $fee  = trim((string) ($row[5] ?? ''));

                if ($type === '' || $fee === '') {
                    continue;
                }

                $sectionLabel = preg_replace('/^[A-Z]\.\s*/', '', $section) ?? $section;

                if (! isset($sectionCounters[$sectionLabel])) {
                    $sectionCounters[$sectionLabel] = 0;
                }

                if ($sectionCounters[$sectionLabel] >= $maxPerSection) {
                    continue;
                }

                $sections[$sectionLabel][] = ['type' => $type, 'fee' => $fee];
                $sectionCounters[$sectionLabel]++;
            }
        } finally {
            fclose($handle);
        }

        return $sections;
    }

    private function resolveContactHandoffCategory(string $text, ?string $categoryHint = null): ?string
    {
        if (! $this->hasContactHandoffIntent($text)) {
            return null;
        }

        if ($this->hasCivilKeywords($text)) {
            return 'civil';
        }

        if ($this->hasCorporateKeywords($text)) {
            return 'corporate';
        }

        if ($this->hasCriminalKeywords($text)) {
            return 'criminal';
        }

        if ($categoryHint !== null && in_array($categoryHint, self::VALID_CATEGORIES, true)) {
            return $categoryHint;
        }

        return null;
    }

    private function hasContactHandoffIntent(string $text): bool
    {
        return (bool) preg_match(
            '/\b(contact|contact\s+details|phone|whatsapp|email|lawyer|attorney|who\s+should\s+i\s+contact|can\s+you\s+give\s+me\s+the\s+contact|share\s+the\s+contact|give\s+me\s+the\s+contact|need\s+a\s+lawyer|need\s+.*\blawyer\b|speak\s+to\s+a\s+lawyer|talk\s+to\s+a\s+lawyer|connect\s+me\s+to\s+a\s+lawyer)\b/i',
            $text
        );
    }

    private function hasCriminalKeywords(string $text): bool
    {
        return $this->matchesAny(self::CRIMINAL_PATTERNS, $text);
    }

    private function detectTypoCorrection(string $text): array
    {
        $dictionary = [
            // Legal terms
            'police', 'court', 'lawyer', 'attorney', 'judge', 'law', 'contract', 'agreement',
            'breach', 'fraud', 'theft', 'crime', 'criminal', 'civil', 'corporate', 'business',
            'employee', 'employer', 'company', 'firm', 'corporation', 'liability', 'damages',
            'witness', 'evidence', 'trial', 'lawsuit', 'claim', 'defendant', 'plaintiff',
            'bankruptcy', 'debt', 'property', 'inheritance', 'will', 'divorce', 'custody',
            'fee', 'cost', 'penalty', 'fine', 'settlement', 'conviction', 'appeal',
            'contact', 'whatsapp', 'phone', 'email', 'address', 'meeting', 'appointment',
            'question', 'answer', 'help', 'advice', 'legal', 'right', 'duty', 'obligation',
            'report', 'rights', 'malaysia', 'selangor', 'kuala', 'lumpur',
        ];

        $words = preg_split('/\s+/', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $corrections = [];
        $maxThreshold = 2; // Allow up to 2 character differences

        foreach ($words as $word) {
            // Strip punctuation from word for comparison
            $cleanWord = preg_replace('/[^a-z0-9]/', '', $word);
            if (strlen($cleanWord) < 6) continue; // Only check longer words to avoid false positives
            
            // Skip words that are already valid dictionary words
            if (in_array($cleanWord, $dictionary, true)) continue;

            $closest = null;
            $minDistance = PHP_INT_MAX;

            foreach ($dictionary as $dictWord) {
                // Only match against similar-length dictionary words
                $lenDiff = abs(strlen($cleanWord) - strlen($dictWord));
                if ($lenDiff > 2) continue; // Skip if length differs by more than 2
                
                $distance = levenshtein($cleanWord, $dictWord);
                if ($distance > 0 && $distance < $minDistance && $distance <= $maxThreshold) {
                    $minDistance = $distance;
                    $closest = $dictWord;
                }
            }

            if ($closest !== null) {
                $corrections[$cleanWord] = $closest;
            }
        }

        return $corrections;
    }

    private function hasCivilKeywords(string $text): bool
    {
        return $this->matchesAny(self::CIVIL_PATTERNS, $text);
    }

    private function hasCorporateKeywords(string $text): bool
    {
        return $this->matchesAny(self::CORPORATE_PATTERNS, $text);
    }

    private function matchesAny(array $patterns, string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksTruncatedAnswer(string $answer, array $rawResponse): bool
    {
        $text = trim($answer);

        if ($text === '') {
            return true;
        }

        $doneReason = strtolower((string) data_get($rawResponse, 'done_reason', ''));
        if ($doneReason === 'length') {
            return true;
        }

        $lastLine = trim((string) last(explode("\n", $text)));
        $likelyCutOffTail = '/(and|or|because|if|that|which|where|when|with|under|regarding|remember\s+that)$/i';

        return preg_match($likelyCutOffTail, $lastLine) === 1;
    }

    private function isTimeoutLikeError(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'headers timeout')
            || str_contains($message, 'curl error 28');
    }

    private function normalizeDocument(mixed $document): array
    {
        $record = is_object($document) ? (array) $document : (array) $document;

        return [
            'chatId' => isset($record['_id']) ? (string) $record['_id'] : null,
            'question' => isset($record['question']) ? (string) $record['question'] : '',
            'answers' => isset($record['answers']) ? (string) $record['answers'] : '',
            'category' => isset($record['category']) ? (string) $record['category'] : null,
            'model' => isset($record['model']) ? (string) $record['model'] : null,
            'firmID' => isset($record['firmID']) ? (string) $record['firmID'] : null,
            'createdAt' => $this->formatDateTime($record['createdAt'] ?? null),
            'updatedAt' => $this->formatDateTime($record['updatedAt'] ?? null),
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'toDateTime')) {
            $dateTime = $value->toDateTime();

            if ($dateTime instanceof \DateTimeInterface) {
                return $dateTime->format(DATE_ATOM);
            }
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (new \DateTimeImmutable($value))->format(DATE_ATOM);
            } catch (Throwable) {
                return $value;
            }
        }

        return null;
    }

    private function collectionForFirm(?string $firmID = null): Collection
    {
        return $this->mongoClient()->selectDatabase($this->mongoDatabaseName())
            ->selectCollection($this->collectionName($firmID));
    }

    private function mongoClient(): Client
    {
        $uri = $this->mongoUri();

        if ($uri === null) {
            throw new RuntimeException('MongoDB is not configured.');
        }

        return new Client($uri);
    }

    private function mongoUri(): ?string
    {
        $uri = trim((string) env('MONGODB_URI', env('MONGO_URI', '')));

        return $uri !== '' ? $uri : null;
    }

    private function mongoDatabaseName(): string
    {
        return (string) env('MONGODB_DATABASE', env('MONGODB_DB', 'aslaw'));
    }

    private function collectionName(?string $firmID = null): string
    {
        $safeFirmID = $this->sanitizeFirmId($firmID);

        return $safeFirmID !== null ? 'chat_' . $safeFirmID : self::DEFAULT_COLLECTION;
    }

    private function sanitizeFirmId(?string $firmID): ?string
    {
        $raw = trim((string) $firmID);

        if ($raw === '') {
            return null;
        }

        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $raw);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function classifierEnabled(): bool
    {
        return filter_var(env('CHATBOT_CLASSIFIER_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function classifierBypassOnCriminalKeywords(): bool
    {
        return filter_var(env('CHATBOT_CLASSIFIER_BYPASS_ON_CRIMINAL_KEYWORDS', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function criminalKeywordOverrideEnabled(): bool
    {
        return filter_var(env('CHATBOT_CRIMINAL_KEYWORD_OVERRIDE', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function civilKeywordOverrideEnabled(): bool
    {
        return filter_var(env('CHATBOT_CIVIL_KEYWORD_OVERRIDE', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function corporateKeywordOverrideEnabled(): bool
    {
        return filter_var(env('CHATBOT_CORPORATE_KEYWORD_OVERRIDE', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function ollamaBaseUrl(): string
    {
        return rtrim((string) env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'), '/');
    }
}
