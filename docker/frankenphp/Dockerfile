FROM dunglas/frankenphp:latest

RUN install-php-extensions \
    pdo_pgsql \
    redis \
    intl \
    zip \
    opcache \
    sodium

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY . .
RUN composer install --no-interaction --optimize-autoloader --no-scripts
RUN mkdir -p /app/var /app/public && chmod -R 777 /app/var
