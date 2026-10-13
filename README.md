# test-manager-library-3145556-api

> **Technical test · ADSO 3145556** — *Manager Library* backend in **PHP 8.3 + Laravel 13**.
> Implements the spec in [`test-manager-library-3145556-docs`](https://github.com/code-sena/test-manager-library-3145556-docs), translated from Python/.NET to Laravel.
> Translation decisions and the spec traps found are documented in the `docs` repo under `laravel-decisions/`.

## Running it

With the `infra` repo (recommended): `docker compose up --build` starts the empty engine, API, frontend and public page.

Locally:

```bash
cp .env.example .env                  # fill DB_*, JWT_SECRET, ADMIN_USERNAME, ADMIN_PASSWORD
composer install
php artisan key:generate
php artisan migrate                   # creates the `library` schema (ADR-001)
php artisan library:bootstrap-admin   # initial admin from the environment (D-10)
PHP_CLI_SERVER_WORKERS=4 php artisan serve
```

## How it is built (spec → Laravel)

| Spec (Python / .NET) | Here |
|---|---|
| Dependency-free `domain/` (art. I) | `app/Domain` — plain PHP: `Book`, `Loan`, `LoanItem`, `Isbn`, `Quantity`, `DateRange` |
| Ports owned by the application (art. II) | `app/Application/Ports.php` — `Clock`, `PasswordHasher`, `TokenIssuer`, `CoverStorage`, repositories, `ReportReader` |
| One service = one use case | `app/Application/Services.php` — `LendBooksService`, `ReturnItemService`, `MostBorrowedReportService`, … |
| Single composition point (art. III) | `app/Bootstrap/PortBindings.php` (service provider) |
| SQLAlchemy / EF Core | Laravel query builder on PostgreSQL 16 (**no Eloquent**, see decisions) |
| Alembic / EF migrations | `database/migrations` — explicit SQL, identical to `data-model.md` |
| Argon2id / BCrypt | Argon2id (`password_hash`) |
| JWT HS256, 8 h | Own `JwtTokenIssuer`, `sub` + `role`, no personal data |
| pytest / xUnit · import-linter / NetArchTest | PHPUnit · `tests/Unit/ArchitectureTest.php` |

Language rule (constitution art. XI + instructor): code, comments, docs and commits in English;
**user-facing messages** (the `message` field of API errors) in Spanish.

## Evidence per task (art. XII)

| Task | Runnable evidence |
|---|---|
| T-01 | `GET /api/v1/health` → `{"status":"ok"}` |
| T-02, T-18 | `tests/Feature/ApiContractTest::test_engine_matches_data_model` (six tables, no `DEFAULT`, CHECKs) |
| T-03 – T-06 | `tests/Unit/DomainTest.php` |
| T-07 – T-11, T-16 | `tests/Feature/ApiContractTest.php` (§2 error codes, single error body, frozen report) |
| T-13 / RNF-2 | `tests/concurrency/last-copy.sh` — two loans of the last copy: `201 409` |
| T-14 | `POST /users` response has no `password`; passwords are in `dontFlash` |
| T-15 | 2 MB PNG cover → 200; 3 MB → 422; a JPG renamed `.png` is detected by content |
| T-17 | `docker/entrypoint.sh`: migrates the empty database and creates the admin from the environment |
| T-19 | `tests/Unit/ArchitectureTest.php` (articles I, II and III) |

```bash
composer test     # 19 tests, library_test database on PostgreSQL
API=http://localhost:8000/api/v1 ADMIN_USERNAME=admin ADMIN_PASSWORD=… tests/concurrency/last-copy.sh
```
