FROM php:8.4-fpm

LABEL maintainer="Cosmopolitan Coalition"
LABEL description="Fair Constitution App — PHP 8.4 service"

# ─── System dependencies ──────────────────────────────────────
# postgresql-client provides pg_dump / pg_restore, used by Phase P.9's
# MapDataExportService / MapDataImportService for portable backup/restore
# of the full map-data state (jurisdictions + worldpop_rasters + meta).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    wget \
    zip \
    unzip \
    libpq-dev \
    postgresql-client \
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

# FPM pool tuning. The "zz-" filename loads AFTER the base www.conf so its
# pm.max_children etc. override the defaults. See the file's header for the
# reasoning behind the chosen values.
COPY docker/php/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# OPcache pre-warm entrypoint. Fires one curl to http://nginx/ in the
# background after the container starts, so Laravel's framework files are
# already compiled into OPcache by the time the operator's browser arrives.
# Without this, the first post-restart request takes 5-15 s to parse the
# framework, which under the polling load this app generates is enough to
# saturate the worker pool and hang the page. See the script's header for
# the full reasoning.
COPY docker/php/entrypoint.sh /usr/local/bin/fc-entrypoint.sh
RUN chmod +x /usr/local/bin/fc-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/fc-entrypoint.sh"]

# ─── Composer (latest) ────────────────────────────────────────
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ─── Node.js 22 LTS (for Vite/npm inside container) ──────────
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# ─── Working directory ────────────────────────────────────────
WORKDIR /var/www/html

# App code comes via bind mount — nothing copied here
