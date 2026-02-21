FROM php:8.4-fpm

LABEL maintainer="Cosmopolitan Coalition"
LABEL description="Fair Constitution App — PHP 8.4 service"

# ─── System dependencies ──────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    wget \
    zip \
    unzip \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# ─── PHP extensions ───────────────────────────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        mbstring \
        exif \
        pcntl \
        bcmath \
        intl \
        gd \
        opcache \
        xml \
        sockets

# ─── Redis PHP extension (via PECL) ───────────────────────────
RUN pecl install redis \
    && docker-php-ext-enable redis

# ─── PHP config for development ───────────────────────────────
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/php-local.ini $PHP_INI_DIR/conf.d/99-local.ini

# ─── Composer (latest) ────────────────────────────────────────
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ─── Node.js 22 LTS (for Vite/npm inside container) ──────────
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# ─── Working directory ────────────────────────────────────────
WORKDIR /var/www/html

# App code comes via bind mount — nothing copied here
