#!/bin/sh
set -e

# Wait for MySQL to be ready (if using MySQL)
if [ "$DB_TYPE" = "mysql" ] || [ -z "$DB_TYPE" ]; then
    echo "Waiting for MySQL to be ready..."
    until nc -z mysql 3306 2>/dev/null; do
        sleep 1
    done
    echo "MySQL is ready!"
fi

# Ensure database directory exists
mkdir -p /var/www/html/database
chown -R www-data:www-data /var/www/html/database

# Ensure uploads directory exists
mkdir -p /var/www/html/uploads
chown -R www-data:www-data /var/www/html/uploads

# Ensure config directory exists and is writable
mkdir -p /var/www/html/config
chown -R www-data:www-data /var/www/html/config

# Run initialization script if database doesn't exist (SQLite) or if explicitly requested
if [ ! -f /var/www/html/database/stockcount.db ] || [ "$FORCE_INIT_DB" = "true" ]; then
    echo "Initializing database..."
    php /var/www/html/scripts/init_db.php || echo "Database initialization completed or skipped"
fi

# Execute the main command
exec "$@"

