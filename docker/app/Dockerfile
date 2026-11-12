# Laravel アプリ用の開発コンテナ（PHP 8.5 CLI + PostgreSQL 拡張 + Composer）
# 学習用の最小構成。Web サーバーは php artisan serve を使う。
FROM php:8.5-cli-bookworm

# pdo_pgsql: PostgreSQL 接続 / zip・unzip・git: composer が依存を展開するのに使う
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
