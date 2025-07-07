FROM php:8.2-apache

WORKDIR /var/www/html

# Install dependencies and enable mod_rewrite
RUN apt-get update && \
    apt-get install -y libicu-dev && \
    docker-php-ext-install intl && \
    a2enmod rewrite

# Copy files and set permissions
COPY . .
RUN chown -R www-data:www-data /var/www/html && \
    chmod 775 users.json error.log

# Environment variables
ENV BOT_TOKEN=${BOT_TOKEN}
ENV BOT_USERNAME=${BOT_USERNAME}
ENV WEBHOOK_SECRET=${WEBHOOK_SECRET}

EXPOSE 80