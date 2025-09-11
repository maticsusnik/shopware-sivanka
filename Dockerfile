# @docs: https://dockerfile.readthedocs.io/en/latest/content/DockerImages/dockerfiles/php-nginx.html
ARG DOCKER_IMAGE_EXTRAS=""
FROM webdevops/php-nginx${DOCKER_IMAGE_EXTRAS}:8.3
WORKDIR "/var/www/html/"

ENV DOCKER_ENTRYPOINT_DISABLE_RSYNC=false

## VARIABLES
ENV TZ="Europe/Ljubljana"
ENV SERVICE_CRON="true"
ENV WEB_DOCUMENT_ROOT="/var/www/html/public"
ENV NODE_VERSION="22.x"

## Install required dependencies
RUN apt-get update -y
RUN apt-get install -y nano jq curl libxml2-dev vim rsync
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION} | bash -
RUN apt-get install -y nodejs
## Dev only - uncomment when building local for HMR
## RUN apt-get xdg-utils
RUN npm install -g npm@latest
RUN docker-php-ext-install phar simplexml
RUN docker-php-ext-enable phar simplexml
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

## COPY Entrypoint
COPY ./docker/entrypoint/* /opt/docker/provision/entrypoint.d
RUN chmod +x /opt/docker/provision/entrypoint.d/*

## COPY Nginx config
# Delete default configs
RUN rm -rf /opt/docker/etc/nginx/vhost.common.d/*
COPY ./config/nginx /opt/docker/etc/nginx/vhost.common.d

## COPY PHP config
COPY ./docker/php/conf.d/*.ini /usr/local/etc/php/conf.d

## COPY Supervisor config
COPY ./docker/supervisor/* /opt/docker/etc/supervisor.d

## Shopware
COPY ./shopware /usr/src/shopware

WORKDIR /usr/src/shopware

RUN composer install --no-interaction --no-scripts

WORKDIR /var/www/html