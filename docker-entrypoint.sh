#!/bin/bash
set -e

echo "==> Creating runtime directories..."
mkdir -p /var/www/html/data/sent_emails /var/www/html/uploads/project_files /var/www/html/uploads/task_files

echo "==> Running SQLite database initialization..."
php /var/www/html/api/init_sqlite.php

echo "==> Setting full permissions for www-data..."
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads
chmod -R 777 /var/www/html/data /var/www/html/uploads
find /var/www/html/data -type f -exec chmod 666 {} + 2>/dev/null || true

echo "==> Starting Apache..."
exec apache2-foreground
