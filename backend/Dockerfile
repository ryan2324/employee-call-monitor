FROM php:8.3-apache

# PostgreSQL development libraries are required for pdo_pgsql
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*


WORKDIR /var/www/html

COPY api /var/www/html/api
COPY setup.php /var/www/html/setup.php

# Allow .htaccess to handle API routes
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/employee-monitor.conf \
    && a2enconf employee-monitor

# Give Apache access to the application
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
