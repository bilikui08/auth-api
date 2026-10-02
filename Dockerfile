FROM php:8.5-fpm-bookworm

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev unzip \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_mysql \
    && groupmod --gid "${GROUP_ID}" www-data \
    && usermod --uid "${USER_ID}" --gid "${GROUP_ID}" www-data \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-interaction

CMD ["php-fpm"]
