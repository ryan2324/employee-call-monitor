FROM php:8.3-apache

# Install PostgreSQL development libraries
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy the complete backend
COPY . /var/www/html/

# Give Apache access
RUN chown -R www-data:www-data /var/www/html

# Allow access to the application directory
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    Options FollowSymLinks' \
    '    AllowOverride None' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/employee-call-monitor.conf \
    && a2enconf employee-call-monitor

# Make /api/health and all API requests go to api/index.php
RUN printf '%s\n' \
    'RewriteEngine On' \
    'RewriteCond %{REQUEST_URI} ^/api/(?!index\.php)' \
    'RewriteRule ^api/.*$ /api/index.php [QSA,L]' \
    > /var/www/html/.htaccess

EXPOSE 80
