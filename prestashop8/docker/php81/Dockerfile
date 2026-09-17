FROM composer:2.8 AS composer

FROM php:8.1-apache

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

ARG INSTALL_XDEBUG=0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        less \
        unzip \
        zip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        curl \
        gd \
        intl \
        mbstring \
        mysqli \
        pdo_mysql \
        soap \
        zip; \
    a2enmod rewrite headers expires; \
    printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    if [ "$INSTALL_XDEBUG" = "1" ]; then \
        pecl install xdebug; \
        docker-php-ext-enable xdebug; \
    elif [ "$INSTALL_XDEBUG" != "0" ]; then \
        echo "INSTALL_XDEBUG must be 0 or 1." >&2; \
        exit 2; \
    fi

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY dev-entrypoint.sh /usr/local/bin/dev-entrypoint.sh

RUN chmod +x /usr/local/bin/dev-entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/dev-entrypoint.sh"]
CMD ["apache2-foreground"]
