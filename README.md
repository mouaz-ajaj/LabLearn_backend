# LabLearn Backend

Phase 1 API foundation for the LabLearn mobile application. It provides Laravel Sanctum bearer-token authentication, regular/student roles, password recovery, self-service profiles, rate limiting, and soft account deletion. Report, OCR, medical-analysis, quiz, comparison, and learning-progress features are intentionally out of scope.

## Requirements

- PHP 8.3+
- Composer
- MySQL 8+

## Local setup

Create separate MySQL databases named `lablearn` and `lablearn_testing`, then configure credentials in `.env` and `.env.testing`.

```bash
composer install
copy .env.example .env
php artisan key:generate
copy .env.testing.example .env.testing
php artisan key:generate --env=testing
php artisan migrate
php artisan test --compact
php artisan serve
```

Set `FRONTEND_URL` to the mobile app's password-reset deep/universal-link base. Set `CORS_ALLOWED_ORIGINS` for Expo web origins; native React Native clients do not rely on browser CORS. For optional local demo users, set a strong `LABLEARN_DEMO_PASSWORD`, then run:

```bash
php artisan db:seed
```

API reference: [docs/phase-1-api.md](docs/phase-1-api.md)

Postman collection: [docs/postman/LabLearn-Phase1.postman_collection.json](docs/postman/LabLearn-Phase1.postman_collection.json)
