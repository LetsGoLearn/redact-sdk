<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Redactor API endpoint
    |--------------------------------------------------------------------------
    */
    'base_uri' => env('REDACT_BASE_URI', 'http://127.0.0.1:8080'),

    // API key sent as "Authorization: Bearer <key>".
    'api_key' => env('REDACT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Transport tuning
    |--------------------------------------------------------------------------
    */
    'timeout' => (float) env('REDACT_TIMEOUT', 10.0),
    'connect_timeout' => (float) env('REDACT_CONNECT_TIMEOUT', 3.0),
    'retries' => (int) env('REDACT_RETRIES', 2),
    'retry_delay_ms' => (int) env('REDACT_RETRY_DELAY_MS', 100),

    /*
    |--------------------------------------------------------------------------
    | Document timeouts (Client::redactDocument)
    |--------------------------------------------------------------------------
    | A whole-document call converts the file AND classifies it in one request,
    | which takes seconds — far longer than the text-redaction `timeout` above,
    | so it gets its own budget. OCR runs Tesseract per page and needs minutes;
    | it should only ever be requested from a queued job.
    */
    'document_timeout' => (float) env('REDACT_DOCUMENT_TIMEOUT', 120.0),
    'ocr_timeout' => (float) env('REDACT_OCR_TIMEOUT', 600.0),

    /*
    |--------------------------------------------------------------------------
    | Redaction defaults
    |--------------------------------------------------------------------------
    | Applied when a call does not pass an explicit threshold. Leave null to use
    | the server's default.
    */
    'default_threshold' => env('REDACT_DEFAULT_THRESHOLD') !== null
        ? (float) env('REDACT_DEFAULT_THRESHOLD')
        : null,
];
