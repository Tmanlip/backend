<?php

return [
    'ollama_base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    'ollama_connect_timeout_seconds' => env('OLLAMA_CONNECT_TIMEOUT_SECONDS', 20),
    'document_template_base_path' => env('DOCUMENT_TEMPLATE_BASE_PATH', storage_path('app/document-generator/templates')),
    'document_generator_model' => env('DOCUMENT_GENERATOR_MODEL', 'llama3'),
    'document_generator_timeout_seconds' => env('DOCUMENT_GENERATOR_TIMEOUT_SECONDS', 25),
    'chatbot_db' => env('CHATBOT_DB', 'rag__usage'),
    'chatbot_collection' => env('CHATBOT_COLLECTION', 'chat_history'),
    'chatbot_timeout_seconds' => env('CHATBOT_TIMEOUT_SECONDS', 25),
    'chatbot_retry_count' => env('CHATBOT_RETRY_COUNT', 0),
    'chatbot_max_tokens' => env('CHATBOT_MAX_TOKENS', 220),
    'chatbot_temperature' => env('CHATBOT_TEMPERATURE', 0.15),
    'chatbot_keep_alive' => env('CHATBOT_KEEP_ALIVE', '10m'),
    'chatbot_fallback_model' => env('CHATBOT_FALLBACK_MODEL', 'llama3'),
];
