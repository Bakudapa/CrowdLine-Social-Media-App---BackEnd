FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_pgsql pgsql redis opcache pcntl intl zip bcmath

# Run php-fpm as the host user so bind-mounted files stay writable on both sides.
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app \
    && sed -i 's/^user = .*/user = app/; s/^group = .*/group = app/' /usr/local/etc/php-fpm.d/www.conf

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html
USER app
