#!/bin/bash
set -e

echo "==> Running SQLite database initialization..."
php /var/www/html/api/init_sqlite.php

echo "==> Starting Apache..."
exec apache2-foreground

