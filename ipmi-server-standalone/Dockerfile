# 1. Pull the official composer binary from its own image
FROM composer:2.8.5 AS composer_stage

# 2. Start your main PHP image
FROM php:8.4-fpm-alpine

# 3. Build ipmitool from source (match add-on).
# Do NOT use --enable-internal-md5: that disables the lanplus (RMCP+) interface.
# hadolint ignore=DL3003,DL3018
RUN apk add --no-cache \
        bash \
        openssl \
        readline \
        ncurses \
        libgcc && \
    apk add --no-cache --virtual .ipmitool-build \
        build-base \
        automake \
        autoconf \
        libtool \
        openssl-dev \
        readline-dev \
        ncurses-dev \
        curl \
        patch && \
    curl -fsSL -o /tmp/ipmitool.tar.gz \
        "https://github.com/ipmitool/ipmitool/archive/refs/tags/IPMITOOL_1_8_19.tar.gz" && \
    mkdir -p /tmp/ipmitool && \
    tar -xzf /tmp/ipmitool.tar.gz -C /tmp/ipmitool --strip-components=1 && \
    cd /tmp/ipmitool && \
    curl -fsSL -o /tmp/iana.patch \
        "https://github.com/ipmitool/ipmitool/commit/1edb0e27e44196d1ebe449aba0b9be22d376bcb6.patch" && \
    patch -p1 < /tmp/iana.patch && \
    ./bootstrap && \
    ./configure \
        --prefix=/usr \
        --bindir=/usr/sbin \
        --enable-intf-lan \
        --enable-intf-lanplus \
        --enable-intf-open \
        --disable-intf-usb \
        --disable-internal-md5 && \
    make -j"$(nproc)" && \
    make install && \
    ipmitool -V && \
    (ipmitool -h 2>&1 || true) | grep -Fq 'lanplus' && \
    cd / && \
    rm -rf /tmp/ipmitool /tmp/ipmitool.tar.gz /tmp/iana.patch && \
    apk del .ipmitool-build && \
    echo "ipmitool lanplus interface OK"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# 4. Install runtime packages + PHP extensions (after ipmitool build).
# hadolint ignore=DL3018
RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        libzip-dev \
        libintl \
        icu-dev \
        git \
        oniguruma-dev && \
    docker-php-ext-install bcmath pcntl zip mbstring intl

COPY /ipmi-server/rootfs/etc/ssl/ipmitool-openssl.cnf /etc/ssl/ipmitool-openssl.cnf
ENV OPENSSL_CONF=/etc/ssl/ipmitool-openssl.cnf

# 5. Configure Nginx, PHP-FPM (unix socket), and Supervisor
COPY /ipmi-server-standalone/nginx.conf /etc/nginx/http.d/default.conf
COPY /ipmi-server-standalone/php-fpm-zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY /ipmi-server-standalone/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 6. Set up app
WORKDIR /var/www/html
COPY /ipmi-server/rootfs/app /var/www/html

# 7. Copy Composer from the builder stage
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

# 8. Install app dependencies
RUN php /usr/bin/composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --working-dir /var/www/html && \
    rm -rf /var/www/html/var/cache/* && \
    rm /usr/bin/composer

# 9. Final permissions
RUN mkdir -p /var/www/html/var/cache/prod /var/www/html/var/log && \
    chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
