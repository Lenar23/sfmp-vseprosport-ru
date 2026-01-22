FROM php:8.2-fpm

RUN mkdir -p /app/

WORKDIR /app

COPY . .

RUN apt-get update \
    && apt-get install -y \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-configure pdo_pgsql \
	&& docker-php-ext-install -j$(nproc) pdo_pgsql \
    && docker-php-ext-configure zip \
	&& docker-php-ext-install -j$(nproc) zip

RUN php composer.phar update \
    && php composer.phar install

RUN mkdir -p /app/tmp \
    && touch /app/tmp/errors.log

RUN cp config/config.prod.php config/config.php

CMD ["php", "index.php", "https://www.vseprosport.ru/news/today"]
