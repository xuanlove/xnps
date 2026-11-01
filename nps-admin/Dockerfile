# syntax=docker/dockerfile:1
#
# xnps-admin 镜像（I3）：多阶段构建（前端资源 + composer 依赖 + 运行时）。
# 同一镜像用于 web / queue / scheduler，command 由 docker-compose 覆盖。

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader

FROM php:8.4-cli-alpine AS runtime
RUN apk add --no-cache tzdata \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl bcmath opcache

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/healthz') === false ? 1 : 0);"

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
