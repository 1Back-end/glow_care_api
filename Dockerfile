FROM php:8.3-apache

RUN apt-get update && apt-get install -y git unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip gd mbstring bcmath \
    && a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker-start.sh /docker-start.sh
RUN chmod +x /docker-start.sh
CMD ["/docker-start.sh"]
