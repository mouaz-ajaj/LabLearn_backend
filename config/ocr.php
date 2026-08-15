<?php

return [
    'base_url' => rtrim(env('OCR_SERVICE_BASE_URL', 'http://127.0.0.1:9001'), '/'),
    'analyze_endpoint' => env('OCR_SERVICE_ANALYZE_ENDPOINT', '/api/v1/ocr/analyze'),
    'health_endpoint' => env('OCR_SERVICE_HEALTH_ENDPOINT', '/health'),
    'timeout_seconds' => (int) env('OCR_SERVICE_TIMEOUT_SECONDS', 180),
    'connect_timeout_seconds' => (int) env('OCR_SERVICE_CONNECT_TIMEOUT_SECONDS', 10),
    'api_key' => env('OCR_SERVICE_API_KEY'),
    'verify_tls' => (bool) env('OCR_SERVICE_VERIFY_TLS', true),
    'storage_disk' => env('OCR_REPORT_STORAGE_DISK', 'local'),
    'max_upload_bytes' => (int) env('OCR_SERVICE_MAX_UPLOAD_BYTES', 20 * 1024 * 1024),
    'max_upload_kilobytes' => (int) ceil((int) env('OCR_SERVICE_MAX_UPLOAD_BYTES', 20 * 1024 * 1024) / 1024),
    'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg'],
    'allowed_mime_types' => ['application/pdf', 'image/png', 'image/jpeg', 'application/octet-stream'],
    'response_profile' => env('OCR_SERVICE_RESPONSE_PROFILE', 'full'),
    'preprocessing_step' => env('OCR_SERVICE_PREPROCESSING_STEP', 'enhanced'),
    'engine_name' => 'medical-laboratory-ocr-api',
];
