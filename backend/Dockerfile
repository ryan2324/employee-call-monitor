FROM php:8.3-apache

# Install PostgreSQL development libraries
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy the backend application
COPY . /var/www/html/

# Apache configuration
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    Options FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/employee-call-monitor.conf \
    && a2enconf employee-call-monitor

# Make /api/* go directly to api/index.php
RUN printf '%s\n' \
    'RewriteEngine On' \
    'RewriteRule ^api/(.*)$ /api/index.php [QSA,L]' \
    > /var/www/html/.htaccess

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
