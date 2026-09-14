FROM php:8.3-apache

# Install PostgreSQL development libraries and other required packages
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite rules
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copy backend application
COPY . /var/www/html/

# Apache listens on Render's PORT
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/g' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
