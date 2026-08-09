#!/usr/bin/env sh
# Init de una sola pasada: dependencias, clave, migraciones. app/worker esperan
# a que este servicio termine (service_completed_successfully).
set -e
cd /var/www

[ -f .env ] || cp .env.example .env
[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

echo "Esperando a MySQL en ${DB_HOST:-db} ..."
until php -r '
  try {
    new PDO("mysql:host=".(getenv("DB_HOST")?:"db").";port=".(getenv("DB_PORT")?:"3306"),
            getenv("DB_USERNAME")?:"root", getenv("DB_PASSWORD")?:"");
    exit(0);
  } catch (Throwable $e) { exit(1); }
' 2>/dev/null; do sleep 2; done

php artisan migrate --force

chmod -R 777 storage bootstrap/cache
echo "init OK"
