FROM php:8.4-cli-bookworm

ARG APP_GID=1000
ARG APP_UID=1000

RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        curl \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        pcntl \
        pdo_pgsql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --gid "${APP_GID}" app \
    && useradd --uid "${APP_UID}" --gid app --create-home --shell /bin/bash app

WORKDIR /var/www/html

COPY --chown=app:app composer.json composer.lock ./

RUN composer install \
    --classmap-authoritative \
    --no-interaction \
    --no-scripts \
    --prefer-dist

COPY --chown=app:app . .

RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload --classmap-authoritative --no-interaction \
    && chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
