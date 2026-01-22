FROM php:8.2-apache

# Sistem bağımlılıkları
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite aktif et
RUN a2enmod rewrite

# Backend klasörüne erişim için Apache alias ekle
RUN echo '<Directory /var/www/backend>' >> /etc/apache2/apache2.conf && \
    echo '    Options Indexes FollowSymLinks' >> /etc/apache2/apache2.conf && \
    echo '    AllowOverride None' >> /etc/apache2/apache2.conf && \
    echo '    Require all granted' >> /etc/apache2/apache2.conf && \
    echo '</Directory>' >> /etc/apache2/apache2.conf
RUN echo 'Alias /backend /var/www/backend' >> /etc/apache2/sites-available/000-default.conf && \
    echo '<Directory /var/www/backend>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Options Indexes FollowSymLinks' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    AllowOverride None' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Require all granted' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</Directory>' >> /etc/apache2/sites-available/000-default.conf

# PHP ayarları
RUN echo "date.timezone = Europe/Istanbul" >> /usr/local/etc/php/php.ini

# Çalışma dizini
WORKDIR /var/www/html

# Dosyaları kopyala
COPY . /var/www/html/

# İzinleri ayarla
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
