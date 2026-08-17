FROM php:7.2-apache

# No apt here: this image is built on Debian Buster, whose repositories are
# retired, so apt-get update returns 404.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql

COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf

RUN printf 'ServerName inventory-service\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

WORKDIR /var/www/html

COPY bootstrap.php ./bootstrap.php
COPY src ./src
COPY public ./public

RUN chown -R www-data:www-data /var/www/html

ENV OTEL_SERVICE_NAME=inventory-service \
    OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318 \
    SERVICE_VERSION=1.0.0 \
    DEPLOYMENT_ENVIRONMENT=local

EXPOSE 80
