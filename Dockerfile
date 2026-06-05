FROM php:8.2-fpm

# تنظیم پروکسی برای apt
# تنظیم پروکسی برای apt (HTTP به جای SOCKS5)
RUN echo 'Acquire::http::Proxy "http://127.0.0.1:20171";' > /etc/apt/apt.conf.d/95proxies \
    && echo 'Acquire::https::Proxy "http://127.0.0.1:20171";' >> /etc/apt/apt.conf.d/95proxies

# نصب وابستگی‌های سیستمی
RUN apt-get update && apt-get install -y libonig-dev \
    && rm -rf /var/lib/apt/lists/*
# نصب افزونه‌های PHP (بدون apt)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath

RUN apt-get update && apt-get install -y wget && \
    export http_proxy=http://127.0.0.1:20171 && \
    export https_proxy=http://127.0.0.1:20171 && \
    cd /tmp && \
    wget https://pecl.php.net/get/redis-6.0.2.tgz && \
    tar -xzf redis-6.0.2.tgz && \
    cd redis-6.0.2 && \
    phpize && \
    ./configure && \
    make && \
    make install && \
    docker-php-ext-enable redis && \
    cd / && rm -rf /tmp/redis-6.0.2* && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# تنظیم مسیر کاری
WORKDIR /var/www

# کپی فایل‌های پروژه (شامل vendor)
COPY . /var/www

# تنظیم دسترسی‌ها
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

EXPOSE 8000

# اجرای artisan serve
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
