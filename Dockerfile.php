FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql pdo_sqlite mysqli mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy entrypoint script
COPY docker-entrypoint-php.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint-php.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 9000 for PHP-FPM
EXPOSE 9000

ENTRYPOINT ["docker-entrypoint-php.sh"]
CMD ["php-fpm"]

