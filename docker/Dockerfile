# EcoCity — servidor web (PHP 8.2 + Apache, que respeita os .htaccess do projeto)
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql gd exif \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers deflate expires

COPY php.ini /usr/local/etc/php/conf.d/ecocity.ini
COPY apache-ecocity.conf /etc/apache2/conf-enabled/zz-ecocity.conf
