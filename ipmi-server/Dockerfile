ARG BUILD_FROM=ghcr.io/hassio-addons/base:14.0.2
# hadolint ignore=DL3006
FROM ${BUILD_FROM}

# Set S6 wait time
ENV S6_CMD_WAIT_FOR_SERVICES=1 \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 \
    S6_SERVICES_GRACETIME=0

ENV LANG C.UTF-8

ENV APP_DIR="/app"

# Set shell
SHELL ["/bin/bash", "-o", "pipefail", "-c"]


# Setup base
# hadolint ignore=DL3003
# Install app dependencies

RUN apk -U update
RUN apk -U upgrade
RUN apk -U add --no-cache \
    ipmitool
RUN apk -U add --no-cache \
    nginx=1.24.0-r7

ENV PHPVERS="81"
RUN apk -U add --no-cache \
    php$PHPVERS \
    php$PHPVERS-fpm \
    php$PHPVERS-curl \
    php$PHPVERS-dom \
    php$PHPVERS-gettext \
    php$PHPVERS-xml \
    php$PHPVERS-simplexml \
    php$PHPVERS-zip \
    php$PHPVERS-zlib \
    php$PHPVERS-gd \
    php$PHPVERS-openssl \
    php$PHPVERS-json \
    php$PHPVERS-mbstring \
    php$PHPVERS-ctype \
    php$PHPVERS-opcache \
    php$PHPVERS-session \
    php$PHPVERS-tokenizer

RUN mkdir -p /app \
    && mkdir /app/cache \
    && mkdir /var/tmp/nginx

# Copy root filesystem
COPY rootfs /

# Corrects permissions for /app directory
RUN if [ -d /app ]; then chown -R nginx /app; fi
RUN chown -R nginx /var/lib/nginx
RUN chmod -R 777 /var/lib/nginx

# Corrects permissions for s6 v3
RUN if [ -d /etc/cont-init.d ]; then chmod -R 755 /etc/cont-init.d; fi && \
    if [ -d /etc/services.d ]; then chmod -R 755 /etc/services.d; fi && \
    if [ -f /entrypoint.sh ]; then chmod 755 /entrypoint.sh; fi

# Build arguments
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
    org.opencontainers.image.source="https://github.com/${BUILD_REPOSITORY}" \
    org.opencontainers.image.documentation="https://github.com/${BUILD_REPOSITORY}/blob/main/README.md" \
    org.opencontainers.image.created=${BUILD_DATE} \
    org.opencontainers.image.revision=${BUILD_REF} \
    org.opencontainers.image.version=${BUILD_VERSION}