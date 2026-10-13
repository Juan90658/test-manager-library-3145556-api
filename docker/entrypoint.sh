#!/bin/sh
# Container startup: waits for the engine, runs migrations (schema owners, ADR-001),
# creates the initial admin from the environment (D-10) and serves the API with several workers (RNF-2).
set -e

until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 5432).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
  echo "Waiting for PostgreSQL..."; sleep 2
done

[ -n "$APP_KEY" ] || export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"

php artisan migrate --force
php artisan library:bootstrap-admin
# --no-reload is required for PHP_CLI_SERVER_WORKERS to start several workers.
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
