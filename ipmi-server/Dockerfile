# 1. ARGs used in FROM must be at the very top
ARG BUILD_FROM=ghcr.io/hassio-addons/base:21.0.0

# 2. Define the composer stage
FROM composer:2.8.5 AS composer_stage

# 3. Start the main build using the ARG (it is now globally available)
# hadolint ignore=DL3006
FROM ${BUILD_FROM}

# Set environment variables
ENV S6_CMD_WAIT_FOR_SERVICES=1 \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 \
    S6_SERVICES_GRACETIME=0 \
    LANG=C.UTF-8 \
    APP_DIR="/app" \
    OPENSSL_CONF="/etc/ssl/ipmitool-openssl.cnf"

# Set shell
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Setup base and install dependencies
# Build ipmitool from source so we control OpenSSL/lanplus linking.
# Do NOT use --enable-internal-md5: that makes configure skip libcrypto and
# disables the lanplus (IPMI v2 / RMCP+) interface entirely.
# hadolint ignore=DL3003,DL3018
RUN apk update && \
    apk add --no-cache \
        nginx=1.30.4-r1 \
        php84=8.4.24-r0 \
        php84-fpm=8.4.24-r0 \
        php84-openssl=8.4.24-r0 \
        php84-ctype=8.4.24-r0 \
        php84-session=8.4.24-r0 \
        php84-tokenizer=8.4.24-r0 \
        php84-opcache=8.4.24-r0 \
        php84-iconv=8.4.24-r0 \
        php84-xml=8.4.24-r0 \
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
        curl && \
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
    cd / && \
    rm -rf /tmp/ipmitool /tmp/ipmitool.tar.gz /tmp/iana.patch && \
    apk del .ipmitool-build && \
    mkdir -p /app/cache /app/var/cache/prod /app/var/log /var/tmp/nginx && \
    chmod -R 777 /app/var/ && \
    ipmitool -V && \
    ipmitool -h 2>&1 | grep -q 'lanplus' && \
    echo "ipmitool lanplus interface OK"

# Copy root filesystem
COPY rootfs /

# Pull composer binary from the aliased stage
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

# Install app dependencies and clean up
# hadolint ignore=DL3018
RUN apk add --no-cache \
        php84-phar=8.4.24-r0 \
        php84-mbstring=8.4.24-r0 && \
    php84 /usr/bin/composer --working-dir /app install && \
    rm -rf /app/var/cache/* && \
    apk del \
        php84-phar \
        php84-mbstring && \
    rm /usr/bin/composer

# Consolidated permissions
RUN if [ -d /app ]; then chown -R nginx /app; fi && \
    chown -R nginx /var/lib/nginx && \
    chmod -R 777 /var/lib/nginx && \
    if [ -d /etc/cont-init.d ]; then chmod -R 755 /etc/cont-init.d; fi && \
    if [ -d /etc/services.d ]; then chmod -R 755 /etc/services.d; fi && \
    if [ -f /entrypoint.sh ]; then chmod 755 /entrypoint.sh; fi

# Build arguments for Hass.io
ARG BUILD_ARCH
ARG BUILD_DATE
ARG BUILD_DESCRIPTION
ARG BUILD_NAME
ARG BUILD_REF
ARG BUILD_REPOSITORY
ARG BUILD_VERSION

# Labels
LABEL \
    io.hass.name="${BUILD_NAME}" \
    io.hass.description="${BUILD_DESCRIPTION}" \
    io.hass.arch="${BUILD_ARCH}" \
    io.hass.type="addon" \
    io.hass.version=${BUILD_VERSION} \
    maintainer="Franck Nijhof <frenck@addons.community>" \
    org.opencontainers.image.title="${BUILD_NAME}" \
    org.opencontainers.image.description="${BUILD_DESCRIPTION}" \
    org.opencontainers.image.vendor="Home Assistant Community Add-ons" \
    org.opencontainers.image.authors="Franck Nijhof <frenck@addons.community>" \
    org.opencontainers.image.licenses="MIT" \
    org.opencontainers.image.url="https://addons.community" \
    org.opencontainers.image.source="https://github.com{BUILD_REPOSITORY}" \
    org.opencontainers.image.documentation="https://github.com{BUILD_REPOSITORY}/blob/main/README.md" \
    org.opencontainers.image.created=${BUILD_DATE} \
    org.opencontainers.image.revision=${BUILD_REF} \
    org.opencontainers.image.version=${BUILD_VERSION}
