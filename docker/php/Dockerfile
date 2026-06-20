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

# ─── lego (ACME client for the broker.tls role; DNS-01 via Cloudflare) ────────
# Baked into the image so a volunteer who adopts the broker role never installs a
# dependency by hand — `mesh:role qualify broker.tls` passes out of the box. A
# prebuilt static Go binary, arch-matched (dpkg arch → lego asset) so the SAME
# Dockerfile builds on amd64 and arm64 (the Pi). The build self-verifies (--version);
# a release fetch that fails fails the build rather than shipping a broken broker.
ARG LEGO_VERSION=4.17.4
RUN arch="$(dpkg --print-architecture)" \
    && case "$arch" in \
        amd64) lego_arch=amd64 ;; \
        arm64) lego_arch=arm64 ;; \
        armhf) lego_arch=armv7 ;; \
        *) lego_arch="$arch" ;; \
    esac \
    && curl -fsSL "https://github.com/go-acme/lego/releases/download/v${LEGO_VERSION}/lego_v${LEGO_VERSION}_linux_${lego_arch}.tar.gz" -o /tmp/lego.tar.gz \
    && tar -xzf /tmp/lego.tar.gz -C /usr/local/bin lego \
    && rm /tmp/lego.tar.gz \
    && /usr/local/bin/lego --version

# ─── Working directory ────────────────────────────────────────
WORKDIR /var/www/html

# App code comes via bind mount — nothing copied here
