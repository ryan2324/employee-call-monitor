FROM php:8.3-apache

RUN docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite headers

WORKDIR /var/www/html
COPY api /var/www/html/api
COPY setup.php /var/www/html/setup.php

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
