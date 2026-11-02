# RecupBuild: PHP 8.3 and Apache, no Composer dependency.
FROM php:8.3-apache

# gd reads the photos (JPEG, PNG, WebP) and exif turns them the right way up;
# the image already ships curl, fileinfo, mbstring and pdo_sqlite.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd exif \
    && rm -rf /var/lib/apt/lists/*

# The project's .htaccess serves the site's files and sends everything else to index.php.
RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/recupbuild.conf \
    && a2enconf recupbuild

# A photo weighs up to 12 MB and the model can take minutes on a plan.
RUN { \
        echo 'upload_max_filesize = 12M'; \
        echo 'post_max_size = 14M'; \
        echo 'max_execution_time = 300'; \
        echo 'memory_limit = 256M'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'expose_php = Off'; \
    } > "$PHP_INI_DIR/conf.d/recupbuild.ini"

COPY . /var/www/html/

# The database and the photos live outside the site, in a volume.
ENV STORAGE_PATH=/data
RUN mkdir -p /data && chown -R www-data:www-data /data
VOLUME /data

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/api/health') === false ? 1 : 0);"
