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

# Wait for PHP/API to be ready (optional, not critical for startup)
echo "Waiting for PHP service..."
until nc -z php 9000 2>/dev/null; do
    sleep 1
done
echo "PHP service is ready!"

# Ensure database directory exists
mkdir -p /var/www/html/database
chmod -R 755 /var/www/html/database

# Ensure config directory exists
mkdir -p /var/www/html/config
chmod -R 755 /var/www/html/config

# Execute the main command
exec "$@"

