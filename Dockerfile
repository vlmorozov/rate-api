FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && git config --system --add safe.directory /app \
    && docker-php-ext-install -j"$(nproc)" bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=ghcr.io/symfony-cli/symfony-cli:latest /usr/local/bin/symfony /usr/local/bin/symfony

WORKDIR /app

EXPOSE 8000
CMD ["symfony", "server:start", "--listen-ip=0.0.0.0", "--port=8000", "--no-tls"]
