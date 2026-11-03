# Aktuelle stabile PHP-Reihe (Stand November 2026: 8.5). Der Tag „8.5-apache“ bekommt jede
# Patch-Version; Sicherheitsupdates holt man mit `docker compose build --pull`.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-apache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-wunschliste.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY bin ./bin
COPY src ./src
COPY public ./public
RUN mkdir -p data && chown www-data:www-data data && chmod 0750 data

VOLUME /var/www/html/data
EXPOSE 80
