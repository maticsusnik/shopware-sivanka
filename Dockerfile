FROM ghcr.io/shopware/docker-base:8.4-frankenphp

ARG DOCKER_USER=shopware
ARG DOCKER_GROUP=shopware
ARG WORKDIR=/var/www/html
ARG DOCKER_IMAGE_EXTRAS=""

ENV DOCKER_ENTRYPOINT_DISABLE_RSYNC=false

USER root

RUN install-php-extensions @composer

RUN if [ "$DOCKER_IMAGE_EXTRAS" = "-dev" ]; then install-php-extensions xdebug; fi

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs jq curl unzip nano vim-tiny rsync \
    && npm install -g npm@11.6.2 \
    && if [ "$DOCKER_IMAGE_EXTRAS" = "-dev" ]; then apt-get install -y xdg-utils; fi \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN \
	adduser --disabled-password --gecos '' ${DOCKER_USER}; \
	setcap -r /usr/local/bin/frankenphp; \
	mkdir -p /tmp/php-opcache; \
	chown -R ${DOCKER_USER}:${DOCKER_USER} /config/caddy /data/caddy /tmp/php-opcache

COPY ./docker/entrypoint/*.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh

COPY ./docker/php/conf.d/*.ini /usr/local/etc/php/conf.d/

COPY ./docker/caddy/Caddyfile /etc/caddy/Caddyfile
RUN chmod 644 /etc/caddy/Caddyfile

ENV SERVER_ROOT=${WORKDIR}/public

WORKDIR ${WORKDIR}

COPY --chown=${DOCKER_USER}:${DOCKER_USER} ./shopware /usr/src/shopware

USER ${DOCKER_USER}

ENTRYPOINT ["ow-shopware.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]