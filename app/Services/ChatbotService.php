<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatbotService
{
    /**
     * Ask ASLAW chatbot and return answer + routing metadata.
     */
    public function ask(string $question, ?string $categoryHint = null): array
    {
        $category = $this->resolveCategory($question, $categoryHint);
        $model = $this->resolveModel($category);

        if ($this->isFeeOrContactIntent($question)) {
            return [
                'answer' => $this->buildFeeContactReferenceResponse($category),
                'category' => $category,
                'model' => $model,
            ];
        }

        if ($this->isGreetingIntent($question)) {
            return [
                'answer' => "Hello. I am ASLAW chatbot. Please ask your legal question and, if possible, choose a domain (civil/corporate/criminal) for faster routing.",
                'category' => $category,
                'model' => $model,
            ];
        }

        $response = $this->generateWithModel($model, $question);

        return [
            'answer' => $response,
            'category' => $category,
            'model' => $model,
        ];
    }

    private function generateWithModel(string $model, string $question): string
    {
        $ollamaBaseUrl = rtrim((string) config('ai.ollama_base_url', 'http://127.0.0.1:11434'), '/');
        $timeoutSeconds = (int) config('ai.chatbot_timeout_seconds', 180);
        $timeoutSeconds = max(30, min($timeoutSeconds, 600));
        $connectTimeoutSeconds = (int) config('ai.ollama_connect_timeout_seconds', 20);
        $connectTimeoutSeconds = max(5, min($connectTimeoutSeconds, 120));
        // Keep PHP script timeout above HTTP client timeout to avoid abrupt 60s fatal errors.
        $scriptTimeoutSeconds = max(90, $timeoutSeconds + 30);
        if (function_exists('set_time_limit')) {
            @set_time_limit($scriptTimeoutSeconds);
        }
        @ini_set('max_execution_time', (string) $scriptTimeoutSeconds);
        $maxTokens = (int) config('ai.chatbot_max_tokens', 220);
        $maxTokens = max(80, min($maxTokens, 512));
        $temperature = (float) config('ai.chatbot_temperature', 0.15);
        $temperature = max(0.0, min($temperature, 1.0));

        $prompt = "Answer concisely with practical legal information. Keep the response reasonably short unless user asks for detailed explanation.\n\n"
            . $question;

        /** @var HttpResponse $response */
        $response = Http::retry(2, 1000, function ($exception): bool {
                return $exception instanceof ConnectionException;
            })
            ->connectTimeout($connectTimeoutSeconds)
            ->timeout($timeoutSeconds)
            ->post($ollamaBaseUrl . '/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => $temperature,
                    'num_predict' => $maxTokens,
                ],
                'keep_alive' => config('ai.chatbot_keep_alive', '10m'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Ollama request failed with status ' . $response->status());
        }

        $answer = trim((string) data_get($response->json(), 'response', ''));
        if ($answer === '') {
            throw new RuntimeException('Ollama response was empty.');
        }

        return $answer;
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

    private function buildFeeContactReferenceResponse(string $category): string
    {
        $contact = 'Partner Contact: Iman Norhizam | Phone: 019-2630432 | WhatsApp: 019-2630432 | Email: Iman@gmail.com';

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
