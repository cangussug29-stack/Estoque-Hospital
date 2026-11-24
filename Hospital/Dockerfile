FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/lib/hospital \
    && chown www-data:www-data /var/lib/hospital

WORKDIR /var/www/html
