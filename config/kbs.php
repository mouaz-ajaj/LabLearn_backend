<?php

return [
    'base_url' => env('KBS_SERVICE_BASE_URL', 'http://127.0.0.1:8601'),
    'analyze_endpoint' => env('KBS_SERVICE_ANALYZE_ENDPOINT', '/v1/analyze'),
    'validate_endpoint' => env('KBS_SERVICE_VALIDATE_ENDPOINT', '/v1/validate'),
    'metadata_endpoint' => env('KBS_SERVICE_METADATA_ENDPOINT', '/v1/metadata'),
    'health_endpoint' => env('KBS_SERVICE_HEALTH_ENDPOINT', '/health'),
    'timeout_seconds' => (int) env('KBS_SERVICE_TIMEOUT_SECONDS', 60),
    'connect_timeout_seconds' => (int) env('KBS_SERVICE_CONNECT_TIMEOUT_SECONDS', 5),
    'api_key' => env('KBS_SERVICE_API_KEY'),
    'verify_tls' => (bool) env('KBS_SERVICE_VERIFY_TLS', false),
    'retry_attempts' => (int) env('KBS_SERVICE_RETRY_ATTEMPTS', 2),
];
