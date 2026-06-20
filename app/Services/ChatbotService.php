<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChatbotService
{
    /**
        * Ask ASALAW chatbot and return answer + routing metadata.
     */
    public function ask(string $question, ?string $categoryHint = null, ?string $languageHint = null): array
    {
        $category = $this->resolveCategory($question, $categoryHint);
        $model = $this->resolveModel($category);
        $language = $this->resolveResponseLanguage($question, $languageHint);

        if ($this->isFeeOrContactIntent($question)) {
            return [
                'answer' => $this->buildFeeContactReferenceResponse($category, $language),
                'category' => $category,
                'model' => $model,
            ];
        }

        if ($this->isGreetingIntent($question)) {
            $greeting = $language === 'malay'
                ? 'Hai. Saya ASALAW chatbot. Sila tanya soalan undang-undang anda. Jika boleh, pilih domain (civil/corporate/criminal) untuk routing yang lebih tepat.'
                : 'Hello. I am ASALAW chatbot. Please ask your legal question and, if possible, choose a domain (civil/corporate/criminal) for faster routing.';

            return [
                'answer' => $greeting,
                'category' => $category,
                'model' => $model,
            ];
        }

        $generation = $this->generateWithModelFallback($model, $question, $language, $category);

        return [
            'answer' => $generation['answer'],
            'category' => $category,
            'model' => $generation['model'],
        ];
    }

    /**
     * Stream chatbot answer chunks and return final answer + metadata.
     *
     * @param callable(string):void $onChunk
     */
    public function askStream(string $question, ?string $categoryHint, ?string $languageHint, callable $onChunk): array
    {
        $category = $this->resolveCategory($question, $categoryHint);
        $model = $this->resolveModel($category);
        $language = $this->resolveResponseLanguage($question, $languageHint);

        if ($this->isFeeOrContactIntent($question)) {
            $answer = $this->buildFeeContactReferenceResponse($category, $language);
            $onChunk($answer);

            return [
                'answer' => $answer,
                'category' => $category,
                'model' => $model,
            ];
        }

        if ($this->isGreetingIntent($question)) {
            $answer = $language === 'malay'
                ? 'Hai. Saya ASALAW chatbot. Sila tanya soalan undang-undang anda. Jika boleh, pilih domain (civil/corporate/criminal) untuk routing yang lebih tepat.'
                : 'Hello. I am ASALAW chatbot. Please ask your legal question and, if possible, choose a domain (civil/corporate/criminal) for faster routing.';
            $onChunk($answer);

            return [
                'answer' => $answer,
                'category' => $category,
                'model' => $model,
            ];
        }

        $answer = $this->streamWithModel($model, $question, $language, $category, $onChunk);

        return [
            'answer' => $answer,
            'category' => $category,
            'model' => $model,
        ];
    }

    private function generateWithModelFallback(string $preferredModel, string $question, string $language, string $category): array
    {
        $fallbackModel = $this->resolveModel('general');
        $lightweightFallbackModel = trim((string) config('ai.chatbot_fallback_model', 'llama3'));
        $models = array_values(array_unique(array_filter([
            $preferredModel,
            $fallbackModel,
            $lightweightFallbackModel,
        ], static fn ($m) => is_string($m) && trim($m) !== '')));

        $lastErrorMessage = null;
        foreach ($models as $model) {
            try {
                return [
                    'answer' => $this->generateWithModel($model, $question, $language, $category),
                    'model' => $model,
                ];
            } catch (\Throwable $error) {
                $lastErrorMessage = $error->getMessage();
            }
        }

        $fallbackMessage = $language === 'malay'
            ? 'Maaf, sistem chatbot sedang sibuk buat sementara waktu. Sila cuba semula dalam beberapa minit atau nyatakan semula soalan anda dengan lebih ringkas.'
            : 'Sorry, the chatbot is temporarily busy. Please try again in a few minutes or rephrase your question more briefly.';

        if ($lastErrorMessage !== null) {
            $fallbackMessage .= ' (' . $lastErrorMessage . ')';
        }

        return [
            'answer' => $fallbackMessage,
            'model' => $preferredModel,
        ];
    }

    private function generateWithModel(string $model, string $question, string $language, string $category): string
    {
        $settings = $this->buildGenerationSettings($question, $language, $category);
        $ollamaBaseUrl = $settings['base_url'];
        $timeoutSeconds = $settings['timeout_seconds'];
        $connectTimeoutSeconds = $settings['connect_timeout_seconds'];
        $retryCount = $settings['retry_count'];

        // Keep PHP script timeout above HTTP client timeout while staying bounded.
        $scriptTimeoutSeconds = max(30, $timeoutSeconds + 10);
        if (function_exists('set_time_limit')) {
            @set_time_limit($scriptTimeoutSeconds);
        }
        @ini_set('max_execution_time', (string) $scriptTimeoutSeconds);
        $maxTokens = $settings['max_tokens'];
        $temperature = $settings['temperature'];
        $prompt = $settings['prompt'];

        $http = Http::connectTimeout($connectTimeoutSeconds)
            ->timeout($timeoutSeconds);

        if ($retryCount > 0) {
            $http = $http->retry($retryCount, 500, function ($exception): bool {
                return $exception instanceof ConnectionException;
            });
        }

        /** @var HttpResponse $response */
        $response = $http->post($ollamaBaseUrl . '/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => $temperature,
                    'num_predict' => $maxTokens,
                ],
                'keep_alive' => $settings['keep_alive'],
            ]);

        if (! $response->successful()) {
            $bodySnippet = mb_substr((string) $response->body(), 0, 300);
            throw new RuntimeException('Ollama request failed with status ' . $response->status() . '. ' . trim($bodySnippet));
        }

        $answer = trim((string) data_get($response->json(), 'response', ''));
        if ($answer === '') {
            throw new RuntimeException('Ollama response was empty.');
        }

        return $answer;
    }

    /**
     * @param callable(string):void $onChunk
     */
    private function streamWithModel(string $model, string $question, string $language, string $category, callable $onChunk): string
    {
        $settings = $this->buildGenerationSettings($question, $language, $category);
        $ollamaBaseUrl = $settings['base_url'];
        $timeoutSeconds = $settings['timeout_seconds'];
        $connectTimeoutSeconds = $settings['connect_timeout_seconds'];
        $prompt = $settings['prompt'];

        // Keep PHP script timeout above HTTP client timeout while staying bounded.
        $scriptTimeoutSeconds = max(30, $timeoutSeconds + 10);
        if (function_exists('set_time_limit')) {
            @set_time_limit($scriptTimeoutSeconds);
        }
        @ini_set('max_execution_time', (string) $scriptTimeoutSeconds);

        /** @var HttpResponse $response */
        $response = Http::connectTimeout($connectTimeoutSeconds)
            ->timeout($timeoutSeconds)
            ->withOptions(['stream' => true])
            ->post($ollamaBaseUrl . '/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => true,
                'options' => [
                    'temperature' => $settings['temperature'],
                    'num_predict' => $settings['max_tokens'],
                ],
                'keep_alive' => $settings['keep_alive'],
            ]);

        if (! $response->successful()) {
            $bodySnippet = mb_substr((string) $response->body(), 0, 300);
            throw new RuntimeException('Ollama stream request failed with status ' . $response->status() . '. ' . trim($bodySnippet));
        }

        $answer = '';
        $buffer = '';
        $stream = $response->toPsrResponse()->getBody();

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '') {
                    continue;
                }

                $payload = json_decode($line, true);
                if (! is_array($payload)) {
                    continue;
                }

                $piece = (string) ($payload['response'] ?? '');
                if ($piece !== '') {
                    $answer .= $piece;
                    $onChunk($piece);
                }
            }
        }

        $finalAnswer = trim($answer);
        if ($finalAnswer === '') {
            throw new RuntimeException('Ollama streaming response was empty.');
        }

        return $finalAnswer;
    }

    /**
     * @return array{base_url:string,timeout_seconds:int,connect_timeout_seconds:int,retry_count:int,max_tokens:int,temperature:float,prompt:string,keep_alive:string}
     */
    private function buildGenerationSettings(string $question, string $language, string $category): array
    {
        $languageInstruction = $language === 'malay'
            ? 'Jawab dalam Bahasa Melayu yang jelas dan profesional.'
            : 'Answer in clear professional English.';

        $formatInstruction = $this->buildFormatInstruction($category, $language);

        $prompt = "Follow the response format exactly and do not omit any numbered section headers. "
            . "Answer concisely with practical legal information. Keep the response reasonably short unless user asks for detailed explanation. "
            . $languageInstruction
            . "\n\n"
            . $formatInstruction
            . "\n\n"
            . $question;

        $timeoutSeconds = (int) config('ai.chatbot_timeout_seconds', 25);
        $timeoutSeconds = max(8, min($timeoutSeconds, 120));
        $connectTimeoutSeconds = (int) config('ai.ollama_connect_timeout_seconds', 20);
        $connectTimeoutSeconds = max(2, min($connectTimeoutSeconds, 20));
        $retryCount = (int) config('ai.chatbot_retry_count', 0);
        $retryCount = max(0, min($retryCount, 2));

        $maxTokens = (int) config('ai.chatbot_max_tokens', 220);
        $maxTokens = max(80, min($maxTokens, 512));
        $temperature = (float) config('ai.chatbot_temperature', 0.15);
        $temperature = max(0.0, min($temperature, 1.0));

        return [
            'base_url' => $this->resolveOllamaBaseUrl(),
            'timeout_seconds' => $timeoutSeconds,
            'connect_timeout_seconds' => $connectTimeoutSeconds,
            'retry_count' => $retryCount,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'prompt' => $prompt,
            'keep_alive' => (string) config('ai.chatbot_keep_alive', '10m'),
        ];
    }

    private function buildFormatInstruction(string $category, string $language): string
    {
        $isMalay = $language === 'malay';

        if ($category === 'criminal') {
            return $isMalay
                ? "Format jawapan WAJIB (guna susunan ini):\n"
                    . "1) Scope and safety check: sahkan skop undang-undang jenayah; jika ada risiko segera, utamakan keselamatan.\n"
                    . "2) Plain explanation: terangkan konsep secara ringkas.\n"
                    . "3) Relevant law: senaraikan Akta/peruntukan berkaitan dengan ringkas.\n"
                    . "4) Lawful next steps: langkah neutral dan sah di sisi undang-undang.\n"
                    . "5) Disclaimer: maklumat umum sahaja, bukan nasihat undang-undang."
                : "Mandatory answer format (use this exact order):\n"
                    . "1) Scope and safety check: confirm criminal-law scope and flag urgent safety if present.\n"
                    . "2) Plain explanation: briefly explain the concept.\n"
                    . "3) Relevant law: list applicable Acts/provisions briefly.\n"
                    . "4) Lawful next steps: neutral, lawful next actions.\n"
                    . "5) Disclaimer: general information only, not legal advice.";
        }

        if ($category === 'civil' || $category === 'corporate' || $category === 'general') {
            return $isMalay
                ? "Format jawapan WAJIB (guna susunan ini):\n"
                    . "1) Scope check\n"
                    . "2) Plain explanation\n"
                    . "3) Relevant law\n"
                    . "4) Practical next steps\n"
                    . "5) Disclaimer"
                : "Mandatory answer format (use this exact order):\n"
                    . "1) Scope check\n"
                    . "2) Plain explanation\n"
                    . "3) Relevant law\n"
                    . "4) Practical next steps\n"
                    . "5) Disclaimer";
        }

        return $isMalay
            ? "Format jawapan WAJIB (guna susunan ini): 1) Scope check 2) Plain explanation 3) Relevant law 4) Practical next steps 5) Disclaimer"
            : "Mandatory answer format: 1) Scope check 2) Plain explanation 3) Relevant law 4) Practical next steps 5) Disclaimer";
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

        $resolved = rtrim($normalized, '/');
        $wasNormalized = $configured !== $resolved;

        if ($wasNormalized || (bool) config('ai.chatbot_log_ollama_url', false)) {
            Log::info('Chatbot Ollama URL resolved.', [
                'configured' => $configured,
                'resolved' => $resolved,
                'was_normalized' => $wasNormalized,
            ]);
        }

        return $resolved;
    }

    private function resolveModel(string $category): string
    {
        return match ($category) {
            'civil' => 'aslaw-civil',
            'corporate' => 'aslaw-corporate',
            'criminal' => 'aslaw-criminal',
            default => 'aslaw-general',
        };
    }

    private function resolveCategory(string $question, ?string $categoryHint = null): string
    {
        $hint = strtolower(trim((string) $categoryHint));
        if (in_array($hint, ['civil', 'corporate', 'criminal', 'general'], true)) {
            return $hint;
        }

        $q = strtolower($question);

        $criminal = [
            'criminal', 'jenayah', 'polis', 'police', 'arrest', 'tangkap', 'remand', 'bail', 'blackmail',
            'ugut', 'threat', 'assault', 'identity theft', 'curi', 'stolen', 'hacked', 'scam', 'fraud',
        ];

        $corporate = [
            'corporate', 'syarikat', 'company', 'director', 'pengarah', 'shareholder', 'ssm', 'board',
            'governance', 'insolvency', 'compliance', 'acquisition', 'merger',
        ];

        $civil = [
            'civil', 'sivil', 'contract', 'kontrak', 'tenant', 'tenancy', 'lease', 'landlord',
            'deposit', 'damages', 'claim', 'saman', 'agreement', 'property', 'refund',
        ];

        if ($this->containsAny($q, $criminal)) {
            return 'criminal';
        }

        if ($this->containsAny($q, $corporate)) {
            return 'corporate';
        }

        if ($this->containsAny($q, $civil)) {
            return 'civil';
        }

        return 'general';
    }

    private function resolveResponseLanguage(string $question, ?string $languageHint = null): string
    {
        $hint = strtolower(trim((string) $languageHint));
        if (in_array($hint, ['malay', 'bm', 'bahasa', 'bahasa melayu'], true)) {
            return 'malay';
        }

        if (in_array($hint, ['english', 'en'], true)) {
            return 'english';
        }

        $q = strtolower($question);
        $malayIndicators = [
            ' saya ', ' saya', ' saya?', 'saya ', ' yang ', ' dan ', 'atau ',
            'adalah', 'boleh', 'tidak', 'undang-undang', 'syarikat', 'pemegang saham',
            'saman', 'peguam', 'hak', 'mahkamah', 'jenayah', 'kontrak', 'bagaimana', 'kenapa',
        ];

        foreach ($malayIndicators as $indicator) {
            if (str_contains($q, trim($indicator)) || str_contains($q, $indicator)) {
                return 'malay';
            }
        }

        return 'english';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function isFeeOrContactIntent(string $question): bool
    {
        $q = strtolower($question);

        $feeKeywords = [
            'fee', 'fees', 'price', 'pricing', 'cost', 'quotation', 'quote', 'estimate',
            'yuran', 'harga', 'kos', 'anggaran', 'bayaran',
            'charge', 'charges', 'rate', 'rates', 'payment', 'bayar', 'bayaran', 'service charge',
            'consultation fee', 'retainer', 'retainer fee', 'legal fee', 'legal cost', 'lawyer fee',
            'berapa', 'berapa caj', 'berapa kos', 'berapa harga', 'berapa yuran', 'berapa fee',
        ];

        $contactKeywords = [
            'contact', 'phone', 'number', 'whatsapp', 'call', 'email',
            'nombor', 'telefon', 'hubungi', 'reach', 'get in touch', 'how to contact',
            'address', 'location', 'office', 'firm address', 'law firm',
        ];

        $lawyerKeywords = [
            'lawyer', 'attorney', 'solicitor', 'legal help', 'legal service', 'legal advisor',
            'peguam', 'firma guaman', 'khidmat guaman', 'legal firm', 'law office',
            'find lawyer', 'need lawyer', 'looking for lawyer', 'recommend lawyer',
        ];

        // Flexible: If user asks about lawyer and also mentions contact/fee/price, treat as intent
        $hasLawyer = $this->containsAny($q, $lawyerKeywords);
        $hasFee = $this->containsAny($q, $feeKeywords);
        $hasContact = $this->containsAny($q, $contactKeywords);

        // If any fee/contact intent, or lawyer intent with fee/contact context, match
        return $hasFee || $hasContact || ($hasLawyer && ($hasFee || $hasContact || str_contains($q, 'how much') || str_contains($q, 'berapa')));
    }

    private function isGreetingIntent(string $question): bool
    {
        $q = strtolower(trim($question));

        $greetings = [
            'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening',
            'hai', 'helo', 'salam', 'assalamualaikum', 'test', 'testing',
        ];

        return $q !== '' && strlen($q) <= 40 && $this->containsAny($q, $greetings);
    }

    private function buildFeeContactReferenceResponse(string $category, string $language): string
    {
        $contact = 'Partner Contact: Iman Norhizam | Phone: 019-2630432 | WhatsApp: 019-2630432 | Email: Iman@gmail.com';

        if ($language === 'malay') {
            return match ($category) {
                'civil' => "Rujukan yuran civil (anggaran, bergantung skop/kerumitan):\n"
                    . "- Nasihat dan pra-tindakan: RM3,000 - RM250,000\n"
                    . "- Penyediaan perjanjian/dokumen: RM3,000 - RM180,000\n"
                    . "- Pakej retainer: Essential RM10,000/bulan, Premium RM15,000/bulan, Elite RM20,000/bulan\n"
                    . "- Tenancy dan lease: Tertakluk kepada SRO jika berkaitan\n\n"
                    . $contact,
                'corporate' => "Rujukan yuran corporate (anggaran, bergantung skop/kerumitan):\n"
                    . "- Hal pertumbuhan bisnes dan berkaitan: RM3,000 - RM250,000\n"
                    . "- Penyediaan perjanjian: RM3,000 - RM180,000\n"
                    . "- Kadar ad hoc sejam: Partner RM500/jam, Senior Associate RM350/jam, Associate RM250/jam\n"
                    . "- Pakej retainer: Essential RM10,000/bulan, Premium RM15,000/bulan, Elite RM20,000/bulan\n"
                    . "- Tenancy dan lease: Tertakluk kepada SRO jika berkaitan\n\n"
                    . $contact,
                'criminal' => "Rujukan yuran criminal (anggaran, bergantung skop/kerumitan):\n"
                    . "- Nasihat dan perkara berkaitan: RM3,000 - RM250,000\n"
                    . "- Dokumen berkaitan jenayah: RM3,000 - RM180,000\n"
                    . "- Pakej retainer: Essential RM10,000/bulan, Premium RM15,000/bulan, Elite RM20,000/bulan\n"
                    . "- Tenancy dan lease: Tertakluk kepada SRO jika berkaitan\n\n"
                    . $contact,
                default => "Untuk rujukan yuran yang lebih tepat, sila nyatakan domain anda (civil/corporate/criminal).\n"
                    . "Rujukan ringkas:\n"
                    . "- Civil: RM3,000 - RM250,000\n"
                    . "- Corporate: RM3,000 - RM250,000\n"
                    . "- Criminal: RM3,000 - RM250,000\n\n"
                    . $contact,
            };
        }

        return match ($category) {
            'civil' => "Civil fee reference (estimated, subject to scope/complexity):\n"
                . "- Advisory and pre-action matters: RM3,000 - RM250,000\n"
                . "- Agreements/documents: RM3,000 - RM180,000\n"
                . "- Retainer packages: Essential RM10,000/month, Premium RM15,000/month, Elite RM20,000/month\n"
                . "- Tenancy and lease: Subject to SRO if applicable\n\n"
                . $contact,
            'corporate' => "Corporate fee reference (estimated, subject to scope/complexity):\n"
                . "- Business growth and related matters: RM3,000 - RM250,000\n"
                . "- Agreements: RM3,000 - RM180,000\n"
                . "- Ad hoc hourly: Partner RM500/hour, Senior Associate RM350/hour, Associate RM250/hour\n"
                . "- Retainer packages: Essential RM10,000/month, Premium RM15,000/month, Elite RM20,000/month\n"
                . "- Tenancy and lease: Subject to SRO if applicable\n\n"
                . $contact,
            'criminal' => "Criminal fee reference (estimated, subject to scope/complexity):\n"
                . "- Advisory and related matters: RM3,000 - RM250,000\n"
                . "- Criminal-related documents: RM3,000 - RM180,000\n"
                . "- Retainer packages: Essential RM10,000/month, Premium RM15,000/month, Elite RM20,000/month\n"
                . "- Tenancy and lease: Subject to SRO if applicable\n\n"
                . $contact,
            default => "For accurate fee guidance, please specify your domain (civil/corporate/criminal).\n"
                . "Quick references:\n"
                . "- Civil: RM3,000 - RM250,000\n"
                . "- Corporate: RM3,000 - RM250,000\n"
                . "- Criminal: RM3,000 - RM250,000\n\n"
                . $contact,
        };
    }
}
