FROM php:8.2-cli-alpine

RUN apk add --no-cache curl curl-dev git unzip libzip-dev \
    && docker-php-ext-install -j$(nproc) curl opcache zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better Docker layer caching)
COPY composer.json composer.lock* /app/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Copy the rest of the app
COPY . /app

ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "php -d post_max_size=128M -d upload_max_filesize=128M -d memory_limit=512M -d max_execution_time=300 -S 0.0.0.0:${PORT} -t /app"]
