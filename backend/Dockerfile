FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

WORKDIR /var/www/html
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY api /var/www/html/api
COPY setup.php /var/www/html/setup.php
COPY index.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
