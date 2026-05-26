FROM php:8.2-cli-alpine

RUN apk add --no-cache curl curl-dev git unzip \
    && docker-php-ext-install -j$(nproc) curl opcache

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

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
