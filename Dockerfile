FROM dunglas/frankenphp:1.13.4-php8.5-alpine

# pdo_pgsql não vem na imagem oficial do FrankenPHP.
RUN install-php-extensions pdo_pgsql

COPY docker/php.ini /usr/local/etc/php/conf.d/99-rinha.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY src /app/src

WORKDIR /app

EXPOSE 80
