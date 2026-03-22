FROM dunglas/frankenphp:latest

RUN install-php-extensions \
    pdo_pgsql \
    redis \
    intl \
    zip \
    opcache \
    sodium \
    pcov

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
# Replace the default FrankenPHP Caddyfile with our custom config
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/php.ini /usr/local/etc/php/conf.d/app.ini
COPY . .
RUN composer install --no-interaction --optimize-autoloader --no-scripts
RUN mkdir -p /app/var /app/public && chmod -R 777 /app/var
