# LabLearn Phase 1 API

## Base URL and conventions

All Phase 1 endpoints use `/api/v1`. Local default: `http://127.0.0.1:8000/api/v1`.

Send `Accept: application/json`. Protected requests also send `Authorization: Bearer <token>`.

Success responses use `success`, an optional `message`, and optional `data`. Errors use `success: false`, `message`, and a stable `error_code`; validation errors also include `errors`. Validation returns 422, invalid credentials/invalid tokens return 401, forbidden operations return 403, missing routes return 404, and throttled requests return 429.

## Roles and profile fields

Registered roles are exactly `regular` and `student`. Guest is unauthenticated and has no database record or Phase 1 API session. `study_year` is a string and accepts `"1"` through `"6"`; it is required for students and must be null/omitted for regular users.

The public user resource contains `id`, `name`, `email`, `role`, `study_year`, and ISO-8601 `created_at`. Passwords, remember tokens, reset tokens, and access-token records are never serialized.

Passwords require at least 8 characters, letters, uppercase and lowercase letters, and numbers. Registration names are 2–100 characters. Emails are trimmed, lowercased, validated, and unique, including against soft-deleted accounts.

## Authentication

### Register

`POST /auth/register` — public, 5 requests/minute/IP.

Regular request:

```json
{
  "name": "Moaz",
  "email": "moaz@example.com",
  "password": "StrongPassword123!",
  "password_confirmation": "StrongPassword123!",
  "role": "regular"
}
```

Student request adds `"role": "student"` and `"study_year": "4"`.

Returns 201 with `data.token`, `data.token_type` (`Bearer`), and `data.user`. Invalid/privileged roles and invalid conditional study-year data return 422 `VALIDATION_ERROR`.

### Login

`POST /auth/login` — public, limited to 5 requests/minute per normalized email+IP and 30/minute/IP.

```json
{
  "email": "moaz@example.com",
  "password": "StrongPassword123!"
}
```

Returns 200 with a new token and user. Unknown emails and wrong passwords both return the same 401 `INVALID_CREDENTIALS` response.

Token policy: each registration/login creates a Sanctum personal access token named by `LABLEARN_TOKEN_NAME` (default `mobile`). Multiple device sessions are allowed. Tokens have Sanctum's default no-expiration policy and remain valid until explicitly revoked.

### Logout

`POST /auth/logout` — protected.

Revokes only the bearer token used for the request. Other device tokens remain valid. Returns 200.

### Current session

`GET /auth/me` — protected.

Returns 200 with `data.user`. The frontend may restore its session and derive its UI mode from `role`; future backend features must still enforce authorization independently.

## Password recovery

### Forgot password

`POST /auth/forgot-password` — public, 5 requests/minute/IP.

```json
{
  "email": "moaz@example.com"
}
```

Always returns the same neutral 200 response whether the account exists. Laravel's password broker stores hashed, expiring reset tokens and sends the standard reset notification. `FRONTEND_URL` controls the generated mobile reset URL. With `MAIL_MAILER=log`, the development notification is written to Laravel's log.

### Reset password

`POST /auth/reset-password` — public, 5 requests/minute/IP.

```json
{
  "email": "moaz@example.com",
  "token": "reset-token",
  "password": "NewStrongPassword123!",
  "password_confirmation": "NewStrongPassword123!"
}
```

Returns 200 on success. The reset token is invalidated by the password broker and every existing Sanctum token is revoked, requiring login on all devices. Invalid reset credentials return 422 `PASSWORD_RESET_FAILED` without identifying which value was wrong.

## User profile

### Get profile

`GET /users/me` — protected. Returns the same user resource as `/auth/me`.

### Update profile

`PATCH /users/me` — protected.

Allowed fields:

- `name` for either role.
- `study_year` only for students, with values `"1"`–`"6"`.

`id`, `email`, `password`, and `role` are explicitly prohibited and return 422 if submitted. Password changes belong to the password-reset flow in Phase 1.

### Delete account

`DELETE /users/me` — protected.

Revokes all Sanctum tokens and soft-deletes the user in one database transaction. Returns 200. Future report ownership/anonymization rules are intentionally deferred.

## Response examples

Authentication success:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "1|...",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "Moaz",
      "email": "moaz@example.com",
      "role": "student",
      "study_year": "4",
      "created_at": "2026-07-20T16:00:00.000000Z"
    }
  }
}
```

Validation error:

```json
{
  "success": false,
  "message": "Validation failed.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Unauthenticated:

```json
{
  "success": false,
  "message": "Unauthenticated.",
  "error_code": "UNAUTHENTICATED"
}
```

Production-only unexpected errors are sanitized as `INTERNAL_ERROR`; stack traces, SQL errors, secrets, passwords, and tokens are not returned.

## Mobile development access

- Android emulator: use `http://10.0.2.2:8000/api/v1` for a backend running on the host.
- iOS simulator: use `http://127.0.0.1:8000/api/v1` where supported.
- Physical device: bind with `php artisan serve --host=0.0.0.0` and use the computer's LAN IP; permit the port in the local firewall.
- Do not deploy with development origins or `APP_DEBUG=true`. Configure `APP_URL`, `FRONTEND_URL`, allowed origins, mail, MySQL credentials, and `APP_KEY` per environment.

## Demo users

Demo records are created only in the local environment and only when `LABLEARN_DEMO_PASSWORD` is set:

- `user@lablearn.demo` — regular.
- `student@lablearn.demo` — student, study year `"4"`.

The seeder is idempotent and restores/replaces the two demo records. It never returns the demo password through an API.
