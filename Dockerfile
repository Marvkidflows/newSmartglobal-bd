
# Use PHP 8.4 CLI
FROM php:8.4-cli

# Set working directory
WORKDIR /var/www

# Configure PHP memory limit
COPY php-memory.ini $PHP_INI_DIR/conf.d/zz-memory.ini

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip bcmath intl \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy entrypoint script (read-only, executable, owned by root)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod 0755 /usr/local/bin/docker-entrypoint.sh

# Least privilege: only storage/ and bootstrap/cache/ are writable, and only by the unprivileged runtime user (was: chmod -R 777).
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 0775 {} + \
    && find storage bootstrap/cache -type f -exec chmod 0664 {} +

# Run as the unprivileged user. (Port 8000 is > 1024, so no root is needed.)
USER www-data

# Expose application port
EXPOSE 8000

# Run the entrypoint script
ENTRYPOINT ["docker-entrypoint.sh"]
