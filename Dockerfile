
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

# Set storage permissions
RUN chmod -R 777 storage bootstrap/cache

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose application port
EXPOSE 8000

# Run the entrypoint script
ENTRYPOINT ["docker-entrypoint.sh"]
