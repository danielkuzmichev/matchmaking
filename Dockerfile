FROM php:8.4-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    curl \
    && docker-php-ext-install sockets \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis \
    && docker-php-ext-enable redis

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY web-socket/src /var/www/shared-src

COPY matchmaking/composer.json matchmaking/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY matchmaking/ .

RUN php bin/console cache:clear || true

WORKDIR /var/www/html
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
