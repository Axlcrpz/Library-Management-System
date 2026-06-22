FROM php:8.2-apache

RUN a2enmod rewrite deflate expires headers

RUN docker-php-ext-install mysqli pdo pdo_mysql

# ── Production PHP tuning ───────────────────────────────────────────────────────
# OPcache: compile PHP once and serve from memory (the single biggest throughput
# win for mod_php — avoids reparsing/recompiling 5k+ lines on every request).
# validate_timestamps + a short revalidate keep dev's bind-mounted edits live.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
 && { \
      echo 'expose_php=Off'; \
      echo 'memory_limit=256M'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=24M'; \
    } > /usr/local/etc/php/conf.d/zz-prod.ini

# ── Apache: gzip text responses (big JSON/JS/CSS) + cache versioned static assets ──
RUN printf '%s\n' \
    '<IfModule mod_deflate.c>' \
    '  AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json image/svg+xml application/xml' \
    '</IfModule>' \
    '<IfModule mod_expires.c>' \
    '  ExpiresActive On' \
    '  ExpiresByType text/css "access plus 7 days"' \
    '  ExpiresByType application/javascript "access plus 7 days"' \
    '</IfModule>' \
    > /etc/apache2/conf-available/perf-lms.conf \
 && a2enconf perf-lms

# Allow .htaccess overrides, and deny ALL direct web access to uploaded files.
# Files under storage/ are reachable only through the authenticated
# api/library_handler.php?action=file_serve endpoint (PHP readfile bypasses this).
RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && printf '%s\n' \
    '<Directory /var/www/html/storage>' \
    '    Require all denied' \
    '</Directory>' \
    > /etc/apache2/conf-available/deny-storage.conf \
 && a2enconf deny-storage

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html
