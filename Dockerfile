FROM php:8.2-cli-alpine

RUN apk add --no-cache curl curl-dev \
    && docker-php-ext-install -j$(nproc) curl opcache

WORKDIR /app
COPY . /app

ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
