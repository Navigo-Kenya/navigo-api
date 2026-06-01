# ── Stage 1: Composer dependencies ───────────────────────────────────────────
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --prefer-dist \
      --optimize-autoloader \
      --no-interaction

# ── Stage 2: Runtime ──────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine

# Runtime shared libraries (kept in final image)
RUN apk add --no-cache \
      libpq \
      libzip \
      icu-libs \
      libpng \
      libjpeg-turbo \
      freetype \
      libxml2 \
      oniguruma

# Build-time deps: headers + toolchain (removed after extension install)
RUN apk add --no-cache --virtual .build-deps \
      ${PHPIZE_DEPS} \
      postgresql-dev \
      libzip-dev \
      icu-dev \
      libpng-dev \
      libjpeg-turbo-dev \
      freetype-dev \
      libxml2-dev \
      oniguruma-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
      pdo_pgsql \
      pgsql \
      zip \
      intl \
      bcmath \
      pcntl \
      exif \
      gd \
      opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps \
 && rm -rf /tmp/pear /var/cache/apk/*

# OPcache — production settings (no file-change polling)
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.interned_strings_buffer=8'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.revalidate_freq=0'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.save_comments=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# PHP-FPM pool — dynamic, 10 workers max (sufficient for current traffic)
RUN { \
      echo '[www]'; \
      echo 'pm = dynamic'; \
      echo 'pm.max_children = 10'; \
      echo 'pm.start_servers = 2'; \
      echo 'pm.min_spare_servers = 1'; \
      echo 'pm.max_spare_servers = 3'; \
      echo 'pm.max_requests = 500'; \
      echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-hopln.conf

WORKDIR /var/www

# Copy vendor from composer stage
COPY --from=deps /app/vendor ./vendor

# Copy application source
COPY . .

# Ensure required storage directories exist (volume mount may shadow them)
RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/testing \
      storage/framework/views \
      storage/logs \
      storage/app/gtfs \
      storage/app/public \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 755 storage bootstrap/cache \
 && chmod +x docker/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
