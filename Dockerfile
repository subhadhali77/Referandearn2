FROM php:8.2-apache

WORKDIR /var/www/html

# Install dependencies and enable mod_rewrite
RUN apt-get update && \
    apt-get install -y libicu-dev && \
    docker-php-ext-install intl && \
    a2enmod rewrite

# Copy all app files
COPY . .

# Fix permissions for all files
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
