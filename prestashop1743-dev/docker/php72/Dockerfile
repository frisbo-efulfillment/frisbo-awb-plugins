FROM composer:2.2.24 AS composer

FROM php:7.2-apache

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

ARG INSTALL_XDEBUG=0

RUN set -eux; \
    if ! apt-get update; then \
        sed -ri \
            -e 's|deb.debian.org/debian|archive.debian.org/debian|g' \
            -e 's|security.debian.org/debian-security|archive.debian.org/debian-security|g' \
            -e 's|deb.debian.org/debian-security|archive.debian.org/debian-security|g' \
            /etc/apt/sources.list; \
        sed -ri '/[[:space:]](stretch|buster)-updates[[:space:]]/d' /etc/apt/sources.list; \
        printf 'Acquire::Check-Valid-Until "false";\nAcquire::Check-Date "false";\n' \
            > /etc/apt/apt.conf.d/99archive; \
        apt-get -o Acquire::Check-Valid-Until=false update; \
    fi; \
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
        libpng-dev \
        libxml2-dev \
        libzip-dev; \
    docker-php-ext-configure gd \
        --with-freetype-dir=/usr/include/ \
        --with-jpeg-dir=/usr/include/; \
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
        pecl install xdebug-2.9.8; \
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
