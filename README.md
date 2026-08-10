# Vehicle Hub Laravel API

Laravel 12 REST API for the multi-tenant Vehicle Hub vehicle-management SaaS, with a separate Vue web application in `vehicle-hub-fe`.

## Setup

Requires PHP 8.3+ for production, Composer, and MySQL 8+.

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan queue:work
php artisan schedule:work
```

Demo accounts after seeding are `owner1@vehiclehub.test` through `owner3@vehiclehub.test`; password: `password`.

The API is under `/api/v1`. Authenticate using `Authorization: Bearer <token>`. Run tests with `php artisan test`. The daily scheduler invokes `vehicle:check-reminders`; production should run one queue worker and Laravel's scheduler cron. Set `FILESYSTEM_DISK=s3` and the AWS-compatible variables for object storage.

## Architecture and security

Every tenant model has a global authenticated-business scope and automatically derives `business_id` from the logged-in user. Route model binding therefore returns 404 for another tenant's resources. Client-supplied business IDs are ignored. Vehicle managers can administer records; drivers are limited to assigned vehicle access for vehicle details and cannot perform administrative mutations.

API response envelopes consistently return `success`, `message`, and `data`; lists add pagination `meta`. Validation and authorization failures use JSON-safe error responses.

See [docs/openapi.yaml](docs/openapi.yaml) for the OpenAPI entry point.

## Vue web application

The Vue 3 + TypeScript frontend is the sibling application at `../vehicle-hub-fe/`. See `../vehicle-hub-fe/README.md` for its setup and development commands.
