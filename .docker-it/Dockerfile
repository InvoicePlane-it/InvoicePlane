# ---it--- multi-stage build

# ----------------------------------------------------------
# Node
FROM node:14-alpine AS node-stage
WORKDIR /app
COPY package* ./
RUN npm install
#RUN npm ci
#COPY .docker-it/node ./.docker-it/node
COPY assets ./assets
#RUN npm run sass
COPY Gruntfile.js ./
RUN npm run build

# ----------------------------------------------------------
# PHP
FROM php:7.3-fpm AS php-stage

ARG BUILD_ENV=production
ENV BUILD_ENV=$BUILD_ENV

# Install git for composer, locales for intl extension
RUN apt-get update && \
  apt-get install -y git locales-all

# Install project's required extensions (https://github.com/mlocati/docker-php-extension-installer)
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
  install-php-extensions gd intl mysqli imagick opcache zip redis

RUN if [ "$BUILD_ENV" = "development" ] ; then install-php-extensions xdebug-^2 ; fi

# php.ini development vs production
RUN mv "$PHP_INI_DIR/php.ini-$BUILD_ENV" "$PHP_INI_DIR/php.ini"
# + custom php.ini
COPY .docker-it/php/conf.d/php.ini "$PHP_INI_DIR/conf.d/"

# test ENV
# https://vsupalov.com/docker-build-pass-environment-variables/
RUN if [ "$BUILD_ENV" = "development" ] ; then touch /_is_build_dev.txt ; else touch /_is_build_prod.txt ; fi
RUN echo "BUILD_ENV is: $BUILD_ENV" >> /_BUILD_ENV.txt

WORKDIR /var/www/html

# Install and run composer
# (according to composer image doc: "(optimal) create your own build image and install Composer inside it.")
RUN install-php-extensions @composer-2
COPY composer.* ./
RUN composer install --no-interaction

# Copy node built artifacts
COPY --from=node-stage /app/assets ./assets

# Copy source code
COPY . .

# Set rw permissions for www-data to logs folder
RUN chown www-data:www-data application/logs

# Set rw permissions for www-data to these mount points
#RUN mkdir uploads && chown www-data:www-data uploads
#RUN mkdir cache && chown www-data:www-data cache

# ----------------------------------------------------------
# Nginx
FROM nginx:1.25 AS nginx-stage
COPY ./.docker-it/nginx/templates /etc/nginx/templates
COPY --from=php-stage /var/www/html /var/www/html
