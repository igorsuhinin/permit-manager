FROM php:8.6-apache@sha256:0b06b358a7d59cca80e0528aad0e49b43208c3a74754ffcd8a12d7727058511e AS runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql mbstring \
    && a2enmod rewrite headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/development.ini

WORKDIR /var/www/html

FROM runtime AS development

RUN pecl install pcov xdebug-3.5.3 && docker-php-ext-enable pcov xdebug
COPY --from=composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca /usr/bin/composer /usr/local/bin/composer
COPY docker/pcov.ini /usr/local/etc/php/conf.d/pcov.ini
COPY docker/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
