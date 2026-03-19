FROM dunglas/frankenphp:latest-php8.5-alpine

RUN install-php-extensions \
    pdo_pgsql \
    redis \
    intl \
    zip \
    opcache \
    sodium

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-interaction --optimize-autoloader --no-dev
RUN chown -R www-data:www-data /app/var
