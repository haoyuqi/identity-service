FROM composer:2 AS composer

FROM php:8.4-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install --no-install-recommends --yes libonig-dev libpq-dev unzip \
    && docker-php-ext-install mbstring pdo_pgsql \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --no-autoloader

COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi \
    && printf '<VirtualHost *:8080>\n    ServerName localhost\n    DocumentRoot /var/www/html/public\n    <Directory /var/www/html/public>\n        AllowOverride All\n        Require all granted\n    </Directory>\n    ErrorLog /proc/self/fd/2\n    LogFormat "%h %l %u %t \\\"%m %>s\\\" %D" privacy_safe\n    CustomLog /proc/self/fd/1 privacy_safe\n</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf \
    && sed -i 's#^PidFile .*#PidFile /tmp/apache2.pid#' /etc/apache2/apache2.conf \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache /var/run/apache2 /var/lock/apache2 /var/log/apache2

USER www-data

EXPOSE 8080

CMD ["apache2-foreground"]
